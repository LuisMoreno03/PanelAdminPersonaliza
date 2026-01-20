/**
 * confirmacion.js — VISTA DETALLES COMO ANTES (FULL)
 * - Listado desde /confirmacion/my-queue (tabla pedidos)
 * - Pull desde /confirmacion/pull (tabla pedidos)
 * - verDetalles render full tipo Shopify + imágenes + upload + auto estado
 */

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

// Mantener contexto para subida / re-render
let DET_IMAGENES_LOCALES = {};
let DET_PRODUCT_IMAGES = {};
let DET_ORDER = null;

/* =====================================================
   HELPERS
===================================================== */
const $ = (id) => document.getElementById(id);

const escapeHtml = (str) =>
  String(str ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");

const esUrl = (u) => /^https?:\/\//i.test(String(u || "").trim());

const esImagenUrl = (url) =>
  /https?:\/\/.*\.(jpg|jpeg|png|webp|gif|svg)(\?.*)?$/i.test(String(url || "").trim());

const setLoader = (v) => $("globalLoader")?.classList.toggle("hidden", !v);
const setTextSafe = (id, v) => $(id) && ($(id).textContent = v ?? "");
const setHtmlSafe = (id, h) => $(id) && ($(id).innerHTML = h ?? "");

/* =====================================================
   CSRF
===================================================== */
function getCsrfHeaders() {
  const t = document.querySelector('meta[name="csrf-token"]')?.content;
  const h = document.querySelector('meta[name="csrf-header"]')?.content || "X-CSRF-TOKEN";
  return t ? { [h]: t } : {};
}

/* =====================================================
   NORMALIZAR LINE ITEMS
===================================================== */
function extraerLineItems(order) {
  // REST (Shopify)
  if (Array.isArray(order?.line_items)) {
    return order.line_items.map((i) => ({
      title: i.title || i.name || "Producto",
      quantity: Number(i.quantity || 1),
      price: Number(i.price || 0),
      product_id: i.product_id ?? null,
      variant_id: i.variant_id ?? null,
      variant_title: i.variant_title || "",
      sku: i.sku || "",
      image: i.image?.src || i.featured_image?.src || "",
      properties: Array.isArray(i.properties) ? i.properties : [],
    }));
  }

  // GraphQL (por si algún día lo usas)
  if (order?.lineItems?.edges) {
    return order.lineItems.edges.map(({ node }) => ({
      title: node.title || "Producto",
      quantity: Number(node.quantity || 1),
      price: Number(node.originalUnitPrice?.amount || 0),
      product_id: node.product?.id || null,
      variant_id: node.variant?.id || null,
      variant_title: node.variant?.title || "",
      sku: node.sku || "",
      image: node.product?.featuredImage?.url || "",
      properties: Array.isArray(node.customAttributes)
        ? node.customAttributes.map((p) => ({ name: p.key, value: p.value }))
        : [],
    }));
  }

  return [];
}

/* =====================================================
   REGLAS IMÁGENES
===================================================== */
function isLlaveroItem(item) {
  const title = String(item?.title || "").toLowerCase();
  const sku = String(item?.sku || "").toLowerCase();
  return title.includes("llavero") || sku.includes("llav");
}

function requiereImagenModificada(item) {
  const props = Array.isArray(item?.properties) ? item.properties : [];
  const tieneImagenCliente = props.some((p) => esImagenUrl(p?.value));
  return isLlaveroItem(item) || tieneImagenCliente;
}

/* =====================================================
   LISTADO
===================================================== */
function renderPedidos(pedidos) {
  const wrap = $("tablaPedidos");
  if (!wrap) return;

  wrap.innerHTML = "";

  if (!Array.isArray(pedidos) || !pedidos.length) {
    wrap.innerHTML = `<div class="p-8 text-center text-slate-500">No hay pedidos</div>`;
    setTextSafe("total-pedidos", 0);
    return;
  }

  pedidos.forEach((p) => {
    const row = document.createElement("div");
    row.className = "orders-grid cols px-4 py-3 border-b items-center";

    const numero = p.numero || p.name || ("#" + (p.order_number || p.id || ""));
    const fecha = (p.created_at || p.fecha || "").slice(0, 10);
    const cliente = p.cliente || p.customer_name || p.email || "—";
    const total = Number(p.total || p.total_price || 0);

    const estado = String(p.estado || "Por preparar");
    const estadoPill =
      estado.toLowerCase().includes("faltan")
        ? `<span class="px-3 py-1 text-xs rounded-full bg-amber-500 text-white font-extrabold">FALTAN ARCHIVOS</span>`
        : estado.toLowerCase().includes("confirm")
        ? `<span class="px-3 py-1 text-xs rounded-full bg-emerald-600 text-white font-extrabold">CONFIRMADO</span>`
        : `<span class="px-3 py-1 text-xs rounded-full bg-blue-600 text-white font-extrabold">POR PREPARAR</span>`;

    const estadoEnvio = String(p.estado_envio || p.fulfillment_status || "");
    const envioPill =
      !estadoEnvio
        ? `<span class="px-3 py-1 text-xs rounded-full bg-slate-100">Unfulfilled</span>`
        : estadoEnvio.toLowerCase().includes("fulfilled")
        ? `<span class="px-3 py-1 text-xs rounded-full bg-emerald-100 border border-emerald-200 text-emerald-900 font-extrabold">Fulfilled</span>`
        : `<span class="px-3 py-1 text-xs rounded-full bg-slate-100">Unfulfilled</span>`;

    const orderKey = p.shopify_order_id || p.id;

    row.innerHTML = `
      <div class="font-extrabold">${escapeHtml(numero)}</div>
      <div>${escapeHtml(fecha || "—")}</div>
      <div class="truncate">${escapeHtml(cliente)}</div>
      <div class="font-bold">${total.toFixed(2)} €</div>
      <div>${estadoPill}</div>
      <div>${escapeHtml(p.estado_por || "—")}</div>
      <div>—</div>
      <div class="text-center">${escapeHtml(p.articulos || "—")}</div>
      <div>${envioPill}</div>
      <div class="truncate">${escapeHtml(p.forma_envio || "-")}</div>
      <div class="text-right">
        <button type="button" onclick="verDetalles('${escapeHtml(orderKey)}')"
          class="px-3 py-2 rounded-2xl bg-blue-600 text-white text-[11px] font-extrabold uppercase tracking-wide hover:bg-blue-700 transition">
          Ver detalles →
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
    const d = await r.json().catch(() => null);

    pedidosCache = r.ok && (d?.ok === true || d?.success === true) ? (d.data || []) : [];
    renderPedidos(pedidosCache);
  } catch (e) {
    console.error("cargarMiCola error:", e);
    pedidosCache = [];
    renderPedidos([]);
  } finally {
    loading = false;
    setLoader(false);
  }
}

async function traerPedidos(n) {
  setLoader(true);
  try {
    const r = await fetch(ENDPOINT_PULL, {
      method: "POST",
      headers: { "Content-Type": "application/json", ...getCsrfHeaders() },
      body: JSON.stringify({ count: n }),
      credentials: "same-origin",
    });

    const d = await r.json().catch(() => null);
    if (!r.ok || !d?.ok) {
      alert("Error pull: " + (d?.message || "Error"));
      return;
    }

    await cargarMiCola();
  } catch (e) {
    console.error("pull error", e);
  } finally {
    setLoader(false);
  }
}

async function devolverPedidos() {
  if (!confirm("¿Devolver todos los pedidos?")) return;
  setLoader(true);
  try {
    await fetch(ENDPOINT_RETURN_ALL, {
      method: "POST",
      headers: { ...getCsrfHeaders() },
      credentials: "same-origin",
    });
  } catch (e) {
    console.error("devolverPedidos error:", e);
  }
  await cargarMiCola();
  setLoader(false);
}

/* =====================================================
   MODAL
===================================================== */
function abrirDetallesFull() {
  $("modalDetallesFull")?.classList.remove("hidden");
  document.documentElement.classList.add("overflow-hidden");
  document.body.classList.add("overflow-hidden");
}

function cerrarModalDetalles() {
  $("modalDetallesFull")?.classList.add("hidden");
  document.documentElement.classList.remove("overflow-hidden");
  document.body.classList.remove("overflow-hidden");
}
window.cerrarModalDetalles = cerrarModalDetalles;

/* =====================================================
   DETALLES — VISTA COMO ANTES
===================================================== */
window.verDetalles = async function (orderId) {
  pedidoActualId = String(orderId || "");
  abrirDetallesFull();

  setTextSafe("detTitulo", "Cargando pedido…");
  setTextSafe("detCliente", "—");
  setHtmlSafe("detProductos", `<div class="p-6 text-slate-500">Cargando productos…</div>`);
  setHtmlSafe("detResumen", `<div class="text-slate-500">Cargando…</div>`);

  // reset
  imagenesRequeridas = [];
  imagenesCargadas = [];
  DET_IMAGENES_LOCALES = {};
  DET_PRODUCT_IMAGES = {};
  DET_ORDER = null;

  try {
    const r = await fetch(`${ENDPOINT_DETALLES}/${encodeURIComponent(pedidoActualId)}`, {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    });

    const d = await r.json().catch(() => null);
    if (!r.ok || !d?.success || !d.order) throw new Error(d?.message || "Detalles inválidos");

    DET_ORDER = d.order;
    DET_IMAGENES_LOCALES = d.imagenes_locales || {};
    DET_PRODUCT_IMAGES = d.product_images || {};

    renderDetalles(DET_ORDER, DET_IMAGENES_LOCALES, DET_PRODUCT_IMAGES);
  } catch (e) {
    console.warn("Detalles fallback:", e);

    // fallback: intenta con lo que haya en cache
    const pedido = Array.isArray(pedidosCache)
      ? pedidosCache.find((p) => String(p.shopify_order_id) === pedidoActualId || String(p.id) === pedidoActualId)
      : null;

    if (pedido) {
      DET_ORDER = pedido;
      renderDetalles(pedido, {}, {});
    } else {
      setHtmlSafe("detProductos", `<div class="p-6 text-rose-600 font-extrabold">No se pudo cargar el pedido.</div>`);
      setHtmlSafe("detResumen", "");
    }
  }
};

/* =====================================================
   RENDER DETALLES (tipo Shopify)
===================================================== */
function renderDetalles(order, imagenesLocales = {}, productImages = {}) {
  const items = extraerLineItems(order);

  // guardar estado global
  DET_IMAGENES_LOCALES = imagenesLocales || {};
  DET_PRODUCT_IMAGES = productImages || {};
  DET_ORDER = order;

  imagenesRequeridas = [];
  imagenesCargadas = [];

  const titulo = order?.name || order?.numero || ("#" + (order?.order_number || order?.id || ""));
  setTextSafe("detTitulo", `Pedido ${titulo}`);

  const clienteNombre = (() => {
    const c = order?.customer;
    if (c?.first_name || c?.last_name) return `${c.first_name || ""} ${c.last_name || ""}`.trim();
    return order?.email || order?.cliente || "—";
  })();
  setTextSafe("detCliente", clienteNombre);

  if (!items.length) {
    setHtmlSafe(
      "detProductos",
      `<div class="p-6 text-center text-slate-500">
        Este pedido no tiene productos en <b>detalles</b>.<br>
        (Si no existe pedido_json, el controller debe traer el order completo de Shopify)
      </div>`
    );
    setHtmlSafe("detResumen", "");
    return;
  }

  const toNum = (v) => {
    const n = Number(v);
    return Number.isFinite(n) ? n : 0;
  };

  function getProductImg(item) {
    const direct = String(item?.image || item?.image_url || item?.featured_image || "").trim();
    if (direct) return direct;

    const pid = item?.product_id != null ? String(item.product_id) : "";
    if (pid && productImages && productImages[pid]) return String(productImages[pid]);

    return "";
  }

  function separarProps(propsArr) {
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
  }

  const orderKey = String(order?.id || pedidoActualId || "order");

  const cardsHtml = items
    .map((item, i) => {
      const qty = toNum(item.quantity || 1);
      const price = toNum(item.price || 0);
      const total = price * qty;

      const requiere = requiereImagenModificada(item);
      const imgMod = imagenesLocales?.[i] ? String(imagenesLocales[i]) : "";

      imagenesRequeridas[i] = !!requiere;
      imagenesCargadas[i] = !!imgMod;

      const { imgs: propsImg, txt: propsTxt } = separarProps(item.properties);
      const imgCliente = propsImg.length ? String(propsImg[0].value || "") : "";

      const imgProducto = getProductImg(item);
      const variant = item.variant_title && item.variant_title !== "Default Title" ? item.variant_title : "";
      const pid = item.product_id != null ? String(item.product_id) : "";
      const vid = item.variant_id != null ? String(item.variant_id) : "";
      const sku = item.sku ? String(item.sku) : "";

      const badgeHtml = requiere
        ? imgMod
          ? `<span class="px-3 py-1 rounded-full text-xs font-extrabold bg-emerald-50 border border-emerald-200 text-emerald-900">Listo</span>`
          : `<span class="px-3 py-1 rounded-full text-xs font-extrabold bg-amber-50 border border-amber-200 text-amber-900">Falta imagen</span>`
        : `<span class="px-3 py-1 rounded-full text-xs font-extrabold bg-slate-50 border border-slate-200 text-slate-700">Sin imagen</span>`;

      const propsTxtHtml = propsTxt.length
        ? `
          <div class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 p-3">
            <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500 mb-2">Personalización</div>
            <div class="space-y-1 text-sm">
              ${propsTxt
                .map(({ name, value }) => {
                  const safeName = escapeHtml(name);
                  const safeV = escapeHtml(value || "—");
                  const val = esUrl(value)
                    ? `<a href="${escapeHtml(value)}" target="_blank" class="underline font-semibold text-slate-900">${safeV}</a>`
                    : `<span class="font-semibold text-slate-900 break-words">${safeV}</span>`;

                  return `
                    <div class="flex gap-2">
                      <div class="min-w-[130px] text-slate-500 font-bold">${safeName}:</div>
                      <div class="flex-1">${val}</div>
                    </div>
                  `;
                })
                .join("")}
            </div>
          </div>
        `
        : "";

      const imgClienteHtml = imgCliente
        ? `
          <div class="mt-3">
            <div class="text-xs font-extrabold text-slate-500 mb-2">Imagen original (cliente)</div>
            <a href="${escapeHtml(imgCliente)}" target="_blank"
              class="inline-block rounded-2xl overflow-hidden border border-slate-200 shadow-sm">
              <img src="${escapeHtml(imgCliente)}" class="h-40 w-40 object-cover">
            </a>
          </div>
        `
        : "";

      const imgModHtml = imgMod
        ? `
          <div class="mt-3">
            <div class="text-xs font-extrabold text-slate-500 mb-2">Imagen modificada (subida)</div>
            <a href="${escapeHtml(imgMod)}" target="_blank"
              class="inline-block rounded-2xl overflow-hidden border border-slate-200 shadow-sm">
              <img src="${escapeHtml(imgMod)}" class="h-40 w-40 object-cover">
            </a>
          </div>
        `
        : requiere
        ? `<div class="mt-3 text-rose-600 font-extrabold text-sm">Falta imagen modificada</div>`
        : "";

      const uploadHtml = requiere
        ? `
          <div class="mt-4">
            <div class="text-xs font-extrabold text-slate-500 mb-2">Subir imagen modificada</div>
            <input type="file" accept="image/*"
              onchange="subirImagenProducto('${orderKey}', ${i}, this)"
              class="w-full border border-slate-200 rounded-2xl p-2">
            <div id="preview_${orderKey}_${i}" class="mt-2"></div>
          </div>
        `
        : "";

      const datosIdsHtml = `
        <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
          ${variant ? `<div><span class="text-slate-500 font-bold">Variante:</span> <span class="font-semibold">${escapeHtml(variant)}</span></div>` : ""}
          ${sku ? `<div><span class="text-slate-500 font-bold">SKU:</span> <span class="font-semibold">${escapeHtml(sku)}</span></div>` : ""}
          ${pid ? `<div><span class="text-slate-500 font-bold">Product ID:</span> <span class="font-semibold">${escapeHtml(pid)}</span></div>` : ""}
          ${vid ? `<div><span class="text-slate-500 font-bold">Variant ID:</span> <span class="font-semibold">${escapeHtml(vid)}</span></div>` : ""}
        </div>
      `;

      const productThumbHtml = imgProducto
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
        `;

      return `
        <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-4">
          <div class="flex items-start gap-4">
            ${productThumbHtml}

            <div class="min-w-0 flex-1">
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <div class="font-extrabold text-slate-900 truncate">${escapeHtml(item.title)}</div>
                  <div class="text-sm text-slate-600 mt-1">
                    Cant: <b>${escapeHtml(qty)}</b> · Precio: <b>${escapeHtml(price.toFixed(2))} €</b> · Total: <b>${escapeHtml(total.toFixed(2))} €</b>
                  </div>
                </div>

                <div id="badge_item_${orderKey}_${i}">
                  ${badgeHtml}
                </div>
              </div>

              ${datosIdsHtml}
              ${propsTxtHtml}
              ${imgClienteHtml}
              ${imgModHtml}

              <div id="preview_${orderKey}_${i}" class="mt-2"></div>

              ${uploadHtml}
            </div>
          </div>
        </div>
      `;
    })
    .join("");

  setHtmlSafe(
    "detProductos",
    `
      <div class="space-y-4">
        <div class="flex items-center justify-between">
          <h3 class="font-extrabold text-slate-900">Productos</h3>
          <span class="px-3 py-1 rounded-full text-xs bg-slate-100 font-extrabold">${items.length}</span>
        </div>
        ${cardsHtml}
      </div>
    `
  );

  actualizarResumenAuto();
}

/* =====================================================
   SUBIR IMAGEN MODIFICADA + PREVIEW INMEDIATA
===================================================== */
window.subirImagenProducto = async function (orderId, index, input) {
  try {
    const file = input?.files?.[0];
    if (!file) return;

    const fd = new FormData();
    fd.append("order_id", String(orderId));
    fd.append("line_index", String(index));
    fd.append("file", file);

    const endpoints = [
      window.API?.subirImagen,
      "/api/pedidos/imagenes/subir",
      "/index.php/api/pedidos/imagenes/subir",
      "/index.php/index.php/api/pedidos/imagenes/subir",
    ].filter(Boolean);

    let lastErr = null;

    for (const url of endpoints) {
      try {
        const r = await fetch(url, {
          method: "POST",
          headers: { ...getCsrfHeaders() },
          body: fd,
          credentials: "same-origin",
        });

        if (r.status === 404) continue;

        const d = await r.json().catch(() => null);
        const ok = r.ok && d?.success === true && d?.url;
        if (!ok) throw new Error(d?.message || `HTTP ${r.status}`);

        const urlFinal = String(d.url);
        const bust = urlFinal + (urlFinal.includes("?") ? "&" : "?") + "t=" + Date.now();

        // Preview inmediato
        const prev = document.getElementById(`preview_${orderId}_${index}`);
        if (prev) {
          prev.innerHTML = `
            <div class="mt-2">
              <div class="text-xs font-extrabold text-slate-500 mb-2">Vista previa (subida ✅)</div>
              <a href="${escapeHtml(urlFinal)}" target="_blank"
                class="inline-block rounded-2xl overflow-hidden border border-slate-200 shadow-sm">
                <img src="${escapeHtml(bust)}" class="h-40 w-40 object-cover">
              </a>
            </div>
          `;
        }

        // Marcar cargada
        imagenesCargadas[index] = true;
        if (!DET_IMAGENES_LOCALES || typeof DET_IMAGENES_LOCALES !== "object") DET_IMAGENES_LOCALES = {};
        DET_IMAGENES_LOCALES[index] = urlFinal;

        // Badge a listo
        const badge = document.getElementById(`badge_item_${orderId}_${index}`);
        if (badge) {
          badge.innerHTML = `<span class="px-3 py-1 rounded-full text-xs font-extrabold bg-emerald-50 border border-emerald-200 text-emerald-900">Listo</span>`;
        }

        actualizarResumenAuto();

        // Auto-estado
        await window.validarEstadoAuto(String(orderId));

        try { input.value = ""; } catch {}

        return;
      } catch (e) {
        lastErr = e;
      }
    }

    throw lastErr || new Error("No se encontró endpoint válido para subir imagen.");
  } catch (e) {
    console.error("subirImagenProducto error:", e);
    alert("Error subiendo imagen: " + (e?.message || e));
  }
};

/* =====================================================
   RESUMEN
===================================================== */
function actualizarResumenAuto() {
  const total = imagenesRequeridas.filter(Boolean).length;
  const ok = imagenesCargadas.filter(Boolean).length;

  setHtmlSafe(
    "detResumen",
    `
      <div class="font-extrabold">${ok} / ${total} imágenes cargadas</div>
      <div class="${ok === total ? "text-emerald-600" : "text-amber-600"} font-bold">
        ${ok === total ? "🟢 Todo listo" : "🟡 Faltan imágenes"}
      </div>
    `
  );
}

/* =====================================================
   GUARDAR ESTADO (backend)
===================================================== */
window.guardarEstado = async function (orderId, nuevoEstado) {
  const endpoints = [
    window.API?.guardarEstado,
    "/api/estado/guardar",
    "/index.php/api/estado/guardar",
    "/index.php/index.php/api/estado/guardar",
  ].filter(Boolean);

  let lastErr = null;

  for (const url of endpoints) {
    try {
      const r = await fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/json", ...getCsrfHeaders() },
        credentials: "same-origin",
        body: JSON.stringify({
          order_id: String(orderId),
          id: String(orderId),
          estado: String(nuevoEstado),
        }),
      });

      if (r.status === 404) continue;

      const d = await r.json().catch(() => null);
      if (!r.ok || !(d?.success || d?.ok)) throw new Error(d?.message || `HTTP ${r.status}`);

      return true;
    } catch (e) {
      lastErr = e;
    }
  }

  console.error("guardarEstado failed:", lastErr);
  return false;
};

/* =====================================================
   AUTO-ESTADO
===================================================== */
window.validarEstadoAuto = async function (orderId) {
  try {
    const oid = String(orderId || "");
    if (!oid) return;

    const req = Array.isArray(imagenesRequeridas) ? imagenesRequeridas : [];
    const ok = Array.isArray(imagenesCargadas) ? imagenesCargadas : [];

    const requiredIdx = req.map((v, i) => (v ? i : -1)).filter((i) => i >= 0);
    const requiredCount = requiredIdx.length;
    if (requiredCount < 1) return;

    const uploadedCount = requiredIdx.filter((i) => ok[i] === true).length;
    const faltaAlguna = uploadedCount < requiredCount;

    const nuevoEstado = faltaAlguna ? "Faltan archivos" : "Confirmado";

    const pedidoLocal = Array.isArray(pedidosCache)
      ? pedidosCache.find((p) => String(p.shopify_order_id) === oid || String(p.id) === oid)
      : null;

    const estadoActual = String(pedidoLocal?.estado || "").toLowerCase().trim();
    if (nuevoEstado.toLowerCase().includes("faltan") && estadoActual.includes("faltan")) {
      actualizarResumenAuto();
      return;
    }
    if (nuevoEstado.toLowerCase().includes("confirm") && estadoActual.includes("confirm")) {
      actualizarResumenAuto();
      return;
    }

    const saved = await window.guardarEstado(oid, nuevoEstado);
    if (pedidoLocal) pedidoLocal.estado = nuevoEstado;

    actualizarResumenAuto();

    if (faltaAlguna) {
      await cargarMiCola();
      return;
    }

    if (saved && nuevoEstado === "Confirmado") {
      await cargarMiCola();
      cerrarModalDetalles();
    }
  } catch (e) {
    console.error("validarEstadoAuto error:", e);
  }
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
