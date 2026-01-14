/**
 * produccion.js (CodeIgniter4)
 * UI estilo Dashboard + Cola por usuario:
 * - Traer 5 / Traer 10 pedidos (solo estado "produccion")
 * - Devolver pedidos restantes (desasignar)
 * - Listado = solo pedidos asignados al usuario y estado "produccion"
 *
 * Requisitos:
 * - Botones: #btnTraer5, #btnTraer10, #btnDevolver
 * - Buscador: #inputBuscar, #btnLimpiarBusqueda
 * - Tabla body: #tablaPedidos
 * - Contador: #total-pedidos (puede existir más de una vez)
 * - Loader: #globalLoader
 *
 * Endpoints (según lo que te propuse):
 * - GET  /produccion/my-queue
 * - POST /produccion/pull        {count: 5|10}
 * - POST /produccion/return-all  {}
 *
 * Nota:
 * - Este JS NO asume tu lógica vieja de paginación con Shopify.
 * - Si ya tienes paginaSiguiente/paginaAnterior, lo dejamos sin tocar.
 * - Aquí renderizamos la tabla desde la cola del usuario.
 */

// ==============================
// Config
// ==============================
const API_BASE = (window.API_BASE || "").replace(/\/$/, ""); // por si viene con /
const ENDPOINT_QUEUE = `${API_BASE}/produccion/my-queue`;
const ENDPOINT_PULL = `${API_BASE}/produccion/pull`;
const ENDPOINT_RETURN_ALL = `${API_BASE}/produccion/return-all`;

let pedidosCache = [];
let pedidosFiltrados = [];

// ==============================
// Helpers UI
// ==============================
function $(id) {
  return document.getElementById(id);
}

function setLoader(show) {
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
  // Ajusta si ya tienes formato propio
  if (v === null || v === undefined || v === "") return "—";
  const num = Number(v);
  if (Number.isNaN(num)) return String(v);
  return num.toLocaleString("es-CO", { style: "currency", currency: "COP" });
}

function safeText(v) {
  return (v === null || v === undefined || v === "") ? "—" : String(v);
}

// Si tu backend trae etiquetas como string "a,b,c" o array, lo normalizamos
function normalizeTags(tags) {
  if (!tags) return [];
  if (Array.isArray(tags)) return tags.filter(Boolean).map(String);
  if (typeof tags === "string") {
    return tags.split(",").map(s => s.trim()).filter(Boolean);
  }
  return [String(tags)];
}

function tagPill(tag) {
  // estilo parecido a tus pills de dashboard
  return `
    <span class="inline-flex items-center px-2 py-1 rounded-full text-[11px] font-extrabold
                 bg-slate-50 border border-slate-200 text-slate-800">
      ${escapeHtml(tag)}
    </span>
  `;
}

function escapeHtml(str) {
  return String(str)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
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
  const res = await fetch(url, { method: "GET", headers: { "Accept": "application/json" } });
  const text = await res.text();

  let data;
  try { data = JSON.parse(text); }
  catch { data = { ok: false, error: "Respuesta no JSON", raw: text }; }

  if (!res.ok) {
    console.error("GET FAIL", url, res.status, data);
  }
  return data;
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
  });

  const text = await res.text();

  let data;
  try { data = JSON.parse(text); }
  catch { data = { ok: false, error: "Respuesta no JSON", raw: text }; }

  if (!res.ok) {
    console.error("POST FAIL", url, res.status, data);
  }
  return data;
}

// ==============================
// Render tabla
// ==============================
function renderTabla(rows) {
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

  tbody.innerHTML = rows.map((p) => {
    const id = p.id ?? p.pedido_id ?? "";
    const fecha = p.fecha ?? p.created_at ?? p.order_date ?? "";
    const cliente = p.cliente ?? p.customer_name ?? "";
    const total = p.total ?? p.total_price ?? "";
    const estado = p.estado_bd ?? p.estado ?? "Producción";
    const etiquetas = normalizeTags(p.etiquetas ?? p.tags);

    const articulos = p.articulos ?? p.items_count ?? p.items ?? "";
    const estadoEntrega = p.estado_entrega ?? p.fulfillment_status ?? "";
    const formaEntrega = p.forma_entrega ?? p.shipping_method ?? p.metodo_entrega ?? "";

    const etiquetasHtml = etiquetas.length
      ? `<div class="flex flex-wrap gap-1">${etiquetas.map(tagPill).join("")}</div>`
      : `<span class="text-slate-400">—</span>`;

    // Botón detalles:
    // - Si ya tienes una función abrirModalDetalles(pedidoId) úsala aquí.
    // - Si no, lo dejamos como placeholder.
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
  }).join("");
}

// ==============================
// Búsqueda local
// ==============================
function aplicarFiltroBusqueda() {
  const q = ($("inputBuscar")?.value || "").trim().toLowerCase();
  if (!q) {
    pedidosFiltrados = [...pedidosCache];
    renderTabla(pedidosFiltrados);
    setTotalPedidos(pedidosFiltrados.length);
    return;
  }

  pedidosFiltrados = pedidosCache.filter((p) => {
    const haystack = [
      p.id, p.pedido_id,
      p.cliente, p.customer_name,
      p.estado, p.status,
      p.etiquetas, p.tags,
      p.forma_entrega, p.shipping_method, p.metodo_entrega,
    ].map(safeText).join(" ").toLowerCase();

    return haystack.includes(q);
  });

  renderTabla(pedidosFiltrados);
  setTotalPedidos(pedidosFiltrados.length);
}

// ==============================
// Cargar cola del usuario
// ==============================
async function cargarMiCola() {
  setLoader(true);
  try {
    const json = await apiGet(ENDPOINT_QUEUE);
    if (!json || json.ok !== true) {
      alert(json?.error ? JSON.stringify(json.error) : "Error en my-queue. Mira consola.");
      console.error("Queue error:", json);
      pedidosCache = [];
      pedidosFiltrados = [];
      renderTabla([]);
      setTotalPedidos(0);
      return;
    }

    pedidosCache = Array.isArray(json.data) ? json.data : [];
    pedidosFiltrados = [...pedidosCache];

    // Si hay búsqueda escrita, aplicarla
    const q = ($("inputBuscar")?.value || "").trim();
    if (q) aplicarFiltroBusqueda();
    else {
      renderTabla(pedidosFiltrados);
      setTotalPedidos(pedidosFiltrados.length);
    }
  } finally {
    setLoader(false);
  }
}

// ==============================
// Acciones de Producción
// ==============================
async function traerPedidos(count) {
  setLoader(true);
  try {
    const json = await apiPost(ENDPOINT_PULL, { count });
    if (!json || json.ok !== true) {
      alert(json?.error || "No se pudo traer pedidos.");
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
    const json = await apiPost(ENDPOINT_RETURN_ALL, {});
    if (!json || json.ok !== true) {
      alert(json?.error || "No se pudo devolver pedidos.");
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
/**
 * Si tú ya tenías una función para abrir el modal de detalles,
 * reemplaza el contenido de verDetallesPedido() para llamar a la tuya.
 */
window.verDetallesPedido = function (pedidoId) {
  // Ejemplo:
  // abrirModalDetalles(pedidoId);
  // Por ahora, si no existe:
  if (typeof window.abrirModalDetalles === "function") {
    window.abrirModalDetalles(pedidoId);
    return;
  }
  alert("Hook de detalles: implementa abrirModalDetalles(pedidoId) o edita verDetallesPedido().\nPedido: " + pedidoId);
};

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

  // Si quieres refresco automático cada X segundos (para que salga al cambiar a fabricando)
  // OJO: tu filtro ya lo saca cuando recargas la cola; esto solo automatiza.
  // setInterval(cargarMiCola, 15000);
}

// ==============================
// Init
// ==============================
document.addEventListener("DOMContentLoaded", () => {
  bindEventos();
  cargarMiCola();
});
