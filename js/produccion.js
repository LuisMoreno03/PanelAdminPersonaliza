/**
 * produccion.js (CodeIgniter4)
 * UI estilo Dashboard + Cola por usuario
 *
 * Requisitos DOM (según tu layout):
 * - Botones: #btnTraer5, #btnTraer10, #btnDevolver
 * - Buscador: #inputBuscar, #btnLimpiarBusqueda
 * - Contenedores render:
 *    A) Dashboard UI: #tablaPedidos (DIV) + #cardsPedidos (DIV)
 *    B) Tabla simple: <tbody id="tablaPedidos">
 * - Contador: #total-pedidos (puede existir más de una vez)
 * - Loader: #globalLoader
 *
 * Endpoints:
 * - GET  /produccion/my-queue
 * - POST /produccion/pull        {count: 5|10}
 * - POST /produccion/return-all  {}
 */

// ==============================
// Config
// ==============================
const API_BASE = (window.API_BASE || "").replace(/\/$/, "");
const ENDPOINT_QUEUE = `${API_BASE}/produccion/my-queue`;
const ENDPOINT_PULL = `${API_BASE}/produccion/pull`;
const ENDPOINT_RETURN_ALL = `${API_BASE}/produccion/return-all`;

// Live refresh (opcional)
const LIVE_ENABLED = true;
const LIVE_MS = 30000;

let pedidosCache = [];
let pedidosFiltrados = [];
let liveInterval = null;
let silentFetch = false;

// ==============================
// Helpers UI
// ==============================
function $(id) {
  return document.getElementById(id);
}

function setLoader(show) {
  if (silentFetch) return; // 👈 no loader en live
  const el = $("globalLoader");
  if (!el) return;
  el.classList.toggle("hidden", !show);
}

function setTotalPedidos(n) {
  document.querySelectorAll("#total-pedidos").forEach((el) => {
    el.textContent = String(n);
  });
}

function moneyFormat(v) {
  if (v === null || v === undefined || v === "") return "—";
  const num = Number(v);
  if (Number.isNaN(num)) return String(v);
  // Ajusta currency si necesitas
  return num.toLocaleString("es-CO", { style: "currency", currency: "COP" });
}

function safeText(v) {
  return v === null || v === undefined || v === "" ? "—" : String(v);
}

function escapeHtml(str) {
  return String(str ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

// Normaliza etiquetas
function normalizeTags(tags) {
  if (!tags) return [];
  if (Array.isArray(tags)) return tags.filter(Boolean).map(String);
  if (typeof tags === "string") return tags.split(",").map((s) => s.trim()).filter(Boolean);
  return [String(tags)];
}

function tagPill(tag) {
  return `
    <span class="inline-flex items-center px-2 py-1 rounded-full text-[11px] font-extrabold
                 bg-slate-50 border border-slate-200 text-slate-800">
      ${escapeHtml(tag)}
    </span>
  `;
}

// ==============================
// CSRF (si lo usas como en dashboard)
// ==============================
function getCsrfHeaders() {
  const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content");
  const header = document.querySelector('meta[name="csrf-header"]')?.getAttribute("content");
  if (!token || !header) return {};
  return { [header]: token };
}

// ==============================
// API
// ==============================
async function apiGet(url) {
  const res = await fetch(url, {
    method: "GET",
    headers: { Accept: "application/json" },
    credentials: "same-origin",
  });

  const text = await res.text();
  let data;
  try {
    data = JSON.parse(text);
  } catch {
    data = { ok: false, error: "Respuesta no JSON", raw: text };
  }

  if (!res.ok) console.error("GET FAIL", url, res.status, data);
  return { res, data };
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
  try {
    data = JSON.parse(text);
  } catch {
    data = { ok: false, error: "Respuesta no JSON", raw: text };
  }

  if (!res.ok) console.error("POST FAIL", url, res.status, data);
  return { res, data };
}

// ==============================
// Adaptador: convierte filas backend a "order" estilo dashboard
// (para poder usar window.actualizarTabla())
// ==============================
function toDashboardOrder(p) {
  const id = p.id ?? p.pedido_id ?? p.order_id ?? "";
  const numero = p.numero ?? p.name ?? p.order_name ?? `#${id}`;
  const fecha = p.fecha ?? p.created_at ?? p.order_date ?? p.date ?? "";
  const cliente = p.cliente ?? p.customer_name ?? p.customer ?? "";
  const total = p.total ?? p.total_price ?? p.amount ?? "";
  const estado =
    p.estado ??
    p.estado_bd ??
    p.status ??
    "Por producir";

  const etiquetas = p.etiquetas ?? p.tags ?? "";
  const articulos = p.articulos ?? p.items_count ?? p.items ?? "";

  // last_status_change (para que el dashboard renderice "Último cambio")
  const last_status_change = p.last_status_change
    ? p.last_status_change
    : {
        user_name: p.estado_por ?? p.estado_updated_by_name ?? p.user_name ?? null,
        changed_at: p.estado_actualizado ?? p.estado_updated_at ?? p.actualizado ?? null,
      };

  return {
    id: String(id),
    numero,
    fecha,
    cliente,
    total,
    estado,
    etiquetas,
    articulos,
    estado_envio: p.estado_envio ?? p.estado_entrega ?? p.fulfillment_status ?? null,
    forma_envio: p.forma_envio ?? p.forma_entrega ?? p.shipping_method ?? p.metodo_entrega ?? "",
    last_status_change,
  };
}

// ==============================
// Render: preferir Dashboard UI si existe
// ==============================
function hasDashboardRenderer() {
  return typeof window.actualizarTabla === "function";
}

function renderDashboardUI(rows) {
  // Set caches globales si tu dashboard usa ordersCache/ordersById
  const orders = (rows || []).map(toDashboardOrder);

  // cache global compatible con tu dashboard
  window.ordersCache = orders;
  window.ordersById = new Map(orders.map((o) => [String(o.id), o]));

  // Render igual que dashboard
  window.actualizarTabla(orders);

  // guarda total
  setTotalPedidos(orders.length);
}

function renderTablaSimple(rows) {
  const tbody = $("tablaPedidos");
  if (!tbody) return;

  if (!rows || !rows.length) {
    tbody.innerHTML = `
      <tr>
        <td colspan="10" class="px-5 py-8 text-slate-500 text-sm">
          No tienes pedidos asignados en Producción.
        </td>
      </tr>
    `;
    return;
  }

  tbody.innerHTML = rows
    .map((p) => {
      const id = p.id ?? p.pedido_id ?? "";
      const fecha = p.fecha ?? p.created_at ?? p.order_date ?? "";
      const cliente = p.cliente ?? p.customer_name ?? "";
      const total = p.total ?? p.total_price ?? "";
      const estado = p.estado_bd ?? p.estado ?? "Por producir";
      const etiquetas = normalizeTags(p.etiquetas ?? p.tags);

      const articulos = p.articulos ?? p.items_count ?? p.items ?? "";
      const estadoEntrega = p.estado_entrega ?? p.estado_envio ?? p.fulfillment_status ?? "";
      const formaEntrega = p.forma_entrega ?? p.forma_envio ?? p.shipping_method ?? p.metodo_entrega ?? "";

      const etiquetasHtml = etiquetas.length
        ? `<div class="flex flex-wrap gap-1">${etiquetas.map(tagPill).join("")}</div>`
        : `<span class="text-slate-400">—</span>`;

      const btnDetalles = `
        <button
          type="button"
          class="h-9 px-3 rounded-2xl bg-slate-100 hover:bg-slate-200 text-slate-900 font-extrabold transition"
          onclick="verDetallesPedido('${escapeHtml(id)}')"
        >
          Ver
        </button>
      `;

      return `
        <tr class="hover:bg-slate-50/60 transition">
          <td class="px-5 py-4 font-extrabold text-slate-900">${escapeHtml(id)}</td>
          <td class="px-5 py-4 text-slate-700">${escapeHtml(fecha)}</td>
          <td class="px-5 py-4 text-slate-700">${escapeHtml(cliente)}</td>
          <td class="px-5 py-4 text-slate-700">${moneyFormat(total)}</td>
          <td class="px-5 py-4">
            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-extrabold
                         bg-amber-50 border border-amber-200 text-amber-900">
              ${escapeHtml(estado)}
            </span>
          </td>
          <td class="px-5 py-4">${etiquetasHtml}</td>
          <td class="px-5 py-4 text-slate-700">${escapeHtml(articulos)}</td>
          <td class="px-5 py-4 text-slate-700">${escapeHtml(estadoEntrega)}</td>
          <td class="px-5 py-4 text-slate-700">${escapeHtml(formaEntrega)}</td>
          <td class="px-5 py-4 text-right">${btnDetalles}</td>
        </tr>
      `;
    })
    .join("");

  setTotalPedidos(rows.length);
}

function renderUI(rows) {
  if (hasDashboardRenderer()) renderDashboardUI(rows);
  else renderTablaSimple(rows);
}

// ==============================
// Búsqueda local (filtra sobre pedidosCache)
// ==============================
function aplicarFiltroBusqueda() {
  const q = ($("inputBuscar")?.value || "").trim().toLowerCase();

  if (!q) {
    pedidosFiltrados = [...pedidosCache];
    renderUI(pedidosFiltrados);
    return;
  }

  pedidosFiltrados = pedidosCache.filter((p) => {
    const haystack = [
      p.id,
      p.pedido_id,
      p.numero,
      p.name,
      p.cliente,
      p.customer_name,
      p.estado,
      p.estado_bd,
      p.status,
      p.etiquetas,
      p.tags,
      p.forma_entrega,
      p.forma_envio,
      p.shipping_method,
      p.metodo_entrega,
      p.estado_envio,
      p.fulfillment_status,
    ]
      .map(safeText)
      .join(" ")
      .toLowerCase();

    return haystack.includes(q);
  });

  renderUI(pedidosFiltrados);
}

// ==============================
// Cargar cola del usuario
// ==============================
async function cargarMiCola({ silent = false } = {}) {
  silentFetch = !!silent;
  setLoader(true);

  try {
    const { data } = await apiGet(ENDPOINT_QUEUE);

    // ✅ soporta {ok:true,data:[]} y también {success:true,orders:[]}
    const ok = data?.ok === true || data?.success === true;

    const rows = Array.isArray(data?.data)
      ? data.data
      : Array.isArray(data?.orders)
      ? data.orders
      : [];

    if (!ok) {
      console.error("Queue error payload:", data);
      if (!silentFetch) alert(data?.error || data?.message || "Error en my-queue. Mira consola.");
      pedidosCache = [];
      pedidosFiltrados = [];
      renderUI([]);
      setTotalPedidos(0);
      return;
    }

    pedidosCache = rows;
    // aplica búsqueda si hay
    const q = ($("inputBuscar")?.value || "").trim();
    if (q) {
      aplicarFiltroBusqueda();
    } else {
      pedidosFiltrados = [...pedidosCache];
      renderUI(pedidosFiltrados);
    }
  } finally {
    setLoader(false);
    silentFetch = false;
  }
}

// ==============================
// Acciones Producción
// ==============================
async function traerPedidos(count) {
  setLoader(true);
  try {
    const { data } = await apiPost(ENDPOINT_PULL, { count });

    const ok = data?.ok === true || data?.success === true;
    if (!ok) {
      alert(data?.error || data?.message || "No se pudo traer pedidos.");
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
    const { data } = await apiPost(ENDPOINT_RETURN_ALL, {});
    const isOk = data?.ok === true || data?.success === true;

    if (!isOk) {
      alert(data?.error || data?.message || "No se pudo devolver pedidos.");
      return;
    }

    await cargarMiCola();
  } finally {
    setLoader(false);
  }
}

// ==============================
// Detalles (hook)
// ==============================
window.verDetallesPedido = function (pedidoId) {
  // Si ya tienes el verDetalles FULL del dashboard, úsalo:
  if (typeof window.verDetalles === "function") {
    // dashboard.verDetalles espera id
    window.verDetalles(pedidoId);
    return;
  }

  // Si tienes un modal legacy:
  if (typeof window.abrirModalDetalles === "function") {
    window.abrirModalDetalles(pedidoId);
    return;
  }

  alert(
    "Hook de detalles: incluye dashboard.js (window.verDetalles) o implementa abrirModalDetalles(pedidoId).\nPedido: " +
      pedidoId
  );
};

// ==============================
// Live refresh (opcional, sin loader)
// ==============================
function startLive() {
  if (!LIVE_ENABLED) return;
  if (liveInterval) clearInterval(liveInterval);

  liveInterval = setInterval(() => {
    cargarMiCola({ silent: true });
  }, LIVE_MS);
}

function stopLive() {
  if (liveInterval) clearInterval(liveInterval);
  liveInterval = null;
}

// ==============================
// Eventos
// ==============================
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

  // Si hay un modal de estado del dashboard, cuando cambias estado,
  // conviene refrescar cola (opcional). Puedes llamarlo manual desde tu guardarEstado.
  window.PRODUCCION_REFRESH = () => cargarMiCola({ silent: true });
}

// ==============================
// Init
// ==============================
document.addEventListener("DOMContentLoaded", () => {
  bindEventos();
  cargarMiCola();
  startLive();
});

// Limpieza al salir (opcional)
window.addEventListener("beforeunload", () => {
  stopLive();
});
