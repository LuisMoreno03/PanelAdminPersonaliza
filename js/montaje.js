/**
 * montaje.js (CI4) — FULL
 * - Trae pedidos en estado: Diseñado
 * - Botón "Subir pedido" en listado y dentro del modal
 * - Al click: estado -> Por producir (POST /montaje/subir-pedido)
 * - Detalles FULL usando /dashboard/detalles/{id} (fallback)
 * - (Opcional) upload/list general de archivos en montaje
 */

const API_BASE = String(window.API_BASE || "").replace(/\/$/, "");

const ENDPOINT_QUEUE = `${API_BASE}/montaje/my-queue`;
const ENDPOINT_SUBIR_PEDIDO = `${API_BASE}/montaje/subir-pedido`;

// opcional (si habilitaste rutas)
const ENDPOINT_UPLOAD_GENERAL = `${API_BASE}/montaje/upload-general`;
const ENDPOINT_LIST_GENERAL = `${API_BASE}/montaje/list-general`;

let pedidosCache = [];
let pedidosFiltrados = [];
let isLoading = false;
let liveInterval = null;
let silentFetch = false;

let currentDetallesPedidoId = null;  // p.id (interno)
let currentDetallesShopifyId = null; // shopify_order_id
let currentDetallesOrderId = null;   // el que llegó al abrir (puede ser shopify o interno)
let currentDetallesKeyForPedido = null;

// =========================
// Helpers DOM/UI
// =========================
function $(id) { return document.getElementById(id); }

function setLoader(show) {
  if (silentFetch) return;
  const el = $("globalLoader");
  if (!el) return;
  el.classList.toggle("hidden", !show);
}

function setTotalPedidos(n) {
  document.querySelectorAll("#total-pedidos").forEach((el) => {
    el.textContent = String(n ?? 0);
  });
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

function safeText(v) {
  return (v === null || v === undefined || v === "") ? "" : String(v);
}

function moneyFormat(v) {
  if (v === null || v === undefined || v === "") return "—";
  const num = Number(v);
  if (Number.isNaN(num)) return escapeHtml(String(v));
  try {
    return num.toLocaleString("es-CO", { style: "currency", currency: "COP" });
  } catch {
    return escapeHtml(String(v));
  }
}

function esBadgeHtml(valor) {
  const s = String(valor ?? "").trim();
  return s.startsWith("<span") || s.includes("<span") || s.includes("</span>");
}

// =========================
// Fechas
// =========================
function parseDateSafe(dtStr) {
  if (!dtStr) return null;
  if (dtStr instanceof Date) return isNaN(dtStr) ? null : dtStr;

  let s = String(dtStr).trim();
  if (!s) return null;

  if (/^\d+$/.test(s)) {
    const n = Number(s);
    const ms = s.length <= 10 ? n * 1000 : n;
    const d = new Date(ms);
    return isNaN(d) ? null : d;
  }

  if (/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/.test(s)) s = s.replace(" ", "T");

  const d = new Date(s);
  return isNaN(d) ? null : d;
}

function formatDateTime(dtStr) {
  const d = parseDateSafe(dtStr);
  if (!d) return "—";
  const pad = (n) => String(n).padStart(2, "0");
  return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

// =========================
// Estados (pill)
// =========================
function normalizeEstado(estado) {
  const s = String(estado || "").trim().toLowerCase();
  if (s.includes("por preparar")) return "Por preparar";
  if (s.includes("faltan archivos") || s.includes("faltan_archivos")) return "Faltan archivos";
  if (s.includes("confirmado")) return "Confirmado";
  if (s.includes("diseñado") || s.includes("disenado")) return "Diseñado";
  if (s.includes("por producir")) return "Por producir";
  if (s.includes("fabricando")) return "Fabricando";
  if (s.includes("enviado")) return "Enviado";
  if (s.includes("repetir")) return "Repetir";
  return estado ? String(estado).trim() : "Por preparar";
}

function estadoStyle(estado) {
  const label = normalizeEstado(estado);
  const s = String(estado || "").toLowerCase().trim();
  const base =
    "inline-flex items-center gap-2 px-3 py-1.5 rounded-2xl border " +
    "text-xs font-extrabold shadow-sm tracking-wide uppercase";
  const dotBase = "h-2.5 w-2.5 rounded-full ring-2 ring-white/40";

  if (s.includes("diseñado") || s.includes("disenado")) {
    return { label, icon: "🎨", wrap: `${base} bg-blue-600 border-blue-700 text-white`, dot: `${dotBase} bg-sky-200` };
  }
  if (s.includes("por producir")) {
    return { label, icon: "🏗️", wrap: `${base} bg-orange-600 border-orange-700 text-white`, dot: `${dotBase} bg-amber-200` };
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

// =========================
// Último cambio
// =========================
function normalizeLastStatusChange(raw) {
  if (!raw) return null;
  if (typeof raw === "string") {
    const t = raw.trim();
    if (t.startsWith("{") && t.endsWith("}")) { try { return JSON.parse(t); } catch { return null; } }
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
  const user = info.user_name ? escapeHtml(info.user_name) : "—";
  return `
    <div class="leading-tight min-w-0 pointer-events-none select-none">
      <div class="text-[12px] font-extrabold text-slate-900 truncate">${user}</div>
      <div class="text-[11px] text-slate-600 whitespace-nowrap">${escapeHtml(exact)}</div>
    </div>
  `;
}

// =========================
// Etiquetas mini
// =========================
function renderEtiquetasMini(etiquetasRaw) {
  const raw = String(etiquetasRaw || "").trim();
  const list = raw ? raw.split(",").map(t => t.trim()).filter(Boolean) : [];
  if (!list.length) return `<span class="text-slate-400">—</span>`;

  const max = 5;
  const visibles = list.slice(0, max);
  const rest = list.length - visibles.length;

  const pills = visibles.map((tag) => `<span class="tag-mini">${escapeHtml(tag)}</span>`).join("");
  const more = rest > 0 ? `<span class="tag-mini">+${rest}</span>` : "";

  return `<div class="tags-wrap-mini">${pills}${more}</div>`;
}

// =========================
// CSRF headers
// =========================
function getCsrfHeaders() {
  const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content");
  const header = document.querySelector('meta[name="csrf-header"]')?.getAttribute("content");
  if (!token || !header) return {};
  return { [header]: token };
}

// =========================
// API helpers
// =========================
async function apiGet(url) {
  const res = await fetch(url, { method: "GET", headers: { Accept: "application/json" }, credentials: "same-origin" });
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch { data = null; }
  return { res, data, raw: text };
}

async function apiPost(url, payload) {
  const headers = {
    "Content-Type": "application/json",
    Accept: "application/json",
    ...getCsrfHeaders(),
  };
  const res = await fetch(url, {
    method: "POST",
    headers,
    body: JSON.stringify(payload ?? {}),
    credentials: "same-origin",
  });
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch { data = null; }
  return { res, data, raw: text };
}

function extractOrdersPayload(payload) {
  if (!payload || typeof payload !== "object") return { ok: false, orders: [] };
  if (payload.success === true) return { ok: true, orders: Array.isArray(payload.orders) ? payload.orders : [] };
  if (payload.ok === true) return { ok: true, orders: Array.isArray(payload.data) ? payload.data : [] };
  return { ok: false, orders: [] };
}

// =========================
// Render según breakpoint
// =========================
function getMode() {
  const w = window.innerWidth || 0;
  if (w >= 1536) return "grid";
  if (w >= 1280) return "table";
  return "cards";
}

// =========================
// Botón “Subir pedido” (listado)
// =========================
window.subirPedidoDesdeListado = function(orderKey, internalId, shopifyId) {
  subirPedidoAPorProducir({
    order_key: String(orderKey || ""),
    pedido_id: String(internalId || ""),
    shopify_order_id: String(shopifyId || ""),
    closeModal: false,
  });
};

function renderBtnSubirPedido({ internalId, shopifyId }) {
  const orderKey = (shopifyId && shopifyId !== "0") ? shopifyId : internalId;
  return `
    <button type="button"
      onclick="subirPedidoDesdeListado('${escapeJsString(orderKey)}','${escapeJsString(internalId)}','${escapeJsString(shopifyId)}')"
      class="h-9 px-3 rounded-2xl bg-emerald-600 text-white text-[11px] font-extrabold uppercase tracking-wide hover:bg-emerald-700 transition">
      Subir pedido ⬆️
    </button>
  `;
}

// =========================
// Listado UI
// =========================
function actualizarListado(pedidos) {
  const mode = getMode();

  window.ordersCache = pedidos || [];
  window.ordersById = new Map((pedidos || []).map(o => [String(o.id), o]));
  window.ordersByShopify = new Map((pedidos || []).map(o => [String(o.shopify_order_id || ""), o]).filter(([k]) => k && k !== "0"));

  const contGrid = $("tablaPedidos");
  const contTable = $("tablaPedidosTable");
  const contCards = $("cardsPedidos");

  if (contGrid) contGrid.innerHTML = "";
  if (contTable) contTable.innerHTML = "";
  if (contCards) contCards.innerHTML = "";

  // GRID
  if (mode === "grid") {
    if (contGrid) contGrid.classList.remove("hidden");
    if (contTable) contTable.classList.add("hidden");
    if (contCards) contCards.classList.add("hidden");
    if (!contGrid) return;

    if (!pedidos || !pedidos.length) {
      contGrid.innerHTML = `<div class="p-8 text-center text-slate-500">No hay pedidos en Diseñado.</div>`;
      return;
    }

    contGrid.innerHTML = pedidos.map((p) => {
      const internalId = String(p.id ?? "");
      const shopifyId = String(p.shopify_order_id ?? "");
      const idDetalles = (shopifyId && shopifyId !== "0") ? shopifyId : internalId;

      const numero = String(p.numero ?? ("#" + internalId));
      const fecha = p.fecha ?? p.created_at ?? "—";
      const cliente = p.cliente ?? p.customer_name ?? "—";
      const total = p.total ?? "";
      const estado = p.estado ?? p.estado_bd ?? "Diseñado";
      const etiquetas = p.etiquetas ?? "";

      const subirBtn = renderBtnSubirPedido({ internalId, shopifyId });

      return `
        <div class="grid prod-grid-cols items-center gap-3 px-4 py-3 text-[13px]
                    border-b border-slate-200 hover:bg-slate-50 transition">

          <div class="font-extrabold text-slate-900 whitespace-nowrap">${escapeHtml(numero)}</div>
          <div class="text-slate-600 whitespace-nowrap">${escapeHtml(String(fecha || "—"))}</div>
          <div class="min-w-0 font-semibold text-slate-800 truncate">${escapeHtml(String(cliente || "—"))}</div>
          <div class="font-extrabold text-slate-900 whitespace-nowrap text-right">${moneyFormat(total)}</div>

          <div class="whitespace-nowrap">${renderEstadoPill(estado)}</div>
          <div class="min-w-0">${renderLastChangeCompact(p)}</div>

          <div class="text-center font-extrabold">${escapeHtml(String(p.articulos ?? "-"))}</div>
          <div class="whitespace-nowrap text-slate-700">${escapeHtml(String(p.estado_envio ?? "-"))}</div>
          <div class="min-w-0 text-xs text-slate-700 truncate">${escapeHtml(String(p.forma_envio ?? "—"))}</div>

          <div class="flex justify-end gap-2">
            ${subirBtn}
            <button type="button" onclick="verDetallesPedido('${escapeJsString(idDetalles)}')"
              class="h-9 px-3 rounded-2xl bg-blue-600 text-white text-[11px] font-extrabold uppercase tracking-wide hover:bg-blue-700 transition">
              Ver detalles →
            </button>
          </div>

        </div>
      `;
    }).join("");
    return;
  }

  // "TABLE" (misma idea)
  if (mode === "table") {
    if (contGrid) contGrid.classList.add("hidden");
    if (contTable) contTable.classList.remove("hidden");
    if (contCards) contCards.classList.add("hidden");
    if (!contTable) return;

    if (!pedidos || !pedidos.length) {
      contTable.innerHTML = `<div class="p-8 text-center text-slate-500">No hay pedidos en Diseñado.</div>`;
      return;
    }

    contTable.innerHTML = pedidos.map((p) => {
      const internalId = String(p.id ?? "");
      const shopifyId = String(p.shopify_order_id ?? "");
      const idDetalles = (shopifyId && shopifyId !== "0") ? shopifyId : internalId;

      const numero = String(p.numero ?? ("#" + internalId));
      const fecha = p.fecha ?? p.created_at ?? "—";
      const cliente = p.cliente ?? p.customer_name ?? "—";
      const total = p.total ?? "";
      const estado = p.estado ?? p.estado_bd ?? "Diseñado";

      const subirBtn = renderBtnSubirPedido({ internalId, shopifyId });

      return `
        <div class="grid prod-grid-cols items-center gap-3 px-4 py-3 text-[13px]
                    border-b border-slate-200 hover:bg-slate-50 transition">
          <div class="font-extrabold text-slate-900 whitespace-nowrap">${escapeHtml(numero)}</div>
          <div class="text-slate-600 whitespace-nowrap">${escapeHtml(String(fecha || "—"))}</div>
          <div class="min-w-0 font-semibold text-slate-800 truncate">${escapeHtml(String(cliente || "—"))}</div>
          <div class="font-extrabold text-slate-900 whitespace-nowrap text-right">${moneyFormat(total)}</div>

          <div class="whitespace-nowrap">${renderEstadoPill(estado)}</div>
          <div class="min-w-0">${renderLastChangeCompact(p)}</div>

          <div class="text-center font-extrabold">${escapeHtml(String(p.articulos ?? "-"))}</div>
          <div class="whitespace-nowrap text-slate-700">${escapeHtml(String(p.estado_envio ?? "-"))}</div>
          <div class="min-w-0 text-xs text-slate-700 truncate">${escapeHtml(String(p.forma_envio ?? "—"))}</div>

          <div class="flex justify-end gap-2">
            ${subirBtn}
            <button type="button" onclick="verDetallesPedido('${escapeJsString(idDetalles)}')"
              class="h-9 px-3 rounded-2xl bg-blue-600 text-white text-[11px] font-extrabold uppercase tracking-wide hover:bg-blue-700 transition">
              Ver detalles →
            </button>
          </div>
        </div>
      `;
    }).join("");
    return;
  }

  // CARDS
  if (contGrid) contGrid.classList.add("hidden");
  if (contTable) contTable.classList.add("hidden");
  if (contCards) contCards.classList.remove("hidden");
  if (!contCards) return;

  if (!pedidos || !pedidos.length) {
    contCards.innerHTML = `<div class="p-8 text-center text-slate-500">No hay pedidos en Diseñado.</div>`;
    return;
  }

  contCards.innerHTML = pedidos.map((p) => {
    const internalId = String(p.id ?? "");
    const shopifyId = String(p.shopify_order_id ?? "");
    const idDetalles = (shopifyId && shopifyId !== "0") ? shopifyId : internalId;

    const numero = String(p.numero ?? ("#" + internalId));
    const fecha = p.fecha ?? p.created_at ?? "—";
    const cliente = p.cliente ?? p.customer_name ?? "—";
    const total = p.total ?? "";
    const estado = p.estado ?? p.estado_bd ?? "Diseñado";
    const etiquetas = p.etiquetas ?? "";

    const subirBtn = renderBtnSubirPedido({ internalId, shopifyId });

    return `
      <div class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden mb-3">
        <div class="p-4">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="text-sm font-extrabold text-slate-900">${escapeHtml(numero)}</div>
              <div class="text-xs text-slate-500 mt-0.5">${escapeHtml(String(fecha || "—"))}</div>
              <div class="text-sm font-semibold text-slate-800 mt-1 truncate">${escapeHtml(String(cliente || "—"))}</div>
            </div>
            <div class="text-right whitespace-nowrap">
              <div class="text-sm font-extrabold text-slate-900">${moneyFormat(total)}</div>
            </div>
          </div>

          <div class="mt-3 flex items-center justify-between gap-3">
            ${renderEstadoPill(estado)}
            <div class="text-right whitespace-nowrap flex gap-2">
              ${subirBtn}
              <button onclick="verDetallesPedido('${escapeJsString(idDetalles)}')"
                class="h-9 px-3 rounded-2xl bg-blue-600 text-white text-[11px] font-extrabold uppercase tracking-wide hover:bg-blue-700 transition">
                Ver detalles →
              </button>
            </div>
          </div>

          <div class="mt-3">
            <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500 mb-1">Etiquetas</div>
            ${renderEtiquetasMini(etiquetas)}
          </div>

          <div class="mt-3 text-xs text-slate-600 space-y-1">
            <div><b>Último cambio:</b> ${renderLastChangeCompact(p)}</div>
          </div>
        </div>
      </div>
    `;
  }).join("");
}

// =========================
// Búsqueda local
// =========================
function aplicarFiltroBusqueda() {
  const q = ($("inputBuscar")?.value || "").trim().toLowerCase();
  if (!q) {
    pedidosFiltrados = [...pedidosCache];
    actualizarListado(pedidosFiltrados);
    setTotalPedidos(pedidosFiltrados.length);
    return;
  }

  pedidosFiltrados = pedidosCache.filter((p) => {
    const haystack = [
      p.id, p.shopify_order_id, p.numero,
      p.cliente, p.customer_name,
      p.estado, p.estado_bd,
      p.etiquetas, p.tags,
    ].map(safeText).join(" ").toLowerCase();

    return haystack.includes(q);
  });

  actualizarListado(pedidosFiltrados);
  setTotalPedidos(pedidosFiltrados.length);
}

// =========================
// Cargar cola (solo DISEÑADO)
// =========================
async function cargarMiCola() {
  if (isLoading) return;
  isLoading = true;
  setLoader(true);

  try {
    const { res, data, raw } = await apiGet(ENDPOINT_QUEUE);

    if (!res.ok || !data) {
      console.error("Queue FAIL:", res.status, raw);
      pedidosCache = [];
      pedidosFiltrados = [];
      actualizarListado([]);
      setTotalPedidos(0);
      return;
    }

    const extracted = extractOrdersPayload(data);
    if (!extracted.ok) {
      console.error("Queue payload inválido:", data);
      pedidosCache = [];
      pedidosFiltrados = [];
      actualizarListado([]);
      setTotalPedidos(0);
      return;
    }

    const incoming = extracted.orders.map((r) => ({
      id: r.id ?? r.pedido_id ?? "",
      shopify_order_id: r.shopify_order_id ?? r.order_id ?? r.shopifyId ?? "",
      numero: r.numero ?? r.name ?? (r.id ? ("#" + r.id) : ""),
      fecha: r.fecha ?? r.created_at ?? r.order_date ?? null,
      cliente: r.cliente ?? r.customer_name ?? r.customer ?? null,
      total: r.total ?? r.total_price ?? null,
      estado: r.estado ?? r.estado_bd ?? "Diseñado",
      estado_bd: r.estado_bd ?? r.estado ?? null,
      etiquetas: r.etiquetas ?? r.tags ?? "",
      articulos: r.articulos ?? r.items_count ?? r.items ?? "",
      estado_envio: r.estado_envio ?? r.estado_entrega ?? r.fulfillment_status ?? "",
      forma_envio: r.forma_envio ?? r.forma_entrega ?? r.shipping_method ?? r.metodo_entrega ?? "",
      last_status_change: r.last_status_change ?? {
        user_name: r.estado_por ?? r.estado_changed_by ?? null,
        changed_at: r.estado_actualizado ?? r.estado_changed_at ?? null,
      },
    }));

    // ✅ Seguridad extra: filtra DISEÑADO sí o sí
    const onlyDisenado = incoming.filter(p => normalizeEstado(p.estado ?? p.estado_bd) === "Diseñado");

    pedidosCache = onlyDisenado;
    pedidosFiltrados = [...pedidosCache];

    const q = ($("inputBuscar")?.value || "").trim();
    if (q) aplicarFiltroBusqueda();
    else {
      actualizarListado(pedidosFiltrados);
      setTotalPedidos(pedidosFiltrados.length);
    }
  } catch (e) {
    console.error("cargarMiCola error:", e);
    pedidosCache = [];
    pedidosFiltrados = [];
    actualizarListado([]);
    setTotalPedidos(0);
  } finally {
    isLoading = false;
    silentFetch = false;
    setLoader(false);
  }
}

// =========================
// SUBIR PEDIDO -> Por producir
// =========================
function buildSubirPedidoEndpoints() {
  return [
    `${API_BASE}/montaje/subir-pedido`,
    `/montaje/subir-pedido`,
    `/index.php/montaje/subir-pedido`,
  ];
}

let isSubmittingPedido = false;

async function subirPedidoAPorProducir({ order_key, pedido_id, shopify_order_id, closeModal }) {
  if (isSubmittingPedido) return;

  const ok = confirm("¿Subir este pedido? Estado → Por producir");
  if (!ok) return;

  isSubmittingPedido = true;
  setLoader(true);

  try {
    const payload = {
      order_id: order_key || null,
      pedido_id: pedido_id || null,
      shopify_order_id: shopify_order_id || null,
    };

    let lastErr = null;
    let success = false;
    let msgOk = "Pedido subido: Estado → Por producir.";

    for (const url of buildSubirPedidoEndpoints()) {
      try {
        const { res, data } = await apiPost(url, payload);
        const okResp = (data?.success === true) || (data?.ok === true);
        if (res.ok && okResp) {
          success = true;
          msgOk = data?.message || msgOk;
          break;
        }
        lastErr = data?.message || data?.error || `HTTP ${res.status}`;
      } catch (e) {
        lastErr = String(e?.message || e);
      }
    }

    if (!success) {
      alert(lastErr || "No se pudo subir el pedido.");
      return;
    }

    // recarga (al pasar a Por producir, desaparecerá de Montaje)
    await cargarMiCola();

    const m = $("generalUploadMsg");
    if (m) m.innerHTML = `<span class="text-emerald-700 font-extrabold">${escapeHtml(msgOk)}</span>`;

    if (closeModal) cerrarDetallesFull();
  } finally {
    isSubmittingPedido = false;
    setLoader(false);
  }
}

// =========================
// DETALLES FULL (usa tu dashboard/detalles existente)
// =========================
function setText(id, v) { const el = $(id); if (el) el.textContent = v ?? ""; }
function setHtml(id, v) { const el = $(id); if (el) el.innerHTML = v ?? ""; }

function abrirDetallesFull() {
  const modal = $("modalDetallesFull");
  if (!modal) return;
  modal.classList.remove("hidden");
  document.documentElement.classList.add("overflow-hidden");
  document.body.classList.add("overflow-hidden");
}

function cerrarDetallesFull() {
  const modal = $("modalDetallesFull");
  if (!modal) return;
  modal.classList.add("hidden");
  document.documentElement.classList.remove("overflow-hidden");
  document.body.classList.remove("overflow-hidden");
}
window.cerrarDetallesFull = cerrarDetallesFull;

window.toggleJsonDetalles = function () {
  const pre = $("detJson");
  if (!pre) return;
  pre.classList.toggle("hidden");
};

window.copiarDetallesJson = async function () {
  const pre = $("detJson");
  if (!pre) return;
  try {
    await navigator.clipboard.writeText(pre.textContent || "");
  } catch {
    const ta = document.createElement("textarea");
    ta.value = pre.textContent || "";
    document.body.appendChild(ta);
    ta.select();
    document.execCommand("copy");
    ta.remove();
  }
};

function buildDetallesEndpoints(orderId) {
  const id = encodeURIComponent(String(orderId || ""));
  return [
    `${API_BASE}/dashboard/detalles/${id}`,
    `/dashboard/detalles/${id}`,
    `/index.php/dashboard/detalles/${id}`,
  ];
}

function esImagenUrl(url) {
  if (!url) return false;
  const u = String(url).trim();
  return /https?:\/\/.*\.(jpeg|jpg|png|gif|webp|svg)(\?.*)?$/i.test(u);
}

function fmtMoney(v) {
  if (v === null || v === undefined || v === "") return "0.00";
  const n = Number(v);
  if (Number.isNaN(n)) return String(v);
  return n.toFixed(2);
}

// =========================
// Archivos generales (list/upload) — opcional
// =========================
async function cargarArchivosGenerales(orderId, opts = {}) {
  const list = $("generalFilesList");
  if (!list) return;

  const tryKey = async (key) => {
    if (!key) return null;
    const url = `${ENDPOINT_LIST_GENERAL}?order_id=${encodeURIComponent(key)}`;
    const r = await fetch(url, { credentials: "same-origin" });
    const d = await r.json().catch(() => null);
    if (!r.ok || !d || d.success !== true) return null;
    return d;
  };

  list.innerHTML = `<div class="text-slate-500 text-sm">Cargando...</div>`;

  let d = await tryKey(orderId);
  if ((!d || !Array.isArray(d.files) || d.files.length === 0) && opts.fallbackKey) d = await tryKey(opts.fallbackKey);
  if ((!d || !Array.isArray(d.files) || d.files.length === 0) && opts.extraFallbackKey) d = await tryKey(opts.extraFallbackKey);

  if (!d) {
    list.innerHTML = `<div class="text-rose-600 text-sm font-bold">No se pudo cargar archivos.</div>`;
    return;
  }

  const files = Array.isArray(d.files) ? d.files : [];
  if (!files.length) {
    list.innerHTML = `<div class="text-slate-500 text-sm">—</div>`;
    return;
  }

  list.innerHTML = files.map(f => `
    <div class="flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2">
      <div class="min-w-0">
        <div class="text-sm font-extrabold text-slate-900 truncate">${escapeHtml(f.original_name || f.filename || "Archivo")}</div>
        <div class="text-xs text-slate-600">${escapeHtml(f.mime || "")} · ${escapeHtml(String(f.size || ""))}</div>
      </div>
      <a href="${escapeHtml(f.url || "#")}" target="_blank"
         class="shrink-0 px-3 py-2 rounded-2xl bg-white border border-slate-200 text-slate-900 font-extrabold text-xs hover:bg-slate-100">
        Abrir
      </a>
    </div>
  `).join("");
}

async function subirArchivosGenerales(orderId, fileList) {
  const msg = $("generalUploadMsg");
  if (msg) msg.innerHTML = `<span class="text-slate-600">Subiendo...</span>`;

  const fd = new FormData();
  fd.append("order_id", String(orderId));
  for (const f of fileList) fd.append("files[]", f);

  const res = await fetch(ENDPOINT_UPLOAD_GENERAL, {
    method: "POST",
    body: fd,
    headers: { ...getCsrfHeaders() },
    credentials: "same-origin",
  });

  const data = await res.json().catch(() => null);

  if (!res.ok || !data || data.success !== true) {
    const err = data?.message || "No se pudo subir.";
    if (msg) msg.innerHTML = `<span class="text-rose-600 font-extrabold">${escapeHtml(err)}</span>`;
    return false;
  }

  if (msg) {
    msg.innerHTML = `<span class="text-emerald-700 font-extrabold">
      Subido (${data.saved || 0}). Estado → Por producir.
    </span>`;
  }

  return true;
}

// =========================
// abrirDetallesPedido
// =========================
async function abrirDetallesPedido(orderId) {
  const id = String(orderId || "");
  if (!id) return;

  abrirDetallesFull();
  currentDetallesOrderId = id;

  // placeholders
  setText("detTitle", "Cargando...");
  setText("detSubtitle", "—");
  setText("detItemsCount", "0");
  setHtml("detItems", `<div class="text-slate-500">Cargando productos…</div>`);
  setHtml("detCliente", `<div class="text-slate-500">Cargando…</div>`);
  setHtml("detEnvio", `<div class="text-slate-500">Cargando…</div>`);
  setHtml("detResumen", `<div class="text-slate-500">Cargando…</div>`);
  setHtml("detTotales", `<div class="text-slate-500">Cargando…</div>`);
  setHtml("detJson", "");

  const btnSubir = $("btnSubirPedidoDetalle");
  if (btnSubir) btnSubir.disabled = true;

  // fetch detalle robusto
  let payload = null;
  let lastErr = null;

  for (const url of buildDetallesEndpoints(id)) {
    try {
      const r = await fetch(url, { headers: { Accept: "application/json" }, credentials: "same-origin" });
      if (r.status === 404) continue;

      const text = await r.text();
      let d = null;
      try { d = JSON.parse(text); } catch { d = null; }

      if (!r.ok || !d) throw new Error(d?.message || `HTTP ${r.status}`);
      if (d.success !== true) throw new Error(d.message || "Respuesta inválida (success!=true)");

      payload = d;
      break;
    } catch (e) {
      lastErr = e;
    }
  }

  if (!payload) {
    console.error("Detalle error:", lastErr);
    setText("detTitle", "Error");
    setText("detSubtitle", "No se pudo cargar el detalle.");
    setHtml("detItems", `<div class="text-rose-600 font-extrabold">Error cargando detalle del pedido.</div>`);
    return;
  }

  const o = payload.order || {};
  const lineItems = Array.isArray(o.line_items) ? o.line_items : (Array.isArray(o.lineItems) ? o.lineItems : []);

  // ids reales
  currentDetallesShopifyId = String(o.id || o.shopify_order_id || o.order_id || "").trim() || null;
  currentDetallesPedidoId = String(payload.pedido_id || payload.id || o.pedido_id || "").trim() || null;

  // key estable
  const keyForFiles =
    (currentDetallesShopifyId && currentDetallesShopifyId !== "0") ? currentDetallesShopifyId :
    (currentDetallesPedidoId && currentDetallesPedidoId !== "0") ? currentDetallesPedidoId :
    id;

  currentDetallesKeyForPedido = keyForFiles;

  const hiddenId = $("generalOrderId");
  if (hiddenId) hiddenId.value = keyForFiles;

  // cargar archivos (si existen endpoints)
  if (ENDPOINT_LIST_GENERAL) {
    await cargarArchivosGenerales(keyForFiles, {
      fallbackKey: (keyForFiles === id ? null : id),
      extraFallbackKey: currentDetallesPedidoId && currentDetallesPedidoId !== keyForFiles ? currentDetallesPedidoId : null,
    });
  }

  // habilita botón subir pedido dentro del modal
  if (btnSubir) {
    btnSubir.disabled = false;
    btnSubir.onclick = () => subirPedidoAPorProducir({
      order_key: currentDetallesKeyForPedido || currentDetallesShopifyId || currentDetallesPedidoId || currentDetallesOrderId,
      pedido_id: currentDetallesPedidoId,
      shopify_order_id: currentDetallesShopifyId,
      closeModal: true,
    });
  }

  // header
  const name = o.name || (o.numero ? String(o.numero) : ("#" + (o.id || id)));
  setText("detTitle", `Detalles ${name}`);

  const clienteHeader = o.customer_name || o.cliente || (() => {
    const c = o.customer || {};
    const full = `${c.first_name || ""} ${c.last_name || ""}`.trim();
    return full || "—";
  })();

  setText("detSubtitle", `${clienteHeader} · ${o.created_at || "—"}`);

  // JSON
  try {
    const json = JSON.stringify(payload, null, 2);
    setHtml("detJson", escapeHtml(json));
  } catch {}

  // Cliente
  const customer = o.customer || {};
  const clienteNombre = (customer.first_name || customer.last_name)
    ? `${customer.first_name || ""} ${customer.last_name || ""}`.trim()
    : (o.customer_name || o.cliente || "—");

  setHtml("detCliente", `
    <div class="space-y-1">
      <div class="font-extrabold text-slate-900">${escapeHtml(clienteNombre)}</div>
      <div><span class="text-slate-500 font-bold">Email:</span> ${escapeHtml(o.email || "—")}</div>
      <div><span class="text-slate-500 font-bold">Tel:</span> ${escapeHtml(o.phone || "—")}</div>
      <div><span class="text-slate-500 font-bold">Shopify ID:</span> ${escapeHtml(String(o.id || o.shopify_order_id || id || "—"))}</div>
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
      <div class="pt-2"><span class="text-slate-500 font-bold">Tel envío:</span> ${escapeHtml(a.phone || "—")}</div>
    </div>
  `);

  // Resumen
  const estado = o.estado ?? o.status ?? "—";
  const tags = String(o.tags ?? o.etiquetas ?? "").trim();
  const lastInfo = normalizeLastStatusChange(o.last_status_change || payload.last_status_change);
  const lastText = lastInfo?.changed_at
    ? `${escapeHtml(lastInfo.user_name || "—")} · ${escapeHtml(formatDateTime(lastInfo.changed_at))}`
    : "—";

  setHtml("detResumen", `
    <div class="space-y-2 text-sm">
      <div><span class="text-slate-500 font-bold">Estado:</span> ${renderEstadoPill(estado)}</div>
      <div><span class="text-slate-500 font-bold">Etiquetas:</span> ${tags ? escapeHtml(tags) : "—"}</div>
      <div><span class="text-slate-500 font-bold">Último cambio:</span> ${lastText}</div>
      <div><span class="text-slate-500 font-bold">Pago:</span> ${escapeHtml(o.financial_status || "—")}</div>
      <div><span class="text-slate-500 font-bold">Entrega:</span> ${escapeHtml(o.fulfillment_status || o.estado_envio || "—")}</div>
      <div><span class="text-slate-500 font-bold">Total artículos:</span> ${escapeHtml(String(lineItems.length))}</div>
    </div>
  `);

  // Totales
  const subtotal = o.subtotal_price ?? "0";
  const envio =
    o.total_shipping_price_set?.shop_money?.amount ??
    o.total_shipping_price_set?.presentment_money?.amount ??
    o.total_shipping_price ??
    "0";
  const impuestos = o.total_tax ?? "0";
  const total = o.total_price ?? "0";

  setHtml("detTotales", `
    <div class="space-y-1 text-sm">
      <div><span class="text-slate-500 font-bold">Subtotal:</span> ${escapeHtml(fmtMoney(subtotal))}</div>
      <div><span class="text-slate-500 font-bold">Envío:</span> ${escapeHtml(fmtMoney(envio))}</div>
      <div><span class="text-slate-500 font-bold">Impuestos:</span> ${escapeHtml(fmtMoney(impuestos))}</div>
      <div class="pt-2 text-lg font-extrabold text-slate-900">Total: ${escapeHtml(fmtMoney(total))}</div>
    </div>
  `);

  // Items
  setText("detItemsCount", String(lineItems.length));

  if (!lineItems.length) {
    setHtml("detItems", `<div class="text-slate-500">Este pedido no tiene productos.</div>`);
    return;
  }

  const imagenesLocales = payload.imagenes_locales || {};
  const productImages = payload.product_images || {};

  const itemsHtml = lineItems.map((item, index) => {
    const title = item.title || item.name || "Producto";
    const qty = item.quantity ?? 1;
    const price = item.price ?? "0";
    const tot = (Number(qty) * Number(price));
    const totTxt = Number.isFinite(tot) ? fmtMoney(tot) : "—";

    const props = Array.isArray(item.properties) ? item.properties : [];
    const propsImg = [];
    const propsTxt = [];

    for (const p of props) {
      const name = String(p?.name ?? "").trim() || "Campo";
      const v = p?.value === null || p?.value === undefined ? "" : String(p.value);
      if (esImagenUrl(v)) propsImg.push({ name, value: v });
      else propsTxt.push({ name, value: v });
    }

    const pid = String(item.product_id || "");
    const productImg = pid && productImages?.[pid] ? String(productImages[pid]) : "";
    const localUrl = imagenesLocales?.[index] ? String(imagenesLocales[index]) : "";

    const productImgHtml = productImg
      ? `<a href="${escapeHtml(productImg)}" target="_blank"
            class="h-16 w-16 rounded-2xl overflow-hidden border border-slate-200 shadow-sm bg-white flex-shrink-0">
           <img src="${escapeHtml(productImg)}" class="h-full w-full object-cover">
         </a>`
      : `<div class="h-16 w-16 rounded-2xl border border-slate-200 bg-slate-50 flex items-center justify-center text-slate-400 flex-shrink-0">🧾</div>`;

    const propsTxtHtml = propsTxt.length ? `
      <div class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 p-3">
        <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500 mb-2">Personalización</div>
        <div class="space-y-1 text-sm">
          ${propsTxt.map(({ name, value }) => `
            <div class="flex gap-2">
              <div class="min-w-[130px] text-slate-500 font-bold">${escapeHtml(name)}:</div>
              <div class="flex-1 font-semibold text-slate-900 break-words">${escapeHtml(value || "—")}</div>
            </div>
          `).join("")}
        </div>
      </div>
    ` : "";

    const propsImgsHtml = propsImg.length ? `
      <div class="mt-3">
        <div class="text-xs font-extrabold text-slate-500 mb-2">Imágenes del cliente</div>
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
    ` : "";

    const modificadaHtml = localUrl ? `
      <div class="mt-3">
        <div class="text-xs font-extrabold text-slate-500">Imagen modificada (subida)</div>
        <a href="${escapeHtml(localUrl)}" target="_blank"
           class="inline-block mt-2 rounded-2xl overflow-hidden border border-slate-200 shadow-sm">
          <img src="${escapeHtml(localUrl)}" class="h-40 w-40 object-cover">
        </a>
      </div>
    ` : "";

    return `
      <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-4">
        <div class="flex items-start gap-4">
          ${productImgHtml}
          <div class="min-w-0 flex-1">
            <div class="font-extrabold text-slate-900 truncate">${escapeHtml(title)}</div>
            <div class="text-sm text-slate-600 mt-1">
              Cant: <b>${escapeHtml(qty)}</b> · Precio: <b>${escapeHtml(price)}</b> · Total: <b>${escapeHtml(totTxt)}</b>
            </div>
            ${propsTxtHtml}
            ${propsImgsHtml}
            ${modificadaHtml}
          </div>
        </div>
      </div>
    `;
  }).join("");

  setHtml("detItems", itemsHtml);
}

// Hook del botón
window.verDetallesPedido = function (pedidoId) {
  abrirDetallesPedido(String(pedidoId));
};

// =========================
// Eventos
// =========================
function bindEventos() {
  $("inputBuscar")?.addEventListener("input", () => aplicarFiltroBusqueda());
  $("btnLimpiarBusqueda")?.addEventListener("click", () => {
    const el = $("inputBuscar");
    if (el) el.value = "";
    aplicarFiltroBusqueda();
  });

  // Upload (opcional)
  $("formGeneralUpload")?.addEventListener("submit", async (e) => {
    e.preventDefault();

    const orderId = $("generalOrderId")?.value || currentDetallesShopifyId || currentDetallesPedidoId || currentDetallesOrderId;
    const input = $("generalFiles");
    const files = input?.files;

    if (!orderId) return;
    if (!files || !files.length) {
      const msg = $("generalUploadMsg");
      if (msg) msg.innerHTML = `<span class="text-rose-600 font-extrabold">Selecciona uno o más archivos.</span>`;
      return;
    }

    setLoader(true);
    try {
      const ok = await subirArchivosGenerales(orderId, files);
      if (!ok) return;

      await cargarArchivosGenerales(orderId, {
        fallbackKey: currentDetallesPedidoId,
        extraFallbackKey: currentDetallesShopifyId,
      });

      // si upload cambia a Por producir, este pedido ya no debe aparecer
      await cargarMiCola();
    } finally {
      if (input) input.value = "";
      setLoader(false);
    }
  });

  window.addEventListener("resize", () => {
    actualizarListado(pedidosFiltrados.length ? pedidosFiltrados : pedidosCache);
  });

  $("modalDetallesFull")?.addEventListener("click", (e) => {
    if (e.target && e.target.id === "modalDetallesFull") cerrarDetallesFull();
  });

  window.addEventListener("keydown", (e) => {
    if (e.key === "Escape") cerrarDetallesFull();
  });
}

// =========================
// Live refresh
// =========================
function startLive(ms = 30000) {
  if (liveInterval) clearInterval(liveInterval);
  liveInterval = setInterval(() => {
    if (!isLoading) {
      silentFetch = true;
      cargarMiCola();
    }
  }, ms);
}

// =========================
// Init
// =========================
document.addEventListener("DOMContentLoaded", () => {
  bindEventos();
  cargarMiCola();
  startLive(30000);
});
