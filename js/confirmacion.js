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
function renderDetalles(order, imagenesLocales = {}, productImages = {}) {
  const items = extraerLineItems(order);

  imagenesRequeridas = [];
  imagenesCargadas = [];

  // Helpers seguros
  const num = (v, d = 0) => {
    const n = Number(v);
    return Number.isFinite(n) ? n : d;
  };

  const normalizeId = (id) => {
    if (id === null || id === undefined) return "";
    const s = String(id);
    const m = s.match(/(\d+)\s*$/); // gid://shopify/Product/123 -> 123
    return m ? m[1] : s;
  };

  const extractImageUrls = (value) => {
    // saca URLs de imagen aunque vengan incrustadas en texto/JSON
    const s =
      value === null || value === undefined
        ? ""
        : typeof value === "object"
        ? JSON.stringify(value)
        : String(value);

    const re = /https?:\/\/[^\s"'<>]+?\.(?:jpg|jpeg|png|webp|gif|svg)(\?[^\s"'<>]*)?/gi;
    const found = s.match(re) || [];
    // únicos
    return Array.from(new Set(found));
  };

  const splitProps = (propsRaw) => {
    const props = Array.isArray(propsRaw) ? propsRaw : [];
    const txt = [];
    const imgs = [];

    for (const p of props) {
      const name = String(p?.name ?? "Campo").trim();
      const value = p?.value;

      const urls = extractImageUrls(value);
      if (urls.length) {
        urls.forEach((u) => imgs.push({ name, url: u }));
      } else {
        const safe =
          value === null || value === undefined
            ? ""
            : typeof value === "object"
            ? JSON.stringify(value)
            : String(value);
        if (safe.trim() !== "") txt.push({ name, value: safe });
      }
    }

    return { txt, imgs };
  };

  const getProductImage = (item) => {
    // 1) si ya viene en el item
    const direct =
      item?.image ||
      item?.featured_image ||
      item?.image_url ||
      item?.product_image ||
      "";

    if (esImagenUrl(direct)) return direct;

    // 2) desde el mapa del backend (mejor)
    const pidRaw = item?.product_id;
    const pid = normalizeId(pidRaw);

    return (
      productImages?.[String(pidRaw)] ||
      productImages?.[String(pid)] ||
      ""
    );
  };

  // =========================
  // TITULO
  // =========================
  setTextSafe("detTitulo", `Pedido ${order.name || order.numero || "#" + (order.id || "")}`);

  // =========================
  // RESUMEN (como Shopify)
  // =========================
  const customerName =
    order.customer?.name ||
    `${order.customer?.first_name || ""} ${order.customer?.last_name || ""}`.trim() ||
    order.cliente ||
    "—";

  const ship = order.shipping_address || {};
  const shipLines = Array.isArray(order.shipping_lines) ? order.shipping_lines : [];

  const subtotal = num(order.subtotal_price ?? order.subtotal ?? 0);
  const taxes = num(order.total_tax ?? 0);

  const shippingCost =
    shipLines.length
      ? shipLines.reduce((acc, l) => acc + num(l.price ?? l.cost ?? 0), 0)
      : num(
          order.total_shipping_price_set?.shop_money?.amount ??
          order.total_shipping_price_set?.presentment_money?.amount ??
          order.shipping_price ??
          0
        );

  const total = num(order.total_price ?? order.total ?? 0);

  const shippingMethod =
    shipLines.map(l => l.title).filter(Boolean).join(" · ") ||
    order.forma_envio ||
    "—";

  // Aquí pintamos cliente + envío + totales
  setHtmlSafe("detResumen", `
    <div class="space-y-4">
      <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5 space-y-2">
        <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500">Cliente</div>
        <div class="font-extrabold text-slate-900">${escapeHtml(customerName)}</div>
        <div class="text-sm text-slate-600">${escapeHtml(order.email || "—")}</div>
        <div class="text-sm text-slate-600">${escapeHtml(order.phone || "—")}</div>
      </div>

      <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5 space-y-2">
        <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500">Envío</div>
        <div class="text-sm text-slate-800 font-semibold">${escapeHtml(ship.name || customerName)}</div>
        <div class="text-sm text-slate-600">${escapeHtml(ship.address1 || "")}</div>
        <div class="text-sm text-slate-600">${escapeHtml(ship.address2 || "")}</div>
        <div class="text-sm text-slate-600">
          ${escapeHtml([ship.zip, ship.city].filter(Boolean).join(" ") || "—")}
        </div>
        <div class="text-sm text-slate-600">${escapeHtml(ship.province || "")}</div>
        <div class="text-sm text-slate-600">${escapeHtml(ship.country || "")}</div>
        <div class="pt-2 text-sm">
          <span class="text-slate-500 font-bold">Método:</span>
          <span class="font-semibold">${escapeHtml(shippingMethod)}</span>
        </div>
      </div>

      <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5 space-y-2">
        <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500">Totales</div>
        <div class="text-sm"><b>Subtotal:</b> ${subtotal.toFixed(2)} €</div>
        <div class="text-sm"><b>Envío:</b> ${shippingCost.toFixed(2)} €</div>
        <div class="text-sm"><b>Impuestos:</b> ${taxes.toFixed(2)} €</div>
        <div class="text-lg font-extrabold"><b>Total:</b> ${total.toFixed(2)} €</div>
        <div class="pt-2 text-xs text-slate-500">
          Pago: <b>${escapeHtml(order.financial_status || "—")}</b> ·
          Entrega: <b>${escapeHtml(order.fulfillment_status || "—")}</b>
        </div>
      </div>
    </div>
  `);

  // =========================
  // PRODUCTOS
  // =========================
  if (!items.length) {
    setHtmlSafe("detProductos", `
      <div class="p-6 text-center text-slate-500">
        ⚠️ Este pedido no tiene productos
      </div>
    `);
    return;
  }

  const cards = items.map((item, i) => {
    const requiere = requiereImagenModificada(item);
    const imgMod = imagenesLocales?.[i] ? String(imagenesLocales[i]) : "";

    imagenesRequeridas[i] = !!requiere;
    imagenesCargadas[i] = !!imgMod;

    const propsRaw = Array.isArray(item?.properties) ? item.properties : [];
    const { txt: propsTxt, imgs: propsImg } = splitProps(propsRaw);

    const imgProducto = getProductImage(item);

    const qty = num(item.quantity, 1);
    const price = num(item.price, 0);
    const lineTotal = (price * qty).toFixed(2);

    const badge = requiere
      ? (imgMod
          ? `<span class="px-3 py-1 text-xs rounded-full bg-emerald-100 text-emerald-800 font-bold">Listo</span>`
          : `<span class="px-3 py-1 text-xs rounded-full bg-amber-100 text-amber-800 font-bold">Falta imagen</span>`
        )
      : `<span class="px-3 py-1 text-xs rounded-full bg-slate-100 text-slate-600">Sin imagen</span>`;

    const variant = item.variant_title && item.variant_title !== "Default Title" ? item.variant_title : "";
    const sku = item.sku || "";

    return `
      <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5 space-y-4">

        <div class="flex items-start gap-4">
          ${
            imgProducto
              ? `
                <a href="${escapeHtml(imgProducto)}" target="_blank"
                   class="h-16 w-16 rounded-2xl overflow-hidden border border-slate-200 shadow-sm bg-white flex-shrink-0">
                  <img src="${escapeHtml(imgProducto)}" class="h-full w-full object-cover">
                </a>
              `
              : `
                <div class="h-16 w-16 rounded-2xl border border-slate-200 bg-slate-50 flex items-center justify-center text-slate-400 flex-shrink-0">
                  🧾
                </div>
              `
          }

          <div class="min-w-0 flex-1">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="font-extrabold text-slate-900 truncate">${escapeHtml(item.title || "Producto")}</div>
                <div class="text-sm text-slate-600 mt-1">
                  Cant: <b>${qty}</b> · Precio: <b>${price.toFixed(2)} €</b> · Total: <b>${lineTotal} €</b>
                </div>
              </div>
              ${badge}
            </div>

            <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
              ${variant ? `<div><span class="text-slate-500 font-bold">Variante:</span> <span class="font-semibold">${escapeHtml(variant)}</span></div>` : ""}
              ${sku ? `<div><span class="text-slate-500 font-bold">SKU:</span> <span class="font-semibold">${escapeHtml(sku)}</span></div>` : ""}
              ${item.product_id ? `<div><span class="text-slate-500 font-bold">Product ID:</span> <span class="font-semibold">${escapeHtml(item.product_id)}</span></div>` : ""}
              ${item.variant_id ? `<div><span class="text-slate-500 font-bold">Variant ID:</span> <span class="font-semibold">${escapeHtml(item.variant_id)}</span></div>` : ""}
            </div>
          </div>
        </div>

        ${
          propsTxt.length
            ? `
              <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500 mb-2">Personalización</div>
                <div class="space-y-1 text-sm">
                  ${propsTxt.map(p => `
                    <div class="flex gap-2">
                      <div class="min-w-[140px] text-slate-500 font-bold">${escapeHtml(p.name)}:</div>
                      <div class="flex-1 font-semibold text-slate-900 break-words">${escapeHtml(p.value || "—")}</div>
                    </div>
                  `).join("")}
                </div>
              </div>
            `
            : ""
        }

        ${
          propsImg.length
            ? `
              <div>
                <div class="text-xs font-extrabold text-slate-500 mb-2">Imagen original (cliente)</div>
                <div class="flex flex-wrap gap-3">
                  ${propsImg.map(p => `
                    <a href="${escapeHtml(p.url)}" target="_blank"
                       class="block rounded-2xl border border-slate-200 overflow-hidden shadow-sm bg-white">
                      <img src="${escapeHtml(p.url)}" class="h-28 w-28 object-cover">
                      <div class="px-3 py-2 text-xs font-bold text-slate-700 border-t border-slate-200">
                        ${escapeHtml(p.name)}
                      </div>
                    </a>
                  `).join("")}
                </div>
              </div>
            `
            : ""
        }

        ${
          imgMod
            ? `
              <div>
                <div class="text-xs font-extrabold text-slate-500 mb-2">Imagen modificada</div>
                <a href="${escapeHtml(imgMod)}" target="_blank"
                   class="inline-block rounded-2xl overflow-hidden border border-slate-200 shadow-sm bg-white">
                  <img src="${escapeHtml(imgMod)}" class="h-40 w-40 object-cover">
                </a>
              </div>
            `
            : requiere
              ? `
                <div>
                  <div class="text-xs font-extrabold text-slate-500 mb-2">Subir imagen modificada</div>
                  <input type="file" accept="image/*"
                    class="w-full border border-slate-200 rounded-2xl p-2"
                    onchange="subirImagenProducto('${order.id}', ${i}, this)">
                </div>
              `
              : ""
        }
      </div>
    `;
  }).join("");

  setHtmlSafe("detProductos", `
    <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5 space-y-5">
      <div class="flex items-center justify-between">
        <h3 class="font-extrabold text-slate-900">Productos</h3>
        <span class="text-xs font-extrabold px-3 py-1 rounded-full bg-slate-100">
          ${items.length}
        </span>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        ${cards}
      </div>
    </div>
  `);

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
