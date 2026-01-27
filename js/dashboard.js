// =====================================================
// DASHBOARD.JS (COMPLETO) - REAL TIME + PAGINACIÓN ESTABLE
// + PROTECCIÓN ANTI-OVERWRITE (12 usuarios)
// (SIN ETIQUETAS) + FILTRO COMPLETO
// ✅ FIX: Mostrar imágenes de confirmaciones / props complejas
//    - esImagenUrl más tolerante
//    - extraerUrls para props tipo objeto/array
//    - imagenesLocales por index O por line_item_id
//    - subirImagenProducto envía line_item_id
// =====================================================

/* =====================================================
  VARIABLES GLOBALES
===================================================== */
let nextPageInfo = null;
let prevPageInfo = null;
let isLoading = false;
let currentPage = 1;
let silentFetch = false; // cuando true, NO muestra loader

// cache local para actualizar estados sin recargar
let ordersCache = [];
let ordersById = new Map();

// LIVE MODE
let liveMode = true;
let liveInterval = null;

let userPingInterval = null;
let userStatusInterval = null;

// evita que un fetch viejo pise uno nuevo
let lastFetchToken = 0;

// protege cambios recientes (evita que LIVE sobrescriba el estado recién guardado)
const dirtyOrders = new Map(); // id -> { until:number, estado:string, last_status_change:{} }
const DIRTY_TTL_MS = 15000; // 15s

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

function getLocalImageUrl(imagenesLocales, key) {
  if (!imagenesLocales || key == null) return "";

  const v = imagenesLocales[key];
  if (!v) return "";

  // string directo
  if (typeof v === "string") return v.trim();

  // objeto {url:"..."} o {value:"..."}
  if (typeof v === "object") {
    const u = (v.url || v.value || "").toString().trim();
    return u;
  }

  return String(v || "").trim();
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

    // CSRF (si existe en tu HTML)
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content");
    const csrfHeader = document.querySelector('meta[name="csrf-header"]')?.getAttribute("content") || "X-CSRF-TOKEN";
    if (csrfToken) headers[csrfHeader] = csrfToken;

    return headers;
}

/* =====================================================
  Loader global
===================================================== */
function showLoader() {
    if (silentFetch) return;
    const el = document.getElementById("globalLoader");
    if (el) el.classList.remove("hidden");
}
function hideLoader() {
    if (silentFetch) return;
    const el = document.getElementById("globalLoader");
    if (el) el.classList.add("hidden");
}

/* =====================================================
  FILTROS
===================================================== */
let filterMode = false;

const FILTERS = {
    q: "",
    estado: "",
    envio: "",
    forma: "",
    desde: "",
    hasta: "",
    total_min: "",
    total_max: "",
    art_min: "",
    art_max: "",
};

function readFiltersFromUI() {
    const v = (id) => (document.getElementById(id)?.value ?? "").toString().trim();

    FILTERS.q = v("f_q_top") || v("f_q");
    FILTERS.estado = v("f_estado");
    FILTERS.envio = v("f_envio");
    FILTERS.forma = v("f_forma");
    FILTERS.desde = v("f_desde");
    FILTERS.hasta = v("f_hasta");
    FILTERS.total_min = v("f_total_min");
    FILTERS.total_max = v("f_total_max");
    FILTERS.art_min = v("f_art_min");
    FILTERS.art_max = v("f_art_max");

    filterMode = hasActiveFilters();
}

function hasActiveFilters() {
    return Object.values(FILTERS).some((val) => String(val ?? "").trim() !== "");
}

function applyFiltersToUrl(u) {
    for (const [k, val] of Object.entries(FILTERS)) {
        const s = String(val ?? "").trim();
        if (s !== "") u.searchParams.set(k, s);
        else u.searchParams.delete(k);
    }
}

function fillFormaEntregaOptionsFromOrders(orders) {
    const sel = document.getElementById("f_forma");
    if (!sel) return;

    const current = String(sel.value ?? "");
    const set = new Set();

    (orders || []).forEach((o) => {
        const s = String(o.forma_envio ?? "").trim();
        if (s && s !== "-") set.add(s);
    });

    const opts = Array.from(set).sort((a, b) => a.localeCompare(b));

    sel.innerHTML =
        `<option value="">Cualquiera</option>` +
        opts
            .map((x) => `<option value="${escapeAttr(x)}">${escapeHtml(x)}</option>`)
            .join("");

    if (current) sel.value = current;
}

function setupFiltersUI() {
    const box = document.getElementById("boxFiltros");
    const toggle = document.getElementById("btnToggleFiltros");

    if (toggle && box) {
        toggle.addEventListener("click", () => box.classList.toggle("hidden"));
    }

    const btnApply = document.getElementById("btnAplicarFiltros");
    const btnClear = document.getElementById("btnLimpiarFiltros");

    const runApply = () => {
        readFiltersFromUI();

        // si hay filtros -> pausa live
        if (filterMode) pauseLive();
        else resumeLiveIfOnFirstPage();

        resetToFirstPage({ withFetch: true });
    };

    const runClear = () => {
        ["f_q", "f_estado", "f_envio", "f_forma", "f_desde", "f_hasta", "f_total_min", "f_total_max", "f_art_min", "f_art_max"]
            .forEach((id) => {
                const el = document.getElementById(id);
                if (el) el.value = "";
            });

        readFiltersFromUI();
        filterMode = false;

        resetToFirstPage({ withFetch: true });
        resumeLiveIfOnFirstPage();
    };

    if (btnApply) btnApply.addEventListener("click", runApply);
    if (btnClear) btnClear.addEventListener("click", runClear);

    const q = document.getElementById("f_q");
    if (q) {
        q.addEventListener("keydown", (e) => {
            if (e.key === "Enter") runApply();
        });
    }

    // ✅ buscador top (siempre visible)
    const qTop = document.getElementById("f_q_top");
    const btnBuscarTop = document.getElementById("btnBuscarTop");

    const runApplyTop = () => {
        readFiltersFromUI();

        if (filterMode) pauseLive();
        else resumeLiveIfOnFirstPage();

        resetToFirstPage({ withFetch: true });
    };

    if (btnBuscarTop) btnBuscarTop.addEventListener("click", runApplyTop);

    if (qTop) {
        qTop.addEventListener("keydown", (e) => {
            if (e.key === "Enter") runApplyTop();
        });
    }
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

    setupFiltersUI();

    // Usuarios online/offline
    pingUsuario();
    userPingInterval = setInterval(pingUsuario, 3600000);

    cargarUsuariosEstado();
    userStatusInterval = setInterval(cargarUsuariosEstado, 150000);

    // Inicial pedidos (página 1)
    resetToFirstPage({ withFetch: true });

    // LIVE refresca la página 1
    startLive(30000);

    // resize: solo re-render (NO registrar listeners)
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
        if (filterMode) return; // si hay filtros activos, NO live
        if (liveMode && currentPage === 1 && !isLoading) {
            silentFetch = true;
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

// ✅ FIX: esImagenUrl más tolerante (URLs sin extensión, rutas relativas, data:)
function esImagenUrl(url) {
    if (!url) return false;
    const u = String(url).trim();

    // data urls
    if (/^data:image\/(png|jpe?g|gif|webp|svg\+xml);base64,/i.test(u)) return true;

    // http(s) con extensión clásica
    if (/^https?:\/\/.+\.(jpeg|jpg|png|gif|webp|svg)(\?.*)?$/i.test(u)) return true;

    // http(s) sin extensión pero con patrones comunes
    if (
        /^https?:\/\/.+/i.test(u) &&
        /(cdn\.shopify\.com|cloudfront|amazonaws|vercel|firebase|imgix|images|upload|storage|files)/i.test(u)
    ) return true;

    // rutas relativas típicas (ajusta a tus rutas si hace falta)
    if (/^(\/uploads\/|\/storage\/|\/files\/|\/img\/)/i.test(u)) return true;

    return false;
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

// ✅ FIX: Extraer URLs desde props tipo string / array / object
function extraerUrls(value) {
    if (value == null) return [];

    if (typeof value === "string") return [value];

    if (Array.isArray(value)) return value.flatMap(extraerUrls);

    if (typeof value === "object") {
        const candidates = [];

        if (value.url) candidates.push(value.url);
        if (value.src) candidates.push(value.src);
        if (value.href) candidates.push(value.href);
        if (value.file) candidates.push(value.file);
        if (value.image) candidates.push(value.image);

        // por si es objeto indexado {0:"...",1:"..."}
        for (const k of Object.keys(value)) {
            const v = value[k];
            if (typeof v === "string") candidates.push(v);
        }

        return candidates.flatMap(extraerUrls);
    }

    return [String(value)];
}

/* =====================================================
  ✅ NORMALIZAR ESTADO (incluye CANCELADO)
===================================================== */
function normalizeEstado(estado) {
    const s = String(estado || "").trim().toLowerCase();

    if (s.includes("por preparar")) return "Por preparar";
    if (s.includes("faltan archivos") || s.includes("faltan_archivos")) return "Faltan archivos";
    if (s.includes("confirmado")) return "Confirmado";
    if (s.includes("diseñado") || s.includes("disenado")) return "Diseñado";
    if (s.includes("por producir")) return "Por producir";
    if (s.includes("enviado")) return "Enviado";
    if (s.includes("repetir")) return "Repetir";

    // ✅ NUEVO: CANCELADO (variantes comunes)
    if (
        s.includes("cancelado") ||
        s.includes("cancelada") ||
        s.includes("canceled") ||
        s.includes("cancelled") ||
        s.includes("anulado") ||
        s.includes("anulada")
    ) return "Cancelado";

    return estado ? String(estado).trim() : "Por preparar";
}

/* =====================================================
  Persistencia de estado (sobrevive recargas)
===================================================== */
const LS_ESTADOS_KEY = "dash_estados_v1"; // { [orderId]: { estado, last_status_change } }

function loadEstadosLS() {
    try {
        const raw = localStorage.getItem(LS_ESTADOS_KEY);
        const obj = raw ? JSON.parse(raw) : {};
        return obj && typeof obj === "object" ? obj : {};
    } catch {
        return {};
    }
}

function saveEstadoLS(orderId, estado, last_status_change) {
    try {
        const all = loadEstadosLS();
        all[String(orderId)] = {
            estado: String(estado ?? ""),
            last_status_change: last_status_change ?? null,
            saved_at: Date.now(),
        };
        localStorage.setItem(LS_ESTADOS_KEY, JSON.stringify(all));
    } catch { }
}

function applyEstadosLSToIncoming(incoming) {
    const all = loadEstadosLS();
    if (!all || typeof all !== "object") return incoming;

    return (incoming || []).map((o) => {
        const id = String(o.id ?? "");
        if (!id || !all[id]) return o;

        const saved = all[id];

        const backendEstado = String(o.estado ?? "").trim();
        const savedEstado = String(saved.estado ?? "").trim();

        const backendEsDefault =
            !backendEstado ||
            backendEstado.toLowerCase() === "por preparar" ||
            backendEstado === "-";

        return {
            ...o,
            estado: backendEsDefault ? (savedEstado || backendEstado) : backendEstado,
            last_status_change: o.last_status_change || saved.last_status_change || null,
        };
    });
}

/* =====================================================
  ESTADO PILL (incluye CANCELADO)
===================================================== */
function estadoStyle(estado) {
    const label = normalizeEstado(estado);
    const s = String(estado || "").toLowerCase().trim();
    const base =
        "inline-flex items-center gap-2 px-3 py-1.5 rounded-2xl border " +
        "text-xs font-extrabold shadow-sm tracking-wide uppercase";

    const dotBase = "h-2.5 w-2.5 rounded-full ring-2 ring-white/40";

    if (s.includes("por preparar")) {
        return { label, icon: "⏳", wrap: `${base} bg-gray-400 border-slate-700 text-white`, dot: `${dotBase} bg-slate-300` };
    }
    if (s.includes("faltan archivos")) {
        return { label, icon: "⚠️", wrap: `${base} bg-yellow-400 border-yellow-500 text-black`, dot: `${dotBase} bg-black/80` };
    }
    if (s.includes("confirmado")) {
        return { label, icon: "✅", wrap: `${base} bg-fuchsia-600 border-fuchsia-700 text-white`, dot: `${dotBase} bg-white` };
    }
    if (s.includes("diseñado") || s.includes("disenado")) {
        return { label: "Diseñados", icon: "🎨", wrap: `${base} bg-blue-600 border-blue-700 text-white`, dot: `${dotBase} bg-sky-200` };
    }
    if (s.includes("por producir")) {
        return { label, icon: "🏗️", wrap: `${base} bg-orange-600 border-orange-700 text-white`, dot: `${dotBase} bg-amber-200` };
    }
    if (s.includes("enviado")) {
        return { label, icon: "🚚", wrap: `${base} bg-emerald-600 border-emerald-700 text-white`, dot: `${dotBase} bg-lime-200` };
    }
    if (s.includes("repetir")) {
        return { label: "Repetir", icon: "🔁", wrap: `${base} bg-slate-800 border-slate-700 text-white`, dot: `${dotBase} bg-slate-300` };
    }
    if (s.includes("cancelado") || s.includes("anulado") || s.includes("canceled") || s.includes("cancelled")) {
        return { label: "Cancelado", icon: "🔁", wrap: `${base} bg-slate-800 border-slate-700 text-white`, dot: `${dotBase} bg-slate-300` };
    }

    return { label: label || "—", icon: "📍", wrap: `${base} bg-slate-700 border-slate-600 text-white`, dot: `${dotBase} bg-slate-200` };
}

function renderEstadoPill(estado) {
    if (esBadgeHtml(estado)) return String(estado);

    const st = estadoStyle(estado);
    return `
    <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-xl border ${st.wrap}
                shadow-sm font-extrabold text-[10px] uppercase tracking-wide whitespace-nowrap">
      <span class="h-2 w-2 rounded-full ${st.dot}"></span>
      <span class="text-sm leading-none">${st.icon}</span>
      <span class="leading-none">${escapeHtml(st.label)}</span>
    </span>
  `;
}

/* =====================================================
  ENTREGA PILL
===================================================== */
function entregaStyle(estadoEnvio) {
    const raw = String(estadoEnvio ?? "").trim();
    const s = raw.toLowerCase();

    const base =
        "inline-flex items-center gap-2 px-3 py-1.5 rounded-2xl border " +
        "text-xs font-extrabold shadow-sm tracking-wide uppercase";

    const dotBase = "h-2.5 w-2.5 rounded-full ring-2 ring-white/40";

    if (!raw || raw === "-" || s === "null" || s === "unfulfilled") {
        return { label: "Pendiente", icon: "📦", wrap: `${base} bg-slate-100 border-slate-200 text-slate-800`, dot: `${dotBase} bg-slate-500` };
    }

    if (s.includes("partial")) {
        return { label: "Parcial", icon: "🟡", wrap: `${base} bg-amber-50 border-amber-200 text-amber-900`, dot: `${dotBase} bg-amber-500` };
    }

    if (s.includes("fulfilled") || s.includes("enviado") || s.includes("entregado") || s.includes("delivered")) {
        return { label: "Enviado", icon: "🚚", wrap: `${base} bg-emerald-50 border-emerald-200 text-emerald-900`, dot: `${dotBase} bg-emerald-500` };
    }

    return { label: raw, icon: "📍", wrap: `${base} bg-slate-50 border-slate-200 text-slate-800`, dot: `${dotBase} bg-slate-500` };
}

function renderEntregaPill(estadoEnvio) {
    const st = entregaStyle(estadoEnvio);
    return `
    <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-xl border ${st.wrap}
                shadow-sm font-extrabold text-[10px] uppercase tracking-wide whitespace-nowrap">
      <span class="h-2 w-2 rounded-full ${st.dot}"></span>
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

    setPaginaUI({ totalPages: null });
    actualizarControlesPaginacion();

    if (withFetch) cargarPedidos({ reset: true, page_info: "" });
}

/* =====================================================
  CARGAR PEDIDOS
===================================================== */
function cargarPedidos({ page_info = "", reset = false } = {}) {
    if (isLoading) return;
    isLoading = true;
    showLoader();

    const fetchToken = ++lastFetchToken;

    readFiltersFromUI();

    const base = filterMode
        ? (window.API?.filter || apiUrl("/dashboard/filter"))
        : (window.API?.pedidos || apiUrl("/dashboard/pedidos"));

    const fallback = window.API?.filter || apiUrl("/dashboard/filter");

    if (reset) {
        currentPage = 1;
        nextPageInfo = null;
        prevPageInfo = null;
        page_info = "";
    }

    const buildUrl = (endpoint) => {
        const u = new URL(endpoint, window.location.origin);
        u.searchParams.set("page", String(currentPage));

        if (!filterMode && page_info) u.searchParams.set("page_info", page_info);
        if (filterMode) applyFiltersToUrl(u);

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

            fillFormaEntregaOptionsFromOrders(incoming);

            const info = document.getElementById("filtersInfo");
            if (info) {
                const total = data.total_orders ?? data.count ?? incoming.length;
                info.textContent = filterMode ? `Filtrado: ${incoming.length} / ${total}` : "";
            }

            incoming = applyEstadosLSToIncoming(incoming);

            // dirty protection
            const now = Date.now();
            incoming = incoming.map((o) => {
                const id = String(o.id ?? "");
                if (!id) return o;

                const dirty = dirtyOrders.get(id);
                if (dirty && dirty.until > now) return { ...o, estado: dirty.estado, last_status_change: dirty.last_status_change };
                if (dirty) dirtyOrders.delete(id);
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

            const totalEl = document.getElementById("total-pedidos");
            if (totalEl) totalEl.textContent = String(data.total_orders ?? data.count ?? 0);

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
            silentFetch = false;
            hideLoader();
        });
}

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
  ÚLTIMO CAMBIO (compacto)
===================================================== */
function parseDateSafe(dtStr) {
    if (!dtStr) return null;
    if (dtStr instanceof Date) return isNaN(dtStr) ? null : dtStr;

    let s = String(dtStr).trim();
    if (!s) return null;

    if (/^\d+$/.test(s)) {
        const n = Number(s);
        if (!isNaN(n)) {
            const ms = s.length <= 10 ? n * 1000 : n;
            const d = new Date(ms);
            return isNaN(d) ? null : d;
        }
    }

    if (/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}(:\d{2})?/.test(s)) {
        s = s.replace(" ", "T");
    }

    const d = new Date(s);
    return isNaN(d) ? null : d;
}

function formatDateTime(dtStr) {
    const d = parseDateSafe(dtStr);
    if (!d) return "—";
    const pad = (n) => String(n).padStart(2, "0");
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function normalizeLastStatusChange(raw) {
    if (!raw) return null;

    if (typeof raw === "string") {
        const t = raw.trim();
        if (t.startsWith("{") && t.endsWith("}")) {
            try { return JSON.parse(t); } catch { return null; }
        }
        return { user_name: null, changed_at: raw };
    }

    if (typeof raw === "object") {
        return {
            user_name: raw.user_name ?? raw.user ?? raw.nombre ?? raw.name ?? null,
            changed_at: raw.changed_at ?? raw.date ?? raw.datetime ?? raw.updated_at ?? null,
        };
    }

    return null;
}

function renderLastChangeCompact(p) {
    const info = normalizeLastStatusChange(p?.last_status_change);
    if (!info?.changed_at) return "—";

    const exact = formatDateTime(info.changed_at);
    if (exact === "—") return "—";

    const user = info.user_name ? escapeHtml(String(info.user_name).toUpperCase()) : "—";

    return `
    <div class="leading-tight min-w-0 pointer-events-none select-none">
      <div class="text-[12px] font-extrabold text-slate-900 truncate">${user}</div>
      <div class="text-[11px] text-slate-600 whitespace-nowrap">${escapeHtml(exact)}</div>
    </div>
  `;
}

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
                        const idStr = String(p.id ?? "");

                        const clickNumero = `onclick="verDetalles('${escapeJsString(idStr)}')"`;
                        const clickCliente = `onclick="verDetalles('${escapeJsString(idStr)}')"`;

                        return `
            <div class="orders-grid cols px-4 py-3 text-[13px] border-b hover:bg-slate-50 transition">

              <!-- ✅ CLICK EN NÚMERO -->
              <div class="font-extrabold text-slate-900 whitespace-nowrap">
                <button type="button"
                  ${clickNumero}
                  class="text-left font-extrabold text-slate-900 hover:underline underline-offset-2 cursor-pointer">
                  ${escapeHtml(p.numero ?? "-")}
                </button>
              </div>

              <div class="text-slate-600 whitespace-nowrap">${escapeHtml(p.fecha ?? "-")}</div>

              <!-- ✅ CLICK EN NOMBRE (en tu caso es CLIENTE) -->
              <div class="min-w-0 font-semibold text-slate-800 truncate">
                <button type="button"
                  ${clickCliente}
                  class="text-left min-w-0 truncate font-semibold text-slate-800 hover:underline underline-offset-2 cursor-pointer">
                  ${escapeHtml(p.cliente ?? "-")}
                </button>
              </div>

              <div class="min-w-0 text-xs text-slate-700 metodo-entrega">${escapeHtml(p.forma_envio ?? "-")}</div>

              <div class="whitespace-nowrap relative z-10">
                <button type="button"
                  onclick="abrirModal('${escapeJsString(idStr)}')"
                  class="group inline-flex items-center gap-1 rounded-xl px-1 py-0.5 bg-transparent hover:bg-slate-100 transition focus:outline-none"
                  title="Cambiar estado">
                  ${renderEstadoPill(p.estado ?? "-")}
                </button>
              </div>

              <div class="min-w-0">${renderLastChangeCompact(p)}</div>

              <div class="text-center font-extrabold">${escapeHtml(p.articulos ?? "-")}</div>

              <div class="whitespace-nowrap">${renderEntregaPill(p.estado_envio ?? "-")}</div>

              <div class="font-extrabold text-slate-900 whitespace-nowrap">${escapeHtml(p.total ?? "-")}</div>

              <!-- ✅ COLUMNA VACÍA (alineación) -->
              <div class="text-right whitespace-nowrap"></div>
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
                const id = String(p.id ?? "");

                const last = p?.last_status_change?.changed_at
                    ? `${escapeHtml(String(p.last_status_change.user_name ?? "—").toUpperCase())} · ${escapeHtml(
                        formatDateTime(p.last_status_change.changed_at)
                    )}`
                    : "—";

                return `
        <div class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden mb-3">
          <div class="p-4">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">

                <!-- ✅ CLICK EN NÚMERO -->
                <button type="button"
                  onclick="verDetalles('${escapeJsString(id)}')"
                  class="text-left text-sm font-extrabold text-slate-900 hover:underline underline-offset-2 cursor-pointer">
                  ${escapeHtml(p.numero ?? "-")}
                </button>

                <div class="text-xs text-slate-500 mt-0.5">${escapeHtml(p.fecha ?? "-")}</div>

                <!-- ✅ CLICK EN NOMBRE (CLIENTE) -->
                <button type="button"
                  onclick="verDetalles('${escapeJsString(id)}')"
                  class="text-left text-sm font-semibold text-slate-800 mt-1 truncate hover:underline underline-offset-2 cursor-pointer">
                  ${escapeHtml(p.cliente ?? "-")}
                </button>
              </div>

              <div class="text-right whitespace-nowrap">
                <div class="text-xs font-extrabold text-slate-700 truncate">${escapeHtml(p.forma_envio ?? "-")}</div>
              </div>
            </div>

            <div class="mt-3 flex items-center justify-between gap-3">
              <button onclick="abrirModal('${escapeJsString(id)}')"
                class="inline-flex items-center gap-2 rounded-2xl bg-transparent border-0 p-0 relative z-10">
                ${renderEstadoPill(p.estado ?? "-")}
              </button>
            </div>

            <div class="mt-3">${renderEntregaPill(p.estado_envio ?? "-")}</div>

            <div class="mt-3 text-xs text-slate-600 space-y-1">
              <div><b>Artículos:</b> ${escapeHtml(p.articulos ?? "-")}</div>
              <div><b>Total:</b> ${escapeHtml(p.total ?? "-")}</div>
              <div><b>Último cambio:</b> ${last}</div>
            </div>
          </div>
        </div>
      `;
            })
            .join("");
    }
}

/* =====================================================
  MODAL ESTADO (tu código sigue igual)
===================================================== */
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

/* =====================================================
  ✅ GUARDAR ESTADO (LOCAL INSTANT + BACKEND + REVERT)
===================================================== */
async function guardarEstado(nuevoEstado) {
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
    saveEstadoLS(id, nuevoEstado, optimisticLast);

    window.cerrarModal?.();

    try {
        const endpoints = [
            window.API?.guardarEstado,
            apiUrl("/api/estado/guardar"),
            "/api/estado/guardar",
            "/index.php/api/estado/guardar",
            "/index.php/index.php/api/estado/guardar",
            apiUrl("/index.php/api/estado/guardar"),
            apiUrl("/index.php/index.php/api/estado/guardar"),
        ].filter(Boolean);

        let lastErr = null;

        for (const url of endpoints) {
            try {
                const r = await fetch(url, {
                    method: "POST",
                    headers: jsonHeaders(),
                    credentials: "same-origin",
                    body: JSON.stringify({
                        order_id: String(id),
                        id: String(id),
                        estado: String(nuevoEstado),
                    }),
                });

                if (r.status === 404) continue;

                const d = await r.json().catch(() => null);

                if (!r.ok || !d?.success) {
                    throw new Error(d?.message || `HTTP ${r.status}`);
                }

                // 3) Sync desde backend (si viene)
                if (d?.order && order) {
                    order.estado = d.order.estado ?? order.estado;
                    order.last_status_change = d.order.last_status_change ?? order.last_status_change;
                    actualizarTabla(ordersCache);

                    dirtyOrders.set(id, {
                        until: Date.now() + DIRTY_TTL_MS,
                        estado: order.estado,
                        last_status_change: order.last_status_change,
                    });
                    saveEstadoLS(id, order.estado, order.last_status_change);
                }

                if (currentPage === 1) cargarPedidos({ reset: false, page_info: "" });

                // Notificar cross-tab
                try {
                    const msg = { type: "estado_changed", order_id: String(id), estado: String(nuevoEstado), ts: Date.now() };
                    if ("BroadcastChannel" in window) {
                        const bc = new BroadcastChannel("panel_pedidos");
                        bc.postMessage(msg);
                        bc.close();
                    }
                    localStorage.setItem("pedido_estado_changed", JSON.stringify(msg));
                } catch { }

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

window.guardarEstado = guardarEstado;

/* =====================================================
  DETALLES (FULL SCREEN)
===================================================== */
function $(id) {
    return document.getElementById(id);
}

function setHtml(id, html) {
    const el = $(id);
    if (!el) return false;
    el.innerHTML = html;
    return true;
}

function setText(id, txt) {
    const el = $(id);
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

// Helpers items
function isLlaveroItem(item) {
    const title = String(item?.title || item?.name || "").toLowerCase();
    const productType = String(item?.product_type || "").toLowerCase();
    const sku = String(item?.sku || "").toLowerCase();

    return title.includes("llavero") || productType.includes("llavero") || sku.includes("llav");
}

function requiereImagenModificada(item) {
    const props = Array.isArray(item?.properties) ? item.properties : [];

    const tieneImagenEnProps = props.some((p) => {
        const urls = extraerUrls(p?.value);
        return urls.some((u) => esImagenUrl(u));
    });

    const tieneCamposImagen =
        esImagenUrl(item?.image_original) ||
        esImagenUrl(item?.image_url) ||
        esImagenUrl(item?.imagen_original) ||
        esImagenUrl(item?.imagen_url);

    if (isLlaveroItem(item)) return true;

    return tieneImagenEnProps || tieneCamposImagen;
}

function totalLinea(price, qty) {
    const p = Number(price);
    const q = Number(qty);
    if (isNaN(p) || isNaN(q)) return null;
    return (p * q).toFixed(2);
}

// =====================================================
// DETALLES (SIN ETIQUETAS)
// =====================================================
window.verDetalles = async function (orderId) {
    const id = String(orderId || "");
    if (!id) return;

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

    try {
        const url = typeof apiUrl === "function"
            ? apiUrl(`/dashboard/detalles/${encodeURIComponent(id)}`)
            : `/index.php/dashboard/detalles/${encodeURIComponent(id)}`;

        const r = await fetch(url, { headers: { Accept: "application/json" } });
        const d = await r.json().catch(() => null);

        if (!r.ok || !d || d.success !== true) {
            setHtml("detItems", `<div class="text-rose-600 font-extrabold">Error cargando detalles.</div>`);
            if (pre) pre.textContent = JSON.stringify({ http: r.status, payload: d }, null, 2);
            return;
        }

        if (pre) pre.textContent = JSON.stringify(d, null, 2);

        const o = d.order || {};
        const lineItems = Array.isArray(o.line_items) ? o.line_items : [];

        const imagenesLocales = d.imagenes_locales || {};
        const productImages = d.product_images || {};

        // Header
        setText("detTitle", `Pedido ${o.name || ("#" + id)}`);

        const clienteNombre = o.customer
            ? `${o.customer.first_name || ""} ${o.customer.last_name || ""}`.trim()
            : "";

        setText("detSubtitle", clienteNombre ? clienteNombre : (o.email || "—"));

        // Cliente
        setHtml("detCliente", `
      <div class="space-y-2">
        <div class="font-extrabold text-slate-900">${escapeHtml(clienteNombre || "—")}</div>
        <div><span class="text-slate-500">Email:</span> ${escapeHtml(o.email || "—")}</div>
        <div><span class="text-slate-500">Tel:</span> ${escapeHtml(o.phone || "—")}</div>
        <div><span class="text-slate-500">ID:</span> ${escapeHtml(o.customer?.id || "—")}</div>
      </div>
    `);

        // Envío
        const a = o.shipping_address || {};
        setHtml("detEnvio", `
      <div class="space-y-1">
        <div class="font-extrabold text-slate-900">${escapeHtml(a.name || "—")}</div>
        <div>${escapeHtml(a.address1 || "")}</div>
        <div>${escapeHtml(a.address2 || "")}</div>
        <div>${escapeHtml((a.zip || "") + " " + (a.city || ""))}</div>
        <div>${escapeHtml(a.province || "")}</div>
        <div>${escapeHtml(a.country || "")}</div>
        <div class="pt-2"><span class="text-slate-500">Tel envío:</span> ${escapeHtml(a.phone || "—")}</div>
      </div>
    `);

        // Totales
        const envio =
            o.total_shipping_price_set?.shop_money?.amount ??
            o.total_shipping_price_set?.presentment_money?.amount ??
            "0";
        const impuestos = o.total_tax ?? "0";

        setHtml("detTotales", `
      <div class="space-y-1">
        <div><b>Subtotal:</b> ${escapeHtml(o.subtotal_price || "0")} €</div>
        <div><b>Envío:</b> ${escapeHtml(envio)} €</div>
        <div><b>Impuestos:</b> ${escapeHtml(impuestos)} €</div>
        <div class="text-lg font-extrabold"><b>Total:</b> ${escapeHtml(o.total_price || "0")} €</div>
      </div>
    `);

        // Resumen (SIN ETIQUETAS)
        setHtml("detResumen", `
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
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
    `);

        // Productos
        setText("detItemsCount", String(lineItems.length));

        if (!lineItems.length) {
            setHtml("detItems", `<div class="text-slate-500">Este pedido no tiene productos.</div>`);
            return;
        }

        window.imagenesLocales = imagenesLocales || {};
        window.imagenesCargadas = new Array(lineItems.length).fill(false);
        window.imagenesRequeridas = new Array(lineItems.length).fill(false);

        const itemsHtml = lineItems.map((item, index) => {
            const props = Array.isArray(item.properties) ? item.properties : [];

            const propsImg = [];
            const propsTxt = [];

            for (const p of props) {
                const name = String(p?.name ?? "").trim() || "Campo";
                const value = p?.value;

                const urls = extraerUrls(value).map((x) => String(x || "").trim()).filter(Boolean);
                const imgs = urls.filter(esImagenUrl);

                if (imgs.length) {
                    imgs.forEach((img) => propsImg.push({ name, value: img }));
                } else {
                    const txt = (value === null || value === undefined)
                        ? ""
                        : (typeof value === "object" ? JSON.stringify(value) : String(value));
                    propsTxt.push({ name, value: txt });
                }
            }

            const requiere = requiereImagenModificada(item);

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

            // ✅ FIX: imagen modificada puede venir por index o por line_item_id
            const lineId = String(item.id || item.line_item_id || item.variant_id || "");
            const localUrl =
                (lineId && getLocalImageUrl(imagenesLocales, lineId)) ||
                getLocalImageUrl(imagenesLocales, index) ||
                getLocalImageUrl(imagenesLocales, String(index)) ||
                "";


            window.imagenesRequeridas[index] = !!requiere;
            window.imagenesCargadas[index] = esImagenUrl(localUrl);


            const estadoItem = requiere ? (localUrl ? "LISTO" : "FALTA") : "NO REQUIERE";
            const badgeCls =
                estadoItem === "LISTO"
                    ? "bg-emerald-50 border-emerald-200 text-emerald-900"
                    : estadoItem === "FALTA"
                        ? "bg-amber-50 border-amber-200 text-amber-900"
                        : "bg-slate-50 border-slate-200 text-slate-700";
            const badgeText =
                estadoItem === "LISTO" ? "Listo" : estadoItem === "FALTA" ? "Falta imagen" : "Sin imagen";

            const propsTxtHtml = propsTxt.length
                ? `
          <div class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 p-3">
            <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500 mb-2">Personalización</div>
            <div class="space-y-1 text-sm">
              ${propsTxt.map(({ name, value }) => {
                    const safeV = escapeHtml(value || "—");
                    const safeName = escapeHtml(name);

                    const val = esUrl(value)
                        ? `<a href="${escapeHtml(value)}" target="_blank" class="underline font-semibold text-slate-900">${safeV}</a>`
                        : `<span class="font-semibold text-slate-900 break-words">${safeV}</span>`;

                    return `
                  <div class="flex gap-2">
                    <div class="min-w-[130px] text-slate-500 font-bold">${safeName}:</div>
                    <div class="flex-1">${val}</div>
                  </div>
                `;
                }).join("")}
            </div>
          </div>
        `
                : "";

            const propsImgsHtml = propsImg.length
                ? `
          <div class="mt-3">
            <div class="text-xs font-extrabold text-slate-500 mb-2">Imagen original (cliente)</div>
            <div class="flex flex-wrap gap-3">
              ${propsImg.map(({ name, value }) => `
                <a href="${escapeHtml(value)}" target="_blank"
                  class="block rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
                  <img src="${escapeHtml(value)}" class="h-28 w-28 object-cover">
                  <div class="px-3 py-2 text-xs font-bold text-slate-700 bg-white border-t border-slate-200">
                    ${escapeHtml(name)}
                  </div>
                </a>
              `).join("")}
            </div>
          </div>
        `
                : "";

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
          ${lineId ? `<div><span class="text-slate-500 font-bold">Line Item ID:</span> <span class="font-semibold">${escapeHtml(lineId)}</span></div>` : ""}
        </div>
      `;

            // ✅ FIX: Pasar line_item_id al subir
            const uploadHtml = requiere
                ? `
          <div class="mt-4">
            <div class="text-xs font-extrabold text-slate-500 mb-2">Subir imagen modificada</div>
            <input type="file" accept="image/*"
              onchange="subirImagenProducto('${escapeJsString(id)}', ${index}, '${escapeJsString(lineId)}', this)"
              class="w-full border border-slate-200 rounded-2xl p-2">
            <div id="preview_${escapeAttr(id)}_${index}" class="mt-2"></div>
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
        }).join("");

        setHtml("detItems", itemsHtml);
    } catch (e) {
        console.error("verDetalles error:", e);
        setHtml("detItems", `<div class="text-rose-600 font-extrabold">Error de red cargando detalles.</div>`);
    }
};

// ===============================
// SUBIR IMAGEN MODIFICADA (ROBUSTO)
// ✅ FIX: recibe lineItemId y lo manda al backend
// ===============================
window.subirImagenProducto = async function (orderId, index, lineItemId, input) {
    try {
        const file = input?.files?.[0];
        if (!file) return;

        const fd = new FormData();
        fd.append("order_id", String(orderId));
        fd.append("line_index", String(index));
        if (lineItemId) fd.append("line_item_id", String(lineItemId));
        fd.append("file", file);

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content");
        const csrfHeader = document.querySelector('meta[name="csrf-header"]')?.getAttribute("content") || "X-CSRF-TOKEN";

        const endpoints = [
        apiUrl("/dashboard/subir-imagen-modificada"),
        "/dashboard/subir-imagen-modificada",
        "/index.php/dashboard/subir-imagen-modificada",
        apiUrl("/index.php/dashboard/subir-imagen-modificada"),
        ];



        let lastErr = null;

        for (const url of endpoints) {
            try {
                const headers = {};
                if (csrfToken) headers[csrfHeader] = csrfToken;

                const r = await fetch(url, {
                    method: "POST",
                    headers,
                    body: fd,
                    credentials: "same-origin",
                });

                if (r.status === 404) continue;

                if (r.status === 401 || r.status === 403) {
                    throw new Error("No autenticado. Tu sesión venció (401/403). Recarga el panel y vuelve a iniciar sesión.");
                }

                const ct = (r.headers.get("content-type") || "").toLowerCase();
                let d = null;
                let rawText = "";

                if (ct.includes("application/json")) {
                    d = await r.json().catch(() => null);
                } else {
                    rawText = await r.text().catch(() => "");
                    if (rawText.trim().startsWith("<!doctype") || rawText.trim().startsWith("<html")) {
                        throw new Error("El servidor devolvió HTML (probable login / sesión expirada). Recarga el panel.");
                    }
                    d = { success: true, url: rawText.trim() };
                }

                const success = (d && (d.success === true || typeof d.url === "string"));
                const urlFinal = d?.url ? String(d.url) : "";

                if (!r.ok || !success || !urlFinal) {
                    throw new Error(d?.message || `Respuesta inválida del servidor (HTTP ${r.status}).`);
                }

                const previewId = `preview_${orderId}_${index}`;
                const prev = document.getElementById(previewId);
                if (prev) {
                    prev.innerHTML = `
            <div class="mt-2">
              <div class="text-xs font-extrabold text-slate-500">Imagen modificada subida ✅</div>
              <img src="${urlFinal}" class="mt-2 w-44 rounded-2xl border border-slate-200 shadow-sm object-cover">
            </div>
          `;
                }

                if (!Array.isArray(window.imagenesCargadas)) window.imagenesCargadas = [];
                if (!Array.isArray(window.imagenesRequeridas)) window.imagenesRequeridas = [];

                window.imagenesCargadas[index] = true;

                if (window.imagenesLocales && typeof window.imagenesLocales === "object") {
                    // ✅ guardar por line_item_id si existe, si no por index
                    if (lineItemId) window.imagenesLocales[String(lineItemId)] = urlFinal;
                    window.imagenesLocales[index] = urlFinal;
                }

                if (typeof window.validarEstadoAuto === "function") {
                    window.validarEstadoAuto(orderId);
                }

                return;
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

// =====================================
// AUTO-ESTADO
// =====================================
window.validarEstadoAuto = async function (orderId) {
    try {
        const oid = String(orderId || "");
        if (!oid) return;

        const req = Array.isArray(window.imagenesRequeridas) ? window.imagenesRequeridas : [];
        const ok = Array.isArray(window.imagenesCargadas) ? window.imagenesCargadas : [];

        const requiredIdx = req.map((v, i) => (v ? i : -1)).filter(i => i >= 0);
        const requiredCount = requiredIdx.length;

        if (requiredCount < 1) return;

        const uploadedCount = requiredIdx.filter(i => ok[i] === true).length;
        const faltaAlguna = uploadedCount < requiredCount;

        const nuevoEstado = faltaAlguna ? "Faltan archivos" : "Confirmado";

        const order =
            (window.ordersById && window.ordersById.get && window.ordersById.get(oid)) ||
            (Array.isArray(window.ordersCache) ? window.ordersCache.find(x => String(x.id) === oid) : null);

        const estadoActual = String(order?.estado || "").toLowerCase().trim();
        const nuevoLower = nuevoEstado.toLowerCase();

        // ✅ NO sobrescribir si está CANCELADO
        if (
            estadoActual.includes("cancelado") ||
            estadoActual.includes("cancelada") ||
            estadoActual.includes("canceled") ||
            estadoActual.includes("cancelled") ||
            estadoActual.includes("anulado") ||
            estadoActual.includes("anulada")
        ) return;

        if (
            (nuevoLower.includes("faltan") && (estadoActual.includes("faltan archivos") || estadoActual.includes("faltan_archivos"))) ||
            (nuevoLower.includes("confirmado") && estadoActual.includes("confirmado"))
        ) return;

        let idInput = document.getElementById("modalOrderId");
        if (!idInput) {
            idInput = document.createElement("input");
            idInput.type = "hidden";
            idInput.id = "modalOrderId";
            document.body.appendChild(idInput);
        }
        idInput.value = oid;

        await window.guardarEstado(nuevoEstado);
    } catch (e) {
        console.error("validarEstadoAuto error:", e);
    }
};

/* =====================================================
  USERS STATUS
===================================================== */
async function pingUsuario() {
    try {
        await fetch(apiUrl("/dashboard/ping"), { headers: { Accept: "application/json" } });
    } catch { }
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
      <li class="flex items-center justify-between gap-3 p-3 rounded-2xl border ${mode === "online" ? "border-emerald-200 bg-white/70" : "border-rose-200 bg-white/70"}">
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

// Export seguro
window.DASH = window.DASH || {};
window.DASH.cargarPedidos = cargarPedidos;
window.DASH.resetToFirstPage = resetToFirstPage;

console.log("✅ dashboard.js cargado (SIN ETIQUETAS) - verDetalles hash:", (window.verDetalles ? window.verDetalles.toString().length : "NO verDetalles"));
