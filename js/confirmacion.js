/**
 * confirmacion.js — FINAL DEFINITIVO
 * ✔ Backend + fallback unificados
 * ✔ Render detallado por producto
 * ✔ Subida de imagen modificada
 * ✔ Resumen automático
 * ✔ Sin errores
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

const setLoader = v => $("globalLoader")?.classList.toggle("hidden", !v);
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
   NORMALIZAR LINE ITEMS
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
      image: i.image?.src || "",
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
      image: node.product?.featuredImage?.url || "",
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
  item.properties?.some(p => esImagenUrl(p.value));

/* =====================================================
   LISTADO
===================================================== */
function renderPedidos(pedidos) {
  const wrap = $("tablaPedidos");
  wrap.innerHTML = "";

  if (!pedidos.length) {
    wrap.innerHTML = `<div class="p-8 text-center text-slate-500">No hay pedidos</div>`;
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
      <div class="font-bold">${Number(p.total || 0).toFixed(2)} €</div>
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
   DETALLES
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
   RENDER DETALLES COMPLETO
===================================================== */
function renderDetalles(order, imagenesLocales = {}) {
  const items = extraerLineItems(order);

  imagenesRequeridas = [];
  imagenesCargadas = [];

  /* =========================
     CABECERA PEDIDO
  ========================= */
  setTextSafe(
    "detTitulo",
    `Pedido ${order.name || order.numero || "#" + order.id}`
  );

  setHtmlSafe(
    "detResumen",
    `
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
      <div>
        <div class="font-extrabold text-slate-900">${escapeHtml(
          order.customer?.name ||
          `${order.customer?.first_name || ""} ${order.customer?.last_name || ""}` ||
          order.cliente ||
          "—"
        )}</div>
        <div class="text-slate-600">${escapeHtml(order.email || "—")}</div>
        <div class="text-slate-600">${escapeHtml(order.phone || "—")}</div>
      </div>

      <div>
        <div><b>Estado pago:</b> ${escapeHtml(order.financial_status || "—")}</div>
        <div><b>Estado envío:</b> ${escapeHtml(order.fulfillment_status || "—")}</div>
        <div><b>Fecha:</b> ${escapeHtml(
          (order.created_at || "").slice(0, 10) || "—"
        )}</div>
      </div>
    </div>

    <div class="mt-4 border-t pt-4 text-sm space-y-1">
      <div><b>Subtotal:</b> ${escapeHtml(order.subtotal_price || "0")} €</div>
      <div><b>Envío:</b> ${escapeHtml(
        order.total_shipping_price_set?.shop_money?.amount ||
        order.shipping_price ||
        "0"
      )} €</div>
      <div><b>Impuestos:</b> ${escapeHtml(order.total_tax || "0")} €</div>
      <div class="text-lg font-extrabold">
        Total: ${escapeHtml(order.total_price || order.total || "0")} €
      </div>
    </div>
    `
  );

  /* =========================
     PRODUCTOS
  ========================= */
  if (!items.length) {
    setHtmlSafe(
      "detProductos",
      `<div class="p-6 text-center text-slate-500">Este pedido no tiene productos</div>`
    );
    return;
  }

  const productosHtml = items
    .map((item, i) => {
      const props = Array.isArray(item.properties) ? item.properties : [];

      const requiere = requiereImagenModificada(item);

      const imgCliente =
        props.find(p => esImagenUrl(p.value))?.value || "";

      const imgModificada = imagenesLocales[i] || "";

      imagenesRequeridas[i] = !!requiere;
      imagenesCargadas[i] = !!imgModificada;

      /* === IMAGEN PRODUCTO (Shopify real) === */
      const imgProducto =
        item.image ||
        item.featured_image ||
        item.image_url ||
        item.product_image ||
        "";

      const precio = Number(item.price || 0);
      const qty = Number(item.quantity || 1);
      const totalLinea = (precio * qty).toFixed(2);

      const estadoBadge = requiere
        ? imgModificada
          ? `<span class="px-3 py-1 text-xs rounded-full bg-emerald-100 text-emerald-800 font-bold">Listo</span>`
          : `<span class="px-3 py-1 text-xs rounded-full bg-amber-100 text-amber-800 font-bold">Falta imagen</span>`
        : `<span class="px-3 py-1 text-xs rounded-full bg-slate-100 text-slate-600">Sin imagen</span>`;

      return `
        <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5 space-y-4">

          <!-- HEADER PRODUCTO -->
          <div class="flex items-start gap-4">
            ${
              imgProducto
                ? `
                  <img
                    src="${imgProducto}"
                    class="h-20 w-20 rounded-2xl border object-cover flex-shrink-0"
                  >
                `
                : `
                  <div class="h-20 w-20 rounded-2xl border bg-slate-50 flex items-center justify-center text-slate-400">
                    🧾
                  </div>
                `
            }

            <div class="flex-1 min-w-0">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="font-extrabold text-slate-900 truncate">
                    ${escapeHtml(item.title)}
                  </div>
                  <div class="text-sm text-slate-600 mt-1">
                    Cant: <b>${qty}</b> · Precio: <b>${precio.toFixed(2)} €</b> ·
                    Total: <b>${totalLinea} €</b>
                  </div>
                </div>
                ${estadoBadge}
              </div>

              ${
                item.variant_title
                  ? `
                    <div class="text-sm mt-2">
                      <span class="text-slate-500 font-bold">Variante:</span>
                      <span class="font-semibold">${escapeHtml(item.variant_title)}</span>
                    </div>
                  `
                  : ""
              }
            </div>
          </div>

          <!-- IMAGEN CLIENTE -->
          ${
            imgCliente
              ? `
                <div>
                  <div class="text-xs font-extrabold text-slate-500 mb-2">
                    Imagen original (cliente)
                  </div>
                  <a href="${imgCliente}" target="_blank">
                    <img src="${imgCliente}" class="h-36 rounded-2xl border object-cover">
                  </a>
                </div>
              `
              : ""
          }

          <!-- IMAGEN MODIFICADA -->
          ${
            imgModificada
              ? `
                <div>
                  <div class="text-xs font-extrabold text-slate-500 mb-2">
                    Imagen modificada
                  </div>
                  <a href="${imgModificada}" target="_blank">
                    <img src="${imgModificada}" class="h-40 rounded-2xl border object-cover">
                  </a>
                </div>
              `
              : requiere
              ? `
                <div>
                  <div class="text-xs font-extrabold text-slate-500 mb-2">
                    Subir imagen modificada
                  </div>
                  <input
                    type="file"
                    accept="image/*"
                    class="block w-full text-sm border border-slate-200 rounded-2xl p-2"
                    onchange="subirImagenProducto('${order.id}', ${i}, this)">
                </div>
              `
              : ""
          }

        </div>
      `;
    })
    .join("");

  setHtmlSafe(
    "detProductos",
    `
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <h3 class="font-extrabold text-slate-900">Productos</h3>
        <span class="px-3 py-1 rounded-full text-xs bg-slate-100 font-bold">
          ${items.length}
        </span>
      </div>
      ${productosHtml}
    </div>
    `
  );

  actualizarResumenAuto(order.id);
}

/* =====================================================
   SUBIR IMAGEN MODIFICADA
===================================================== */
window.subirImagenProducto = async function (orderId, index, input) {
  const file = input.files?.[0];
  if (!file) return;

  const fd = new FormData();
  fd.append("order_id", orderId);
  fd.append("line_index", index);
  fd.append("file", file);

  setLoader(true);

  const r = await fetch("/api/pedidos/imagenes/subir", {
    method: "POST",
    body: fd,
    headers: getCsrfHeaders(),
    credentials: "same-origin"
  });

  const d = await r.json();
  if (d?.url) {
    imagenesCargadas[index] = true;
    actualizarResumenAuto(orderId);
  }

  setLoader(false);
};

/* =====================================================
   RESUMEN
===================================================== */
function actualizarResumenAuto() {
  const total = imagenesRequeridas.filter(Boolean).length;
  const ok = imagenesCargadas.filter(Boolean).length;

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
