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
let silentFetch = false; // 👈 cuando true, NO muestra loader


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

// Estado del modal completo (chips)
let _etqOrderId = null;
let _etqOrderNumero = "";
let _etqSelected = new Set();

// Etiquetas dinámicas desde BD (modal completo)
let ETQ_PRODUCCION = [];
let ETQ_DISENO = [];
function escapeAttr(str) {
  return String(str ?? "")
    .replace(/&/g, "&amp;")
    .replace(/"/g, "&quot;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
}

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
  if (silentFetch) return; // 👈 evita loader molesto
  const el = document.getElementById("globalLoader");
  if (el) el.classList.remove("hidden");
}
function hideLoader() {
  if (silentFetch) return; // 👈 evita loader molesto
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
  userPingInterval = setInterval(pingUsuario, 3600000);

  cargarUsuariosEstado();
  userStatusInterval = setInterval(cargarUsuariosEstado, 150000);

  // ✅ Inicial pedidos (página 1)
  resetToFirstPage({ withFetch: true });

  // ✅ LIVE refresca la página 1 (recomendado 20s con 12 usuarios)
  startLive(30000);


  // ✅ refresca render según ancho (desktop/cards) sin pedir al backend
  window.addEventListener("resize", () => {
    const cont = document.getElementById("tablaPedidos");
    if (cont && cont.dataset.lastOrders) {
      try {
        const orders = JSON.parse(cont.dataset.lastOrders);
        actualizarTabla(Array.isArray(orders) ? orders : []);
      } catch { }
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
      silentFetch = true; // 👈 NO loader
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
// Helpers imagen mejor (acepta querystring)
function esImagenUrl(url) {
  if (!url) return false;
  const u = String(url).trim();
  return /https?:\/\/.*\.(jpeg|jpg|png|gif|webp|svg)(\?.*)?$/i.test(u);
}
function esUrl(u) {
  if (!u) return false;
  return /^https?:\/\//i.test(String(u).trim());
}

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
  if (s.includes("faltan archivos") || s.includes("faltan_archivos")) return "Faltan archivos";
  if (s.includes("confirmado")) return "Confirmado";
  if (s.includes("diseñado") || s.includes("disenado")) return "Diseñado";
  if (s.includes("por producir")) return "Por producir";
  if (s.includes("enviado")) return "Enviado";

  return estado ? String(estado).trim() : "Por preparar";
}

/* =====================================================
  ESTADO PILL (igual a colores del modal)
===================================================== */
function estadoStyle(estado) {
  const label = normalizeEstado(estado); // ✅ ahora SÍ existe
  const s = String(estado || "").toLowerCase().trim();
  const base =
    "inline-flex items-center gap-2 px-3 py-1.5 rounded-2xl border " +
    "text-xs font-extrabold shadow-sm tracking-wide uppercase";

  const dotBase = "h-2.5 w-2.5 rounded-full ring-2 ring-white/40";


  if (s.includes("por preparar")) {
    return {
      label,
      icon: "⏳",
      wrap: `${base} bg-slate-900 border-slate-700 text-white`,
      dot: `${dotBase} bg-slate-300`,
    };
  }

  if (s.includes("faltan archivos")) {
    return {
      label,
      icon: "⚠️",
      wrap: `${base} bg-yellow-400 border-yellow-500 text-black`,
      dot: `${dotBase} bg-black/80`,
    };
  }

  if (s.includes("confirmado")) {
    return {
      label,
      icon: "✅",
      wrap: `${base} bg-fuchsia-600 border-fuchsia-700 text-white`,
      dot: `${dotBase} bg-white`,
    };
  }

  if (s.includes("diseñado")) {
    return {
      label,
      icon: "🎨",
      wrap: `${base} bg-blue-600 border-blue-700 text-white`,
      dot: `${dotBase} bg-sky-200`,
    };
  }

  if (s.includes("por producir")) {
    return {
      label,
      icon: "🏗️",
      wrap: `${base} bg-orange-600 border-orange-700 text-white`,
      dot: `${dotBase} bg-amber-200`,
    };
  }

  if (s.includes("enviado")) {
    return {
      label,
      icon: "🚚",
      wrap: `${base} bg-emerald-600 border-emerald-700 text-white`,
      dot: `${dotBase} bg-lime-200`,
    };
  }

  return {
    label: label || "—",
    icon: "📍",
    wrap: `${base} bg-slate-700 border-slate-600 text-white`,
    dot: `${dotBase} bg-slate-200`,
  };
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

  if (withFetch) cargarPedidos({ reset: true, page_info: "" }); // ✅ directo
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
      silentFetch = false; // 👈 vuelve a normal
      hideLoader();
    });

}
// ✅ Exponer para llamadas que usan window.cargarPedidos(...)
window.cargarPedidos = cargarPedidos;

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
              <div class="orders-grid cols px-4 py-3 text-[13px] border-b hover:bg-slate-50 transition">
                <!-- Pedido -->
                <div class="font-extrabold text-slate-900 whitespace-nowrap">
                  ${escapeHtml(p.numero ?? "-")}
                </div>

                <!-- Fecha -->
                <div class="text-slate-600 whitespace-nowrap">
                  ${escapeHtml(p.fecha ?? "-")}
                </div>

                <!-- Cliente -->
                <div class="min-w-0 font-semibold text-slate-800 truncate">
                  ${escapeHtml(p.cliente ?? "-")}
                </div>

                <!-- Total -->
                <div class="font-extrabold text-slate-900 whitespace-nowrap">
                  ${escapeHtml(p.total ?? "-")}
                </div>

                <!-- Estado -->
                  <div class="whitespace-nowrap relative z-10">
                    <button
                      type="button"
                      onclick="abrirModal('${escapeJsString(String(id))}')"
                      class="
                        group inline-flex items-center gap-1
                        rounded-xl px-1 py-0.5
                        bg-transparent
                        hover:bg-slate-100
                        transition
                        focus:outline-none
                      "
                      title="Cambiar estado"
                    >
                      ${renderEstadoPill(p.estado ?? "-")}
                    </button>
                  </div>



                <!-- Último cambio -->
                <div class="min-w-0">
                  ${renderLastChangeCompact(p)}
                </div>

                <!-- Etiquetas -->
                <div class="min-w-0">
                  ${renderEtiquetasCompact(etiquetas, id)}
                </div>

                <!-- Artículos -->
                <div class="text-center font-extrabold">
                  ${escapeHtml(p.articulos ?? "-")}
                </div>

                <!-- Entrega -->
                <div class="whitespace-nowrap">
                  ${renderEntregaPill(p.estado_envio ?? "-")}
                </div>

                <!-- Método de entrega (mejor 2 líneas) -->
                <div class="min-w-0 text-xs text-slate-700 metodo-entrega">
                  ${escapeHtml(p.forma_envio ?? "-")}
                </div>

                <!-- Ver detalles -->
                <div class="text-right whitespace-nowrap">
                  <button type="button" onclick="verDetalles('${escapeJsString(String(id))}')"
                    class="px-3 py-2 rounded-2xl bg-blue-600 text-white text-[11px] font-extrabold uppercase tracking-wide hover:bg-blue-700 transition">
                    Ver detalles →
                  </button>
                </div>
              </div>
            `;
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

                <div class="text-right whitespace-nowrap">
                  <button onclick="verDetalles(${Number(id)})"
                    class="px-3 py-2 rounded-2xl bg-blue-600 text-white text-[11px] font-extrabold uppercase tracking-wide hover:bg-blue-700 transition">
                    Ver detalles →
                  </button>
                </div>
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
// ===============================
// DETALLES (FULL SCREEN) - FIX IDs
// ===============================

function $(id) {
  return document.getElementById(id);
}

function setHtml(id, html) {
  const el = $(id);
  if (!el) {
    console.warn("Falta en el DOM:", id);
    return false;
  }
  el.innerHTML = html;
  return true;
}

function setText(id, txt) {
  const el = $(id);
  if (!el) {
    console.warn("Falta en el DOM:", id);
    return false;
  }
  el.textContent = txt ?? "";
  return true;
}

function abrirDetallesFull() {
  const modal = $("modalDetallesFull");
  if (modal) modal.classList.remove("hidden");
  document.documentElement.classList.add("overflow-hidden");
  document.body.classList.add("overflow-hidden");
}

function cerrarDetallesFull() {
  const modal = $("modalDetallesFull");
  if (modal) modal.classList.add("hidden");
  document.documentElement.classList.remove("overflow-hidden");
  document.body.classList.remove("overflow-hidden");
}

function toggleJsonDetalles() {
  const pre = $("detJson");
  if (!pre) return;
  pre.classList.toggle("hidden");
}

function copiarDetallesJson() {
  const pre = $("detJson");
  if (!pre) return;
  const text = pre.textContent || "";
  navigator.clipboard?.writeText(text).then(
    () => alert("JSON copiado ✅"),
    () => alert("No se pudo copiar ❌")
  );
}

// Helpers imagen
function esImagen(url) {
  if (!url) return false;
  return /\.(jpeg|jpg|png|gif|webp|svg)$/i.test(String(url));
}

function escapeHtml(str) {
  return String(str ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

// ===============================
// VER DETALLES (FULL MODAL SHOPIFY-LIKE)
// ===============================
window.verDetalles = async function (orderId) {
  const id = String(orderId || "");
  if (!id) return;

  // -----------------------------
  // Helpers DOM (compat)
  // -----------------------------
  function $(x) { return document.getElementById(x); }

  function setHtml(elId, html) {
    const el = $(elId);
    if (!el) return false;
    el.innerHTML = html;
    return true;
  }

  function setText(elId, txt) {
    const el = $(elId);
    if (!el) return false;
    el.textContent = txt ?? "";
    return true;
  }

  function abrirDetallesFull() {
    const modal = $("modalDetallesFull");
    if (modal) modal.classList.remove("hidden");
    document.documentElement.classList.add("overflow-hidden");
    document.body.classList.add("overflow-hidden");
  }

  // -----------------------------
  // Helpers sanitize
  // -----------------------------
  function escapeHtml(str) {
    return String(str ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  // 👇 FIX: faltaba escapeAttr en esta función (lo usas en detResumen)
  function escapeAttr(str) {
    return String(str ?? "")
      .replace(/&/g, "&amp;")
      .replace(/"/g, "&quot;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;");
  }

  function esUrl(u) {
    return /^https?:\/\//i.test(String(u || "").trim());
  }

  // acepta querystring
  function esImagenUrl(url) {
    if (!url) return false;
    const u = String(url).trim();
    return /https?:\/\/.*\.(jpeg|jpg|png|gif|webp|svg)(\?.*)?$/i.test(u);
  }

  // total línea
  function totalLinea(price, qty) {
    const p = Number(price);
    const q = Number(qty);
    if (isNaN(p) || isNaN(q)) return null;
    return (p * q).toFixed(2);
  }

  // -----------------------------
  // Open modal + placeholders
  // -----------------------------
  abrirDetallesFull();

  setText("detTitle", "Cargando…");
  setText("detSubtitle", "—");
  setText("detItemsCount", "0");

  setHtml("detItems", `<div class="text-slate-500">Cargando productos…</div>`);
  setHtml("detResumen", `<div class="text-slate-500">Cargando…</div>`);
  setHtml("detCliente", `<div class="text-slate-500">Cargando…</div>`);
  setHtml("detEnvio", `<div class="text-slate-500">Cargando…</div>`);
  setHtml("detTotales", `<div class="text-slate-500">Cargando…</div>`);

  const pre = $("detJson");
  if (pre) pre.textContent = "";

  // -----------------------------
  // Fetch detalles
  // -----------------------------
  try {
    const url =
      typeof apiUrl === "function"
        ? apiUrl(`/dashboard/detalles/${encodeURIComponent(id)}`)
        : `/index.php/dashboard/detalles/${encodeURIComponent(id)}`;

    const r = await fetch(url, { headers: { Accept: "application/json" } });
    const d = await r.json().catch(() => null);

    if (!r.ok || !d || d.success !== true) {
      setHtml(
        "detItems",
        `<div class="text-rose-600 font-extrabold">Error cargando detalles. Revisa endpoint.</div>`
      );
      if (pre) pre.textContent = JSON.stringify({ http: r.status, payload: d }, null, 2);
      return;
    }

    // debug
    if (pre) pre.textContent = JSON.stringify(d, null, 2);

    const o = d.order || {};
    const lineItems = Array.isArray(o.line_items) ? o.line_items : [];

    // mapas extra del backend
    const imagenesLocales = d.imagenes_locales || {};
    const productImages = d.product_images || {};

    // -----------------------------
    // Header
    // -----------------------------
    setText("detTitle", `Pedido ${o.name || ("#" + id)}`);

    const clienteNombre = o.customer
      ? `${o.customer.first_name || ""} ${o.customer.last_name || ""}`.trim()
      : "";

    setText("detSubtitle", clienteNombre ? clienteNombre : (o.email || "—"));

    // -----------------------------
    // Cliente
    // -----------------------------
    setHtml(
      "detCliente",
      `
      <div class="space-y-2">
        <div class="font-extrabold text-slate-900">${escapeHtml(clienteNombre || "—")}</div>
        <div><span class="text-slate-500">Email:</span> ${escapeHtml(o.email || "—")}</div>
        <div><span class="text-slate-500">Tel:</span> ${escapeHtml(o.phone || "—")}</div>
        <div><span class="text-slate-500">ID:</span> ${escapeHtml(o.customer?.id || "—")}</div>
      </div>
      `
    );

    // -----------------------------
    // Envío
    // -----------------------------
    const a = o.shipping_address || {};
    setHtml(
      "detEnvio",
      `
      <div class="space-y-1">
        <div class="font-extrabold text-slate-900">${escapeHtml(a.name || "—")}</div>
        <div>${escapeHtml(a.address1 || "")}</div>
        <div>${escapeHtml(a.address2 || "")}</div>
        <div>${escapeHtml((a.zip || "") + " " + (a.city || ""))}</div>
        <div>${escapeHtml(a.province || "")}</div>
        <div>${escapeHtml(a.country || "")}</div>
        <div class="pt-2"><span class="text-slate-500">Tel envío:</span> ${escapeHtml(a.phone || "—")}</div>
      </div>
      `
    );

    // -----------------------------
    // Totales
    // -----------------------------
    const envio = o.total_shipping_price_set?.shop_money?.amount ?? o.total_shipping_price_set?.presentment_money?.amount ?? "0";
    const impuestos = o.total_tax ?? "0";

    // ==============================
    // ✅ ETIQUETAS (FIX DEFINITIVO)
    // - prefiero etiquetas BD (cache dashboard)
    // - fallback a Shopify tags (o.tags)
    // ==============================
    const shopifyTags = String(o.tags || "").trim();

    const cachedOrder =
      (window.ordersById && window.ordersById.get && window.ordersById.get(String(id))) ||
      (window.ordersById && window.ordersById.get && window.ordersById.get(String(o.id))) ||
      (Array.isArray(window.ordersCache) ? window.ordersCache.find(x => String(x.id) === String(id)) : null) ||
      (Array.isArray(window.ordersCache) ? window.ordersCache.find(x => String(x.id) === String(o.id)) : null) ||
      null;

    const dbTags = String(cachedOrder?.etiquetas || "").trim();

    const tagsActuales = (dbTags || shopifyTags || "").trim();

    // helper global para repintar SIN recargar detalles
    window.__pintarTagsEnDetalle = function (tagsStr) {
      const wrap = document.getElementById("det-tags-view");
      if (!wrap) return;

      const clean = String(tagsStr || "").trim();

      wrap.innerHTML = clean
        ? clean
          .split(",")
          .map(t => t.trim())
          .filter(Boolean)
          .map(tag => {
            // usa mismo color que dashboard si existe
            const cls =
              (typeof window.colorEtiqueta === "function")
                ? window.colorEtiqueta(tag)
                : "bg-white border-slate-200 text-slate-900";
            return `
                <span class="px-3 py-1 rounded-full text-xs font-extrabold uppercase tracking-wide border ${cls}">
                  ${escapeHtml(tag)}
                </span>
              `;
          })
          .join("")
        : `<span class="text-xs text-slate-400">—</span>`;

      const btn = document.getElementById("btnEtiquetasDetalle");
      if (btn) btn.dataset.orderTags = clean;
    };

    setHtml(
      "detTotales",
      `
      <div class="space-y-1">
        <div><b>Subtotal:</b> ${escapeHtml(o.subtotal_price || "0")} €</div>
        <div><b>Envío:</b> ${escapeHtml(envio)} €</div>
        <div><b>Impuestos:</b> ${escapeHtml(impuestos)} €</div>
        <div class="text-lg font-extrabold"><b>Total:</b> ${escapeHtml(o.total_price || "0")} €</div>
      </div>
      `
    );

    // -----------------------------
    // Resumen
    // -----------------------------
    setHtml(
      "detResumen",
      `
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
          <div class="flex items-center justify-between">
            <div class="text-xs text-slate-500 font-extrabold uppercase">Etiquetas</div>

            <button
              id="btnEtiquetasDetalle"
              type="button"
              class="px-3 py-1 rounded-full border border-slate-200 bg-white text-[11px] font-extrabold tracking-wide shadow-sm hover:bg-slate-50 active:scale-[0.99]"
              data-order-id="${o.id}"
              data-order-label="${escapeAttr(o.name || ("#" + o.id))}"
              data-order-tags="${escapeAttr(tagsActuales)}"
              onclick="abrirEtiquetasDesdeDetalle(this)"
            >
              ETIQUETAS <span class="ml-1 font-black">+</span>
            </button>
          </div>

          <div id="det-tags-view" class="mt-2 flex flex-wrap gap-2"></div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
          <div class="text-xs text-slate-500 font-extrabold uppercase">Pago</div>
          <div class="mt-1 font-semibold">${escapeHtml(o.financial_status || "—")}</div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
          <div class="text-xs text-slate-500 font-extrabold uppercase">Entrega</div>
          <div class="mt-1 font-semibold">${escapeHtml(o.fulfillment_status || "—")}</div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
          <div class="text-xs text-slate-500 font-extrabold uppercase">Creado</div>
          <div class="mt-1 font-semibold">${escapeHtml(o.created_at || "—")}</div>
        </div>
      </div>
      `
    );

    // ✅ IMPORTANTÍSIMO: pintamos DESPUÉS de crear el DOM de detResumen
    window.__pintarTagsEnDetalle(tagsActuales);

    // ✅ Detalles -> abre el MISMO modal del dashboard (y marca que viene de detalles)
    window.abrirEtiquetasDesdeDetalle = function (btn) {
      try {
        const orderId = btn?.dataset?.orderId;
        const label = btn?.dataset?.orderLabel || ("#" + orderId);
        const tagsStr = btn?.dataset?.orderTags || "";

        // marca para que guardarEtiquetasModal repinte en detalles
        window.__ETQ_DETALLE_ORDER_ID = Number(orderId) || null;

        if (typeof window.abrirModalEtiquetas === "function") {
          window.abrirModalEtiquetas(orderId, tagsStr, label);
          return;
        }

        // fallback
        const modal = document.getElementById("modalEtiquetas");
        if (modal) modal.classList.remove("hidden");

        const lbl = document.getElementById("etqPedidoLabel");
        if (lbl) lbl.textContent = label;
      } catch (e) {
        console.error("abrirEtiquetasDesdeDetalle error:", e);
      }
    };

    // -----------------------------
    // Productos
    // -----------------------------
    setText("detItemsCount", String(lineItems.length));

    if (!lineItems.length) {
      setHtml("detItems", `<div class="text-slate-500">Este pedido no tiene productos.</div>`);
      return;
    }

    window.imagenesLocales = imagenesLocales || {};
    window.imagenesCargadas = new Array(lineItems.length).fill(false);
    window.imagenesRequeridas = new Array(lineItems.length).fill(false);

    const itemsHtml = lineItems
      .map((item, index) => {
        const props = Array.isArray(item.properties) ? item.properties : [];

        // separa properties: imagen vs texto
        const propsImg = [];
        const propsTxt = [];

        for (const p of props) {
          const name = String(p?.name ?? "").trim() || "Campo";
          const value = p?.value;

          const v =
            value === null || value === undefined
              ? ""
              : typeof value === "object"
                ? JSON.stringify(value)
                : String(value);

          if (esImagenUrl(v)) propsImg.push({ name, value: v });
          else propsTxt.push({ name, value: v });
        }

        const requiere = propsImg.length > 0;

        // imagen producto (desde backend)
        const pid = String(item.product_id || "");
        const productImg = pid && productImages?.[pid] ? String(productImages[pid]) : "";

        const productImgHtml = productImg
          ? `
            <a href="${escapeHtml(productImg)}" target="_blank"
              class="h-16 w-16 rounded-2xl overflow-hidden border border-slate-200 shadow-sm bg-white flex-shrink-0">
              <img src="${escapeHtml(productImg)}" class="h-full w-full object-cover">
            </a>
          `
          : `
            <div class="h-16 w-16 rounded-2xl border border-slate-200 bg-slate-50 flex items-center justify-center text-slate-400 flex-shrink-0">
              🧾
            </div>
          `;

        // imagen modificada (local)
        const localUrl = imagenesLocales?.[index] ? String(imagenesLocales[index]) : "";

        window.imagenesRequeridas[index] = !!requiere;
        window.imagenesCargadas[index] = !!localUrl;

        const estadoItem = requiere ? (localUrl ? "LISTO" : "FALTA") : "NO REQUIERE";
        const badgeCls =
          estadoItem === "LISTO"
            ? "bg-emerald-50 border-emerald-200 text-emerald-900"
            : estadoItem === "FALTA"
              ? "bg-amber-50 border-amber-200 text-amber-900"
              : "bg-slate-50 border-slate-200 text-slate-700";
        const badgeText =
          estadoItem === "LISTO" ? "Listo" : estadoItem === "FALTA" ? "Falta imagen" : "Sin imagen";

        // props texto (personalización)
        const propsTxtHtml = propsTxt.length
          ? `
            <div class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500 mb-2">Personalización</div>
              <div class="space-y-1 text-sm">
                ${propsTxt
            .map(({ name, value }) => {
              const safeV = escapeHtml(value || "—");
              const safeName = escapeHtml(name);

              const val =
                esUrl(value)
                  ? `<a href="${escapeHtml(value)}" target="_blank" class="underline font-semibold text-slate-900">${safeV}</a>`
                  : `<span class="font-semibold text-slate-900 break-words">${safeV}</span>`;

              return `
                      <div class="flex gap-2">
                        <div class="min-w-[130px] text-slate-500 font-bold">${safeName}:</div>
                        <div class="flex-1">${val}</div>
                      </div>
                    `;
            })
            .join("")}
              </div>
            </div>
          `
          : "";

        // imágenes cliente
        const propsImgsHtml = propsImg.length
          ? `
            <div class="mt-3">
              <div class="text-xs font-extrabold text-slate-500 mb-2">Imagen original (cliente)</div>
              <div class="flex flex-wrap gap-3">
                ${propsImg
            .map(
              ({ name, value }) => `
                    <a href="${escapeHtml(value)}" target="_blank"
                      class="block rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
                      <img src="${escapeHtml(value)}" class="h-28 w-28 object-cover">
                      <div class="px-3 py-2 text-xs font-bold text-slate-700 bg-white border-t border-slate-200">
                        ${escapeHtml(name)}
                      </div>
                    </a>
                  `
            )
            .join("")}
              </div>
            </div>
          `
          : "";

        // imagen modificada mostrada
        const modificadaHtml = localUrl
          ? `
            <div class="mt-3">
              <div class="text-xs font-extrabold text-slate-500">Imagen modificada (subida)</div>
              <a href="${escapeHtml(localUrl)}" target="_blank"
                class="inline-block mt-2 rounded-2xl overflow-hidden border border-slate-200 shadow-sm">
                <img src="${escapeHtml(localUrl)}" class="h-40 w-40 object-cover">
              </a>
            </div>
          `
          : requiere
            ? `<div class="mt-3 text-rose-600 font-extrabold text-sm">Falta imagen modificada</div>`
            : "";

        // datos del item (tipo Shopify)
        const variant = item.variant_title && item.variant_title !== "Default Title" ? item.variant_title : "";
        const sku = item.sku || "";
        const qty = item.quantity ?? 1;
        const price = item.price ?? "0";
        const tot = totalLinea(price, qty);

        const datosProductoHtml = `
          <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
            ${variant ? `<div><span class="text-slate-500 font-bold">Variante:</span> <span class="font-semibold">${escapeHtml(variant)}</span></div>` : ""}
            ${sku ? `<div><span class="text-slate-500 font-bold">SKU:</span> <span class="font-semibold">${escapeHtml(sku)}</span></div>` : ""}
            ${item.product_id ? `<div><span class="text-slate-500 font-bold">Product ID:</span> <span class="font-semibold">${escapeHtml(item.product_id)}</span></div>` : ""}
            ${item.variant_id ? `<div><span class="text-slate-500 font-bold">Variant ID:</span> <span class="font-semibold">${escapeHtml(item.variant_id)}</span></div>` : ""}
          </div>
        `;

        const uploadHtml = requiere
          ? `
            <div class="mt-4">
              <div class="text-xs font-extrabold text-slate-500 mb-2">Subir imagen modificada</div>
              <input type="file" accept="image/*"
                onchange="subirImagenProducto(${Number(orderId)}, ${index}, this)"
                class="w-full border border-slate-200 rounded-2xl p-2">
              <div id="preview_${id}_${index}" class="mt-2"></div>
            </div>
          `
          : "";

        return `
          <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-4">
            <div class="flex items-start gap-4">
              ${productImgHtml}

              <div class="min-w-0 flex-1">
                <div class="flex items-start justify-between gap-3">
                  <div class="min-w-0">
                    <div class="font-extrabold text-slate-900 truncate">${escapeHtml(item.title || item.name || "Producto")}</div>
                    <div class="text-sm text-slate-600 mt-1">
                      Cant: <b>${escapeHtml(qty)}</b> · Precio: <b>${escapeHtml(price)} €</b>
                      ${tot ? ` · Total: <b>${escapeHtml(tot)} €</b>` : ""}
                    </div>
                  </div>

                  <span class="text-xs font-extrabold px-3 py-1 rounded-full border ${badgeCls}">
                    ${badgeText}
                  </span>
                </div>

                ${datosProductoHtml}
                ${propsTxtHtml}
                ${propsImgsHtml}
                ${modificadaHtml}
                ${uploadHtml}
              </div>
            </div>
          </div>
        `;
      })
      .join("");

    setHtml("detItems", itemsHtml);
  } catch (e) {
    console.error("verDetalles error:", e);
    setHtml("detItems", `<div class="text-rose-600 font-extrabold">Error de red cargando detalles.</div>`);
  }
};




// ===============================
// SUBIR IMAGEN MODIFICADA + AUTO ESTADO (FIX)
// ===============================
window.subirImagenProducto = async function (orderId, index, input) {
  try {
    const file = input?.files?.[0];
    if (!file) return;

    // ✅ FormData correcto
    const fd = new FormData();
    fd.append("order_id", String(orderId));
    fd.append("line_index", String(index));
    fd.append("file", file);


    // CSRF (si lo usas)
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content");
    const csrfHeader = document.querySelector('meta[name="csrf-header"]')?.getAttribute("content") || "X-CSRF-TOKEN";

    const endpoints = [
      (typeof apiUrl === "function" ? apiUrl("/api/pedidos/imagenes/subir") : "/index.php/api/pedidos/imagenes/subir"),
      "/api/pedidos/imagenes/subir",
      "/index.php/api/pedidos/imagenes/subir",
      "/index.php/index.php/api/pedidos/imagenes/subir",
    ];

    let lastErr = null;

    for (const url of endpoints) {
      try {
        const headers = {};
        if (csrfToken) headers[csrfHeader] = csrfToken;

        const r = await fetch(url, { method: "POST", headers, body: fd });

        if (r.status === 404) continue;

        const d = await r.json().catch(() => null);
        if (!r.ok || !d?.success) throw new Error(d?.message || `HTTP ${r.status}`);

        // ✅ pintar preview
        const previewId = `preview_${orderId}_${index}`;
        const prev = document.getElementById(previewId);
        if (prev) {
          prev.innerHTML = `
            <div class="mt-2">
              <div class="text-xs font-extrabold text-slate-500">Imagen modificada subida ✅</div>
              <img src="${d.url}" class="mt-2 w-44 rounded-2xl border border-slate-200 shadow-sm object-cover">
            </div>
          `;
        }

        // ✅ marcar como cargada en memoria
        if (!Array.isArray(window.imagenesCargadas)) window.imagenesCargadas = [];
        if (!Array.isArray(window.imagenesRequeridas)) window.imagenesRequeridas = [];

        window.imagenesCargadas[index] = true;

        if (window.imagenesLocales && typeof window.imagenesLocales === "object") {
          window.imagenesLocales[index] = d.url;
        }

        // ✅ ahora sí: recalcular estado automático
        validarEstadoAuto(orderId);

        return; // ✅ éxito
      } catch (e) {
        lastErr = e;
      }
    }

    throw lastErr || new Error("No se encontró endpoint para subir imagen (404).");
  } catch (e) {
    console.error("subirImagenProducto error:", e);
    alert("Error subiendo imagen: " + (e?.message || e));
  }
};


// ===============================
// AUTO ESTADO (FIX): Produccion / A medias
// ===============================
function validarEstadoAuto(orderId) {
  const req = Array.isArray(window.imagenesRequeridas) ? window.imagenesRequeridas : [];
  const carg = Array.isArray(window.imagenesCargadas) ? window.imagenesCargadas : [];

  // Solo cuentan las requeridas
  let totalReq = 0;
  let okReq = 0;

  for (let i = 0; i < req.length; i++) {
    if (req[i]) {
      totalReq++;
      if (carg[i]) okReq++;
    }
  }

  // Si no hay requeridas, no tocamos estado
  if (totalReq === 0) return;

  // ✅ SIN ACENTO, como tu backend
  const nuevoEstado = okReq === totalReq ? "Confirmado" : "Faltan Archivos";

  // ✅ usa el mismo guardarEstado robusto si existe
  if (typeof window.guardarEstado === "function") {
    // truco: abre modal no es necesario, guardamos directo con endpoint
  }

  // Guardar estado automáticamente (con endpoints robustos)
  const endpoints = [
    apiUrl("/api/estado/guardar"),
    "/api/estado/guardar",
    "/index.php/api/estado/guardar",
    "/index.php/index.php/api/estado/guardar",
  ];

  (async () => {
    for (const url of endpoints) {
      try {
        const r = await fetch(url, {
          method: "POST",
          headers: jsonHeaders(),
          body: JSON.stringify({ id: Number(orderId), estado: nuevoEstado }),
        });

        if (r.status === 404) continue;

        const d = await r.json().catch(() => null);
        if (!r.ok || !d?.success) throw new Error(d?.message || `HTTP ${r.status}`);

        // refresca lista si estás en pág 1
        if (typeof cargarPedidos === "function" && currentPage === 1) {
          cargarPedidos({ reset: false, page_info: "" });
        }
        return;
      } catch (e) {
        // intenta siguiente endpoint
      }
    }
  })();
}


/* =====================================================
  USERS STATUS
===================================================== */
async function pingUsuario() {
  try {
    await fetch(apiUrl("/dashboard/ping"), { headers: { Accept: "application/json" } });
  } catch (e) { }
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
      <li class="flex items-center justify-between gap-3 p-3 rounded-2xl border ${mode === "online" ? "border-emerald-200 bg-white/70" : "border-rose-200 bg-white/70"
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


// Etiquetas generales fijas (modal completo)
const ETQ_GENERALES = [
  "Cancelar pedido",
  "Reembolso 50%",
  "Reembolso 30%",
  "Reembolso completo",
  "Repetir",
  "No contesta 24h",
  "Test",
  "Urgente",
  "Retrasado",
  "Contacto cliente",
  "Pendiente pago",

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
// ✅ OrderId actual cuando el modal se abre desde Detalles
window.__ETQ_DETALLE_ORDER_ID = null;

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

    // ✅ 1) Guardar en Shopify (y en cache del dashboard)
    await guardarEtiquetas(_etqOrderId, etiquetas);

    // ✅ 2) Si el modal se abrió desde DETALLES, pinta inmediatamente ahí
    if (window.__ETQ_DETALLE_ORDER_ID) {
      pintarTagsDetalle(etiquetas);

      // también actualiza el dataset del botón si existe (para reabrir con lo nuevo)
      const btnDet = document.querySelector('[onclick*="abrirEtiquetasDesdeDetalle"], [data-order-id]');
      // (si quieres lo hacemos más exacto con un id fijo del botón)
      if (btnDet && btnDet.dataset) {
        btnDet.dataset.orderTags = etiquetas;
      }
    }

    // ✅ 3) Cerrar modal
    window.cerrarModalEtiquetas();

    // ✅ 4) refrescar tabla dashboard (sin romper live)
    if (currentPage === 1) cargarPedidos({ reset: false, page_info: "" });

  } catch (e) {
    console.error(e);
    if (err) {
      err.textContent = "Error guardando etiquetas.";
      err.classList.remove("hidden");
    }
  } finally {
    if (btn) btn.disabled = false;
    // limpia al final (no antes)
    window.__ETQ_DETALLE_ORDER_ID = null;
  }
};


window.abrirModalEtiquetasDesdeDetalles = function (orderId, tagsActuales) {
  window.__ETQ_DETALLE_ORDER_ID = Number(orderId) || null;

  // Llama a tu función existente del modal (la que ya usas en Dashboard)
  // Si se llama distinto en tu código, cambia SOLO esta línea:
  abrirModalEtiquetas(orderId, tagsActuales);
};

function pintarTagsDetalle(tagsStr) {
  const wrap = document.getElementById('det-tags-view');
  if (!wrap) return; // si no estás en pantalla detalles, no hace nada

  const clean = (tagsStr || '').trim();
  if (!clean) {
    wrap.innerHTML = '<span class="text-xs text-slate-400">—</span>';
    return;
  }

  wrap.innerHTML = clean.split(',')
    .map(t => t.trim())
    .filter(Boolean)
    .map(t => `
      <span class="px-3 py-1 rounded-full text-xs font-semibold border bg-white">
        ${escapeHtml(t)}
      </span>
    `)
    .join('');
}

// Cargar etiquetas BD al iniciar (solo si existe el modal completo)
document.addEventListener("DOMContentLoaded", () => {
  if (document.getElementById("etqGeneralesList")) {
    cargarEtiquetasDisponiblesBD();
  }
});
// =====================================================
// FIX: MODAL ESTADO - robusto (sin variables inexistentes)
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


// ===============================
// Export seguro (evita colisiones con IDs del DOM)
// ===============================
window.DASH = window.DASH || {};

window.DASH.cargarPedidos = cargarPedidos;
window.DASH.resetToFirstPage = resetToFirstPage;

console.log("✅ dashboard.js cargado - verDetalles hash:", (window.verDetalles ? window.verDetalles.toString().length : "NO verDetalles"));

// =============================
// TAGS EN DETALLES (GLOBAL)
// =============================
(function () {
  let detTagsSelected = [];
  let detTagsOriginal = [];

  function editarTagsDetalle(orderId) {
    const view = document.getElementById("det-tags-view");
    const editor = document.getElementById("det-tags-editor");
    const chipsWrap = document.getElementById("det-tags-chips");
    const msg = document.getElementById("det-tags-msg");

    if (!view || !editor || !chipsWrap || !msg) {
      console.error("Faltan contenedores det-tags-* en el DOM");
      return;
    }

    // tags actuales desde el order (si no hay, array vacío)
    const currentTagsText = (view.getAttribute("data-tags") || "").trim();
    detTagsOriginal = currentTagsText
      ? currentTagsText.split(",").map(t => t.trim()).filter(Boolean)
      : [];

    detTagsSelected = [...detTagsOriginal];

    view.classList.add("hidden");
    editor.classList.remove("hidden");
    msg.textContent = "";
    msg.className = "text-xs mt-2 text-slate-500";

    chipsWrap.innerHTML = "Cargando...";

    fetch("/dashboard/etiquetas-disponibles", { credentials: "include" })
      .then(r => r.json())
      .then(data => {
        chipsWrap.innerHTML = "";

        if (!data || !data.ok) {
          chipsWrap.innerHTML = `<span class="text-xs text-red-600">No se pudieron cargar etiquetas</span>`;
          return;
        }

        const all = [...(data.diseno || []), ...(data.produccion || [])];

        if (!all.length) {
          chipsWrap.innerHTML = `<span class="text-xs text-slate-500">No hay etiquetas disponibles</span>`;
          return;
        }

        all.forEach(tag => {
          const btn = document.createElement("button");
          btn.type = "button";

          const refresh = () => {
            const active = detTagsSelected.includes(tag);
            btn.className = active
              ? "px-3 py-1 rounded-full text-xs font-semibold bg-slate-900 text-white border"
              : "px-3 py-1 rounded-full text-xs font-semibold bg-white border";
          };

          btn.textContent = tag;
          refresh();

          btn.addEventListener("click", () => {
            if (detTagsSelected.includes(tag)) {
              detTagsSelected = detTagsSelected.filter(t => t !== tag);
            } else {
              detTagsSelected.push(tag);
            }
            refresh();
          });

          chipsWrap.appendChild(btn);
        });
      })
      .catch(err => {
        console.error(err);
        chipsWrap.innerHTML = `<span class="text-xs text-red-600">Error cargando etiquetas</span>`;
      });
  }

  function cancelarTagsDetalle() {
    const view = document.getElementById("det-tags-view");
    const editor = document.getElementById("det-tags-editor");
    if (view) view.classList.remove("hidden");
    if (editor) editor.classList.add("hidden");
  }

  function guardarTagsDetalle(orderId) {
    const msg = document.getElementById("det-tags-msg");
    const view = document.getElementById("det-tags-view");
    const editor = document.getElementById("det-tags-editor");

    if (!msg || !view || !editor) return;

    msg.textContent = "Guardando...";
    msg.className = "text-xs mt-2 text-slate-500";

    const tagsString = detTagsSelected.join(", ");

    fetch("/api/estado_etiquetas/guardar", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify({
        id: orderId,
        tags: tagsString,
      }),
    })
      .then(r => r.json())
      .then(data => {
        if (!data || !data.success) {
          msg.textContent = (data && data.message) ? data.message : "Error guardando etiquetas";
          msg.className = "text-xs mt-2 text-red-600";
          return;
        }

        // Render chips en vista
        view.innerHTML = "";

        if (!detTagsSelected.length) {
          view.innerHTML = `<span class="text-xs text-slate-400">—</span>`;
          view.setAttribute("data-tags", "");
        } else {
          detTagsSelected.forEach(t => {
            view.innerHTML += `
              <span class="px-3 py-1 rounded-full text-xs font-semibold border bg-white">
                ${escapeHtml(t)}
              </span>
            `;
          });
          view.setAttribute("data-tags", detTagsSelected.join(", "));
        }

        view.classList.remove("hidden");
        editor.classList.add("hidden");

        msg.textContent = "Guardado ✓";
        msg.className = "text-xs mt-2 text-green-600";

        // (Opcional) sincroniza tabla si existe una celda
        const rowCell = document.querySelector(`tr[data-order-id="${orderId}"] .col-tags`);
        if (rowCell) rowCell.textContent = tagsString || "-";
      })
      .catch(err => {
        console.error(err);
        msg.textContent = "Error guardando etiquetas";
        msg.className = "text-xs mt-2 text-red-600";
      });
  }

  // ✅ EXPONER A GLOBAL (para onclick=)
  window.editarTagsDetalle = editarTagsDetalle;
  window.cancelarTagsDetalle = cancelarTagsDetalle;
  window.guardarTagsDetalle = guardarTagsDetalle;
})();
