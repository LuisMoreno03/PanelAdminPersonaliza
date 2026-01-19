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
  const id = String(orderId || "");
  if (!id) return;

  function $(x) { return document.getElementById(x); }
  function setHtml(id, html) { const el = $(id); if (el) el.innerHTML = html; }
  function setText(id, txt) { const el = $(id); if (el) el.textContent = txt ?? ""; }

  abrirDetallesFull();

  setText("detTitle", "Cargando…");
  setHtml("detItems", `<div class="text-slate-500">Cargando productos…</div>`);
  setText("detItemsCount", "0");

  try {
    const r = await fetch(`/dashboard/detalles/${encodeURIComponent(id)}`, {
      headers: { Accept: "application/json" },
      credentials: "same-origin"
    });

    const d = await r.json();
    if (!r.ok || !d?.success) throw d;

    const o = d.order || {};
    const lineItems = Array.isArray(o.line_items) ? o.line_items : [];
    const imagenesLocales = d.imagenes_locales || {};
    const productImages = d.product_images || {};

    // HEADER
    setText("detTitle", `Pedido ${o.name || "#" + id}`);
    setText(
      "detSubtitle",
      o.customer
        ? `${o.customer.first_name || ""} ${o.customer.last_name || ""}`.trim()
        : (o.email || "—")
    );

    // CLIENTE
    setHtml("detCliente", `
      <div>
        <b>${o.customer?.first_name || ""} ${o.customer?.last_name || ""}</b><br>
        ${o.email || "—"}<br>
        ${o.phone || "—"}
      </div>
    `);

    // ENVÍO
    const a = o.shipping_address || {};
    setHtml("detEnvio", `
      <div>
        ${a.name || ""}<br>
        ${a.address1 || ""} ${a.address2 || ""}<br>
        ${a.zip || ""} ${a.city || ""}<br>
        ${a.country || ""}
      </div>
    `);

    // TOTALES
    setHtml("detTotales", `
      <div>
        Subtotal: ${o.subtotal_price || "0"} €<br>
        Envío: ${o.total_shipping_price_set?.shop_money?.amount || "0"} €<br>
        <b>Total: ${o.total_price || "0"} €</b>
      </div>
    `);

    // PRODUCTOS
    window.imagenesLocales = imagenesLocales;
    window.imagenesRequeridas = [];
    window.imagenesCargadas = [];

    setText("detItemsCount", lineItems.length);

    const html = lineItems.map((item, index) => {
      const requiere = requiereImagenModificada(item);
      const localUrl = imagenesLocales[index] || "";

      window.imagenesRequeridas[index] = requiere;
      window.imagenesCargadas[index] = !!localUrl;

      const productImg = productImages[item.product_id] || "";
      const imgCliente = (item.properties || []).find(p => esImagenUrl(p.value))?.value || "";

      return `
        <div class="rounded-2xl border p-4 mb-3">
          <div class="flex gap-4">
            <img src="${productImg}" class="h-16 w-16 object-cover rounded-xl border">

            <div class="flex-1">
              <div class="flex justify-between">
                <b>${item.title}</b>
                <span class="text-xs px-3 py-1 rounded-full ${
                  requiere
                    ? localUrl
                      ? "bg-emerald-100 text-emerald-900"
                      : "bg-amber-100 text-amber-900"
                    : "bg-slate-100"
                }">
                  ${
                    requiere
                      ? localUrl ? "Listo" : "Falta imagen"
                      : "No requiere"
                  }
                </span>
              </div>

              ${imgCliente ? `
                <img src="${imgCliente}" class="mt-2 h-28 rounded-xl border">
              ` : ""}

              ${localUrl ? `
                <img src="${localUrl}" class="mt-2 h-32 rounded-xl border">
              ` : requiere ? `
                <input type="file"
                  class="mt-2"
                  accept="image/*"
                  onchange="subirImagenProducto('${id}', ${index}, this)">
              ` : ""}
            </div>
          </div>
        </div>
      `;
    }).join("");

    setHtml("detItems", html);

    if (typeof validarEstadoAuto === "function") {
      validarEstadoAuto(id);
    }

  } catch (e) {
    console.error(e);
    setHtml("detItems", `<div class="text-red-600">Error cargando detalles</div>`);
  }
};


/* =====================================================
   RENDER DETALLES COMPLETO
===================================================== */
function renderDetalles(order, imagenesLocales = {}) {
  const items = extraerLineItems(order);

  imagenesRequeridas = [];
  imagenesCargadas = [];

  setTextSafe("detTitulo", `Pedido #${order.numero || order.id}`);

  const html = items.map((item, i) => {
    const requiere = requiereImagenModificada(item);
    const imgCliente = item.properties.find(p => esImagenUrl(p.value))?.value || "";
    const imgMod = imagenesLocales[i] || "";

    imagenesRequeridas[i] = requiere;
    imagenesCargadas[i] = !!imgMod;

    return `
      <div class="rounded-3xl border bg-white p-5 shadow-sm space-y-4">
        <div class="flex gap-4">
          ${item.image ? `<img src="${item.image}" class="h-20 w-20 rounded-xl border object-cover">` : ""}
          <div class="flex-1">
            <div class="flex justify-between">
              <div>
                <div class="font-extrabold">${escapeHtml(item.title)}</div>
                <div class="text-sm text-slate-600">
                  Cant: ${item.quantity} · ${item.price.toFixed(2)} € · ${(item.price * item.quantity).toFixed(2)} €
                </div>
              </div>
              ${
                requiere
                  ? imgMod
                    ? `<span class="text-emerald-600 font-bold">Listo</span>`
                    : `<span class="text-amber-600 font-bold">Falta imagen</span>`
                  : `<span class="text-slate-400">Sin imagen</span>`
              }
            </div>

            ${item.variant_title ? `<div class="text-sm"><b>Variante:</b> ${escapeHtml(item.variant_title)}</div>` : ""}

            ${imgCliente ? `<img src="${imgCliente}" class="mt-3 h-32 rounded-xl border object-cover">` : ""}

            ${
              imgMod
                ? `<img src="${imgMod}" class="mt-3 h-32 rounded-xl border object-cover">`
                : requiere
                  ? `<input type="file" class="mt-2" accept="image/*"
                      onchange="subirImagenProducto('${order.id}', ${i}, this)">`
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
