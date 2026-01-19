/**
 * confirmacion.js — FINAL DEFINITIVO
 * ✔ Backend + fallback unificados
 * ✔ Sin ReferenceError
 * ✔ Sin toFixed errors
 * ✔ Render único de productos
 * ✔ Modal estable
 */

/* =====================================================
   CONFIG
===================================================== */
const API = window.API || {};
const ENDPOINT_QUEUE = API.myQueue;
const ENDPOINT_PULL = API.pull;
const ENDPOINT_RETURN_ALL = API.returnAll;
const ENDPOINT_DETALLES = API.detalles;

let pedidosCache = [];
let loading = false;

let imagenesRequeridas = [];
let imagenesCargadas = [];
let pedidoActualId = null;

/* =====================================================
   HELPERS
===================================================== */
const $ = id => document.getElementById(id);

const escapeHtml = str =>
  String(str ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");

const esImagenUrl = url =>
  /https?:\/\/.*\.(jpg|jpeg|png|webp|gif|svg)(\?.*)?$/i.test(String(url || ""));

const setLoader = show =>
  $("globalLoader")?.classList.toggle("hidden", !show);

const setTextSafe = (id, v) => $(id) && ($(id).textContent = v ?? "");
const setHtmlSafe = (id, h) => $(id) && ($(id).innerHTML = h ?? "");

/* =====================================================
   CSRF
===================================================== */
function getCsrfHeaders() {
  const t = document.querySelector('meta[name="csrf-token"]')?.content;
  const h = document.querySelector('meta[name="csrf-header"]')?.content;
  return t && h ? { [h]: t } : {};
}

/* =====================================================
   NORMALIZAR LINE ITEMS (CLAVE)
===================================================== */
function extraerLineItems(order) {
  if (Array.isArray(order?.line_items)) {
    return order.line_items.map(i => ({
      title: i.title,
      quantity: Number(i.quantity || 1),
      price: Number(i.price || 0),
      product_id: i.product_id,
      variant_id: i.variant_id,
      variant_title: i.variant_title || "",
      properties: Array.isArray(i.properties) ? i.properties : []
    }));
  }

  if (order?.lineItems?.edges) {
    return order.lineItems.edges.map(({ node }) => ({
      title: node.title,
      quantity: Number(node.quantity || 1),
      price: Number(node.originalUnitPrice?.amount || 0),
      product_id: node.product?.id || null,
      variant_id: node.variant?.id || null,
      variant_title: node.variant?.title || "",
      properties: Array.isArray(node.customAttributes)
        ? node.customAttributes.map(p => ({ name: p.key, value: p.value }))
        : []
    }));
  }

  return [];
}

/* =====================================================
   REGLAS IMÁGENES
===================================================== */
const isLlaveroItem = item =>
  String(item?.title || "").toLowerCase().includes("llavero");

const requiereImagenModificada = item =>
  isLlaveroItem(item) ||
  (Array.isArray(item.properties) &&
    item.properties.some(p => esImagenUrl(p.value)));

/* =====================================================
   LISTADO
===================================================== */
function renderPedidos(pedidos) {
  const wrap = $("tablaPedidos");
  wrap.innerHTML = "";

  if (!pedidos.length) {
    wrap.innerHTML = `<div class="p-8 text-center text-slate-500">No hay pedidos asignados</div>`;
    setTextSafe("total-pedidos", 0);
    return;
  }

  pedidos.forEach(p => {
    const row = document.createElement("div");
    row.className = "orders-grid cols px-4 py-3 border-b items-center";

    row.innerHTML = `
      <div class="font-extrabold">${escapeHtml(p.numero)}</div>
      <div>${(p.created_at || "").slice(0,10)}</div>
      <div class="truncate">${escapeHtml(p.cliente)}</div>
      <div class="font-bold">${Number(p.total).toFixed(2)} €</div>
      <div><span class="px-3 py-1 text-xs rounded-full bg-blue-600 text-white">POR PREPARAR</span></div>
      <div>${escapeHtml(p.estado_por || "—")}</div>
      <div>—</div>
      <div class="text-center">${p.articulos || 1}</div>
      <div><span class="px-3 py-1 text-xs rounded-full bg-slate-100">Sin preparar</span></div>
      <div class="truncate">${escapeHtml(p.forma_envio || "-")}</div>
      <div class="text-right">
        <button onclick="verDetalles('${p.shopify_order_id}')"
          class="px-3 py-1 rounded-2xl bg-blue-600 text-white font-extrabold">
          VER DETALLES →
        </button>
      </div>
    `;
    wrap.appendChild(row);
  });

  setTextSafe("total-pedidos", pedidos.length);
}

/* =====================================================
   CARGA / ACCIONES
===================================================== */
async function cargarMiCola() {
  if (loading) return;
  loading = true;
  setLoader(true);

  try {
    const r = await fetch(ENDPOINT_QUEUE, { credentials: "same-origin" });
    const d = await r.json();
    pedidosCache = r.ok && d.ok ? d.data : [];
    renderPedidos(pedidosCache);
  } finally {
    loading = false;
    setLoader(false);
  }
}

async function traerPedidos(n) {
  setLoader(true);
  await fetch(ENDPOINT_PULL, {
    method: "POST",
    headers: { "Content-Type": "application/json", ...getCsrfHeaders() },
    body: JSON.stringify({ count: n }),
    credentials: "same-origin"
  });
  await cargarMiCola();
  setLoader(false);
}

async function devolverPedidos() {
  if (!confirm("¿Devolver todos los pedidos?")) return;
  setLoader(true);
  await fetch(ENDPOINT_RETURN_ALL, {
    method: "POST",
    headers: getCsrfHeaders(),
    credentials: "same-origin"
  });
  await cargarMiCola();
  setLoader(false);
}

/* =====================================================
   MODAL
===================================================== */
const abrirDetallesFull = () => {
  $("modalDetallesFull")?.classList.remove("hidden");
  document.body.classList.add("overflow-hidden");
};

const cerrarModalDetalles = () => {
  $("modalDetallesFull")?.classList.add("hidden");
  document.body.classList.remove("overflow-hidden");
};

window.cerrarModalDetalles = cerrarModalDetalles;

/* =====================================================
   DETALLES (BACKEND + FALLBACK UNIFICADO)
===================================================== */
window.verDetalles = async function (orderId) {
  pedidoActualId = orderId;
  abrirDetallesFull();
  setTextSafe("detTitulo", "Cargando pedido…");

  try {
    const r = await fetch(`${ENDPOINT_DETALLES}/${orderId}`, { credentials: "same-origin" });
    const d = await r.json();
    if (!d?.success) throw "fallback";
    renderDetalles(d.order, d.imagenes_locales || {});
  } catch {
    const pedido = pedidosCache.find(p => String(p.shopify_order_id) === String(orderId));
    if (pedido) renderDetalles(pedido, {});
  }
};

/* =====================================================
   RENDER ÚNICO DE DETALLES
===================================================== */
function renderDetalles(order, imagenesLocales = {}) {
  const items = extraerLineItems(order);

  imagenesRequeridas = [];
  imagenesCargadas = [];

  setTextSafe("detTitulo", `Pedido #${order.numero || order.id}`);

  const productos = items.map((item, i) => {
    const requiere = requiereImagenModificada(item);
    const imgLocal = imagenesLocales[i] || "";

    imagenesRequeridas[i] = requiere;
    imagenesCargadas[i] = !!imgLocal;

    return `
      <div class="rounded-3xl border bg-white p-5 shadow-sm space-y-3">
        <div class="flex justify-between">
          <div>
            <div class="font-extrabold">${escapeHtml(item.title)}</div>
            <div class="text-sm text-slate-600">
              Cant: ${item.quantity} · Precio: ${item.price.toFixed(2)} €
            </div>
          </div>
          ${
            requiere
              ? imgLocal
                ? `<span class="text-xs bg-emerald-100 text-emerald-800 px-3 py-1 rounded-full">Listo</span>`
                : `<span class="text-xs bg-amber-100 text-amber-800 px-3 py-1 rounded-full">Falta imagen</span>`
              : `<span class="text-xs bg-slate-100 px-3 py-1 rounded-full">Sin imagen</span>`
          }
        </div>
      </div>
    `;
  }).join("");

  setHtmlSafe("detProductos", `
    <div class="rounded-3xl border bg-white p-5 space-y-4">
      <div class="flex justify-between">
        <h3 class="font-extrabold">Productos</h3>
        <span class="text-xs bg-slate-100 px-3 py-1 rounded-full">${items.length}</span>
      </div>
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">${productos}</div>
    </div>
  `);

  actualizarResumenAuto(order.id);
}

/* =====================================================
   RESUMEN
===================================================== */
function actualizarResumenAuto(orderId) {
  const total = imagenesRequeridas.filter(Boolean).length;
  const ok = imagenesRequeridas.filter((v, i) => v && imagenesCargadas[i]).length;

  setHtmlSafe("detResumen", `
    <div class="font-extrabold">${ok} / ${total} imágenes cargadas</div>
    <div class="${ok === total ? "text-emerald-600" : "text-amber-600"} font-bold">
      ${ok === total ? "🟢 Todo listo" : "🟡 Faltan imágenes"}
    </div>
  `);
}

/* =====================================================
   INIT
===================================================== */
document.addEventListener("DOMContentLoaded", () => {
  $("btnTraer5")?.addEventListener("click", () => traerPedidos(5));
  $("btnTraer10")?.addEventListener("click", () => traerPedidos(10));
  $("btnDevolver")?.addEventListener("click", devolverPedidos);
  cargarMiCola();
});
