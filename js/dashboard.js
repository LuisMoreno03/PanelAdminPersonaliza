// =====================================================
// DASHBOARD.JS (COMPLETO) - REAL TIME + PAGINACIÓN ESTABLE
// - Página 1: refresca en vivo (trae últimos 50 pedidos)
// - Si el usuario navega a página 2+: pausa live automáticamente
// - Paginación Shopify REAL (page_info)
// - Render Desktop: GRID (1 línea) sin scroll horizontal
// - Render Mobile/Tablet: CARDS
// - ✅ Estado pedido: cambio LOCAL instantáneo + persistencia backend + revert
// - ✅ Usuarios: tiempo conectado/desconectado
// - ✅ FIX CSRF: envía token si existe meta csrf-token/csrf-header
// - ✅ FIX rutas: usa /index.php si existe (fallback automático)
// =====================================================

/* =====================================================
   VARIABLES GLOBALES
===================================================== */
let nextPageInfo = null;
let prevPageInfo = null;
let isLoading = false;
let currentPage = 1;

// ✅ cache local para actualizar estados sin recargar
let ordersCache = [];
let ordersById = new Map();

// ✅ LIVE MODE
let liveMode = true;
let liveInterval = null;

let userPingInterval = null;
let userStatusInterval = null;

/* =====================================================
   CONFIG / HELPERS DE RUTAS
===================================================== */
function hasIndexPhp() {
  return window.location.pathname.includes("/index.php/");
}

function apiUrl(path) {
  // path ejemplo: "/api/estado/guardar" o "/dashboard/pedidos"
  if (!path.startsWith("/")) path = "/" + path;
  if (hasIndexPhp()) {
    // si ya estás en /index.php/... mantén consistencia
    return "/index.php" + path;
  }
  return path;
}

function jsonHeaders() {
  const headers = { "Content-Type": "application/json", Accept: "application/json" };

  // ✅ CSRF (si existe en tu HTML)
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content");
  const csrfHeader = document.querySelector('meta[name="csrf-header"]')?.getAttribute("content") || "X-CSRF-TOKEN";
  if (csrfToken) headers[csrfHeader] = csrfToken;

  return headers;
}

/* =====================================================
   Loader global
===================================================== */
function showLoader() {
  const el = document.getElementById("globalLoader");
  if (el) el.classList.remove("hidden");
}
function hideLoader() {
  const el = document.getElementById("globalLoader");
  if (el) el.classList.add("hidden");
}

/* =====================================================
   INIT
===================================================== */
document.addEventListener("DOMContentLoaded", () => {
  const btnAnterior = document.getElementById("btnAnterior");
  const btnSiguiente = document.getElementById("btnSiguiente");

  if (btnAnterior) {
    btnAnterior.addEventListener("click", (e) => {
      e.preventDefault();
      if (!btnAnterior.disabled) paginaAnterior();
    });
  }

  if (btnSiguiente) {
    btnSiguiente.addEventListener("click", (e) => {
      e.preventDefault();
      if (!btnSiguiente.disabled) paginaSiguiente();
    });
  }

  // Usuarios online/offline
  pingUsuario();
  userPingInterval = setInterval(pingUsuario, 30000);

  cargarUsuariosEstado();
  userStatusInterval = setInterval(cargarUsuariosEstado, 15000);

  // ✅ Inicial pedidos (página 1)
  resetToFirstPage({ withFetch: true });

  // ✅ LIVE refresca la página 1 cada 12s
  startLive(12000);

  // ✅ refresca render según ancho (desktop/cards) sin pedir al backend
  window.addEventListener("resize", () => {
    const cont = document.getElementById("tablaPedidos");
    if (cont && cont.dataset.lastOrders) {
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
function startLive(ms = 12000) {
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
   HELPERS
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

function stripHtml(html) {
  const d = document.createElement("div");
  d.innerHTML = String(html || "");
  return d.textContent || "";
}

/* =====================================================
   ENTREGA PILL
===================================================== */
function entregaStyle(estado) {
  const s = String(estado || "").toLowerCase().trim();

  if (!s || s === "-" || s === "null") {
    return { wrap: "bg-slate-50 border-slate-200 text-slate-800", dot: "bg-slate-400", icon: "📦", label: "Sin estado" };
  }
  if (s.includes("entregado") || s.includes("delivered")) {
    return { wrap: "bg-emerald-50 border-emerald-200 text-emerald-900", dot: "bg-emerald-500", icon: "✅", label: "Entregado" };
  }
  if (s.includes("enviado") || s.includes("shipped")) {
    return { wrap: "bg-blue-50 border-blue-200 text-blue-900", dot: "bg-blue-500", icon: "🚚", label: "Enviado" };
  }
  if (s.includes("prepar") || s.includes("pendiente") || s.includes("processing")) {
    return { wrap: "bg-amber-50 border-amber-200 text-amber-900", dot: "bg-amber-500", icon: "⏳", label: "Preparando" };
  }
  if (s.includes("cancel") || s.includes("devuelto") || s.includes("return")) {
    return { wrap: "bg-rose-50 border-rose-200 text-rose-900", dot: "bg-rose-500", icon: "⛔", label: "Incidencia" };
  }
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

/* =====================================================
   PÍLDORA PÁGINA
===================================================== */
function setPaginaUI({ totalPages = null } = {}) {
  const pill = document.getElementById("pillPagina");
  if (pill) pill.textContent = `Página ${currentPage}`;

  const pillTotal = document.getElementById("pillPaginaTotal");
  if (pillTotal) {
    if (totalPages) pillTotal.textContent = `Página ${currentPage} de ${totalPages}`;
    else pillTotal.textContent = `Página ${currentPage}`;
  }
}

/* =====================================================
   RESET a página 1
===================================================== */
function resetToFirstPage({ withFetch = false } = {}) {
  currentPage = 1;
  nextPageInfo = null;
  prevPageInfo = null;
  liveMode = true;

  setPaginaUI({ totalPages: null });
  actualizarControlesPaginacion();

  if (withFetch) cargarPedidos({ reset: true, page_info: "" });
}

/* =====================================================
   CARGAR PEDIDOS (50 en 50)
===================================================== */
function cargarPedidos({ page_info = "", reset = false } = {}) {
  if (isLoading) return;
  isLoading = true;
  showLoader();

  const base = apiUrl("/dashboard/pedidos");
  const fallback = apiUrl("/dashboard/filter");

  if (reset) {
    currentPage = 1;
    nextPageInfo = null;
    prevPageInfo = null;
    page_info = "";
    liveMode = true;
  }

  const buildUrl = (endpoint) => {
    const u = new URL(endpoint, window.location.origin);
    u.searchParams.set("page", String(currentPage));
    if (page_info) u.searchParams.set("page_info", page_info);
    return u.toString();
  };

  fetch(buildUrl(base), { headers: { Accept: "application/json" } })
    .then(async (res) => {
      if (res.status === 404) {
        const r2 = await fetch(buildUrl(fallback), { headers: { Accept: "application/json" } });
        return r2.json();
      }
      return res.json();
    })
    .then((data) => {
      if (!data || !data.success) {
        actualizarTabla([]);
        ordersCache = [];
        ordersById = new Map();
        nextPageInfo = null;
        prevPageInfo = null;
        actualizarControlesPaginacion();
        setPaginaUI({ totalPages: null });
        return;
      }

      nextPageInfo = data.next_page_info ?? null;
      prevPageInfo = data.prev_page_info ?? null;

      // ✅ cache local (para cambios instantáneos)
      ordersCache = Array.isArray(data.orders) ? data.orders : [];
      ordersById = new Map(ordersCache.map((o) => [String(o.id), o]));

      actualizarTabla(ordersCache);

      const total = document.getElementById("total-pedidos");
      if (total) total.textContent = String(data.total_orders ?? data.count ?? 0);

      setPaginaUI({ totalPages: data.total_pages ?? null });
      actualizarControlesPaginacion();
    })
    .catch((err) => {
      console.error("Error cargando pedidos:", err);
      actualizarTabla([]);
      ordersCache = [];
      ordersById = new Map();
      nextPageInfo = null;
      prevPageInfo = null;
      actualizarControlesPaginacion();
      setPaginaUI({ totalPages: null });
    })
    .finally(() => {
      isLoading = false;
      hideLoader();
    });
}

/* =====================================================
   CONTROLES PAGINACIÓN
===================================================== */
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
  cargarPedidos({ page_info: nextPageInfo });
}

function paginaAnterior() {
  if (!prevPageInfo || currentPage <= 1) return;
  currentPage -= 1;
  cargarPedidos({ page_info: prevPageInfo });
  resumeLiveIfOnFirstPage();
}

/* =====================================================
   RENDER ÚLTIMO CAMBIO (compacto)
===================================================== */
function renderLastChangeCompact(p) {
  const info = p?.last_status_change;
  if (!info || !info.changed_at) return "—";

  const user = info.user_name ? escapeHtml(info.user_name) : "—";
  const ago = timeAgo(info.changed_at);

  return `
    <div class="leading-tight">
      <div class="text-[12px] font-bold text-slate-900 truncate">${user}</div>
      <div class="text-[11px] text-slate-500">${escapeHtml(ago)}</div>
    </div>
  `;
}

/* =====================================================
   TIME AGO
===================================================== */
function timeAgo(dtStr) {
  if (!dtStr) return "";
  const d = new Date(String(dtStr).replace(" ", "T"));
  if (isNaN(d)) return "";

  const diff = Date.now() - d.getTime();
  const sec = Math.floor(diff / 1000);
  const min = Math.floor(sec / 60);
  const hr = Math.floor(min / 60);
  const day = Math.floor(hr / 24);

  if (day > 0) return `${day}d ${hr % 24}h`;
  if (hr > 0) return `${hr}h ${min % 60}m`;
  if (min > 0) return `${min}m`;
  return `${sec}s`;
}

/* =====================================================
   ETIQUETAS
===================================================== */
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
      ? `<span class="px-2.5 py-1.5 rounded-full text-[10px] font-extrabold uppercase tracking-wide border bg-white border-slate-200 text-slate-700">
        +${rest}
      </span>`
      : "";

  const onClick =
    typeof window.abrirModalEtiquetas === "function"
      ? `abrirModalEtiquetas(${orderId}, '${escapeJsString(raw)}')`
      : `alert('Falta implementar abrirModalEtiquetas()');`;

  if (!list.length) {
    return `
      <button onclick="${onClick}"
        class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl
               bg-white border border-slate-200 text-slate-900 text-[11px] font-extrabold uppercase tracking-wide
               hover:shadow-md transition whitespace-nowrap">
        Etiquetas <span class="text-blue-700">＋</span>
      </button>`;
  }

  return `
    <div class="flex flex-wrap items-center gap-2">
      ${pills}${more}
      <button onclick="${onClick}"
        class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl
               bg-slate-900 text-white text-[11px] font-extrabold uppercase tracking-wide
               hover:bg-slate-800 transition shadow-sm whitespace-nowrap">
        Etiquetas <span class="text-white/80">✎</span>
      </button>
    </div>`;
}

function colorEtiqueta(tag) {
  tag = String(tag).toLowerCase().trim();
  if (tag.startsWith("d.")) return "bg-emerald-50 border-emerald-200 text-emerald-900";
  if (tag.startsWith("p.")) return "bg-amber-50 border-amber-200 text-amber-900";
  return "bg-slate-50 border-slate-200 text-slate-800";
}

/* =====================================================
   TABLA / GRID + CARDS
===================================================== */
function actualizarTabla(pedidos) {
  const cont = document.getElementById("tablaPedidos");
  const cards = document.getElementById("cardsPedidos");

  if (cont) cont.dataset.lastOrders = JSON.stringify(pedidos || []);

  const useCards = window.innerWidth <= 1180;

  // ---------- DESKTOP GRID ----------
  if (cont) {
    cont.innerHTML = "";

    if (!useCards) {
      if (!pedidos.length) {
        cont.innerHTML = `<div class="p-8 text-center text-slate-500">No se encontraron pedidos</div>`;
      } else {
        cont.innerHTML = pedidos
          .map((p) => {
            const id = p.id ?? "";
            const etiquetas = p.etiquetas ?? "";

            return `
            <div class="orders-grid px-4 py-3 text-[13px] border-b hover:bg-slate-50 transition">
              <div class="font-extrabold text-slate-900 whitespace-nowrap">${escapeHtml(p.numero ?? "-")}</div>
              <div class="text-slate-600 whitespace-nowrap">${escapeHtml(p.fecha ?? "-")}</div>
              <div class="font-semibold text-slate-800 truncate">${escapeHtml(p.cliente ?? "-")}</div>
              <div class="font-extrabold text-slate-900 whitespace-nowrap">${escapeHtml(p.total ?? "-")}</div>

              <div class="whitespace-nowrap">
                <button onclick="abrirModal('${String(id)}')"
                  class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl bg-white border border-slate-200 shadow-sm">
                  <span class="h-2 w-2 rounded-full bg-blue-600"></span>
                  <span class="text-[11px] font-extrabold uppercase tracking-wide text-slate-900">
                    ${renderEstado(p.estado ?? "-")}
                  </span>
                </button>
              </div>

              <div class="min-w-0">${renderLastChangeCompact(p)}</div>
              <div class="min-w-0">${renderEtiquetasCompact(etiquetas, id)}</div>
              <div class="text-center font-extrabold">${escapeHtml(p.articulos ?? "-")}</div>
              <div class="whitespace-nowrap">${renderEntregaPill(p.estado_envio ?? "-")}</div>
              <div class="text-xs text-slate-700 truncate">${escapeHtml(p.forma_envio ?? "-")}</div>

              <div class="text-right whitespace-nowrap">
                <button onclick="verDetalles(${id})"
                  class="px-3 py-2 rounded-2xl bg-blue-600 text-white text-[11px] font-extrabold uppercase tracking-wide">
                  Ver →
                </button>
              </div>
            </div>`;
          })
          .join("");
      }
    }
  }

  // ---------- CARDS ----------
  if (cards) {
    cards.innerHTML = "";

    if (!useCards) return;

    if (!pedidos.length) {
      cards.innerHTML = `<div class="p-8 text-center text-slate-500">No se encontraron pedidos</div>`;
      return;
    }

    cards.innerHTML = pedidos
      .map((p) => {
        const id = p.id ?? "";
        const numero = escapeHtml(p.numero ?? "-");
        const fecha = escapeHtml(p.fecha ?? "-");
        const cliente = escapeHtml(p.cliente ?? "-");
        const total = escapeHtml(p.total ?? "-");
        const etiquetas = p.etiquetas ?? "";

        const last = p?.last_status_change?.changed_at
          ? `${escapeHtml(p.last_status_change.user_name ?? "—")} · ${escapeHtml(timeAgo(p.last_status_change.changed_at))}`
          : "—";

        return `
          <div class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden mb-3">
            <div class="p-4">
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <div class="text-sm font-extrabold text-slate-900">${numero}</div>
                  <div class="text-xs text-slate-500 mt-0.5">${fecha}</div>
                  <div class="text-sm font-semibold text-slate-800 mt-1 truncate">${cliente}</div>
                </div>

                <div class="text-right whitespace-nowrap">
                  <div class="text-sm font-extrabold text-slate-900">${total}</div>
                </div>
              </div>

              <div class="mt-3 flex items-center justify-between gap-3">
                <button onclick="abrirModal('${String(id)}')"
                  class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl bg-white border border-slate-200 shadow-sm">
                  <span class="h-2 w-2 rounded-full bg-blue-600"></span>
                  <span class="text-[11px] font-extrabold uppercase tracking-wide text-slate-900">
                    ${renderEstado(p.estado ?? "-")}
                  </span>
                </button>

                <button onclick="verDetalles(${id})"
                  class="px-3 py-2 rounded-2xl bg-blue-600 text-white text-[11px] font-extrabold uppercase tracking-wide">
                  Ver →
                </button>
              </div>

              <div class="mt-3">${renderEntregaPill(p.estado_envio ?? "-")}</div>
              <div class="mt-3">${renderEtiquetasCompact(etiquetas, id, true)}</div>

              <div class="mt-3 text-xs text-slate-600 space-y-1">
                <div><b>Artículos:</b> ${escapeHtml(p.articulos ?? "-")}</div>
                <div><b>Forma:</b> ${escapeHtml(p.forma_envio ?? "-")}</div>
                <div><b>Último cambio:</b> ${last}</div>
              </div>
            </div>
          </div>`;
      })
      .join("");
  }
}

/* =====================================================
   MODAL ESTADO
===================================================== */
function abrirModal(orderId) {
  const idInput = document.getElementById("modalOrderId");
  if (idInput) idInput.value = String(orderId ?? "");

  const modal = document.getElementById("modalEstado");
  if (modal) modal.classList.remove("hidden");
}
function cerrarModal() {
  const modal = document.getElementById("modalEstado");
  if (modal) modal.classList.add("hidden");
}

/* =====================================================
   ✅ GUARDAR ESTADO (LOCAL INSTANT + BACKEND + REVERT)
   - FIX: ahora SÍ envía CSRF si está en el HTML
   - FIX: usa /index.php si aplica
===================================================== */
async function guardarEstado(nuevoEstado) {
  const id = String(document.getElementById("modalOrderId")?.value || "");
  if (!id) return;

  const order = ordersById.get(id);
  const prevEstado = order?.estado ?? null;
  const prevLast = order?.last_status_change ?? null;

  // 1) Cambia en UI al instante (optimistic)
  if (order) {
    order.estado = nuevoEstado;
    order.last_status_change = {
      user_name: "Tú",
      changed_at: new Date().toISOString().slice(0, 19).replace("T", " "),
    };
    actualizarTabla(ordersCache);
  }

  cerrarModal();

  // 2) Guarda en backend
  try {
    // intenta con /api...
    let r = await fetch(apiUrl("/api/estado/guardar"), {
      method: "POST",
      headers: jsonHeaders(),
      body: JSON.stringify({ id, estado: nuevoEstado }),
    });

    // fallback por si tu servidor NO usa index.php pero la app sí (o al revés)
    if (r.status === 404) {
      const alt = hasIndexPhp() ? "/api/estado/guardar" : "/index.php/api/estado/guardar";
      r = await fetch(alt, { method: "POST", headers: jsonHeaders(), body: JSON.stringify({ id, estado: nuevoEstado }) });
    }

    const d = await r.json().catch(() => null);

    if (!r.ok || !d?.success) {
      const msg = d?.message || `HTTP ${r.status}`;
      throw new Error(msg);
    }

    // 3) Si backend devuelve el estado real, sincroniza
    if (d?.order && order) {
      order.estado = d.order.estado ?? order.estado;
      order.last_status_change = d.order.last_status_change ?? order.last_status_change;
      actualizarTabla(ordersCache);
    }
  } catch (e) {
    console.error("guardarEstado error:", e);

    // 4) Revertir si falla
    if (order) {
      order.estado = prevEstado;
      order.last_status_change = prevLast;
      actualizarTabla(ordersCache);
    }

    alert("No se pudo guardar el estado. Se revirtió el cambio.");
  }
}

/* =====================================================
   DETALLES (tu función real está en otro lado)
===================================================== */
function abrirModalDetalles() {
  document.getElementById("modalDetalles")?.classList.remove("hidden");
}
function cerrarModalDetalles() {
  document.getElementById("modalDetalles")?.classList.add("hidden");
}

/* =====================================================
   USERS STATUS
===================================================== */
async function pingUsuario() {
  try {
    await fetch(apiUrl("/dashboard/ping"), { headers: { Accept: "application/json" } });
  } catch (e) {}
}

async function cargarUsuariosEstado() {
  try {
    const r = await fetch(apiUrl("/dashboard/usuarios-estado"), { headers: { Accept: "application/json" } });
    const d = await r.json().catch(() => null);
    if (!d) return;

    const ok = d.ok === true || d.success === true;
    if (ok) {
      if (typeof window.renderUsersStatus === "function") window.renderUsersStatus(d);
      else if (typeof window.renderUserStatus === "function") window.renderUserStatus(d);
    }
  } catch (e) {
    console.error("Error usuarios estado:", e);
  }
}

// =====================================================
// UI: USUARIOS ONLINE/OFFLINE + TIEMPO CONECTADO/DESCONECTADO
// - usa seconds_since_seen si existe
// - fallback: calcula con last_seen
// =====================================================
window.renderUsersStatus = function (payload) {
  const onlineEl = document.getElementById("onlineUsers");
  const offlineEl = document.getElementById("offlineUsers");
  const onlineCountEl = document.getElementById("onlineCount");
  const offlineCountEl = document.getElementById("offlineCount");

  if (!onlineEl || !offlineEl) return;

  const users = payload?.users || [];

  // normaliza seconds_since_seen
  const normalized = users.map((u) => {
    const secs =
      u.seconds_since_seen != null
        ? Number(u.seconds_since_seen)
        : u.last_seen
        ? Math.max(
            0,
            Math.floor((Date.now() - new Date(String(u.last_seen).replace(" ", "T")).getTime()) / 1000)
          )
        : null;

    return { ...u, seconds_since_seen: isNaN(secs) ? null : secs };
  });

  const online = normalized.filter((u) => u.online);
  const offline = normalized.filter((u) => !u.online);

  if (onlineCountEl) onlineCountEl.textContent = String(payload.online_count ?? online.length);
  if (offlineCountEl) offlineCountEl.textContent = String(payload.offline_count ?? offline.length);

  onlineEl.innerHTML = online.length
    ? online.map(renderUserRow("online")).join("")
    : `<li class="text-sm text-emerald-800/80">No hay usuarios conectados</li>`;

  offlineEl.innerHTML = offline.length
    ? offline.map(renderUserRow("offline")).join("")
    : `<li class="text-sm text-rose-800/80">No hay usuarios desconectados</li>`;
};

function renderUserRow(mode) {
  return (u) => {
    const nombre = escapeHtml(u.nombre ?? "—");
    const role = escapeHtml(u.role ?? "");
    const since = formatDuration(u.seconds_since_seen);

    const badge =
      mode === "online"
        ? `<span class="px-3 py-1 rounded-full text-[11px] font-extrabold bg-emerald-100 text-emerald-900 border border-emerald-200 whitespace-nowrap">
            Conectado · ${since}
          </span>`
        : `<span class="px-3 py-1 rounded-full text-[11px] font-extrabold bg-rose-100 text-rose-900 border border-rose-200 whitespace-nowrap">
            Desconectado · ${since}
          </span>`;

    return `
      <li class="flex items-center justify-between gap-3 p-3 rounded-2xl border ${
        mode === "online" ? "border-emerald-200 bg-white/70" : "border-rose-200 bg-white/70"
      }">
        <div class="min-w-0">
          <div class="font-extrabold text-slate-900 truncate">${nombre}</div>
          <div class="text-xs text-slate-500 truncate">${role ? role : "—"}</div>
        </div>
        ${badge}
      </li>
    `;
  };
}

// "seconds_since_seen" → "5s", "3m", "2h 10m", "1d 3h"
function formatDuration(seconds) {
  if (seconds === null || seconds === undefined) return "—";

  const s = Math.max(0, Number(seconds));
  const d = Math.floor(s / 86400);
  const h = Math.floor((s % 86400) / 3600);
  const m = Math.floor((s % 3600) / 60);
  const sec = Math.floor(s % 60);

  if (d > 0) return `${d}d ${h}h`;
  if (h > 0) return `${h}h ${m}m`;
  if (m > 0) return `${m}m`;
  return `${sec}s`;
}
