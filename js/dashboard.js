// =====================================================
// DASHBOARD.JS (COMPLETO) - REAL TIME + PAGINACIÓN ESTABLE
// + PROTECCIÓN ANTI-OVERWRITE (12 usuarios)
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

// ✅ evita que un fetch viejo pise uno nuevo
let lastFetchToken = 0;

// ✅ protege cambios recientes (evita que LIVE sobrescriba el estado recién guardado)
const dirtyOrders = new Map(); // id -> { until:number, estado:string, last_status_change:{} }
const DIRTY_TTL_MS = 15000; // 15s

/* =====================================================
   CONFIG / HELPERS DE RUTAS
===================================================== */
function hasIndexPhp() {
  return window.location.pathname.includes("/index.php/");
}

function normalizeBase(base) {
  base = String(base || "").trim();
  base = base.replace(/\/+$/, "");
  return base;
}

function apiUrl(path) {
  if (!path.startsWith("/")) path = "/" + path;
  const base = normalizeBase(window.API_BASE || "");
  return base ? base + path : path;
}

function jsonHeaders() {
  const headers = { Accept: "application/json", "Content-Type": "application/json" };

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

  // ✅ LIVE refresca la página 1 (recomendado 20s con 12 usuarios)
  startLive(20000);

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
function normalizeEstado(estado) {
  const s = String(estado || "").trim().toLowerCase();

  if (s.includes("por preparar")) return "Por preparar";
  if (s.includes("a medias") || s.includes("medias")) return "A medias";
  if (s.includes("producción") || s.includes("produccion")) return "Produccion";
  if (s.includes("fabricando")) return "Fabricando";
  if (s.includes("enviado")) return "Enviado";

  return estado || "Por preparar";
}

/* =====================================================
   ESTADO PILL (igual a colores del modal)
===================================================== */
function estadoStyle(estado) {
  const s = String(estado || "").toLowerCase().trim();

  if (s.includes("por preparar")) {
    return { wrap: "bg-slate-100 border-slate-200 text-slate-800", dot: "bg-slate-500", icon: "⏳", label: "Por preparar" };
  }
  if (s.includes("a medias") || s.includes("medias")) {
    return { wrap: "bg-amber-100 border-amber-200 text-amber-900", dot: "bg-amber-500", icon: "🟡", label: "A medias" };
  }
  if (s.includes("producción") || s.includes("produccion")) {
    return { wrap: "bg-purple-100 border-purple-200 text-purple-900", dot: "bg-purple-500", icon: "🏭", label: "Producción" };
  }
  if (s.includes("fabricando")) {
    return { wrap: "bg-blue-100 border-blue-200 text-blue-900", dot: "bg-blue-500", icon: "🛠️", label: "Fabricando" };
  }
  if (s.includes("enviado")) {
    return { wrap: "bg-emerald-100 border-emerald-200 text-emerald-900", dot: "bg-emerald-500", icon: "🚚", label: "Enviado" };
  }

  return { wrap: "bg-white border-slate-200 text-slate-900", dot: "bg-slate-400", icon: "📍", label: estado || "—" };
}

function renderEstadoPill(estado) {
  if (esBadgeHtml(estado)) return String(estado);

  const st = estadoStyle(estado);
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
  if (pillTotal) pillTotal.textContent = totalPages ? `Página ${currentPage} de ${totalPages}` : `Página ${currentPage}`;
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
   CARGAR PEDIDOS (con protección anti-overwrite)
===================================================== */
function cargarPedidos({ page_info = "", reset = false } = {}) {
  if (isLoading) return;
  isLoading = true;
  showLoader();

  const fetchToken = ++lastFetchToken;

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
      // ✅ si llegó una respuesta vieja, la ignoramos
      if (fetchToken !== lastFetchToken) return;

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

      let incoming = Array.isArray(data.orders) ? data.orders : [];

      // ✅ aplicar "dirty protection"
      const now = Date.now();
      incoming = incoming.map((o) => {
        const id = String(o.id ?? "");
        if (!id) return o;

        const dirty = dirtyOrders.get(id);
        if (dirty && dirty.until > now) {
          return {
            ...o,
            estado: dirty.estado,
            last_status_change: dirty.last_status_change,
          };
        } else if (dirty) {
          dirtyOrders.delete(id);
        }
        return o;
      });

      ordersCache = incoming;
      ordersById = new Map(ordersCache.map((o) => [String(o.id), o]));

      try {
        actualizarTabla(ordersCache);
      } catch (e) {
        console.error("Error renderizando tabla:", e);
        actualizarTabla([]);
      }


      const total = document.getElementById("total-pedidos");
      if (total) total.textContent = String(data.total_orders ?? data.count ?? 0);

      setPaginaUI({ totalPages: data.total_pages ?? null });
      actualizarControlesPaginacion();
    })
    .catch((err) => {
      if (fetchToken !== lastFetchToken) return;

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
      if (fetchToken !== lastFetchToken) return;
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
    <div class="leading-tight min-w-0 pointer-events-none select-none">
      <div class="text-[12px] font-extrabold text-slate-900 truncate">${user}</div>
      <div class="text-[11px] text-slate-600 whitespace-nowrap">${escapeHtml(exact)}</div>
    </div>
  `;
}

/* =====================================================
   ETIQUETAS
===================================================== */
function renderEtiquetasCompact(etiquetas, orderId, mobile = false) {
  const raw = String(etiquetas || "").trim();
  const list = raw ? raw.split(",").map((t) => t.trim()).filter(Boolean) : [];

  const max = mobile ? 6 : 6;
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

  const onClick = `abrirModalEtiquetas(${Number(orderId)}, '${escapeJsString(raw)}')`;

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
// PÍLDORA ESTADO ENVÍO
===================================================== */

function renderEntregaPill(estadoEnvio) {
  const s = String(estadoEnvio ?? "").toLowerCase().trim();

  // Shopify suele devolver: null, "fulfilled", "partial", "unfulfilled"
  if (!s || s === "-" || s === "null") {
    return `
      <span class="inline-flex items-center px-3 py-1.5 rounded-full text-[11px] font-extrabold
                   bg-slate-100 text-slate-800 border border-slate-200 whitespace-nowrap">
        ⏳ Sin preparar
      </span>
    `;
  }

  if (s.includes("fulfilled") || s.includes("entregado")) {
    return `
      <span class="inline-flex items-center px-3 py-1.5 rounded-full text-[11px] font-extrabold
                   bg-emerald-100 text-emerald-900 border border-emerald-200 whitespace-nowrap">
        ✅ Preparado / enviado
      </span>
    `;
  }

  if (s.includes("partial")) {
    return `
      <span class="inline-flex items-center px-3 py-1.5 rounded-full text-[11px] font-extrabold
                   bg-amber-100 text-amber-900 border border-amber-200 whitespace-nowrap">
        🟡 Parcial
      </span>
    `;
  }

  if (s.includes("unfulfilled") || s.includes("pend")) {
    return `
      <span class="inline-flex items-center px-3 py-1.5 rounded-full text-[11px] font-extrabold
                   bg-slate-100 text-slate-800 border border-slate-200 whitespace-nowrap">
        ⏳ Pendiente
      </span>
    `;
  }

  // fallback
  return `
    <span class="inline-flex items-center px-3 py-1.5 rounded-full text-[11px] font-extrabold
                 bg-white text-slate-900 border border-slate-200 whitespace-nowrap">
      📦 ${escapeHtml(estadoEnvio)}
    </span>
  `;
}

// por si lo llamas desde HTML inline
window.renderEntregaPill = renderEntregaPill;

/* =====================================================
   TABLA / GRID + CARDS
===================================================== */
function actualizarTabla(pedidos) {
  const cont = document.getElementById("tablaPedidos");
  const cards = document.getElementById("cardsPedidos");

  if (cont) cont.dataset.lastOrders = JSON.stringify(pedidos || []);
  const useCards = window.innerWidth <= 1180;

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

              <div class="whitespace-nowrap relative z-10">
                <button onclick="abrirModal('${String(id)}')"
                  class="inline-flex items-center gap-2 rounded-2xl bg-transparent border-0 p-0">
                  ${renderEstadoPill(p.estado ?? "-")}
                </button>
              </div>


              <div class="min-w-0">${renderLastChangeCompact(p)}</div>
              <div class="min-w-0">${renderEtiquetasCompact(etiquetas, id)}</div>
              <div class="text-center font-extrabold">${escapeHtml(p.articulos ?? "-")}</div>
              <div class="whitespace-nowrap">${renderEntregaPill(p.estado_envio ?? "-")}</div>
              <div class="text-xs text-slate-700 truncate">${escapeHtml(p.forma_envio ?? "-")}</div>

              <div class="text-right whitespace-nowrap">
                <button onclick="verDetalles(${Number(id)})"
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
        const etiquetas = p.etiquetas ?? "";
        const last = p?.last_status_change?.changed_at
          ? `${escapeHtml(p.last_status_change.user_name ?? "—")} · ${escapeHtml(formatDateTime(p.last_status_change.changed_at))}`
          : "—";

        return `
          <div class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden mb-3">
            <div class="p-4">
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <div class="text-sm font-extrabold text-slate-900">${escapeHtml(p.numero ?? "-")}</div>
                  <div class="text-xs text-slate-500 mt-0.5">${escapeHtml(p.fecha ?? "-")}</div>
                  <div class="text-sm font-semibold text-slate-800 mt-1 truncate">${escapeHtml(p.cliente ?? "-")}</div>
                </div>
                <div class="text-right whitespace-nowrap">
                  <div class="text-sm font-extrabold text-slate-900">${escapeHtml(p.total ?? "-")}</div>
                </div>
              </div>

              <div class="mt-3 flex items-center justify-between gap-3">
                <button onclick="abrirModal('${String(id)}')"
                  class="inline-flex items-center gap-2 rounded-2xl bg-transparent border-0 p-0 relative z-10">
                  ${renderEstadoPill(p.estado ?? "-")}
                </button>


                <button onclick="verDetalles(${Number(id)})"
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
   + pause live + dirty TTL
   + FIX endpoints (incluye /index.php/index.php)
===================================================== */
async function guardarEstado(nuevoEstado) {
  // ✅ intenta varios inputs por si cambió el modal
  const idInput =
    document.getElementById("modalOrderId") ||
    document.getElementById("modalEstadoOrderId") ||
    document.getElementById("estadoOrderId") ||
    document.querySelector('input[name="order_id"]');

  const id = String(idInput?.value || "");
  if (!id) {
    alert("No se encontró el ID del pedido en el modal (input). Revisa layouts/modales_estados.");
    return;
  }

  pauseLive();

  const order = ordersById.get(id);
  const prevEstado = order?.estado ?? null;
  const prevLast = order?.last_status_change ?? null;

  // 1) UI instantánea + dirty
  const userName = window.CURRENT_USER || "Sistema";
  const now = new Date();
  const nowStr = now.toISOString().slice(0, 19).replace("T", " ");
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

  cerrarModal();

  // 2) Guardar backend
  try {
    // ✅ endpoints ampliados (incluye doble index.php)
    const endpoints = [
      apiUrl("/api/estado/guardar"),
      "/api/estado/guardar",
      "/index.php/api/estado/guardar",
      "/index.php/index.php/api/estado/guardar",
      apiUrl("/index.php/api/estado/guardar"),
      apiUrl("/index.php/index.php/api/estado/guardar"),
    ];

    let lastErr = null;

    for (const url of endpoints) {
      try {
        const r = await fetch(url, {
          method: "POST",
          headers: jsonHeaders(),
          // ✅ manda id numérico (tu backend suele esperar num)
          body: JSON.stringify({ id: Number(id), estado: nuevoEstado }),
        });

        if (r.status === 404) continue;

        const d = await r.json().catch(() => null);

        if (!r.ok || !d?.success) {
          throw new Error(d?.message || `HTTP ${r.status}`);
        }

        // 3) Sync desde backend
        if (d?.order && order) {
          order.estado = d.order.estado ?? order.estado;
          order.last_status_change = d.order.last_status_change ?? order.last_status_change;
          actualizarTabla(ordersCache);

          dirtyOrders.set(id, {
            until: Date.now() + DIRTY_TTL_MS,
            estado: order.estado,
            last_status_change: order.last_status_change,
          });
        }

        // refresca si estás en pág 1
        if (currentPage === 1) cargarPedidos({ reset: false, page_info: "" });

        resumeLiveIfOnFirstPage();
        return;
      } catch (e) {
        lastErr = e;
      }
    }

    throw lastErr || new Error("No se encontró un endpoint válido (404).");
  } catch (e) {
    console.error("guardarEstado error:", e);

    // Revert
    dirtyOrders.delete(id);

    if (order) {
      order.estado = prevEstado;
      order.last_status_change = prevLast;
      actualizarTabla(ordersCache);
    }

    alert("No se pudo guardar el estado. Se revirtió el cambio.");
    resumeLiveIfOnFirstPage();
  }
}

// ✅ asegurar funciones globales para onclick=""
window.guardarEstado = guardarEstado;

/* =====================================================
   DETALLES
===================================================== */
window.verDetalles = async function (orderId) {
  const id = String(orderId || "");
  if (!id) return;

  const url = apiUrl(`/dashboard/detalles/${encodeURIComponent(id)}`);

  const modal = document.getElementById("modalDetalles");
  const pre = document.getElementById("modalDetallesJson");

  try {
    showLoader();
    const r = await fetch(url, { headers: { Accept: "application/json" } });
    const d = await r.json().catch(() => null);

    if (!r.ok || !d) throw new Error(`HTTP ${r.status}`);

    if (modal && pre) {
      pre.textContent = JSON.stringify(d, null, 2);
      modal.classList.remove("hidden");
      return;
    }

    window.open(url, "_blank");
  } catch (e) {
    console.error("verDetalles error:", e);
    alert("No se pudieron cargar los detalles del pedido.");
  } finally {
    hideLoader();
  }
};

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

window.renderUsersStatus = function (payload) {
  const onlineEl = document.getElementById("onlineUsers");
  const offlineEl = document.getElementById("offlineUsers");
  const onlineCountEl = document.getElementById("onlineCount");
  const offlineCountEl = document.getElementById("offlineCount");

  if (!onlineEl || !offlineEl) return;

  const users = payload?.users || [];

  const normalized = users.map((u) => {
    const secs =
      u.seconds_since_seen != null
        ? Number(u.seconds_since_seen)
        : u.last_seen
        ? Math.max(0, Math.floor((Date.now() - new Date(String(u.last_seen).replace(" ", "T")).getTime()) / 1000))
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

// =====================================================
// ETIQUETAS: UI por ROL (1 o 2) usando window.etiquetasPredeterminadas
// =====================================================

function getEtiquetasDisponibles() {
  const arr = Array.isArray(window.etiquetasPredeterminadas) ? window.etiquetasPredeterminadas : [];
  // limpia duplicados y vacíos
  return Array.from(new Set(arr.map((x) => String(x || "").trim()).filter(Boolean)));
}

function maxEtiquetasPermitidas() {
  const disponibles = getEtiquetasDisponibles();
  // confirmación => 1 (D.Nombre)
  // producción => 2 (D.Nombre, P.Nombre)
  // admin => depende de lo que venga, pero normalmente >= 2
  if (disponibles.length <= 1) return 1;
  return 6;
}

function parseTags(tagsStr) {
  return String(tagsStr || "")
    .split(",")
    .map((t) => t.trim())
    .filter(Boolean);
}

function serializeTags(tagsArr) {
  return Array.from(new Set((tagsArr || []).map((t) => String(t).trim()).filter(Boolean))).join(", ");
}

/**
 * Renderiza opciones de etiquetas dentro del modal si existe un contenedor.
 * Espera que tengas un div con id="modalEtiquetasOptions" (te digo abajo cómo).
 */
function renderOpcionesEtiquetas({ selected = [] } = {}) {
  const cont = document.getElementById("modalEtiquetasOptions");
  if (!cont) return;

  const disponibles = getEtiquetasDisponibles();
  const max = maxEtiquetasPermitidas();

  const selectedSet = new Set(selected);

  cont.innerHTML = `
    <div class="text-xs text-slate-500 mb-2">
      Puedes seleccionar <b>${max}</b> etiqueta${max > 1 ? "s" : ""}.
    </div>
    <div class="flex flex-wrap gap-2">
      ${disponibles
        .map((tag) => {
          const on = selectedSet.has(tag);
          const cls = on
            ? "bg-slate-900 text-white border-slate-900"
            : "bg-white text-slate-900 border-slate-200 hover:bg-slate-50";
          return `
            <button type="button"
              data-tag="${escapeHtml(tag)}"
              class="px-3 py-2 rounded-2xl border text-[11px] font-extrabold uppercase tracking-wide ${cls}">
              ${escapeHtml(tag)}
            </button>
          `;
        })
        .join("")}
    </div>
  `;

  // clicks
  cont.querySelectorAll("button[data-tag]").forEach((btn) => {
    btn.addEventListener("click", () => {
      const tag = btn.getAttribute("data-tag") || "";
      const inputTags =
        document.getElementById("modalEtiquetasTags") ||
        document.getElementById("inputEtiquetas");

      const current = parseTags(inputTags?.value || "");
      const set = new Set(current);

      if (set.has(tag)) {
        set.delete(tag);
      } else {
        // limitar cantidad
        if (set.size >= max) {
          // si max=1, reemplaza; si max=2, no deja añadir más
          if (max === 6) {
            set.clear();
            set.add(tag);
          } else {
            alert(`Solo puedes seleccionar ${max} etiquetas.`);
            return;
          }
        } else {
          set.add(tag);
        }
      }

      const next = Array.from(set);
      if (inputTags) inputTags.value = serializeTags(next);

      // rerender para refrescar estilos
      renderOpcionesEtiquetas({ selected: next });
    });
  });
}
/* =====================================================
   ETIQUETAS (ÚNICO) - COMPATIBLE SIMPLE + COMPLETO
   - Soporta modal "simple" (inputs) o modal "completo" (chips)
   - Evita duplicados y sobrescrituras
===================================================== */

// Estado del modal completo (chips)
let _etqOrderId = null;
let _etqOrderNumero = "";
let _etqSelected = new Set();

// Etiquetas dinámicas desde BD (modal completo)
let ETQ_PRODUCCION = [];
let ETQ_DISENO = [];

// Etiquetas generales fijas (modal completo)
const ETQ_GENERALES = [
  "Cancelar pedido",
  "Reembolso 50%",
  "Reembolso 30%",
  "Reembolso completo",
  "Repetir",
  "No contesta 24h",
];

function isConfirmacionRole() {
  const r = String(window.currentUserRole || "").toLowerCase().trim();
  return r === "confirmacion" || r === "confirmación";
}

/** =========================
 *  FUENTE DE ETIQUETAS
 *  1) Si existe endpoint /dashboard/etiquetas-disponibles => usarlo (completo)
 *  2) Si no, usar window.etiquetasPredeterminadas (simple)
 ========================= */
async function cargarEtiquetasDisponiblesBD() {
  const endpoints = [
    apiUrl("/dashboard/etiquetas-disponibles"),
    "/index.php/dashboard/etiquetas-disponibles",
    "/dashboard/etiquetas-disponibles",
  ];

  for (const url of endpoints) {
    try {
      const r = await fetch(url, { headers: { Accept: "application/json" } });
      if (r.status === 404) continue;

      const d = await r.json().catch(() => null);
      if (!d) continue;

      const ok = d.ok === true || d.success === true;
      if (!ok) continue;

      ETQ_DISENO = Array.isArray(d.diseno) ? d.diseno : [];
      ETQ_PRODUCCION = Array.isArray(d.produccion) ? d.produccion : [];
      return true;
    } catch (e) {
      // intenta siguiente endpoint
    }
  }
  return false;
}

// Para modal simple (por rol) basado en window.etiquetasPredeterminadas
function getEtiquetasDisponiblesSimple() {
  const arr = Array.isArray(window.etiquetasPredeterminadas) ? window.etiquetasPredeterminadas : [];
  return Array.from(new Set(arr.map((x) => String(x || "").trim()).filter(Boolean)));
}

function maxEtiquetasPermitidasSimple() {
  const disponibles = getEtiquetasDisponiblesSimple();
  if (disponibles.length <= 1) return 1;
  return 6;
}

function parseTags(tagsStr) {
  return String(tagsStr || "")
    .split(",")
    .map((t) => t.trim())
    .filter(Boolean);
}

function serializeTags(tagsArr) {
  return Array.from(new Set((tagsArr || []).map((t) => String(t).trim()).filter(Boolean))).join(", ");
}

/** =========================
 *  MODAL SIMPLE (inputs)
 ========================= */
function renderOpcionesEtiquetasSimple({ selected = [] } = {}) {
  const cont = document.getElementById("modalEtiquetasOptions");
  if (!cont) return;

  const disponibles = getEtiquetasDisponiblesSimple();
  const max = maxEtiquetasPermitidasSimple();
  const selectedSet = new Set(selected);

  cont.innerHTML = `
    <div class="text-xs text-slate-500 mb-2">
      Puedes seleccionar <b>${max}</b> etiqueta${max > 1 ? "s" : ""}.
    </div>
    <div class="flex flex-wrap gap-2">
      ${disponibles
        .map((tag) => {
          const on = selectedSet.has(tag);
          const cls = on
            ? "bg-slate-900 text-white border-slate-900"
            : "bg-white text-slate-900 border-slate-200 hover:bg-slate-50";
          return `
            <button type="button"
              data-tag="${escapeHtml(tag)}"
              class="px-3 py-2 rounded-2xl border text-[11px] font-extrabold uppercase tracking-wide ${cls}">
              ${escapeHtml(tag)}
            </button>
          `;
        })
        .join("")}
    </div>
  `;

  cont.querySelectorAll("button[data-tag]").forEach((btn) => {
    btn.addEventListener("click", () => {
      const tag = btn.getAttribute("data-tag") || "";
      const inputTags = document.getElementById("modalEtiquetasTags") || document.getElementById("inputEtiquetas");

      const current = parseTags(inputTags?.value || "");
      const set = new Set(current);

      if (set.has(tag)) {
        set.delete(tag);
      } else {
        if (set.size >= max) {
          if (max === 6) {
            set.clear();
            set.add(tag);
          } else {
            alert(`Solo puedes seleccionar ${max} etiquetas.`);
            return;
          }
        } else {
          set.add(tag);
        }
      }

      const next = Array.from(set);
      if (inputTags) inputTags.value = serializeTags(next);

      renderOpcionesEtiquetasSimple({ selected: next });
    });
  });
}

/** =========================
 *  MODAL COMPLETO (chips)
 ========================= */
function chip(tag, selected) {
  const active = selected
    ? "bg-slate-900 text-white border-slate-900"
    : "bg-white text-slate-900 border-slate-200 hover:border-slate-300";

  return `
    <button type="button"
      class="px-3 py-2 rounded-2xl border text-xs font-extrabold uppercase tracking-wide transition ${active}"
      onclick="toggleEtiqueta('${escapeJsString(tag)}')">
      ${escapeHtml(tag)}
    </button>
  `;
}

function updateCounter() {
  const c = document.getElementById("etqCounter");
  if (c) c.textContent = `${_etqSelected.size} / 6`;
}

function renderSelected() {
  const wrap = document.getElementById("etqSelectedWrap");
  const hint = document.getElementById("etqLimitHint");
  if (!wrap) return;

  const arr = Array.from(_etqSelected);

  wrap.innerHTML = arr.length
    ? arr
        .map(
          (t) => `
        <span class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl bg-slate-900 text-white text-xs font-extrabold">
          ${escapeHtml(t)}
          <button type="button"
            class="text-white/80 hover:text-white font-extrabold"
            onclick="toggleEtiqueta('${escapeJsString(t)}')">×</button>
        </span>
      `
        )
        .join("")
    : `<span class="text-sm text-slate-500">Ninguna</span>`;

  if (hint) hint.classList.toggle("hidden", arr.length <= 6);
  updateCounter();
}

function renderSections() {
  const prodWrap = document.getElementById("etqProduccionList");
  const disWrap = document.getElementById("etqDisenoList");
  const genWrap = document.getElementById("etqGeneralesList");

  const secProd = document.getElementById("etqSectionProduccion");
  const secDis = document.getElementById("etqSectionDiseno");

  const confirm = isConfirmacionRole();

  if (secProd) secProd.classList.toggle("hidden", confirm);
  if (secDis) secDis.classList.remove("hidden");

  if (prodWrap)
    prodWrap.innerHTML = (ETQ_PRODUCCION || []).map((t) => chip(t, _etqSelected.has(t))).join("");

  if (disWrap)
    disWrap.innerHTML = (ETQ_DISENO || []).map((t) => chip(t, _etqSelected.has(t))).join("");

  if (genWrap) genWrap.innerHTML = ETQ_GENERALES.map((t) => chip(t, _etqSelected.has(t))).join("");

  renderSelected();
}

window.toggleEtiqueta = function (tag) {
  tag = String(tag || "").trim();
  if (!tag) return;

  const err = document.getElementById("etqError");
  if (err) err.classList.add("hidden");

  if (_etqSelected.has(tag)) {
    _etqSelected.delete(tag);
  } else {
    if (_etqSelected.size >= 6) {
      const hint = document.getElementById("etqLimitHint");
      if (hint) hint.classList.remove("hidden");
      return;
    }
    _etqSelected.add(tag);
  }

  renderSections();
};

window.limpiarEtiquetas = function () {
  _etqSelected = new Set();
  renderSections();
};

/** =========================
 *  API GUARDAR ETIQUETAS (ÚNICO)
 ========================= */
async function guardarEtiquetas(orderId, tagsStr) {
  const id = String(orderId || "");
  const order = ordersById.get(id);
  const prev = order?.etiquetas ?? "";

  // UI instant
  if (order) {
    order.etiquetas = String(tagsStr ?? "");
    actualizarTabla(ordersCache);
  }

  // Endpoints posibles (con y sin index.php, con underscore y con slashes)
  const endpoints = [
    apiUrl("/api/estado_etiquetas/guardar"),
    apiUrl("/api/estado/etiquetas/guardar"),
    "/index.php/api/estado_etiquetas/guardar",
    "/index.php/api/estado/etiquetas/guardar",
    "/api/estado_etiquetas/guardar",
    "/api/estado/etiquetas/guardar",
  ];

  // Payload compatible (manda ambos nombres por si el backend espera uno u otro)
  const payload = {
    id: Number(id),
    tags: String(tagsStr ?? ""),
    etiquetas: String(tagsStr ?? ""),
  };

  try {
    let lastErr = null;

    for (const url of endpoints) {
      try {
        const r = await fetch(url, {
          method: "POST",
          headers: jsonHeaders(), // incluye CSRF si existe
          body: JSON.stringify(payload),
        });

        if (r.status === 404) continue;

        const d = await r.json().catch(() => null);

        const ok =
          (r.ok && (d?.success === true || d?.ok === true)) ||
          (d?.success === true || d?.ok === true);

        if (!ok) throw new Error(d?.message || `HTTP ${r.status}`);

        // ✅ si el backend devuelve etiquetas normalizadas, sincroniza
        if (order && (d?.tags || d?.etiquetas)) {
          order.etiquetas = String(d.tags ?? d.etiquetas ?? order.etiquetas);
          actualizarTabla(ordersCache);
        }

        return; // ✅ guardado OK
      } catch (e) {
        lastErr = e;
      }
    }

    throw lastErr || new Error("No se encontró un endpoint válido (404).");
  } catch (e) {
    console.error("guardarEtiquetas error:", e);

    // Revert
    if (order) {
      order.etiquetas = prev;
      actualizarTabla(ordersCache);
    }

    alert("No se pudo guardar etiquetas. Se revirtió el cambio.");
  }
}


/** =========================
 *  ABRIR/CERRAR MODAL (ÚNICO)
 *  - Si existe modal completo (#modalEtiquetas con secciones etq*) => usa chips
 *  - Si existe modal simple (#modalEtiquetasPedido o inputs) => usa inputs
 *  - Si no existe modal => prompt
 ========================= */
window.abrirModalEtiquetas = async function (orderId, rawTags, numeroPedido = "") {
  const id = String(orderId ?? "");
  if (!id) return;

  const order = ordersById.get(id);
  const current = String(rawTags ?? order?.etiquetas ?? "").trim();

  // Detecta modal completo (chips)
  const modalCompleto = document.getElementById("modalEtiquetas") && document.getElementById("etqGeneralesList");

  if (modalCompleto) {
    _etqOrderId = Number(id);
    _etqOrderNumero = String(numeroPedido || order?.numero || "");

    const lbl = document.getElementById("etqPedidoLabel");
    if (lbl) lbl.textContent = _etqOrderNumero ? _etqOrderNumero : `#${id}`;

    _etqSelected = new Set(parseTags(current));
    if (_etqSelected.size > 6) _etqSelected = new Set(Array.from(_etqSelected).slice(0, 6));

    // Carga etiquetas desde BD si aún no hay
    if (!ETQ_DISENO.length && !ETQ_PRODUCCION.length) {
      await cargarEtiquetasDisponiblesBD();
    }

    renderSections();

    document.getElementById("modalEtiquetas")?.classList.remove("hidden");
    return;
  }

  // Modal simple (inputs)
  const modalSimple = document.getElementById("modalEtiquetasPedido") || document.getElementById("modalEtiquetas");
  const inputId = document.getElementById("modalEtiquetasOrderId") || document.getElementById("modalEtiquetasId");
  const inputTags = document.getElementById("modalEtiquetasTags") || document.getElementById("inputEtiquetas");

  if (modalSimple && inputId && inputTags) {
    const disponibles = getEtiquetasDisponiblesSimple();
    const allowedSet = new Set(disponibles);
    const max = maxEtiquetasPermitidasSimple();

    let selected = parseTags(current).filter((t) => allowedSet.has(t));
    if (selected.length > max) selected = selected.slice(0, max);

    inputId.value = id;
    inputTags.value = serializeTags(selected);

    renderOpcionesEtiquetasSimple({ selected });

    modalSimple.classList.remove("hidden");
    return;
  }

  // Fallback sin modal
  const max = maxEtiquetasPermitidasSimple();
  const nuevo = prompt(`Editar etiquetas (máx ${max}, separadas por coma):`, current);
  if (nuevo === null) return;

  const disponibles = getEtiquetasDisponiblesSimple();
  const allowedSet = new Set(disponibles);

  let final = parseTags(nuevo).filter((t) => allowedSet.has(t));
  final = final.slice(0, max);

  guardarEtiquetas(id, serializeTags(final));
};

window.cerrarModalEtiquetas = function () {
  document.getElementById("modalEtiquetas")?.classList.add("hidden");
  document.getElementById("modalEtiquetasPedido")?.classList.add("hidden");
};

window.guardarEtiquetasDesdeModal = function () {
  const inputId = document.getElementById("modalEtiquetasOrderId") || document.getElementById("modalEtiquetasId");
  const inputTags = document.getElementById("modalEtiquetasTags") || document.getElementById("inputEtiquetas");
  const id = String(inputId?.value || "");
  const tags = String(inputTags?.value || "");
  if (!id) return;

  guardarEtiquetas(id, tags);
  window.cerrarModalEtiquetas();
};

window.guardarEtiquetasModal = async function () {
  const err = document.getElementById("etqError");
  const btn = document.getElementById("btnGuardarEtiquetas");

  if (!_etqOrderId) return;

  if (_etqSelected.size > 6) {
    if (err) {
      err.textContent = "Máximo 6 etiquetas.";
      err.classList.remove("hidden");
    }
    return;
  }

  const etiquetas = Array.from(_etqSelected).join(", ");

  try {
    if (btn) btn.disabled = true;

    await guardarEtiquetas(_etqOrderId, etiquetas);

    window.cerrarModalEtiquetas();

    // refrescar pedidos (sin romper live)
    if (currentPage === 1) cargarPedidos({ reset: false, page_info: "" });
  } catch (e) {
    console.error(e);
    if (err) {
      err.textContent = "Error guardando etiquetas.";
      err.classList.remove("hidden");
    }
  } finally {
    if (btn) btn.disabled = false;
  }
};

// Cargar etiquetas BD al iniciar (solo si existe el modal completo)
document.addEventListener("DOMContentLoaded", () => {
  if (document.getElementById("etqGeneralesList")) {
    cargarEtiquetasDisponiblesBD();
  }
});
// =====================================================
// FIX: MODAL ESTADO - soporta distintos IDs (por si en la vista cambió)
// =====================================================
function findEstadoModal() {
  return (
    document.getElementById("modalEstado") ||
    document.getElementById("modalEstadoPedido") ||
    document.getElementById("modalEstadoOrden") ||
    document.querySelector('[data-modal="estado"]')
  );
}

function findEstadoOrderIdInput() {
  nuevoEstado = normalizeEstado(nuevoEstado);
  return (
    document.getElementById("modalOrderId") ||
    document.getElementById("modalEstadoOrderId") ||
    document.getElementById("estadoOrderId") ||
    document.querySelector('input[name="order_id"]')
  );
}

window.abrirModal = function (orderId) {
  const input = findEstadoOrderIdInput();
  if (input) input.value = String(orderId ?? "");
  const modal = findEstadoModal();
  if (modal) modal.classList.remove("hidden");
};

window.cerrarModal = function () {
  const modal = findEstadoModal();
  if (modal) modal.classList.add("hidden");
};

// Si tu guardarEstado usaba modalOrderId fijo, lo hacemos robusto también:
const _oldGuardarEstado = window.guardarEstado;
window.guardarEstado = async function (nuevoEstado) {
  const input = findEstadoOrderIdInput();
  if (!input || !input.value) {
    alert("No se encontró el ID del pedido en el modal (input). Revisa layouts/modales_estados.");
    return;
  }
  // Si ya tenías guardarEstado definido, úsalo
  if (typeof _oldGuardarEstado === "function") return _oldGuardarEstado(nuevoEstado);
};


 
