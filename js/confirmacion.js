/**
 * confirmacion.js — VISTA DETALLES COMO ANTES (FULL) + DRAG&DROP + EDITOR + AUDITORÍA
 * - Listado desde /confirmacion/my-queue (tabla pedidos)
 * - Pull desde /confirmacion/pull (tabla pedidos)
 * - verDetalles render full tipo Shopify + imágenes + upload + auto estado
 *
 * NUEVO:
 * - Subida con Drag & Drop + click
 * - Editor (Cropper.js) antes de subir (rotar/zoom/crop)
 * - Auditoría: modified_by + modified_at (se envía al backend y se muestra)
 * - Estado "Faltan archivos" NO debe desasignar (se envía mantener_asignado: true en guardarEstado)
 *
 * Requiere (para editor):
 * - cropperjs en tu layout:
 *   <link rel="stylesheet" href="https://unpkg.com/cropperjs@1.6.2/dist/cropper.min.css">
 *   <script src="https://unpkg.com/cropperjs@1.6.2/dist/cropper.min.js"></script>
 * - opcional: usuario actual
 *   <meta name="current-user" content="Luis Moreno">
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
   DRAG & DROP + EDITOR + AUDITORÍA
===================================================== */
let PENDING_FILES = {}; // { "<orderId>_<index>": File }
let EDITED_BLOBS = {};  // { "<orderId>_<index>": Blob }
let EDITED_NAMES = {};  // { "<orderId>_<index>": "nombre_edit.png" }

function keyFile(orderId, index) {
  return `${String(orderId)}_${String(index)}`;
}

function getCurrentUserLabel() {
  const metaUser = document.querySelector('meta[name="current-user"]')?.content?.trim();
  if (metaUser) return metaUser;

  if (window.CURRENT_USER) return String(window.CURRENT_USER);
  if (window.API?.currentUser) return String(window.API.currentUser);

  return "Desconocido";
}

function formatFechaLocal(iso) {
  if (!iso) return "";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return String(iso);
  return d.toLocaleString();
}

function normalizeImagenLocal(v) {
  // Compatibilidad: antes era string URL, ahora permitimos objeto con auditoría
  if (!v) return { url: "", modified_by: "", modified_at: "" };
  if (typeof v === "string") return { url: v, modified_by: "", modified_at: "" };
  if (typeof v === "object") {
    return {
      url: String(v.url || v.value || ""),
      modified_by: String(v.modified_by || v.user || ""),
      modified_at: String(v.modified_at || v.date || ""),
    };
  }
  return { url: String(v), modified_by: "", modified_at: "" };
}

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

  // ✅ Ocultar cancelados en la lista (por si backend los devuelve)
  const visibles = Array.isArray(pedidos)
    ? pedidos.filter((p) => !String(p?.estado || "").toLowerCase().includes("cancel"))
    : [];

  if (!visibles.length) {
    wrap.innerHTML = `<div class="p-8 text-center text-slate-500">No hay pedidos</div>`;
    setTextSafe("total-pedidos", 0);
    return;
  }

  visibles.forEach((p) => {
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

    // ✅ Ahora el # pedido y el cliente abren detalles
    row.innerHTML = `
      <div>
        <button type="button"
          onclick="verDetalles('${escapeHtml(orderKey)}')"
          class="text-left font-extrabold text-slate-900 hover:underline cursor-pointer">
          ${escapeHtml(numero)}
        </button>
      </div>

      <div>${escapeHtml(fecha || "—")}</div>

      <div class="truncate">
        <button type="button"
          onclick="verDetalles('${escapeHtml(orderKey)}')"
          class="text-left font-bold text-slate-900 hover:underline cursor-pointer truncate">
          ${escapeHtml(cliente)}
        </button>
      </div>

      <div class="font-bold">${total.toFixed(2)} €</div>
      <div>${estadoPill}</div>
      <div>${escapeHtml(p.estado_por || "—")}</div>
      <div>—</div>
      <div class="text-center">${escapeHtml(p.articulos || "—")}</div>
      <div>${envioPill}</div>
      <div class="truncate">${escapeHtml(p.forma_envio || "-")}</div>

      <!-- ✅ Columna final: ya no hay botón -->
      <div class="text-right"></div>
    `;

    wrap.appendChild(row);
  });

  setTextSafe("total-pedidos", visibles.length);
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

  // reset editor caches por seguridad
  PENDING_FILES = {};
  EDITED_BLOBS = {};
  EDITED_NAMES = {};

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

      const imgLocalObj = normalizeImagenLocal(imagenesLocales?.[i]);
      const imgMod = imgLocalObj.url || "";

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

            <div
              id="dz_${orderKey}_${i}"
              data-dropzone="1"
              data-order="${escapeHtml(orderKey)}"
              data-index="${i}"
              class="rounded-2xl border-2 border-dashed border-slate-300 bg-slate-50 p-4 cursor-pointer hover:bg-slate-100 transition"
            >
              <div class="font-extrabold text-slate-700">Arrastra y suelta aquí</div>
              <div class="text-xs text-slate-500 mt-1">o haz clic para elegir un archivo</div>

              <input id="file_${orderKey}_${i}" type="file" accept="image/*" class="hidden" />

              <div class="mt-3 flex flex-wrap gap-2">
                <button type="button"
                  data-action="edit"
                  class="px-3 py-2 rounded-2xl bg-slate-900 text-white text-[11px] font-extrabold uppercase tracking-wide disabled:opacity-40"
                  disabled
                >Editar</button>

                <button type="button"
                  data-action="upload"
                  class="px-3 py-2 rounded-2xl bg-blue-600 text-white text-[11px] font-extrabold uppercase tracking-wide hover:bg-blue-700 transition disabled:opacity-40"
                  disabled
                >Subir</button>

                <button type="button"
                  data-action="clear"
                  class="px-3 py-2 rounded-2xl bg-slate-200 text-slate-900 text-[11px] font-extrabold uppercase tracking-wide hover:bg-slate-300 transition disabled:opacity-40"
                  disabled
                >Quitar</button>
              </div>

              <div id="preview_${orderKey}_${i}" class="mt-3"></div>
              <div id="audit_${orderKey}_${i}" class="mt-2 text-xs text-slate-500"></div>
            </div>
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

  // Inicializar dropzones + auditoría
  initDropzones(orderKey);
  hydrateAuditsFromExisting(orderKey, items.length, imagenesLocales);
}

/* =====================================================
   DROPZONES + PREVIEW LOCAL + AUDITORÍA
===================================================== */
function initDropzones(orderId) {
  const oidStr = String(orderId);
  const zones = document.querySelectorAll(`[data-dropzone="1"][data-order="${CSS.escape(oidStr)}"]`);

  zones.forEach((zone) => {
    const oid = zone.getAttribute("data-order");
    const idx = Number(zone.getAttribute("data-index"));

    const input = document.getElementById(`file_${oid}_${idx}`);
    const btnEdit = zone.querySelector(`[data-action="edit"]`);
    const btnUpload = zone.querySelector(`[data-action="upload"]`);
    const btnClear = zone.querySelector(`[data-action="clear"]`);

    const enableBtns = (v) => {
      if (btnEdit) btnEdit.disabled = !v;
      if (btnUpload) btnUpload.disabled = !v;
      if (btnClear) btnClear.disabled = !v;
    };

    const onPick = (file) => {
      if (!file) return;
      const k = keyFile(oid, idx);
      PENDING_FILES[k] = file;
      delete EDITED_BLOBS[k];
      delete EDITED_NAMES[k];
      renderLocalPreview(oid, idx, file);
      enableBtns(true);
    };

    // click zona => abrir picker (pero no al pulsar botones)
    zone.addEventListener("click", (e) => {
      const tag = (e.target?.tagName || "").toLowerCase();
      if (tag === "button") return;
      input?.click();
    });

    input?.addEventListener("change", () => onPick(input.files?.[0]));

    // drag & drop
    zone.addEventListener("dragover", (e) => {
      e.preventDefault();
      zone.classList.add("border-blue-500");
    });

    zone.addEventListener("dragleave", () => zone.classList.remove("border-blue-500"));

    zone.addEventListener("drop", (e) => {
      e.preventDefault();
      zone.classList.remove("border-blue-500");
      const file = e.dataTransfer?.files?.[0];
      onPick(file);
    });

    btnClear?.addEventListener("click", (e) => {
      e.preventDefault();
      const k = keyFile(oid, idx);
      delete PENDING_FILES[k];
      delete EDITED_BLOBS[k];
      delete EDITED_NAMES[k];
      if (input) input.value = "";
      clearLocalPreview(oid, idx);
      enableBtns(false);
    });

    btnEdit?.addEventListener("click", (e) => {
      e.preventDefault();
      const k = keyFile(oid, idx);
      const file = PENDING_FILES[k];
      if (!file) return;
      openImageEditor(oid, idx, file);
    });

    btnUpload?.addEventListener("click", async (e) => {
      e.preventDefault();
      const k = keyFile(oid, idx);

      const editedBlob = EDITED_BLOBS[k];
      const editedName = EDITED_NAMES[k];
      const originalFile = PENDING_FILES[k];
      if (!originalFile && !editedBlob) return;

      const payloadFile = editedBlob
        ? new File([editedBlob], editedName || originalFile?.name || `edit_${idx}.png`, {
            type: editedBlob.type || "image/png",
          })
        : originalFile;

      await subirImagenProductoFile(oid, idx, payloadFile, { edited: !!editedBlob });

      // limpiar pendientes
      delete PENDING_FILES[k];
      delete EDITED_BLOBS[k];
      delete EDITED_NAMES[k];
      if (input) input.value = "";
      enableBtns(false);
    });
  });
}

function renderLocalPreview(orderId, index, fileOrBlob) {
  const prev = document.getElementById(`preview_${orderId}_${index}`);
  if (!prev) return;
  const url = URL.createObjectURL(fileOrBlob);
  prev.innerHTML = `
    <div>
      <div class="text-xs font-extrabold text-slate-500 mb-2">Vista previa (local)</div>
      <div class="inline-block rounded-2xl overflow-hidden border border-slate-200 shadow-sm">
        <img src="${escapeHtml(url)}" class="h-40 w-40 object-cover">
      </div>
    </div>
  `;
}

function clearLocalPreview(orderId, index) {
  const prev = document.getElementById(`preview_${orderId}_${index}`);
  if (prev) prev.innerHTML = "";
}

function hydrateAuditsFromExisting(orderId, count, imagenesLocales) {
  for (let i = 0; i < count; i++) {
    const obj = normalizeImagenLocal(imagenesLocales?.[i]);
    const audit = document.getElementById(`audit_${orderId}_${i}`);
    if (!audit) continue;

    if (obj.url) {
      audit.innerHTML = `
        <span>Última modificación:</span>
        <b class="text-slate-900">${escapeHtml(obj.modified_by || "—")}</b>
        ${obj.modified_at ? ` · ${escapeHtml(formatFechaLocal(obj.modified_at))}` : ""}
      `;
    } else {
      audit.innerHTML = "";
    }
  }
}

/* =====================================================
   SUBIR IMAGEN MODIFICADA + PREVIEW INMEDIATA
===================================================== */
// Compat (por si alguna parte del HTML antiguo lo sigue usando)
window.subirImagenProducto = async function (orderId, index, input) {
  try {
    const file = input?.files?.[0];
    if (!file) return;
    await subirImagenProductoFile(String(orderId), Number(index), file, { edited: false });
    try {
      input.value = "";
    } catch {}
  } catch (e) {
    console.error("subirImagenProducto error:", e);
    alert("Error subiendo imagen: " + (e?.message || e));
  }
};

async function subirImagenProductoFile(orderId, index, file, meta = {}) {
  const modified_by = getCurrentUserLabel();
  const modified_at = new Date().toISOString();

  const fd = new FormData();
  fd.append("order_id", String(orderId));
  fd.append("line_index", String(index));
  fd.append("file", file);

  // Auditoría
  fd.append("modified_by", modified_by);
  fd.append("modified_at", modified_at);
  fd.append("edited", meta?.edited ? "1" : "0");

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

      // Preview inmediato (subida)
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

      // Auditoría visible
      const audit = document.getElementById(`audit_${orderId}_${index}`);
      if (audit) {
        audit.innerHTML = `
          <span>Última modificación:</span>
          <b class="text-slate-900">${escapeHtml(modified_by)}</b>
          · ${escapeHtml(formatFechaLocal(modified_at))}
        `;
      }

      // Marcar cargada + guardar localmente (con auditoría)
      imagenesCargadas[index] = true;
      if (!DET_IMAGENES_LOCALES || typeof DET_IMAGENES_LOCALES !== "object") DET_IMAGENES_LOCALES = {};
      DET_IMAGENES_LOCALES[index] = { url: urlFinal, modified_by, modified_at };

      // Badge a listo
      const badge = document.getElementById(`badge_item_${orderId}_${index}`);
      if (badge) {
        badge.innerHTML = `<span class="px-3 py-1 rounded-full text-xs font-extrabold bg-emerald-50 border border-emerald-200 text-emerald-900">Listo</span>`;
      }

      actualizarResumenAuto();

      // Auto-estado
      await window.validarEstadoAuto(String(orderId));

      return true;
    } catch (e) {
      lastErr = e;
    }
  }

  console.error("subirImagenProductoFile error:", lastErr);
  alert("Error subiendo imagen: " + (lastErr?.message || lastErr));
  return false;
}

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

      <!-- ✅ Botón cancelar debajo de la confirmación -->
      <div class="mt-4">
        <button type="button"
          onclick="cancelarPedidoActual()"
          class="px-4 py-2 rounded-2xl bg-rose-600 text-white text-[11px] font-extrabold uppercase tracking-wide hover:bg-rose-700 transition">
          Cancelar pedido
        </button>
      </div>
    `
  );
}

/* =====================================================
   GUARDAR ESTADO (backend)
===================================================== */
window.guardarEstado = async function (orderId, nuevoEstado, opts = {}) {
  const endpoints = [
    window.API?.guardarEstado,
    "/api/estado/guardar",
    "/index.php/api/estado/guardar",
    "/index.php/index.php/api/estado/guardar",
  ].filter(Boolean);

  let lastErr = null;

  // ✅ por defecto: mantener asignado SOLO si es "Faltan archivos"
  const mantener_asignado =
    typeof opts?.mantener_asignado === "boolean"
      ? opts.mantener_asignado
      : String(nuevoEstado || "").toLowerCase().includes("faltan");

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
          mantener_asignado,
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



window.cancelarPedidoActual = async function () {
  const oid = String(pedidoActualId || "");
  if (!oid) return;

  if (!confirm("¿Seguro que deseas CANCELAR este pedido? Pasará a estado 'Cancelado' y se quitará de tu lista.")) {
    return;
  }

  setLoader(true);

  try {
    const ok = await window.guardarEstado(oid, "Cancelado", { mantener_asignado: false });
    if (!ok) throw new Error("No se pudo guardar el estado.");

    // actualizar cache local
    const pedidoLocal = Array.isArray(pedidosCache)
      ? pedidosCache.find((p) => String(p.shopify_order_id) === oid || String(p.id) === oid)
      : null;

    if (pedidoLocal) pedidoLocal.estado = "Cancelado";

    await cargarMiCola();        // recarga lista
    cerrarModalDetalles();       // cierra modal
  } catch (e) {
    console.error("cancelarPedidoActual error:", e);
    alert("Error cancelando pedido: " + (e?.message || e));
  } finally {
    setLoader(false);
  }
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

    // Si faltan, mantenemos asignación (backend) y refrescamos lista, SIN cerrar modal
    if (faltaAlguna) {
      await cargarMiCola();
      return;
    }

    // Si ya está confirmado, cerramos
    if (saved && nuevoEstado === "Confirmado") {
      await cargarMiCola();
      cerrarModalDetalles();
    }
  } catch (e) {
    console.error("validarEstadoAuto error:", e);
  }
};

/* =====================================================
   EDITOR (Cropper.js)
===================================================== */
let __editor = {
  modal: null,
  img: null,
  cropper: null,
  current: null, // { orderId, index, file }
};

function ensureEditorModal() {
  if (__editor.modal) return;

  const modal = document.createElement("div");
  modal.id = "imgEditorModal";
  modal.className = "fixed inset-0 z-[9999] hidden";
  modal.innerHTML = `
    <div class="absolute inset-0 bg-black/60"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
      <div class="w-full max-w-3xl rounded-3xl bg-white shadow-xl overflow-hidden">
        <div class="p-4 border-b flex items-center justify-between">
          <div class="font-extrabold text-slate-900">Editar imagen antes de subir</div>
          <button type="button" id="btnEditorClose" class="px-3 py-2 rounded-2xl bg-slate-100 hover:bg-slate-200 font-extrabold">✕</button>
        </div>

        <div class="p-4">
          <div class="rounded-2xl border bg-slate-50 p-2 flex items-center justify-center">
            <img id="editorImg" alt="editor" class="max-h-[60vh]">
          </div>

          <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" data-ed="rotateL" class="px-3 py-2 rounded-2xl bg-slate-900 text-white text-[11px] font-extrabold uppercase">⟲ Rotar</button>
            <button type="button" data-ed="rotateR" class="px-3 py-2 rounded-2xl bg-slate-900 text-white text-[11px] font-extrabold uppercase">⟳ Rotar</button>
            <button type="button" data-ed="zoomIn" class="px-3 py-2 rounded-2xl bg-slate-200 text-slate-900 text-[11px] font-extrabold uppercase">＋ Zoom</button>
            <button type="button" data-ed="zoomOut" class="px-3 py-2 rounded-2xl bg-slate-200 text-slate-900 text-[11px] font-extrabold uppercase">－ Zoom</button>

            <div class="flex-1"></div>

            <button type="button" data-ed="cancel" class="px-3 py-2 rounded-2xl bg-slate-100 hover:bg-slate-200 text-[11px] font-extrabold uppercase">Cancelar</button>
            <button type="button" data-ed="save" class="px-3 py-2 rounded-2xl bg-blue-600 hover:bg-blue-700 text-white text-[11px] font-extrabold uppercase">Guardar edición</button>
          </div>

          <div class="mt-2 text-xs text-slate-500">
            Tip: ajusta el encuadre y guarda. Luego pulsa “Subir”.
          </div>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(modal);

  __editor.modal = modal;
  __editor.img = modal.querySelector("#editorImg");

  modal.querySelector("#btnEditorClose")?.addEventListener("click", closeImageEditor);
  modal.addEventListener("click", (e) => {
    const bg = modal.querySelector(".absolute.inset-0.bg-black\\/60");
    if (e.target === bg) closeImageEditor();
  });

  modal.querySelectorAll("[data-ed]").forEach((btn) => {
    btn.addEventListener("click", async () => {
      const action = btn.getAttribute("data-ed");
      if (action === "cancel") return closeImageEditor();
      if (action === "save") return saveImageEditor();
      if (!__editor.cropper) return;

      if (action === "rotateL") __editor.cropper.rotate(-90);
      if (action === "rotateR") __editor.cropper.rotate(90);
      if (action === "zoomIn") __editor.cropper.zoom(0.1);
      if (action === "zoomOut") __editor.cropper.zoom(-0.1);
    });
  });
}

function openImageEditor(orderId, index, file) {
  ensureEditorModal();

  if (!window.Cropper) {
    alert("Para editar imágenes, incluye Cropper.js (CDN o npm).");
    return;
  }

  __editor.current = { orderId: String(orderId), index: Number(index), file };

  const url = URL.createObjectURL(file);
  __editor.img.src = url;

  __editor.modal.classList.remove("hidden");

  // destruir cropper previo
  try {
    __editor.cropper?.destroy();
  } catch {}
  __editor.cropper = new Cropper(__editor.img, {
    viewMode: 1,
    autoCropArea: 1,
    responsive: true,
    background: false,
  });
}

function closeImageEditor() {
  if (!__editor.modal) return;
  __editor.modal.classList.add("hidden");
  try {
    __editor.cropper?.destroy();
  } catch {}
  __editor.cropper = null;
  __editor.current = null;
}

async function saveImageEditor() {
  if (!__editor.cropper || !__editor.current) return;

  const { orderId, index, file } = __editor.current;
  const k = keyFile(orderId, index);

  const canvas = __editor.cropper.getCroppedCanvas({
    imageSmoothingEnabled: true,
    imageSmoothingQuality: "high",
  });

  const blob = await new Promise((res) => canvas.toBlob(res, "image/png", 0.92));
  if (!blob) return alert("No se pudo generar la imagen editada.");

  EDITED_BLOBS[k] = blob;
  EDITED_NAMES[k] =
    (file?.name ? file.name.replace(/\.[a-z0-9]+$/i, "") : `edit_${index}`) + "_edit.png";

  // preview local del resultado editado
  renderLocalPreview(orderId, index, blob);
  closeImageEditor();
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
