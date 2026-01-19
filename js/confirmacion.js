/**
 * confirmacion.js — FINAL DEFINITIVO
 * Backend + fallback manual
 * Sin errores, sin duplicados
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
  /^https?:\/\/.*\.(jpg|jpeg|png|webp|gif|svg)(\?.*)?$/i.test(String(url || ""));

const setLoader = v => $("globalLoader")?.classList.toggle("hidden", !v);
const setTextSafe = (id, v) => $(id) && ($(id).textContent = v ?? "");
const setHtmlSafe = (id, v) => $(id) && ($(id).innerHTML = v ?? "");

/* =====================================================
   CSRF
===================================================== */
function getCsrfHeaders() {
  const token = document.querySelector('meta[name="csrf-token"]')?.content;
  const header = document.querySelector('meta[name="csrf-header"]')?.content;
  return token && header ? { [header]: token } : {};
}

/* =====================================================
   LINE ITEMS NORMALIZADOS
===================================================== */
function extraerLineItems(order) {
  if (Array.isArray(order?.line_items)) return order.line_items;

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
        : [],
      image: node.product?.featuredImage?.url || ""
    }));
  }

  return Array.isArray(order?.lineItems) ? order.lineItems : [];
}

/* =====================================================
   REGLAS IMÁGENES
===================================================== */
const isLlaveroItem = item =>
  String(item?.title || "").toLowerCase().includes("llavero");

const requiereImagenModificada = item => {
  const props = Array.isArray(item?.properties) ? item.properties : [];
  return isLlaveroItem(item) || props.some(p => esImagenUrl(p.value));
};

/* =====================================================
   LISTADO
===================================================== */
function renderPedidos(pedidos) {
  const wrap = $("tablaPedidos");
  if (!wrap) return;

  wrap.innerHTML = "";

  if (!pedidos.length) {
    wrap.innerHTML = `<div class="p-8 text-center text-slate-500">No hay pedidos</div>`;
    setTextSafe("total-pedidos", "0");
    return;
  }

  pedidos.forEach(p => {
    const row = document.createElement("div");
    row.className = "orders-grid cols px-4 py-3 border-b items-center";

    row.innerHTML = `
      <div class="font-extrabold">${escapeHtml(p.numero)}</div>
      <div>${(p.created_at || "").slice(0, 10)}</div>
      <div class="truncate">${escapeHtml(p.cliente)}</div>
      <div class="font-bold">${Number(p.total || 0).toFixed(2)} €</div>
      <div><span class="badge-blue">POR PREPARAR</span></div>
      <div>${escapeHtml(p.estado_por || "—")}</div>
      <div>—</div>
      <div class="text-center">${p.articulos || 1}</div>
      <div><span class="badge-gray">Sin preparar</span></div>
      <div>${escapeHtml(p.forma_envio || "-")}</div>
      <div class="text-right">
        <button onclick="verDetalles('${p.shopify_order_id}')"
          class="btn-primary">VER DETALLES →</button>
      </div>
    `;
    wrap.appendChild(row);
  });

  setTextSafe("total-pedidos", pedidos.length);
}

/* =====================================================
   CARGA COLA
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

/* =====================================================
   MODAL
===================================================== */
function abrirDetallesFull() {
  $("modalDetallesFull")?.classList.remove("hidden");
  document.body.classList.add("overflow-hidden");
}

function cerrarModalDetalles() {
  $("modalDetallesFull")?.classList.add("hidden");
  document.body.classList.remove("overflow-hidden");
}
window.cerrarModalDetalles = cerrarModalDetalles;

/* =====================================================
   DETALLES (BACKEND + FALLBACK)
===================================================== */
window.verDetalles = async function (orderId) {
  pedidoActualId = orderId;
  abrirDetallesFull();
  setTextSafe("detTitulo", "Cargando pedido…");
  setHtmlSafe("detProductos", "Cargando productos…");
  setHtmlSafe("detResumen", "Cargando resumen…");

  try {
    const r = await fetch(`${ENDPOINT_DETALLES}/${orderId}`, {
      headers: { Accept: "application/json" },
      credentials: "same-origin"
    });
    const d = await r.json();
    if (!r.ok || !d?.success) throw new Error();
    renderDetallesPedido(d.order, d.imagenes_locales || {});
  } catch {
    const pedido = pedidosCache.find(p => String(p.shopify_order_id) === String(orderId));
    if (pedido) renderDetallesPedido(pedido, {});
    else setHtmlSafe("detProductos", "Error cargando pedido");
  }
};

/* =====================================================
   RENDER DETALLES (ÚNICO)
===================================================== */
function renderDetallesPedido(order, imagenesLocales = {}) {
  const items = extraerLineItems(order);
  imagenesRequeridas = [];
  imagenesCargadas = [];

  setTextSafe("detTitulo", `Pedido #${order.numero || order.name || order.id}`);

  const html = items.map((item, i) => {
    const qty = Number(item.quantity || 1);
    const price = Number(item.price || 0);
    const total = qty * price;

    const requiere = requiereImagenModificada(item);
    const imgCliente = item.properties?.find(p => esImagenUrl(p.value))?.value || "";
    const imgMod = imagenesLocales[i] || "";

    imagenesRequeridas[i] = requiere;
    imagenesCargadas[i] = !!imgMod;

    return `
      <div class="card">
        <div class="flex gap-4">
          ${item.image ? `<img src="${item.image}" class="thumb">` : ""}
          <div class="flex-1">
            <div class="flex justify-between">
              <div>
                <b>${escapeHtml(item.title)}</b>
                <div class="text-sm">Cant: ${qty} · ${price.toFixed(2)} € · ${total.toFixed(2)} €</div>
              </div>
              ${
                requiere
                  ? imgMod ? `<span class="ok">Listo</span>` : `<span class="warn">Falta imagen</span>`
                  : `<span class="muted">Sin imagen</span>`
              }
            </div>

            ${item.variant_title ? `<div class="text-sm"><b>Variante:</b> ${escapeHtml(item.variant_title)}</div>` : ""}

            ${
              imgCliente
                ? `<img src="${imgCliente}" class="img-cliente">`
                : ""
            }

            ${
              imgMod
                ? `<img src="${imgMod}" class="img-modificada">`
                : requiere
                ? `<input type="file" onchange="subirImagenProducto('${order.id}', ${i}, this)">`
                : ""
            }
          </div>
        </div>
      </div>
    `;
  }).join("");

  setHtmlSafe("detProductos", html);
  actualizarResumenAuto(order.id);
}

/* =====================================================
   RESUMEN
===================================================== */
function actualizarResumenAuto(orderId) {
  const total = imagenesRequeridas.filter(Boolean).length;
  const ok = imagenesCargadas.filter(Boolean).length;
  setHtmlSafe("detResumen", `
    <b>${ok} / ${total} imágenes</b>
    <div class="${ok === total ? "ok" : "warn"}">
      ${ok === total ? "Todo listo" : "Faltan imágenes"}
    </div>
  `);
}

/* =====================================================
   SUBIR IMAGEN
===================================================== */
window.subirImagenProducto = async function (orderId, index, input) {
  const file = input.files?.[0];
  if (!file) return;

  const fd = new FormData();
  fd.append("order_id", orderId);
  fd.append("line_index", index);
  fd.append("file", file);

  await fetch("/api/pedidos/imagenes/subir", {
    method: "POST",
    body: fd,
    headers: getCsrfHeaders(),
    credentials: "same-origin"
  });

  imagenesCargadas[index] = true;
  actualizarResumenAuto(orderId);
};

/* =====================================================
   INIT
===================================================== */
document.addEventListener("DOMContentLoaded", () => {
  $("btnTraer5")?.addEventListener("click", () => traerPedidos(5));
  $("btnTraer10")?.addEventListener("click", () => traerPedidos(10));
  $("btnDevolver")?.addEventListener("click", devolverPedidos);
  cargarMiCola();
});
