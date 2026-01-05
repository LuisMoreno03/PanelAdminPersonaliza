// =====================================================
// DASHBOARD.JS (COMPLETO) - REAL TIME + PAGINACIÓN ESTABLE
// - Paginación Shopify (next/prev page_info)
// - Live refresh solo en página 1 (anti-overwrite)
// - Modal etiquetas bonito (max 2) + generales
// - Endpoints con fallback /index.php
// =====================================================

/* =====================================================
   VARIABLES GLOBALES
===================================================== */
let nextPageInfo = null;
let prevPageInfo = null;
let isLoading = false;
let currentPage = 1;

// cache local
let ordersCache = [];
let ordersById = new Map();

// live
let liveMode = true;
let liveInterval = null;

// users
let userPingInterval = null;
let userStatusInterval = null;

// anti overwrite
let lastFetchToken = 0;
const dirtyOrders = new Map(); // id -> { until, estado, last_status_change, etiquetas? }
const DIRTY_TTL_MS = 15000;

/* =====================================================
   HELPERS URL (con fallback index.php)
===================================================== */
function normalizePath(p) {
  if (!p.startsWith("/")) p = "/" + p;
  return p;
}

function endpointsFor(path) {
  path = normalizePath(path);
  const withIndex = "/index.php" + path;

  // si ya estás en /index.php/... intenta primero con index
  const preferIndex = window.location.pathname.includes("/index.php");
  return preferIndex ? [withIndex, path] : [path, withIndex];
}

async function fetchJsonWithFallback(path, options = {}) {
  const urls = endpointsFor(path);
  let lastErr = null;

  for (const url of urls) {
    try {
      const r = await fetch(url, options);
      if (r.status === 404) continue;
      const d = await r.json().catch(() => null);
      return { ok: r.ok, status: r.status, data: d, url };
    } catch (e) {
      lastErr = e;
    }
  }
  throw lastErr || new Error("No se pudo conectar.");
}

function jsonHeaders() {
  const headers = { Accept: "application/json", "Content-Type": "application/json" };

  // (opcional) CSRF si lo agregas en meta
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content");
  const csrfHeader = document.querySelector('meta[name="csrf-header"]')?.getAttribute("content") || "X-CSRF-TOKEN";
  if (csrfToken) headers[csrfHeader] = csrfToken;

  return headers;
}

/* =====================================================
   Loader global
===================================================== */
function showLoader() {
  document.getElementById("globalLoader")?.classList.remove("hidden");
}
function hideLoader() {
  document.getElementById("globalLoader")?.classList.add("hidden");
}

/* =====================================================
   INIT
===================================================== */
document.addEventListener("DOMContentLoaded", () => {
  // botones paginación
  const btnAnterior = document.getElementById("btnAnterior");
  const btnSiguiente = document.getElementById("btnSiguiente");

  btnAnterior?.addEventListener("click", (e) => {
    e.preventDefault();
    if (!btnAnterior.disabled) paginaAnterior();
  });

  btnSiguiente?.addEventListener("click", (e) => {
    e.preventDefault();
    if (!btnSiguiente.disabled) paginaSiguiente();
  });

  // users
  pingUsuario();
  userPingInterval = setInterval(pingUsuario, 30000);

  cargarUsuariosEstado();
  userStatusInterval = setInterval(cargarUsuariosEstado, 15000);

  // etiquetas disponibles (para modal)
  cargarEtiquetasDisponibles();

  // pedidos
  resetToFirstPage(true);

  // live solo en página 1
  startLive(20000);

  // re-render sin pedir backend
  window.addEventListener("resize", () => {
    const cont = document.getElementById("tablaPedidos");
    if (cont?.dataset?.lastOrders) {
      try {
        const orders = JSON.parse(cont.dataset.lastOrders);
        actualizarTabla(Array.isArray(orders) ? orders : []);
      } catch {}
    }
  });
});

/* =====================================================
   LIVE CONTROL
===================================================== */
function startLive(ms = 20000) {
  if (liveInterval) clearInterval(liveInterval);

  liveInterval = setInterval(() => {
    if (liveMode && currentPage === 1 && !isLoading) {
      cargarPedidos({ reset: false, page_info: "" });
    }
  }, ms);
}
function pauseLive() {
  liveMode = false;
}
function resumeLiveIfOnFirstPage() {
  if (currentPage === 1) liveMode = true;
}

/* =====================================================
   HELPERS UI
===================================================== */
function escapeHtml(str) {
  return String(str ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}
function escapeJsString(str) {
  return String(str ?? "").replaceAll("\\", "\\\\").replaceAll("'", "\\'");
}
function esBadgeHtml(valor) {
  const s = String(valor ?? "").trim();
  return s.startsWith("<span") || s.includes("<span") || s.includes("</span>");
}
function renderEstado(valor) {
  if (esBadgeHtml(valor)) return String(valor);
  return escapeHtml(valor ?? "-");
}

function entregaStyle(estado) {
  const s = String(estado || "").toLowerCase().trim();
  if (!s || s === "-" || s === "null") return { wrap: "bg-slate-50 border-slate-200 text-slate-800", dot: "bg-slate-400", icon: "📦", label: "Sin estado" };
  if (s.includes("entregado") || s.includes("delivered")) return { wrap: "bg-emerald-50 border-emerald-200 text-emerald-900", dot: "bg-emerald-500", icon: "✅", label: "Entregado" };
  if (s.includes("enviado") || s.includes("shipped")) return { wrap: "bg-blue-50 border-blue-200 text-blue-900", dot: "bg-blue-500", icon: "🚚", label: "Enviado" };
  if (s.includes("prepar") || s.includes("pendiente") || s.includes("processing")) return { wrap: "bg-amber-50 border-amber-200 text-amber-900", dot: "bg-amber-500", icon: "⏳", label: "Preparando" };
  if (s.includes("cancel") || s.includes("devuelto") || s.includes("return")) return { wrap: "bg-rose-50 border-rose-200 text-rose-900", dot: "bg-rose-500", icon: "⛔", label: "Incidencia" };
  return { wrap: "bg-slate-50 border-slate-200 text-slate-900", dot: "bg-slate-400", icon: "📍", label: estado || "—" };
}
function renderEntregaPill(estadoEnvio) {
  const st = entregaStyle(estadoEnvio);
  return `
    <span class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl border ${st.wrap}
                 shadow-sm font-extrabold text-[11px] uppercase tracking-wide whitespace-nowrap">
      <span class="h-2.5 w-2.5 rounded-full ${st.dot}"></span>
      <span class="text-sm leading-none">${st.icon}</span>
      <span class="leading-none">${escapeHtml(st.label)}</span>
    </span>
  `;
}

function setPaginaUI() {
  const pill = document.getElementById("pillPagina");
  if (pill) pill.textContent = `Página ${currentPage}`;
}

/* =====================================================
   PAGINACIÓN (RESET)
===================================================== */
function resetToFirstPage(withFetch = false) {
  currentPage = 1;
  nextPageInfo = null;
  prevPageInfo = null;
  liveMode = true;

  setPaginaUI();
  actualizarControlesPaginacion();

  if (withFetch) cargarPedidos({ reset: true, page_info: "" });
}

/* =====================================================
   CARGAR PEDIDOS (PROTECCIÓN anti overwrite)
   Endpoint recomendado: /dashboard/pedidos
   Fallback: /dashboard/filter
===================================================== */
function buildPedidosUrl(path, page_info) {
  // el backend realmente usa page_info (shopify) - el "page" es solo UI
  const u = new URL(endpointsFor(path)[0], window.location.origin);
  if (page_info) u.searchParams.set("page_info", page_info);
  return u.pathname + u.search;
}

function cargarPedidos({ page_info = "", reset = false } = {}) {
  if (isLoading) return;
  isLoading = true;
  showLoader();

  const fetchToken = ++lastFetchToken;

  if (reset) {
    currentPage = 1;
    nextPageInfo = null;
    prevPageInfo = null;
    page_info = "";
    liveMode = true;
  }

  const primary = buildPedidosUrl("/dashboard/pedidos", page_info);
  const fallback = buildPedidosUrl("/dashboard/filter", page_info);

  // intentamos primero primary, luego fallback
  fetchJsonWithFallback(primary, { headers: { Accept: "application/json" } })
    .then(async (res1) => {
      // si primary no trae success, intentamos fallback
      const d1 = res1.data;
      if (d1 && d1.success) return d1;

      const res2 = await fetchJsonWithFallback(fallback, { headers: { Accept: "application/json" } });
      return res2.data;
    })
    .then((data) => {
      if (fetchToken !== lastFetchToken) return;

      if (!data || !data.success) {
        ordersCache = [];
        ordersById = new Map();
        nextPageInfo = null;
        prevPageInfo = null;
        actualizarTabla([]);
        actualizarControlesPaginacion();
        setPaginaUI();
        return;
      }

      nextPageInfo = data.next_page_info ?? null;
      prevPageInfo = data.prev_page_info ?? null;

      let incoming = Array.isArray(data.orders) ? data.orders : [];

      // dirty protection
      const now = Date.now();
      incoming = incoming.map((o) => {
        const id = String(o.id ?? "");
        if (!id) return o;

        const dirty = dirtyOrders.get(id);
        if (dirty && dirty.until > now) {
          return {
            ...o,
            estado: dirty.estado ?? o.estado,
            etiquetas: dirty.etiquetas ?? o.etiquetas,
            last_status_change: dirty.last_status_change ?? o.last_status_change,
          };
        }
        if (dirty) dirtyOrders.delete(id);
        return o;
      });

      ordersCache = incoming;
      ordersById = new Map(ordersCache.map((o) => [String(o.id), o]));

      actualizarTabla(ordersCache);

      document.getElementById("total-pedidos") &&
        (document.getElementById("total-pedidos").textContent = String(data.count ?? incoming.length));

      setPaginaUI();
      actualizarControlesPaginacion();
    })
    .catch((err) => {
      if (fetchToken !== lastFetchToken) return;
      console.error("Error cargando pedidos:", err);

      ordersCache = [];
      ordersById = new Map();
      nextPageInfo = null;
      prevPageInfo = null;

      actualizarTabla([]);
      actualizarControlesPaginacion();
      setPaginaUI();
    })
    .finally(() => {
      if (fetchToken !== lastFetchToken) return;
      isLoading = false;
      hideLoader();
    });
}

function actualizarControlesPaginacion() {
  const btnSig = document.getElementById("btnSiguiente");
  const btnAnt = document.getElementById("btnAnterior");

  if (btnSig) {
    btnSig.disabled = !nextPageInfo;
    btnSig.classList.toggle("opacity-50", btnSig.disabled);
    btnSig.classList.toggle("cursor-not-allowed", btnSig.disabled);
  }
  if (btnAnt) {
    btnAnt.disabled = !prevPageInfo || currentPage <= 1;
    btnAnt.classList.toggle("opacity-50", btnAnt.disabled);
    btnAnt.classList.toggle("cursor-not-allowed", btnAnt.disabled);
  }
}

function paginaSiguiente() {
  if (!nextPageInfo) return;
  pauseLive();
  currentPage += 1;
  setPaginaUI();
  cargarPedidos({ page_info: nextPageInfo });
}
function paginaAnterior() {
  if (!prevPageInfo || currentPage <= 1) return;
  currentPage -= 1;
  setPaginaUI();
  cargarPedidos({ page_info: prevPageInfo });
  resumeLiveIfOnFirstPage();
}

/* =====================================================
   ÚLTIMO CAMBIO
===================================================== */
function formatDateTime(dtStr) {
  if (!dtStr) return "—";
  const safe = String(dtStr).includes("T") ? String(dtStr) : String(dtStr).replace(" ", "T");
  const d = new Date(safe);
  if (isNaN(d)) return "—";
  const pad = (n) => String(n).padStart(2, "0");
  return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
function renderLastChangeCompact(p) {
  const info = p?.last_status_change;
  if (!info || !info.changed_at) return "—";
  const user = info.user_name ? escapeHtml(info.user_name) : "—";
  const exact = formatDateTime(info.changed_at);
  return `
    <div class="leading-tight min-w-0">
      <div class="text-[12px] font-extrabold text-slate-900 truncate">${user}</div>
      <div class="text-[11px] text-slate-600 whitespace-nowrap">${escapeHtml(exact)}</div>
    </div>
  `;
}

/* =====================================================
   ETIQUETAS (compact)
===================================================== */
function colorEtiqueta(tag) {
  tag = String(tag).toLowerCase().trim();
  if (tag.startsWith("d.")) return "bg-emerald-50 border-emerald-200 text-emerald-900";
  if (tag.startsWith("p.")) return "bg-amber-50 border-amber-200 text-amber-900";
  return "bg-slate-50 border-slate-200 text-slate-800";
}

function renderEtiquetasCompact(etiquetas, orderId, mobile = false) {
  const raw = String(etiquetas || "").trim();
  const list = raw ? raw.split(",").map((t) => t.trim()).filter(Boolean) : [];

  const max = mobile ? 3 : 2;
  const visibles = list.slice(0, max);
  const rest = list.length - visibles.length;

  const pills = visibles
    .map((tag) => {
      const cls = colorEtiqueta(tag);
      return `<span class="px-2.5 py-1.5 rounded-full text-[10px] font-extrabold uppercase tracking-wide border ${cls}">
        ${escapeHtml(tag)}
      </span>`;
    })
    .join("");

  const more =
    rest > 0
      ? `<span class="px-2.5 py-1.5 rounded-full text-[10px] font-extrabold uppercase tracking-wide border bg-white border-slate-200 text-slate-700">+${rest}</span>`
      : "";

  const onClick = `abrirModalEtiquetas('${String(orderId)}')`;

  if (!list.length) {
    return `
      <button onclick="${onClick}"
        class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl bg-white border border-slate-200 text-slate-900 text-[11px] font-extrabold uppercase tracking-wide hover:shadow-md transition whitespace-nowrap">
        Etiquetas <span class="text-blue-700">＋</span>
      </button>`;
  }

  return `
    <div class="flex flex-wrap items-center gap-2">
      ${pills}${more}
      <button onclick="${onClick}"
        class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl bg-slate-900 text-white text-[11px] font-extrabold uppercase tracking-wide hover:bg-slate-800 transition shadow-sm whitespace-nowrap">
        Etiquetas <span class="text-white/80">✎</span>
      </button>
    </div>`;
}

/* =====================================================
   TABLA (tbody) + (opcional) cards
   - Si tu HTML usa tbody, esto genera TRs.
===================================================== */
function actualizarTabla(pedidos) {
  const tbody = document.getElementById("tablaPedidos");
  const cards = document.getElementById("cardsPedidos");

  if (tbody) tbody.dataset.lastOrders = JSON.stringify(pedidos || []);

  // --- TBODY (tu HTML actual usa table + tbody)
  if (tbody) {
    tbody.innerHTML = "";

    if (!pedidos.length) {
      tbody.innerHTML = `
        <tr>
          <td colspan="11" class="py-10 text-center text-slate-500">No se encontraron pedidos</td>
        </tr>`;
      return;
    }

    tbody.innerHTML = pedidos
      .map((p) => {
        const id = String(p.id ?? "");
        const etiquetas = p.etiquetas ?? "";

        return `
        <tr class="border-b border-slate-100 hover:bg-slate-50/60 transition">
          <td class="py-4 px-4 font-extrabold text-slate-900 whitespace-nowrap">${escapeHtml(p.numero ?? "-")}</td>

          <td class="py-4 px-4 hidden lg:table-cell whitespace-nowrap">${escapeHtml(p.fecha ?? "-")}</td>
          <td class="py-4 px-4 hidden lg:table-cell truncate max-w-[220px]">${escapeHtml(p.cliente ?? "-")}</td>
          <td class="py-4 px-4 hidden lg:table-cell whitespace-nowrap font-extrabold">${escapeHtml(p.total ?? "-")}</td>

          <td class="py-4 px-2 w-44">
            <button onclick="abrirModalEstado('${escapeJsString(id)}')"
              class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl bg-white border border-slate-200 shadow-sm hover:shadow-md transition">
              <span class="h-2 w-2 rounded-full bg-blue-600"></span>
              <span class="text-[11px] font-extrabold uppercase tracking-wide text-slate-900">
                ${renderEstado(p.estado ?? "-")}
              </span>
            </button>
          </td>

          <td class="py-4 px-4 hidden xl:table-cell">${renderLastChangeCompact(p)}</td>

          <td class="py-4 px-4">${renderEtiquetasCompact(etiquetas, id)}</td>

          <td class="py-4 px-4 hidden lg:table-cell text-center font-extrabold">${escapeHtml(p.articulos ?? "-")}</td>

          <td class="py-4 px-4">${renderEntregaPill(p.estado_envio ?? "-")}</td>

          <td class="py-4 px-4 hidden xl:table-cell truncate max-w-[220px]">${escapeHtml(p.forma_envio ?? "-")}</td>

          <td class="py-4 px-4 text-right whitespace-nowrap">
            <button onclick="verDetalles('${escapeJsString(id)}')"
              class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl bg-blue-600 text-white text-[11px] font-extrabold uppercase tracking-wide hover:bg-blue-700 transition">
              Ver <span class="text-white/90">→</span>
            </button>
          </td>
        </tr>`;
      })
      .join("");
  }

  // --- Cards (si tienes contenedor)
  if (cards) {
    cards.innerHTML = "";
    // si tu HTML no lo usa, no pasa nada
  }
}

/* =====================================================
   MODAL ESTADO (usa tu modal existente #modalEstado)
===================================================== */
window.abrirModalEstado = function(orderId) {
  const idInput = document.getElementById("modalOrderId");
  if (idInput) idInput.value = String(orderId ?? "");
  document.getElementById("modalEstado")?.classList.remove("hidden");
};

window.cerrarModalEstado = function() {
  document.getElementById("modalEstado")?.classList.add("hidden");
};

// Botones del modal pueden llamar: guardarEstado("Producción") etc.
window.guardarEstado = async function(nuevoEstado) {
  const id = String(document.getElementById("modalOrderId")?.value || "");
  if (!id) return;

  pauseLive();

  const order = ordersById.get(id);
  const prevEstado = order?.estado ?? null;
  const prevLast = order?.last_status_change ?? null;

  // optimistic
  const userName = window.CURRENT_USER || "Sistema";
  const nowStr = new Date().toISOString().slice(0, 19).replace("T", " ");
  const optimisticLast = { user_name: userName, changed_at: nowStr };

  if (order) {
    order.estado = nuevoEstado;
    order.last_status_change = optimisticLast;
    actualizarTabla(ordersCache);
  }

  dirtyOrders.set(id, {
    until: Date.now() + DIRTY_TTL_MS,
    estado: nuevoEstado,
    last_status_change: optimisticLast,
  });

  window.cerrarModalEstado();

  try {
    const res = await fetchJsonWithFallback("/api/estado/guardar", {
      method: "POST",
      headers: jsonHeaders(),
      body: JSON.stringify({ id, estado: nuevoEstado }),
    });

    const d = res.data;
    if (!res.ok || !d?.success) throw new Error(d?.message || `HTTP ${res.status}`);

    // refrescar pagina 1 si toca
    if (currentPage === 1) cargarPedidos({ reset: false, page_info: "" });

    resumeLiveIfOnFirstPage();
  } catch (e) {
    console.error("guardarEstado error:", e);
    dirtyOrders.delete(id);

    if (order) {
      order.estado = prevEstado;
      order.last_status_change = prevLast;
      actualizarTabla(ordersCache);
    }
    alert("No se pudo guardar el estado. Se revirtió el cambio.");
    resumeLiveIfOnFirstPage();
  }
};

/* =====================================================
   DETALLES (usa tu backend /dashboard/detalles/:id)
===================================================== */
window.verDetalles = async function(orderId) {
  const id = String(orderId || "");
  if (!id) return;

  try {
    showLoader();
    const res = await fetchJsonWithFallback(`/dashboard/detalles/${encodeURIComponent(id)}`, {
      headers: { Accept: "application/json" },
    });
    if (!res.ok || !res.data?.success) throw new Error(res.data?.message || `HTTP ${res.status}`);

    // aquí tú ya tienes tu modal de detalles armado en tu HTML,
    // si quieres lo conecto igual que antes. Por ahora abre tu modal si existe:
    document.getElementById("modalDetalles")?.classList.remove("hidden");

    // si tienes contenedores:
    const titulo = document.getElementById("tituloPedido");
    if (titulo) titulo.textContent = `Detalles del pedido ${res.data?.order?.name ?? ""}`;

    // Si ya tienes el render completo de detalles en otro archivo, lo respetas.
    // Si quieres que lo deje 100% como antes, me pegas tu response exacta de /detalles.
  } catch (e) {
    console.error(e);
    alert("No se pudieron cargar los detalles del pedido.");
  } finally {
    hideLoader();
  }
};

/* =====================================================
   USERS STATUS (ping + estado)
===================================================== */
async function pingUsuario() {
  try {
    await fetchJsonWithFallback("/dashboard/ping", { headers: { Accept: "application/json" } });
  } catch {}
}

async function cargarUsuariosEstado() {
  try {
    const res = await fetchJsonWithFallback("/dashboard/usuarios-estado", { headers: { Accept: "application/json" } });
    const d = res.data;
    if (!d) return;

    // soporta ok o success
    const ok = d.ok === true || d.success === true;
    if (!ok) return;

    renderUsersStatus(d);
  } catch (e) {
    console.error("Error usuarios estado:", e);
  }
}

function renderUsersStatus(payload) {
  const onlineEl = document.getElementById("onlineUsers");
  const offlineEl = document.getElementById("offlineUsers");
  const onlineCountEl = document.getElementById("onlineCount");
  const offlineCountEl = document.getElementById("offlineCount");

  if (!onlineEl || !offlineEl) return;

  // payload esperado: { users:[{nombre,online,...}], online_count, offline_count }
  const users = Array.isArray(payload.users) ? payload.users : [];

  const online = users.filter(u => !!u.online);
  const offline = users.filter(u => !u.online);

  if (onlineCountEl) onlineCountEl.textContent = String(payload.online_count ?? online.length);
  if (offlineCountEl) offlineCountEl.textContent = String(payload.offline_count ?? offline.length);

  onlineEl.innerHTML = online.length
    ? online.map(u => `<li class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span><span class="font-semibold text-slate-800">${escapeHtml(u.nombre ?? "—")}</span></li>`).join("")
    : `<li class="text-sm text-emerald-800/80">No hay usuarios conectados</li>`;

  offlineEl.innerHTML = offline.length
    ? offline.map(u => `<li class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-rose-500"></span><span class="font-semibold text-slate-800">${escapeHtml(u.nombre ?? "—")}</span></li>`).join("")
    : `<li class="text-sm text-rose-800/80">No hay usuarios desconectados</li>`;
}

/* =====================================================
   MODAL ETIQUETAS (bonito) - UNIFICADO (sin duplicados)
   - carga D/P desde /dashboard/etiquetas-disponibles
   - admin ve todas (backend), otros solo las suyas (backend)
   - max 2
===================================================== */
let ETQ_DISENO = [];
let ETQ_PRODUCCION = [];
const ETQ_GENERALES = [
  "Cancelar pedido",
  "Reembolso 50%",
  "Reembolso 30%",
  "Reembolso completo",
  "Repetir",
  "No contesta 24h",
];

let _etqOrderId = null;
let _etqSelected = new Set();

async function cargarEtiquetasDisponibles() {
  try {
    const res = await fetchJsonWithFallback("/dashboard/etiquetas-disponibles", {
      headers: { Accept: "application/json" },
    });

    const d = res.data;
    if (!d || d.ok !== true) return;

    ETQ_DISENO = Array.isArray(d.diseno) ? d.diseno : [];
    ETQ_PRODUCCION = Array.isArray(d.produccion) ? d.produccion : [];

    // para compat con tu HTML viejo si lo usabas:
    // window.etiquetasPredeterminadas = [...ETQ_DISENO, ...ETQ_PRODUCCION, ...ETQ_GENERALES];
  } catch (e) {
    console.error("Error cargando etiquetas disponibles:", e);
  }
}

function parseTags(raw) {
  return String(raw || "")
    .split(",")
    .map(t => t.trim())
    .filter(Boolean);
}

function renderSelectedEtiquetas() {
  const wrap = document.getElementById("etqSelectedWrap");
  if (!wrap) return;

  const arr = Array.from(_etqSelected);

  wrap.innerHTML = arr.length
    ? arr.map(t => `
      <span class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl bg-slate-900 text-white text-xs font-extrabold">
        ${escapeHtml(t)}
        <button type="button" class="text-white/80 hover:text-white font-extrabold"
          onclick="toggleEtiqueta('${escapeJsString(t)}')">×</button>
      </span>`).join("")
    : `<span class="text-sm text-slate-500">Ninguna</span>`;

  const counter = document.getElementById("etqCounter");
  if (counter) counter.textContent = `${_etqSelected.size} / 2`;
}

function chipEtiqueta(tag) {
  const selected = _etqSelected.has(tag);
  const cls = selected
    ? "bg-slate-900 text-white border-slate-900"
    : "bg-white text-slate-900 border-slate-200 hover:border-slate-300";

  return `
    <button type="button"
      class="px-3 py-2 rounded-2xl border text-xs font-extrabold uppercase tracking-wide transition ${cls}"
      onclick="toggleEtiqueta('${escapeJsString(tag)}')">
      ${escapeHtml(tag)}
    </button>`;
}

function renderEtiquetasSections() {
  const prodWrap = document.getElementById("etqProduccionList");
  const disWrap = document.getElementById("etqDisenoList");
  const genWrap = document.getElementById("etqGeneralesList");

  if (prodWrap) prodWrap.innerHTML = ETQ_PRODUCCION.map(chipEtiqueta).join("");
  if (disWrap) disWrap.innerHTML = ETQ_DISENO.map(chipEtiqueta).join("");
  if (genWrap) genWrap.innerHTML = ETQ_GENERALES.map(chipEtiqueta).join("");

  renderSelectedEtiquetas();
}

window.toggleEtiqueta = function(tag) {
  tag = String(tag || "").trim();
  if (!tag) return;

  const err = document.getElementById("etqError");
  err?.classList.add("hidden");

  if (_etqSelected.has(tag)) {
    _etqSelected.delete(tag);
  } else {
    if (_etqSelected.size >= 2) return;
    _etqSelected.add(tag);
  }

  renderEtiquetasSections();
};

window.limpiarEtiquetas = function() {
  _etqSelected = new Set();
  renderEtiquetasSections();
};

// abre el modal con el pedido
window.abrirModalEtiquetas = function(orderId) {
  const id = String(orderId ?? "");
  if (!id) return;

  const order = ordersById.get(id);
  const raw = String(order?.etiquetas ?? "").trim();

  _etqOrderId = id;
  _etqSelected = new Set(parseTags(raw).slice(0, 2)); // max 2

  // set label pedido
  const lbl = document.getElementById("etqPedidoLabel");
  if (lbl) lbl.textContent = String(order?.numero ?? `#${id}`);

  renderEtiquetasSections();
  document.getElementById("modalEtiquetas")?.classList.remove("hidden");
};

window.cerrarModalEtiquetas = function() {
  document.getElementById("modalEtiquetas")?.classList.add("hidden");
};

window.guardarEtiquetasModal = async function() {
  if (!_etqOrderId) return;

  const err = document.getElementById("etqError");
  const btn = document.getElementById("btnGuardarEtiquetas");

  const etiquetas = Array.from(_etqSelected).join(", ");

  // optimistic UI
  const order = ordersById.get(_etqOrderId);
  const prev = order?.etiquetas ?? "";
  if (order) {
    order.etiquetas = etiquetas;
    actualizarTabla(ordersCache);
  }

  // dirty to avoid live overwrite
  dirtyOrders.set(String(_etqOrderId), {
    until: Date.now() + DIRTY_TTL_MS,
    etiquetas,
  });

  try {
    btn && (btn.disabled = true);

    const res = await fetchJsonWithFallback("/api/estado/etiquetas/guardar", {
      method: "POST",
      headers: jsonHeaders(),
      body: JSON.stringify({ id: _etqOrderId, etiquetas }), // ✅ clave correcta: etiquetas
    });

    const d = res.data;
    if (!res.ok || !d?.success) throw new Error(d?.message || `HTTP ${res.status}`);

    window.cerrarModalEtiquetas();

    // refresca página actual (mejor que reset siempre)
    cargarPedidos({ reset: false, page_info: currentPage === 1 ? "" : "" });

  } catch (e) {
    console.error(e);
    dirtyOrders.delete(String(_etqOrderId));

    if (order) {
      order.etiquetas = prev;
      actualizarTabla(ordersCache);
    }

    if (err) {
      err.textContent = "No se pudieron guardar las etiquetas.";
      err.classList.remove("hidden");
    } else {
      alert("No se pudieron guardar las etiquetas.");
    }
  } finally {
    btn && (btn.disabled = false);
  }
};
