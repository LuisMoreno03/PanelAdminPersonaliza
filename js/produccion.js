/**
 * produccion.js (CI4) — COMPLETO + RESPONSIVE + DETALLES FULL + SUBIR ARCHIVOS
 *
 * ✅ Listado responsive igual dashboard:
 *   - 2xl: Grid (divs) (#tablaPedidos)
 *   - xl..2xl-: Tabla con scroll (#tablaPedidosTable)
 *   - <xl: Cards (#cardsPedidos)
 *
 * ✅ Botón "Ver detalles →" (OPCION A) en grid/tabla/cards
 * ✅ Etiquetas ULTRA compactas (usa .col-etiquetas + .tags-wrap-mini + .tag-mini del view)
 * ✅ Detalles FULL usando modal #modalDetallesFull (del view)
 * ✅ FIX CLAVE: Detalles usa shopify_id (no el id interno) para /dashboard/detalles/{id}
 *
 * ✅ Upload de archivos Illustrator (AI/EPS/PDF/SVG/ZIP) en el modal:
 *   - input multiple
 *   - preview de nombres
 *   - botón subir
 *   - endpoint configurable:
 *       window.UPLOAD_ENDPOINT (opcional) o por defecto: /produccion/upload-archivos/{internalId}
 *   - envía FormData: files[], order_id (interno), shopify_order_id
 */

const API_BASE = String(window.API_BASE || "").replace(/\/$/, "");
const ENDPOINT_QUEUE = `${API_BASE}/produccion/my-queue`;
const ENDPOINT_PULL = `${API_BASE}/produccion/pull`;
const ENDPOINT_RETURN_ALL = `${API_BASE}/produccion/return-all`;

let pedidosCache = [];
let pedidosFiltrados = [];
let isLoading = false;
let liveInterval = null;
let silentFetch = false;

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

  if (s.includes("por preparar")) return { label, icon: "⏳", wrap: `${base} bg-slate-900 border-slate-700 text-white`, dot: `${dotBase} bg-slate-300` };
  if (s.includes("faltan archivos")) return { label, icon: "⚠️", wrap: `${base} bg-yellow-400 border-yellow-500 text-black`, dot: `${dotBase} bg-black/80` };
  if (s.includes("confirmado")) return { label, icon: "✅", wrap: `${base} bg-fuchsia-600 border-fuchsia-700 text-white`, dot: `${dotBase} bg-white` };
  if (s.includes("diseñado") || s.includes("disenado")) return { label, icon: "🎨", wrap: `${base} bg-blue-600 border-blue-700 text-white`, dot: `${dotBase} bg-sky-200` };
  if (s.includes("por producir")) return { label, icon: "🏗️", wrap: `${base} bg-orange-600 border-orange-700 text-white`, dot: `${dotBase} bg-amber-200` };
  if (s.includes("fabricando")) return { label, icon: "🛠️", wrap: `${base} bg-indigo-600 border-indigo-700 text-white`, dot: `${dotBase} bg-indigo-200` };
  if (s.includes("enviado")) return { label, icon: "🚚", wrap: `${base} bg-emerald-600 border-emerald-700 text-white`, dot: `${dotBase} bg-lime-200` };
  if (s.includes("repetir")) return { label: "Repetir", icon: "🔁", wrap: `${base} bg-slate-800 border-slate-700 text-white`, dot: `${dotBase} bg-slate-300` };

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
// Etiquetas (mini)
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
// Entrega pill
// =========================
function renderEntregaPill(estadoEnvio) {
  const s = String(estadoEnvio ?? "").toLowerCase().trim();

  if (!s || s === "-" || s === "null") {
    return `<span class="inline-flex items-center px-3 py-1.5 rounded-full text-[11px] font-extrabold
                  bg-slate-100 text-slate-800 border border-slate-200 whitespace-nowrap">⏳ Sin preparar</span>`;
  }
  if (s.includes("fulfilled") || s.includes("entregado")) {
    return `<span class="inline-flex items-center px-3 py-1.5 rounded-full text-[11px] font-extrabold
                  bg-emerald-100 text-emerald-900 border border-emerald-200 whitespace-nowrap">✅ Preparado / enviado</span>`;
  }
  if (s.includes("partial")) {
    return `<span class="inline-flex items-center px-3 py-1.5 rounded-full text-[11px] font-extrabold
                  bg-amber-100 text-amber-900 border border-amber-200 whitespace-nowrap">🟡 Parcial</span>`;
  }
  if (s.includes("unfulfilled") || s.includes("pend")) {
    return `<span class="inline-flex items-center px-3 py-1.5 rounded-full text-[11px] font-extrabold
                  bg-slate-100 text-slate-800 border border-slate-200 whitespace-nowrap">⏳ Pendiente</span>`;
  }
  return `<span class="inline-flex items-center px-3 py-1.5 rounded-full text-[11px] font-extrabold
                bg-white text-slate-900 border border-slate-200 whitespace-nowrap">📦 ${escapeHtml(estadoEnvio)}</span>`;
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
// API JSON
// =========================
async function apiGet(url) {
  const res = await fetch(url, { method: "GET", headers: { "Accept": "application/json" }, credentials: "same-origin" });
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch { data = null; }
  return { res, data, raw: text };
}

async function apiPost(url, payload) {
  const headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
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

// =========================
// Payload normalizer
// =========================
function extractOrdersPayload(payload) {
  if (!payload || typeof payload !== "object") return { ok: false, orders: [] };
  if (payload.success === true) return { ok: true, orders: Array.isArray(payload.orders) ? payload.orders : [] };
  if (payload.ok === true) return { ok: true, orders: Array.isArray(payload.data) ? payload.data : [] };
  return { ok: false, orders: [] };
}

// =========================
// Render modes
// =========================
function getMode() {
  const w = window.innerWidth || 0;
  if (w >= 1536) return "grid";     // 2xl
  if (w >= 1280) return "table";    // xl
  return "cards";
}

function actualizarListado(pedidos) {
  const mode = getMode();

  window.ordersCache = pedidos || [];
  window.ordersById = new Map((pedidos || []).map(o => [String(o.id), o]));

  const contGrid = $("tablaPedidos");
  const contTable = $("tablaPedidosTable");
  const contCards = $("cardsPedidos");

  if (contGrid) contGrid.innerHTML = "";
  if (contTable) contTable.innerHTML = "";
  if (contCards) contCards.innerHTML = "";

  // GRID (2xl)
  if (mode === "grid") {
    if (contGrid) contGrid.classList.remove("hidden");
    if (contCards) contCards.classList.add("hidden");

    if (!contGrid) return;

    if (!pedidos || !pedidos.length) {
      contGrid.innerHTML = `<div class="p-8 text-center text-slate-500">No tienes pedidos asignados.</div>`;
      return;
    }

    contGrid.innerHTML = pedidos.map((p) => {
      const id = String(p.id ?? "");
      const numero = String(p.numero ?? ("#" + id));
      const fecha = p.fecha ?? p.created_at ?? "—";
      const cliente = p.cliente ?? "—";
      const total = p.total ?? "";
      const estado = p.estado ?? p.estado_bd ?? "Por producir";
      const etiquetas = p.etiquetas ?? "";
      const articulos = p.articulos ?? "-";
      const estadoEnvio = p.estado_envio ?? p.estado_entrega ?? "-";
      const formaEnvio = p.forma_envio ?? p.forma_entrega ?? "-";

      const estadoBtn = (typeof window.abrirModal === "function")
        ? `
          <button type="button" onclick="window.abrirModal('${escapeJsString(id)}')"
            class="group inline-flex items-center gap-1 rounded-xl px-1 py-0.5 bg-transparent hover:bg-slate-100 transition"
            title="Cambiar estado">
            ${renderEstadoPill(estado)}
          </button>
        `
        : renderEstadoPill(estado);

      const detallesBtn = `
        <button type="button" onclick="verDetallesPedido('${escapeJsString(id)}')"
          class="px-3 py-2 rounded-2xl bg-blue-600 text-white text-[11px] font-extrabold uppercase tracking-wide hover:bg-blue-700 transition">
          Ver detalles →
        </button>
      `;

      return `
        <div class="orders-grid cols px-4 py-3 text-[13px] border-b hover:bg-slate-50 transition">
          <div class="font-extrabold text-slate-900 whitespace-nowrap">${escapeHtml(numero)}</div>
          <div class="text-slate-600 whitespace-nowrap">${escapeHtml(String(fecha || "—"))}</div>
          <div class="min-w-0 font-semibold text-slate-800 truncate" title="${escapeHtml(String(cliente || "—"))}">
            ${escapeHtml(String(cliente || "—"))}
          </div>
          <div class="font-extrabold text-slate-900 whitespace-nowrap">${moneyFormat(total)}</div>
          <div class="whitespace-nowrap relative z-10">${estadoBtn}</div>
          <div class="min-w-0">${renderLastChangeCompact(p)}</div>
          <div class="col-etiquetas">${renderEtiquetasMini(etiquetas)}</div>
          <div class="text-center font-extrabold">${escapeHtml(String(articulos ?? "-"))}</div>
          <div class="whitespace-nowrap">${renderEntregaPill(estadoEnvio)}</div>
          <div class="min-w-0 text-xs text-slate-700 truncate" title="${escapeHtml(String(formaEnvio || "—"))}">
            ${escapeHtml(String(formaEnvio || "—"))}
          </div>
          <div class="text-right whitespace-nowrap">${detallesBtn}</div>
        </div>
      `;
    }).join("");

    return;
  }

  // TABLE (xl..2xl-)
  if (mode === "table") {
    if (contCards) contCards.classList.add("hidden");
    if (!contTable) return;

    if (!pedidos || !pedidos.length) {
      contTable.innerHTML = `
        <tr>
          <td colspan="11" class="px-5 py-8 text-slate-500 text-sm">No tienes pedidos asignados.</td>
        </tr>
      `;
      return;
    }

    contTable.innerHTML = pedidos.map((p) => {
      const id = String(p.id ?? "");
      const numero = String(p.numero ?? ("#" + id));
      const fecha = p.fecha ?? p.created_at ?? "—";
      const cliente = p.cliente ?? "—";
      const total = p.total ?? "";
      const estado = p.estado ?? p.estado_bd ?? "Por producir";
      const etiquetas = p.etiquetas ?? "";
      const articulos = p.articulos ?? "-";
      const estadoEnvio = p.estado_envio ?? p.estado_entrega ?? "-";
      const formaEnvio = p.forma_envio ?? p.forma_entrega ?? "-";

      const estadoHtml = (typeof window.abrirModal === "function")
        ? `<button type="button" onclick="window.abrirModal('${escapeJsString(id)}')" class="hover:opacity-90">${renderEstadoPill(estado)}</button>`
        : renderEstadoPill(estado);

      const detallesBtn = `
        <button type="button" onclick="verDetallesPedido('${escapeJsString(id)}')"
          class="px-3 py-2 rounded-2xl bg-blue-600 text-white text-[11px] font-extrabold uppercase tracking-wide hover:bg-blue-700 transition">
          Ver detalles →
        </button>
      `;

      return `
        <tr class="hover:bg-slate-50/60 transition">
          <td class="px-5 py-4 font-extrabold text-slate-900 whitespace-nowrap">${escapeHtml(numero)}</td>
          <td class="px-5 py-4 text-slate-700 whitespace-nowrap">${escapeHtml(String(fecha || "—"))}</td>
          <td class="px-5 py-4 text-slate-700 max-w-[320px] truncate" title="${escapeHtml(String(cliente || "—"))}">
            ${escapeHtml(String(cliente || "—"))}
          </td>
          <td class="px-5 py-4 text-slate-700 whitespace-nowrap">${moneyFormat(total)}</td>
          <td class="px-5 py-4 whitespace-nowrap">${estadoHtml}</td>
          <td class="px-5 py-4">${renderLastChangeCompact(p)}</td>
          <td class="px-5 py-4 col-etiquetas">${renderEtiquetasMini(etiquetas)}</td>
          <td class="px-5 py-4 text-center font-extrabold">${escapeHtml(String(articulos ?? "-"))}</td>
          <td class="px-5 py-4 whitespace-nowrap">${renderEntregaPill(estadoEnvio)}</td>
          <td class="px-5 py-4 text-slate-700 max-w-[240px] truncate" title="${escapeHtml(String(formaEnvio || "—"))}">
            ${escapeHtml(String(formaEnvio || "—"))}
          </td>
          <td class="px-5 py-4 text-right whitespace-nowrap">${detallesBtn}</td>
        </tr>
      `;
    }).join("");

    return;
  }

  // CARDS (<xl)
  if (!contCards) return;
  contCards.classList.remove("hidden");

  if (!pedidos || !pedidos.length) {
    contCards.innerHTML = `<div class="p-8 text-center text-slate-500">No tienes pedidos asignados.</div>`;
    return;
  }

  contCards.innerHTML = pedidos.map((p) => {
    const id = String(p.id ?? "");
    const numero = String(p.numero ?? ("#" + id));
    const fecha = p.fecha ?? p.created_at ?? "—";
    const cliente = p.cliente ?? "—";
    const total = p.total ?? "";
    const estado = p.estado ?? p.estado_bd ?? "Por producir";
    const etiquetas = p.etiquetas ?? "";
    const articulos = p.articulos ?? "-";
    const estadoEnvio = p.estado_envio ?? p.estado_entrega ?? "-";
    const formaEnvio = p.forma_envio ?? p.forma_entrega ?? "-";

    const estadoBtn = (typeof window.abrirModal === "function")
      ? `<button onclick="window.abrirModal('${escapeJsString(id)}')" class="inline-flex items-center gap-2 rounded-2xl bg-transparent border-0 p-0">
          ${renderEstadoPill(estado)}
        </button>`
      : renderEstadoPill(estado);

    const detallesBtn = `
      <button onclick="verDetallesPedido('${escapeJsString(id)}')"
        class="px-3 py-2 rounded-2xl bg-blue-600 text-white text-[11px] font-extrabold uppercase tracking-wide hover:bg-blue-700 transition">
        Ver detalles →
      </button>
    `;

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
            ${estadoBtn}
            <div class="text-right whitespace-nowrap">${detallesBtn}</div>
          </div>

          <div class="mt-3">${renderEntregaPill(estadoEnvio)}</div>

          <div class="mt-3">
            <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500 mb-1">Etiquetas</div>
            ${renderEtiquetasMini(etiquetas)}
          </div>

          <div class="mt-3 text-xs text-slate-600 space-y-1">
            <div><b>Artículos:</b> ${escapeHtml(String(articulos ?? "-"))}</div>
            <div><b>Método:</b> ${escapeHtml(String(formaEnvio || "—"))}</div>
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
      p.id, p.shopify_id, p.numero,
      p.cliente,
      p.estado, p.estado_bd,
      p.etiquetas, p.tags,
      p.forma_envio, p.forma_entrega,
      p.estado_envio, p.estado_entrega,
    ].map(safeText).join(" ").toLowerCase();

    return haystack.includes(q);
  });

  actualizarListado(pedidosFiltrados);
  setTotalPedidos(pedidosFiltrados.length);
}

// =========================
// Cargar cola
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

    // ✅ FIX: guardamos id interno y shopify_id (para detalles)
    const incoming = extracted.orders.map((r) => {
      const internalId = r.id ?? r.pedido_id ?? r.queue_id ?? "";
      const shopifyId =
        r.shopify_order_id ??
        r.shopify_id ??
        r.order_id ??
        r.shopifyId ??
        r.shopifyOrderId ??
        internalId;

      return {
        id: internalId,                 // para acciones producción
        shopify_id: shopifyId,          // para detalles Shopify
        numero: r.numero ?? r.name ?? (internalId ? ("#" + internalId) : ""),
        fecha: r.fecha ?? r.created_at ?? r.order_date ?? null,
        cliente: r.cliente ?? r.customer_name ?? r.customer ?? null,
        total: r.total ?? r.total_price ?? null,
        estado: r.estado ?? r.estado_bd ?? "Por producir",
        etiquetas: r.etiquetas ?? r.tags ?? "",
        articulos: r.articulos ?? r.items_count ?? r.items ?? "",
        estado_envio: r.estado_envio ?? r.estado_entrega ?? r.fulfillment_status ?? "",
        forma_envio: r.forma_envio ?? r.forma_entrega ?? r.shipping_method ?? r.metodo_entrega ?? "",
        last_status_change: r.last_status_change ?? {
          user_name: r.estado_por ?? r.estado_changed_by ?? null,
          changed_at: r.estado_actualizado ?? r.estado_changed_at ?? null,
        },
      };
    });

    pedidosCache = incoming;
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
// Acciones Producción
// =========================
async function traerPedidos(count) {
  setLoader(true);
  try {
    const { res, data, raw } = await apiPost(ENDPOINT_PULL, { count });

    if (!res.ok || !data) {
      console.error("PULL FAIL:", res.status, raw);
      alert("No se pudo traer pedidos (error de red o sesión).");
      return;
    }

    const ok = data.ok === true || data.success === true;
    if (!ok) {
      alert(data.error || data.message || "No se pudo traer pedidos.");
      return;
    }

    await cargarMiCola();
  } finally {
    setLoader(false);
  }
}

async function devolverPedidosRestantes() {
  const ok = confirm("¿Seguro que quieres devolver TODOS tus pedidos pendientes en Producción?");
  if (!ok) return;

  setLoader(true);
  try {
    const { res, data, raw } = await apiPost(ENDPOINT_RETURN_ALL, {});

    if (!res.ok || !data) {
      console.error("RETURN ALL FAIL:", res.status, raw);
      alert("No se pudo devolver pedidos (error de red o sesión).");
      return;
    }

    const ok2 = data.ok === true || data.success === true;
    if (!ok2) {
      alert(data.error || data.message || "No se pudo devolver pedidos.");
      return;
    }

    await cargarMiCola();
  } finally {
    setLoader(false);
  }
}

// =========================
// DETALLES FULL + UPLOAD
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

// ✅ endpoints robustos para detalles
function buildDetallesEndpoints(shopifyId) {
  const id = encodeURIComponent(String(shopifyId || ""));
  return [
    `${API_BASE}/dashboard/detalles/${id}`,
    `/dashboard/detalles/${id}`,
    `/index.php/dashboard/detalles/${id}`,
    `/index.php/index.php/dashboard/detalles/${id}`,
    `${API_BASE}/produccion/detalles/${id}`, // por si existe
    `/produccion/detalles/${id}`,
    `/index.php/produccion/detalles/${id}`,
  ];
}

function buildUploadUrl(internalId) {
  const id = encodeURIComponent(String(internalId || ""));
  if (window.UPLOAD_ENDPOINT) return String(window.UPLOAD_ENDPOINT).replace("{id}", id);
  return `${API_BASE}/produccion/upload-archivos/${id}`;
}

function esImagenUrl(url) {
  if (!url) return false;
  const u = String(url).trim();
  return /https?:\/\/.*\.(jpeg|jpg|png|gif|webp)(\?.*)?$/i.test(u);
}

function fmtMoney(v) {
  if (v === null || v === undefined || v === "") return "0.00";
  const n = Number(v);
  if (Number.isNaN(n)) return String(v);
  return n.toFixed(2);
}

let currentDetailInternalId = null;
let currentDetailShopifyId = null;
let selectedFiles = [];

function hideUploadMsgs() {
  $("uploadErrorGeneral")?.classList.add("hidden");
  $("uploadOkGeneral")?.classList.add("hidden");
  if ($("uploadErrorGeneral")) $("uploadErrorGeneral").textContent = "";
  if ($("uploadOkGeneral")) $("uploadOkGeneral").textContent = "";
}

function showUploadError(msg) {
  const el = $("uploadErrorGeneral");
  if (!el) return;
  el.textContent = msg || "Error subiendo archivos.";
  el.classList.remove("hidden");
}

function showUploadOk(msg) {
  const el = $("uploadOkGeneral");
  if (!el) return;
  el.textContent = msg || "Archivos subidos.";
  el.classList.remove("hidden");
}

function renderUploadBox() {
  const boxId = "uploadBoxGeneral";
  const existing = document.getElementById(boxId);
  if (existing) return;

  const wrap = document.createElement("div");
  wrap.id = boxId;
  wrap.className = "mt-6 rounded-3xl border border-slate-200 bg-white shadow-sm p-4";
  wrap.innerHTML = `
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <div class="text-sm font-extrabold text-slate-900">Archivo general (Illustrator)</div>
        <div class="text-xs text-slate-500 mt-1">
          Sube uno o varios archivos (.ai .eps .pdf .svg .zip). Se guardan para este pedido.
        </div>
      </div>
      <div class="text-right">
        <button id="btnUploadGeneral"
          class="h-10 px-4 rounded-2xl bg-slate-900 text-white font-extrabold text-xs uppercase tracking-wide hover:bg-slate-800 transition">
          Subir archivos
        </button>
      </div>
    </div>

    <div class="mt-4 grid grid-cols-1 sm:grid-cols-[1fr_auto] gap-3 items-center">
      <input id="inputUploadGeneral" type="file" multiple
        accept=".ai,.eps,.pdf,.svg,.zip,application/pdf,image/svg+xml,application/zip"
        class="block w-full text-sm file:mr-4 file:py-2 file:px-4
               file:rounded-2xl file:border-0 file:text-sm file:font-extrabold
               file:bg-slate-100 file:text-slate-900 hover:file:bg-slate-200
               border border-slate-200 rounded-2xl bg-slate-50 p-2"
      />

      <div id="uploadStatusGeneral"
        class="text-xs font-extrabold text-slate-600 bg-slate-50 border border-slate-200 rounded-2xl px-4 py-2 text-center">
        Sin archivos
      </div>
    </div>

    <div id="uploadListGeneral" class="mt-3 text-xs text-slate-700 space-y-1"></div>

    <div id="uploadErrorGeneral" class="hidden mt-3 text-sm font-extrabold text-rose-600"></div>
    <div id="uploadOkGeneral" class="hidden mt-3 text-sm font-extrabold text-emerald-600"></div>
  `;

  const detItems = $("detItems");
  if (detItems) detItems.appendChild(wrap);

  const input = $("inputUploadGeneral");
  const list = $("uploadListGeneral");
  const status = $("uploadStatusGeneral");
  const btn = $("btnUploadGeneral");

  if (input) {
    input.addEventListener("change", () => {
      selectedFiles = Array.from(input.files || []);
      if (!selectedFiles.length) {
        status.textContent = "Sin archivos";
        list.innerHTML = "";
        return;
      }
      status.textContent = `${selectedFiles.length} archivo(s)`;
      list.innerHTML = selectedFiles.map(f => `• ${escapeHtml(f.name)} <span class="text-slate-400">(${Math.round(f.size/1024)} KB)</span>`).join("<br>");
      hideUploadMsgs();
    });
  }

  if (btn) {
    btn.addEventListener("click", async () => {
      await subirArchivosGeneral();
    });
  }
}

async function subirArchivosGeneral() {
  hideUploadMsgs();

  const internalId = currentDetailInternalId;
  const shopifyId = currentDetailShopifyId;

  if (!internalId) {
    showUploadError("No hay pedido activo.");
    return;
  }

  const input = $("inputUploadGeneral");
  const files = selectedFiles.length ? selectedFiles : Array.from(input?.files || []);
  if (!files.length) {
    showUploadError("Selecciona uno o varios archivos primero.");
    return;
  }

  const allowedExt = [".ai", ".eps", ".pdf", ".svg", ".zip"];
  const invalid = files.find(f => {
    const n = String(f.name || "").toLowerCase();
    return !allowedExt.some(ext => n.endsWith(ext));
  });
  if (invalid) {
    showUploadError(`Archivo no permitido: ${invalid.name}`);
    return;
  }

  const url = buildUploadUrl(internalId);
  const fd = new FormData();
  fd.append("order_id", String(internalId));
  fd.append("shopify_order_id", String(shopifyId || ""));
  files.forEach((f) => fd.append("files[]", f));

  const csrfHeaders = getCsrfHeaders();

  try {
    $("btnUploadGeneral")?.setAttribute("disabled", "disabled");
    $("btnUploadGeneral")?.classList.add("opacity-60", "cursor-not-allowed");

    const r = await fetch(url, {
      method: "POST",
      body: fd,
      headers: { ...csrfHeaders },
      credentials: "same-origin",
    });

    const txt = await r.text();
    let data = null;
    try { data = JSON.parse(txt); } catch { data = null; }

    if (!r.ok) {
      console.error("UPLOAD FAIL", r.status, txt);
      showUploadError(data?.error || data?.message || `Error subiendo (HTTP ${r.status})`);
      return;
    }

    const ok = data?.ok === true || data?.success === true || data?.status === "ok";
    if (!ok) {
      showUploadError(data?.error || data?.message || "No se pudo subir.");
      return;
    }

    showUploadOk(data?.message || "Archivos subidos correctamente.");

    selectedFiles = [];
    if ($("inputUploadGeneral")) $("inputUploadGeneral").value = "";
    if ($("uploadListGeneral")) $("uploadListGeneral").innerHTML = "";
    if ($("uploadStatusGeneral")) $("uploadStatusGeneral").textContent = "Sin archivos";

  } catch (e) {
    console.error("UPLOAD error", e);
    showUploadError("Error subiendo archivos.");
  } finally {
    $("btnUploadGeneral")?.removeAttribute("disabled");
    $("btnUploadGeneral")?.classList.remove("opacity-60", "cursor-not-allowed");
  }
}

async function abrirDetallesPedido(internalId) {
  const iid = String(internalId || "");
  if (!iid) return;

  // Si el dashboard ya tiene su verDetalles, úsalo (si estás compartiendo JS)
  if (typeof window.verDetalles === "function" && window.verDetalles !== window.verDetallesPedido) {
    window.verDetalles(iid);
    return;
  }

  // ✅ FIX: buscar shopify_id desde cache por id interno
  const row = window.ordersById?.get(iid) || null;
  const shopifyId = row?.shopify_id || row?.shopify_order_id || row?.shopifyId || iid;

  currentDetailInternalId = iid;
  currentDetailShopifyId = shopifyId;

  abrirDetallesFull();

  setText("detTitle", "Cargando...");
  setText("detSubtitle", "—");
  setText("detItemsCount", "0");
  setHtml("detItems", `<div class="text-slate-500">Cargando productos…</div>`);
  setHtml("detCliente", `<div class="text-slate-500">Cargando…</div>`);
  setHtml("detEnvio", `<div class="text-slate-500">Cargando…</div>`);
  setHtml("detResumen", `<div class="text-slate-500">Cargando…</div>`);
  setHtml("detTotales", `<div class="text-slate-500">Cargando…</div>`);
  if ($("detJson")) $("detJson").textContent = "";

  let payload = null;
  let lastText = "";
  let lastStatus = 0;

  // ✅ Robust fetch: prueba varias rutas
  for (const url of buildDetallesEndpoints(shopifyId)) {
    try {
      const r = await fetch(url, { headers: { Accept: "application/json" }, credentials: "same-origin" });
      lastStatus = r.status;
      const txt = await r.text();
      lastText = txt;

      if (r.status === 404) continue;

      let d = null;
      try { d = JSON.parse(txt); } catch { d = null; }

      if (!r.ok || !d || d.success !== true) {
        // si viene JSON con error del backend (como tu screenshot)
        const msg = d?.message || d?.error || `HTTP ${r.status}`;
        throw new Error(msg);
      }

      payload = d;
      break;
    } catch (e) {
      // continúa al siguiente endpoint
    }
  }

  if (!payload) {
    console.error("Detalle error:", { internalId: iid, shopifyId, lastStatus, lastText });
    setText("detTitle", "Error");
    setText("detSubtitle", "No se pudo cargar el detalle.");
    setHtml("detItems", `<div class="text-rose-600 font-extrabold">Error cargando detalle del pedido.</div>`);
    // deja upload igual por si quieres adjuntar aunque Shopify falle
    renderUploadBox();
    return;
  }

  const o = payload.order || {};
  const lineItems = Array.isArray(o.line_items) ? o.line_items : [];

  const name = o.name || (o.numero ? String(o.numero) : ("#" + (o.id || shopifyId)));
  const sub = `${o.customer_name || o.cliente || "—"} · ${o.created_at ? formatDateTime(o.created_at) : "—"}`;
  setText("detTitle", `Detalles ${name}`);
  setText("detSubtitle", sub);

  try { if ($("detJson")) $("detJson").textContent = JSON.stringify(payload, null, 2); } catch {}

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
      <div><span class="text-slate-500 font-bold">Shopify ID:</span> ${escapeHtml(o.id || shopifyId)}</div>
      <div><span class="text-slate-500 font-bold">Interno:</span> ${escapeHtml(iid)}</div>
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
  const lastChangeText = lastInfo?.changed_at
    ? `${escapeHtml(lastInfo.user_name || "—")} · ${escapeHtml(formatDateTime(lastInfo.changed_at))}`
    : "—";

  setHtml("detResumen", `
    <div class="space-y-2 text-sm">
      <div><span class="text-slate-500 font-bold">Estado:</span> ${renderEstadoPill(estado)}</div>
      <div><span class="text-slate-500 font-bold">Etiquetas:</span> ${tags ? escapeHtml(tags) : "—"}</div>
      <div><span class="text-slate-500 font-bold">Último cambio:</span> ${lastChangeText}</div>
      <div><span class="text-slate-500 font-bold">Pago:</span> ${escapeHtml(o.financial_status || "—")}</div>
      <div><span class="text-slate-500 font-bold">Entrega:</span> ${escapeHtml(o.fulfillment_status || "—")}</div>
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
  const descuento = o.total_discounts ?? "0";

  setHtml("detTotales", `
    <div class="space-y-1 text-sm">
      <div><span class="text-slate-500 font-bold">Subtotal:</span> ${escapeHtml(fmtMoney(subtotal))}</div>
      <div><span class="text-slate-500 font-bold">Descuento:</span> ${escapeHtml(fmtMoney(descuento))}</div>
      <div><span class="text-slate-500 font-bold">Envío:</span> ${escapeHtml(fmtMoney(envio))}</div>
      <div><span class="text-slate-500 font-bold">Impuestos:</span> ${escapeHtml(fmtMoney(impuestos))}</div>
      <div class="pt-2 text-lg font-extrabold text-slate-900">Total: ${escapeHtml(fmtMoney(total))}</div>
    </div>
  `);

  // Items
  setText("detItemsCount", String(lineItems.length));

  if (!lineItems.length) {
    setHtml("detItems", `<div class="text-slate-500">Este pedido no tiene productos.</div>`);
    renderUploadBox();
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

  // ✅ upload al final
  renderUploadBox();
}

// Hook requerido (OPCION A)
window.verDetallesPedido = function (pedidoId) {
  abrirDetallesPedido(String(pedidoId));
};

// =========================
// Eventos
// =========================
function bindEventos() {
  $("btnTraer5")?.addEventListener("click", () => traerPedidos(5));
  $("btnTraer10")?.addEventListener("click", () => traerPedidos(10));
  $("btnDevolver")?.addEventListener("click", () => devolverPedidosRestantes());

  $("inputBuscar")?.addEventListener("input", () => aplicarFiltroBusqueda());
  $("btnLimpiarBusqueda")?.addEventListener("click", () => {
    const el = $("inputBuscar");
    if (el) el.value = "";
    aplicarFiltroBusqueda();
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
