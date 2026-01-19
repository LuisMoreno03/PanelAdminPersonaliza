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
    renderDetalles(
  d.order,
  d.imagenes_locales || {},
  d.product_images || {}
);

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

  // ---- Header básico ----
  setTextSafe("detTitulo", `Pedido #${order.name || order.numero || order.id}`);
  setTextSafe("detCliente", (() => {
    const c = order.customer;
    if (c?.first_name || c?.last_name) return `${c.first_name || ""} ${c.last_name || ""}`.trim();
    return order.email || order.cliente || "—";
  })());

  if (!items.length) {
    setHtmlSafe("detProductos", `<div class="p-6 text-center text-slate-500">Este pedido no tiene productos</div>`);
    setHtmlSafe("detResumen", "");
    return;
  }

  // ---- Helpers internos ----
  const toNum = v => {
    const n = Number(v);
    return Number.isFinite(n) ? n : 0;
  };

  const getProductImg = (item) => {
    // 1) si el item ya trae image/featured_image
    const direct = item.image || item.featured_image || item.image_url || "";
    if (direct) return direct;

    // 2) buscar en mapa productImages por product_id
    const pid = item.product_id != null ? String(item.product_id) : "";
    if (pid && productImages && productImages[pid]) return String(productImages[pid]);

    // 3) a veces viene productId (GraphQL) como gid://...
    // (si tu backend alguna vez manda eso, acá puedes mapearlo)
    return "";
  };

  const separarProps = (propsArr) => {
    const props = Array.isArray(propsArr) ? propsArr : [];
    const imgs = [];
    const txt = [];

    for (const p of props) {
      const name = String(p?.name ?? "").trim() || "Campo";
      const valueRaw = p?.value;

      const value =
        valueRaw === null || valueRaw === undefined
          ? ""
          : typeof valueRaw === "object"
          ? JSON.stringify(valueRaw)
          : String(valueRaw);

      if (esImagenUrl(value)) imgs.push({ name, value });
      else txt.push({ name, value });
    }

    return { imgs, txt };
  };

  // ---- Render productos ----
  const cardsHtml = items.map((item, i) => {
    const qty = toNum(item.quantity || 1);
    const price = toNum(item.price || 0);
    const total = (price * qty);

    const requiere = requiereImagenModificada(item);
    const imgMod = imagenesLocales?.[i] ? String(imagenesLocales[i]) : "";

    imagenesRequeridas[i] = !!requiere;
    imagenesCargadas[i] = !!imgMod;

    const { imgs: propsImg, txt: propsTxt } = separarProps(item.properties);

    const imgProducto = getProductImg(item); // ✅ CLAVE
    const variant = item.variant_title && item.variant_title !== "Default Title" ? item.variant_title : "";
    const pid = item.product_id != null ? String(item.product_id) : "";
    const vid = item.variant_id != null ? String(item.variant_id) : "";

    const badge = requiere
      ? (imgMod
        ? `<span class="px-3 py-1 rounded-full text-xs font-extrabold bg-emerald-50 border border-emerald-200 text-emerald-900">Listo</span>`
        : `<span class="px-3 py-1 rounded-full text-xs font-extrabold bg-amber-50 border border-amber-200 text-amber-900">Falta imagen</span>`)
      : `<span class="px-3 py-1 rounded-full text-xs font-extrabold bg-slate-50 border border-slate-200 text-slate-700">Sin imagen</span>`;

    return `
      <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5">
        <div class="flex items-start gap-4">
          
          <!-- Imagen Producto Shopify -->
          ${
            imgProducto
              ? `<a href="${imgProducto}" target="_blank"
                   class="h-20 w-20 rounded-2xl overflow-hidden border border-slate-200 bg-white flex-shrink-0">
                   <img src="${imgProducto}" class="h-full w-full object-cover" />
                 </a>`
              : `<div class="h-20 w-20 rounded-2xl border border-slate-200 bg-slate-50 flex items-center justify-center text-slate-400 flex-shrink-0">
                   🧾
                 </div>`
          }

          <div class="min-w-0 flex-1">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="font-extrabold text-slate-900 truncate">${escapeHtml(item.title || "Producto")}</div>
                <div class="text-sm text-slate-600 mt-1">
                  Cant: <b>${qty}</b> · Precio: <b>${price.toFixed(2)} €</b> · Total: <b>${total.toFixed(2)} €</b>
                </div>

                ${
                  variant
                    ? `<div class="text-sm mt-1"><b>Variante:</b> ${escapeHtml(variant)}</div>`
                    : ""
                }

                <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs text-slate-600">
                  ${pid ? `<div><b>Product ID:</b> ${escapeHtml(pid)}</div>` : ""}
                  ${vid ? `<div><b>Variant ID:</b> ${escapeHtml(vid)}</div>` : ""}
                </div>
              </div>

              ${badge}
            </div>

            <!-- Personalización (texto) -->
            ${
              propsTxt.length
                ? `
                  <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-3">
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

            <!-- Imágenes cliente -->
            ${
              propsImg.length
                ? `
                  <div class="mt-4">
                    <div class="text-xs font-extrabold text-slate-500 mb-2">Imagen original (cliente)</div>
                    <div class="flex flex-wrap gap-3">
                      ${propsImg.map(p => `
                        <a href="${p.value}" target="_blank"
                          class="block rounded-2xl border border-slate-200 overflow-hidden shadow-sm bg-white">
                          <img src="${p.value}" class="h-28 w-28 object-cover">
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

            <!-- Imagen modificada -->
            ${
              imgMod
                ? `
                  <div class="mt-4">
                    <div class="text-xs font-extrabold text-slate-500">Imagen modificada (subida)</div>
                    <a href="${imgMod}" target="_blank"
                      class="inline-block mt-2 rounded-2xl overflow-hidden border border-slate-200 shadow-sm">
                      <img src="${imgMod}" class="h-40 w-40 object-cover">
                    </a>
                  </div>
                `
                : requiere
                  ? `<div class="mt-4 text-rose-600 font-extrabold text-sm">Falta imagen modificada</div>`
                  : ""
            }

            <!-- Upload -->
            ${
              requiere
                ? `
                  <div class="mt-4">
                    <div class="text-xs font-extrabold text-slate-500 mb-2">Subir imagen modificada</div>
                    <input type="file" accept="image/*"
                      class="w-full border border-slate-200 rounded-2xl p-2"
                      onchange="subirImagenProducto('${order.id}', ${i}, this)">
                    <div id="preview_${order.id}_${i}" class="mt-2"></div>
                  </div>
                `
                : ""
            }
          </div>
        </div>
      </div>
    `;
  }).join("");

  setHtmlSafe("detProductos", `
    <div class="space-y-4">
      <div class="flex items-center justify-between">
        <h3 class="font-extrabold text-slate-900">Productos</h3>
        <span class="px-3 py-1 rounded-full text-xs bg-slate-100 font-extrabold">
          ${items.length}
        </span>
      </div>
      ${cardsHtml}
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
