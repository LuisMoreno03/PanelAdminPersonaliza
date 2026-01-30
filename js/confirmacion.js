/**
 * confirmacion.js — FULL + DRAG&DROP + EDITOR + AUDITORÍA (STABLE) + NOTA GLOBAL PEDIDO + ORDER_KEY CONSISTENTE
 * - Lista: /confirmacion/my-queue
 * - Pull:  /confirmacion/pull
 * - Detalles: /confirmacion/detalles/{id}
 * - Subir: /confirmacion/subir-imagen
 * - Guardar estado: /confirmacion/guardar-estado
 * - Guardar nota: /confirmacion/guardar-nota
 *
 * ✅ Compatible con tu controller actual:
 *   - detalles() devuelve:
 *       order_key (string)
 *       order_note (objeto: {note, modified_by, modified_at})
 *   - guardarNota() devuelve:
 *       note, modified_by, modified_at (y order_id)
 *
 * Editor (Cropper.js) opcional:
 * <link rel="stylesheet" href="https://unpkg.com/cropperjs@1.6.2/dist/cropper.min.css">
 * <script src="https://unpkg.com/cropperjs@1.6.2/dist/cropper.min.js"></script>
 */

const API = window.API || {};

const ENDPOINT_QUEUE      = (API.myQueue      || "/confirmacion/my-queue").replace(/\/$/, "");
const ENDPOINT_PULL       = (API.pull         || "/confirmacion/pull").replace(/\/$/, "");
const ENDPOINT_RETURN_ALL = (API.returnAll    || "/confirmacion/return-all").replace(/\/$/, "");
const ENDPOINT_DETALLES   = (API.detalles     || "/confirmacion/detalles").replace(/\/$/, "");

const ENDPOINT_SUBIR_IMAGEN    = (API.subirImagen   || "/confirmacion/subir-imagen").replace(/\/$/, "");
const ENDPOINT_GUARDAR_ESTADO  = (API.guardarEstado || "/confirmacion/guardar-estado").replace(/\/$/, "");
const ENDPOINT_GUARDAR_NOTA    = (API.guardarNota   || "/confirmacion/guardar-nota").replace(/\/$/, "");

// Nota del pedido (global)
let DET_ORDER_NOTE = "";
let DET_ORDER_NOTE_AUDIT = { modified_by: "", modified_at: "" };

// ✅ orderKey real (devuelto por backend) para que TODO sea consistente
let DET_ORDER_KEY = "";

// ✅ autosave
let ORDER_NOTE_LAST_SAVED = "";
let __orderNoteDebounce = null;
let __orderNoteSaving = false;

let pedidosCache = [];
let loading = false;

let imagenesRequeridas = [];
let imagenesCargadas = [];
let pedidoActualId = null;

let DET_IMAGENES_LOCALES = {};
let DET_PRODUCT_IMAGES = {};
let DET_ORDER = null;

let PENDING_FILES = {}; // { "<orderId>_<index>": File }
let EDITED_BLOBS  = {}; // { "<orderId>_<index>": Blob }
let EDITED_NAMES  = {}; // { "<orderId>_<index>": "nombre_edit.png" }
let PREVIEW_URLS  = {}; // { "<orderId>_<index>": "blob:..." }

function normalizeOrderId(v) {
  const s = String(v ?? "").trim();
  if (!s) return "";
  const m = s.match(/\/Order\/(\d+)/i);
  return m?.[1] ? m[1] : s;
}

function keyFile(orderId, index) {
  const oid = normalizeOrderId(orderId);
  return `${String(oid)}_${String(index)}`;
}

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

const setLoader   = (v) => $("globalLoader")?.classList.toggle("hidden", !v);
const setTextSafe = (id, v) => $(id) && ($(id).textContent = v ?? "");
const setHtmlSafe = (id, h) => $(id) && ($(id).innerHTML = h ?? "");

/* =====================================================
   USER / FECHA
===================================================== */
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

function withBust(url, token) {
  const u = String(url || "").trim();
  if (!u) return "";
  const t = token ? String(token) : String(Date.now());
  return u + (u.includes("?") ? "&" : "?") + "v=" + encodeURIComponent(t);
}

/* =====================================================
   CSRF
===================================================== */
function getCsrfHeaders() {
  const t = document.querySelector('meta[name="csrf-token"]')?.content;
  const h = document.querySelector('meta[name="csrf-header"]')?.content || "X-CSRF-TOKEN";
  return t ? { [h]: t } : {};
}

/* =====================================================
   LINE ITEMS
===================================================== */
function extraerLineItems(order) {
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
  const title   = String(item?.title || "").toLowerCase();
  const sku     = String(item?.sku || "").toLowerCase();
  const variant = String(item?.variant_title || "").toLowerCase();

  const isLampara =
    title.includes("lampara") ||
    title.includes("lámpara") ||
    variant.includes("lampara") ||
    variant.includes("lámpara");

  return title.includes("llavero") || sku.includes("llav") || isLampara;
}

function requiereImagenModificada(item) {
  const props = Array.isArray(item?.properties) ? item.properties : [];

  const tieneImagenCliente = props.some((p) => esImagenUrl(p?.value));
  const tieneNombreArchivoImagen = props.some((p) =>
    /\.(jpg|jpeg|png|webp|gif|svg)$/i.test(String(p?.value || "").trim())
  );

  return isLlaveroItem(item) || tieneImagenCliente || tieneNombreArchivoImagen;
}

/* =====================================================
   LISTADO
===================================================== */
function renderPedidos(pedidos) {
  const wrap = $("tablaPedidos");
  if (!wrap) return;

  wrap.innerHTML = "";

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

    if (Number(p?.over_24h) === 1) row.classList.add("pedido-overdue");

    const numero  = p.numero || p.name || ("#" + (p.order_number || p.id || ""));
    const fecha   = (p.created_at || p.fecha || "").slice(0, 10);
    const cliente = p.cliente || p.customer_name || p.email || "—";
    const total   = Number(p.total || p.total_price || 0);

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

    const orderKey =
      (p.shopify_order_id !== null &&
        p.shopify_order_id !== undefined &&
        String(p.shopify_order_id).trim() !== "")
        ? p.shopify_order_id
        : p.id;

    row.innerHTML = `
      <div>
        <button type="button" onclick="verDetalles('${escapeHtml(orderKey)}')"
          class="text-left font-extrabold text-slate-900 hover:underline cursor-pointer">
          ${escapeHtml(numero)}
        </button>
      </div>

      <div>${escapeHtml(fecha || "—")}</div>

      <div class="truncate">
        <button type="button" onclick="verDetalles('${escapeHtml(orderKey)}')"
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
   NOTA GLOBAL — GUARDAR (✅ soporta controller actual)
===================================================== */
async function guardarNotaPedido(orderId, note) {
  const modified_by = getCurrentUserLabel();
  const modified_at = new Date().toISOString();

  // ✅ usa DET_ORDER_KEY si existe (ID consistente backend)
  const finalOrderId = normalizeOrderId(DET_ORDER_KEY || orderId);

  const endpoints = [
    ENDPOINT_GUARDAR_NOTA,
    "/index.php" + ENDPOINT_GUARDAR_NOTA,
  ].filter(Boolean);

  let lastErr = null;

  for (const url of endpoints) {
    try {
      const r = await fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/json", ...getCsrfHeaders() },
        credentials: "same-origin",
        body: JSON.stringify({
          order_id: finalOrderId,
          note: String(note ?? ""),
          modified_by,
          modified_at,
        }),
      });

      if (r.status === 404) continue;

      const d = await r.json().catch(() => null);
      if (!r.ok || !(d?.success || d?.ok)) throw new Error(d?.message || `HTTP ${r.status}`);

      // controller responde: note, modified_by, modified_at
      const finalNote = String(d?.note ?? note ?? "");
      DET_ORDER_NOTE = finalNote;

      DET_ORDER_NOTE_AUDIT = {
        modified_by: String(d?.modified_by || modified_by || ""),
        modified_at: String(d?.modified_at || modified_at || ""),
      };

      ORDER_NOTE_LAST_SAVED = DET_ORDER_NOTE;

      return { ok: true, note: DET_ORDER_NOTE, ...DET_ORDER_NOTE_AUDIT };
    } catch (e) {
      lastErr = e;
    }
  }

  console.error("guardarNotaPedido failed:", lastErr);
  return { ok: false, error: lastErr?.message || String(lastErr) };
}

function setupOrderNoteDelegation() {
  // CLICK: Guardar / Limpiar
  document.addEventListener("click", async (e) => {
    const btnSave  = e.target?.closest?.("#btnOrderNoteSave");
    const btnClear = e.target?.closest?.("#btnOrderNoteClear");

    if (!btnSave && !btnClear) return;

    e.preventDefault();

    const oid = normalizeOrderId(DET_ORDER_KEY || pedidoActualId);
    const ta = document.getElementById("orderNoteText");
    const status = document.getElementById("orderNoteStatus");
    const audit = document.getElementById("orderNoteAudit");
    if (!oid || !ta) return;

    if (btnClear) {
      ta.value = "";
      if (status) status.textContent = "Nota vaciada (sin guardar).";
      return;
    }

    btnSave.disabled = true;
    if (status) status.textContent = "Guardando…";

    const res = await guardarNotaPedido(oid, ta.value);

    if (!res.ok) {
      if (status) status.textContent = "Error guardando: " + (res.error || "Error");
      btnSave.disabled = false;
      return;
    }

    if (status) status.textContent = "✅ Nota guardada";
    if (audit) {
      audit.innerHTML = `Última nota: <b class="text-slate-900">${escapeHtml(res.modified_by || "—")}</b>${
        res.modified_at ? ` · ${escapeHtml(formatFechaLocal(res.modified_at))}` : ""
      }`;
    }

    btnSave.disabled = false;
  });

  // INPUT: Autosave (debounce)
  document.addEventListener("input", (e) => {
    const ta = e.target;
    if (!(ta instanceof HTMLTextAreaElement)) return;
    if (ta.id !== "orderNoteText") return;

    const status = document.getElementById("orderNoteStatus");
    if (status) status.textContent = "⏳ Cambios sin guardar…";

    const current = String(ta.value ?? "");
    if (current === String(ORDER_NOTE_LAST_SAVED ?? "")) return;

    clearTimeout(__orderNoteDebounce);
    __orderNoteDebounce = setTimeout(async () => {
      const oid = normalizeOrderId(DET_ORDER_KEY || pedidoActualId);
      if (!oid) return;

      if (__orderNoteSaving) return;
      __orderNoteSaving = true;

      try {
        if (status) status.textContent = "Guardando…";
        const res = await guardarNotaPedido(oid, current);

        if (!res.ok) {
          if (status) status.textContent = "Error guardando: " + (res.error || "Error");
          return;
        }

        const audit = document.getElementById("orderNoteAudit");
        if (audit) {
          audit.innerHTML = `Última nota: <b class="text-slate-900">${escapeHtml(res.modified_by || "—")}</b>${
            res.modified_at ? ` · ${escapeHtml(formatFechaLocal(res.modified_at))}` : ""
          }`;
        }

        if (status) status.textContent = "✅ Guardado automáticamente";
      } finally {
        __orderNoteSaving = false;
      }
    }, 900);
  });
}

/* =====================================================
   DETALLES
===================================================== */
window.verDetalles = async function (orderId) {
  pedidoActualId = normalizeOrderId(orderId);
  abrirDetallesFull();

  setTextSafe("detTitulo", "Cargando pedido…");
  setTextSafe("detCliente", "—");
  setHtmlSafe("detProductos", `<div class="p-6 text-slate-500">Cargando productos…</div>`);
  setHtmlSafe("detResumen", `<div class="text-slate-500">Cargando…</div>`);

  imagenesRequeridas = [];
  imagenesCargadas = [];
  DET_IMAGENES_LOCALES = {};
  DET_PRODUCT_IMAGES = {};
  DET_ORDER = null;

  // reset nota
  DET_ORDER_KEY = "";
  DET_ORDER_NOTE = "";
  DET_ORDER_NOTE_AUDIT = { modified_by: "", modified_at: "" };
  ORDER_NOTE_LAST_SAVED = "";

  PENDING_FILES = {};
  EDITED_BLOBS = {};
  EDITED_NAMES = {};
  PREVIEW_URLS = {};

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

    // ✅ order_key consistente (backend) — NO lo normalizamos a número, puede ser id interno/externo
    DET_ORDER_KEY = String(d.order_key || d.order?.id || pedidoActualId || "").trim();

    // ✅ nota viene como OBJETO {note, modified_by, modified_at}
    const noteObj = d.order_note ?? d.nota_pedido ?? null;

    if (noteObj && typeof noteObj === "object") {
      DET_ORDER_NOTE = String(noteObj.note ?? "").trim();
      DET_ORDER_NOTE_AUDIT = {
        modified_by: String(noteObj.modified_by || noteObj.user || ""),
        modified_at: String(noteObj.modified_at || noteObj.date || ""),
      };
    } else {
      DET_ORDER_NOTE = String(noteObj ?? "").trim();
      DET_ORDER_NOTE_AUDIT = { modified_by: "", modified_at: "" };
    }

    ORDER_NOTE_LAST_SAVED = DET_ORDER_NOTE;

    // pedidoActualId: si viene id en order, lo actualizamos (solo para UI)
    if (d?.order?.id) pedidoActualId = normalizeOrderId(d.order.id);

    renderDetalles(DET_ORDER, DET_IMAGENES_LOCALES, DET_PRODUCT_IMAGES, DET_ORDER_NOTE, DET_ORDER_NOTE_AUDIT);
  } catch (e) {
    console.warn("Detalles fallback:", e);

    const pedido = Array.isArray(pedidosCache)
      ? pedidosCache.find((p) => String(p.shopify_order_id) === pedidoActualId || String(p.id) === pedidoActualId)
      : null;

    if (pedido) {
      DET_ORDER = pedido;
      if (pedido?.shopify_order_id) pedidoActualId = normalizeOrderId(pedido.shopify_order_id);
      else if (pedido?.id) pedidoActualId = normalizeOrderId(pedido.id);

      DET_ORDER_KEY = String(pedido?.shopify_order_id || pedido?.id || pedidoActualId || "").trim();

      DET_ORDER_NOTE = "";
      DET_ORDER_NOTE_AUDIT = { modified_by: "", modified_at: "" };
      ORDER_NOTE_LAST_SAVED = "";

      renderDetalles(pedido, {}, {}, "", {});
    } else {
      setHtmlSafe("detProductos", `<div class="p-6 text-rose-600 font-extrabold">No se pudo cargar el pedido.</div>`);
      setHtmlSafe("detResumen", "");
    }
  }
};

function renderDetalles(order, imagenesLocales = {}, productImages = {}, orderNote = "", orderNoteAudit = {}) {
  const items = extraerLineItems(order);

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
      `<div class="p-6 text-center text-slate-500">Este pedido no tiene productos en detalles.</div>`
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

  // ✅ el orderKey de UI debe coincidir con las keys de imagenes_locales
  // el backend guarda imagenes_locales con $orderKey = shopify_order_id si existe, si no p.id
  // d.order_key viene de orderKeyFromPedido, que coincide con esa misma regla.
  const orderKey = String(DET_ORDER_KEY || order?.id || pedidoActualId || "order").trim();

  // ===== Nota global =====
  const noteAuditText = orderNoteAudit?.modified_by
    ? `Última nota: <b class="text-slate-900">${escapeHtml(orderNoteAudit.modified_by || "—")}</b>${
        orderNoteAudit.modified_at ? ` · ${escapeHtml(formatFechaLocal(orderNoteAudit.modified_at))}` : ""
      }`
    : "";

  const noteHtml = `
    <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-4">
      <div class="flex items-start justify-between gap-3">
        <div>
          <div class="font-extrabold text-slate-900">Nota del pedido</div>
          <div class="text-xs text-slate-500">Información general que pidió el cliente (aplica a todo el pedido)</div>
        </div>
        <div id="orderNoteAudit" class="text-xs text-slate-500 text-right">${noteAuditText}</div>
      </div>

      <textarea id="orderNoteText"
        class="mt-3 w-full min-h-[110px] rounded-2xl border border-slate-200 bg-slate-50 p-3 text-sm outline-none focus:bg-white"
        placeholder="Ej: Cambiar texto, color, enviar con X, preferencia de diseño, etc.">${escapeHtml(orderNote || "")}</textarea>

      <div class="mt-3 flex flex-wrap gap-2">
        <button type="button" id="btnOrderNoteSave"
          class="px-4 py-2 rounded-2xl bg-slate-900 text-white text-[11px] font-extrabold uppercase tracking-wide hover:bg-black transition">
          Guardar nota
        </button>

        <button type="button" id="btnOrderNoteClear"
          class="px-4 py-2 rounded-2xl bg-slate-200 text-slate-900 text-[11px] font-extrabold uppercase tracking-wide hover:bg-slate-300 transition">
          Limpiar
        </button>

        <div class="flex-1"></div>
        <div id="orderNoteStatus" class="text-xs text-slate-500 self-center"></div>
      </div>
    </div>
  `;

  const cardsHtml = items
    .map((item, i) => {
      const qty = toNum(item.quantity || 1);
      const price = toNum(item.price || 0);
      const total = price * qty;

      const requiere = requiereImagenModificada(item);

      const imgLocalObj = normalizeImagenLocal(imagenesLocales?.[String(i)] ?? imagenesLocales?.[i]);
      const imgMod = imgLocalObj.url || "";
      const imgModBust = imgMod ? withBust(imgMod, imgLocalObj.modified_at || Date.now()) : "";

      imagenesRequeridas[i] = !!requiere;
      imagenesCargadas[i] = !!imgMod;

      const { imgs: propsImg, txt: propsTxt } = separarProps(item.properties);
      const imgCliente = propsImg.length ? String(propsImg[0].value || "") : "";
      const imgClienteBust = imgCliente ? withBust(imgCliente, Date.now()) : "";

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
              <img src="${escapeHtml(imgClienteBust)}" class="h-40 w-40 object-cover">
            </a>
          </div>
        `
        : "";

      const imgModHtml = `
        <div id="imgModWrap_${orderKey}_${i}">
          ${
            imgMod
              ? `
                <div class="mt-3">
                  <div class="text-xs font-extrabold text-slate-500 mb-2">Imagen modificada (subida)</div>
                  <a href="${escapeHtml(imgMod)}" target="_blank"
                    class="inline-block rounded-2xl overflow-hidden border border-slate-200 shadow-sm">
                    <img src="${escapeHtml(imgModBust)}" class="h-40 w-40 object-cover">
                  </a>
                </div>
              `
              : requiere
              ? `<div class="mt-3 text-rose-600 font-extrabold text-sm">Falta imagen modificada</div>`
              : ``
          }
        </div>
      `;

      const uploadHtml = requiere
        ? `
          <div class="mt-4">
            <div class="text-xs font-extrabold text-slate-500 mb-2">Subir imagen modificada (puedes reemplazarla)</div>

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
                <button type="button" data-action="edit"
                  class="px-3 py-2 rounded-2xl bg-slate-900 text-white text-[11px] font-extrabold uppercase tracking-wide disabled:opacity-40"
                  disabled>Editar</button>

                <button type="button" data-action="upload"
                  class="px-3 py-2 rounded-2xl bg-blue-600 text-white text-[11px] font-extrabold uppercase tracking-wide hover:bg-blue-700 transition disabled:opacity-40"
                  disabled>Subir</button>

                <button type="button" data-action="clear"
                  class="px-3 py-2 rounded-2xl bg-slate-200 text-slate-900 text-[11px] font-extrabold uppercase tracking-wide hover:bg-slate-300 transition disabled:opacity-40"
                  disabled>Quitar</button>
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
                <div id="badge_item_${orderKey}_${i}">${badgeHtml}</div>
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
        ${noteHtml}

        <div class="flex items-center justify-between">
          <h3 class="font-extrabold text-slate-900">Productos</h3>
          <span class="px-3 py-1 rounded-full text-xs bg-slate-100 font-extrabold">${items.length}</span>
        </div>

        ${cardsHtml}
      </div>
    `
  );

  actualizarResumenAuto();
  hydrateAuditsFromExisting(orderKey, items.length, imagenesLocales);
}

/* =====================================================
   PREVIEW + AUDITORÍA
===================================================== */
function renderLocalPreview(orderId, index, fileOrBlob) {
  const prev = document.getElementById(`preview_${orderId}_${index}`);
  if (!prev) return;

  const k = keyFile(orderId, index);
  const oldUrl = PREVIEW_URLS[k];
  if (oldUrl) {
    try { URL.revokeObjectURL(oldUrl); } catch {}
    delete PREVIEW_URLS[k];
  }

  const url = URL.createObjectURL(fileOrBlob);
  PREVIEW_URLS[k] = url;

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

  const k = keyFile(orderId, index);
  const oldUrl = PREVIEW_URLS[k];
  if (oldUrl) {
    try { URL.revokeObjectURL(oldUrl); } catch {}
    delete PREVIEW_URLS[k];
  }
}

function hydrateAuditsFromExisting(orderId, count, imagenesLocales) {
  for (let i = 0; i < count; i++) {
    const obj = normalizeImagenLocal(imagenesLocales?.[String(i)] ?? imagenesLocales?.[i]);
    const audit = document.getElementById(`audit_${orderId}_${i}`);
    if (!audit) continue;

    audit.innerHTML = obj.url
      ? `Última modificación: <b class="text-slate-900">${escapeHtml(obj.modified_by || "—")}</b>${
          obj.modified_at ? ` · ${escapeHtml(formatFechaLocal(obj.modified_at))}` : ""
        }`
      : "";
  }
}

/* =====================================================
   SUBIR IMAGEN
===================================================== */
async function subirImagenProductoFile(orderId, index, file, meta = {}) {
  const modified_by = getCurrentUserLabel();
  const modified_at = new Date().toISOString();

  if (!(file instanceof File) && !(file instanceof Blob)) {
    alert("Archivo inválido (no es File/Blob).");
    return false;
  }
  if ((file.size ?? 0) <= 0) {
    alert("El archivo está vacío (0 bytes). Vuelve a seleccionarlo.");
    return false;
  }

  const endpoints = [
    ENDPOINT_SUBIR_IMAGEN,
    "/index.php" + ENDPOINT_SUBIR_IMAGEN,
  ].filter(Boolean);

  let lastErr = null;

  for (const url of endpoints) {
    try {
      const fd = new FormData();
      fd.append("order_id", String(orderId)); // 👈 mantener tal cual, controller normaliza
      fd.append("line_index", String(index));
      fd.append("file", file, file.name || `img_${index}.png`);
      fd.append("modified_by", modified_by);
      fd.append("modified_at", modified_at);
      fd.append("edited", meta?.edited ? "1" : "0");

      const r = await fetch(url, {
        method: "POST",
        headers: { ...getCsrfHeaders() },
        body: fd,
        credentials: "same-origin",
      });

      if (r.status === 404) continue;

      const d = await r.json().catch(() => null);
      if (!r.ok || d?.success !== true || !d?.url) {
        throw new Error(d?.message || `HTTP ${r.status}`);
      }

      const urlFinal = String(d.url);
      const bust = withBust(urlFinal, d.modified_at || modified_at || Date.now());

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

      const audit = document.getElementById(`audit_${orderId}_${index}`);
      if (audit) {
        audit.innerHTML = `Última modificación: <b class="text-slate-900">${escapeHtml(modified_by)}</b> · ${escapeHtml(
          formatFechaLocal(modified_at)
        )}`;
      }

      const wrap = document.getElementById(`imgModWrap_${orderId}_${index}`);
      if (wrap) {
        wrap.innerHTML = `
          <div class="mt-3">
            <div class="text-xs font-extrabold text-slate-500 mb-2">Imagen modificada (subida)</div>
            <a href="${escapeHtml(urlFinal)}" target="_blank"
              class="inline-block rounded-2xl overflow-hidden border border-slate-200 shadow-sm">
              <img src="${escapeHtml(bust)}" class="h-40 w-40 object-cover">
            </a>
          </div>
        `;
      }

      imagenesCargadas[index] = true;
      if (!DET_IMAGENES_LOCALES || typeof DET_IMAGENES_LOCALES !== "object") DET_IMAGENES_LOCALES = {};
      DET_IMAGENES_LOCALES[String(index)] = { url: urlFinal, modified_by, modified_at };

      const badge = document.getElementById(`badge_item_${orderId}_${index}`);
      if (badge) {
        badge.innerHTML = `<span class="px-3 py-1 rounded-full text-xs font-extrabold bg-emerald-50 border border-emerald-200 text-emerald-900">Listo</span>`;
      }

      actualizarResumenAuto();
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

      <div class="mt-4">
        <button type="button" onclick="cancelarPedidoActual()"
          class="px-4 py-2 rounded-2xl bg-rose-600 text-white text-[11px] font-extrabold uppercase tracking-wide hover:bg-rose-700 transition">
          Cancelar pedido
        </button>
      </div>
    `
  );
}

/* =====================================================
   GUARDAR ESTADO
===================================================== */
window.guardarEstado = async function (orderId, nuevoEstado, opts = {}) {
  const endpoints = [
    ENDPOINT_GUARDAR_ESTADO,
    "/index.php" + ENDPOINT_GUARDAR_ESTADO,
  ].filter(Boolean);

  let lastErr = null;

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
          order_id: normalizeOrderId(orderId),
          id: normalizeOrderId(orderId),
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
  const oid = normalizeOrderId(pedidoActualId);
  if (!oid) return;

  if (!confirm("¿Cancelar este pedido? Se quitará de tu lista.")) return;

  setLoader(true);
  try {
    const ok = await window.guardarEstado(oid, "Cancelado", { mantener_asignado: false });
    if (!ok) throw new Error("No se pudo guardar el estado.");

    pedidosCache = Array.isArray(pedidosCache)
      ? pedidosCache.filter((p) => {
          const id =
            (p.shopify_order_id !== null &&
              p.shopify_order_id !== undefined &&
              String(p.shopify_order_id).trim() !== "")
              ? String(p.shopify_order_id)
              : String(p.id || "");
          return normalizeOrderId(id) !== oid;
        })
      : [];

    renderPedidos(pedidosCache);
    await cargarMiCola();
    cerrarModalDetalles();
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
    const oid = normalizeOrderId(orderId);
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
    if (nuevoEstado.toLowerCase().includes("faltan") && estadoActual.includes("faltan")) return;
    if (nuevoEstado.toLowerCase().includes("confirm") && estadoActual.includes("confirm")) return;

    const saved = await window.guardarEstado(oid, nuevoEstado);
    if (pedidoLocal) pedidoLocal.estado = nuevoEstado;

    actualizarResumenAuto();
    await cargarMiCola();

    if (saved && nuevoEstado === "Confirmado") {
      cerrarModalDetalles();
    }
  } catch (e) {
    console.error("validarEstadoAuto error:", e);
  }
};

/* =====================================================
   EDITOR (Cropper.js)
===================================================== */
let __editor = { modal: null, img: null, cropper: null, current: null };

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
          <div class="font-extrabold text-slate-900">Editar imagen</div>
          <button type="button" id="btnEditorClose"
            class="px-3 py-2 rounded-2xl bg-slate-100 hover:bg-slate-200 font-extrabold">✕</button>
        </div>

        <div class="p-4">
          <div class="rounded-2xl border bg-slate-50 p-2 flex items-center justify-center">
            <img id="editorImg" alt="editor" class="max-h-[60vh]">
          </div>

          <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" data-ed="rotateL"
              class="px-3 py-2 rounded-2xl bg-slate-900 text-white text-[11px] font-extrabold uppercase">⟲</button>
            <button type="button" data-ed="rotateR"
              class="px-3 py-2 rounded-2xl bg-slate-900 text-white text-[11px] font-extrabold uppercase">⟳</button>
            <button type="button" data-ed="zoomIn"
              class="px-3 py-2 rounded-2xl bg-slate-200 text-slate-900 text-[11px] font-extrabold uppercase">＋</button>
            <button type="button" data-ed="zoomOut"
              class="px-3 py-2 rounded-2xl bg-slate-200 text-slate-900 text-[11px] font-extrabold uppercase">－</button>

            <div class="flex-1"></div>

            <button type="button" data-ed="cancel"
              class="px-3 py-2 rounded-2xl bg-slate-100 hover:bg-slate-200 text-[11px] font-extrabold uppercase">Cancelar</button>
            <button type="button" data-ed="save"
              class="px-3 py-2 rounded-2xl bg-blue-600 hover:bg-blue-700 text-white text-[11px] font-extrabold uppercase">Guardar</button>
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
    alert("Incluye Cropper.js para editar imágenes.");
    return;
  }

  __editor.current = { orderId: String(orderId), index: Number(index), file };

  const url = URL.createObjectURL(file);
  __editor.img.src = url;

  __editor.modal.classList.remove("hidden");

  try { __editor.cropper?.destroy(); } catch {}
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
  try { __editor.cropper?.destroy(); } catch {}
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
  EDITED_NAMES[k] = (file?.name ? file.name.replace(/\.[a-z0-9]+$/i, "") : `edit_${index}`) + "_edit.png";

  renderLocalPreview(normalizeOrderId(orderId), index, blob);
  closeImageEditor();
}

/* =====================================================
   DROPZONES — EVENT DELEGATION (FIX PERMANENTE)
===================================================== */
function zoneInfoFromEl(zone) {
  const orderId = String(zone?.getAttribute("data-order") || "").trim();
  const index = Number(zone?.getAttribute("data-index"));
  const oid = normalizeOrderId(orderId);

  const input = zone.querySelector('input[type="file"]') || document.getElementById(`file_${orderId}_${index}`);
  const btnEdit = zone.querySelector('[data-action="edit"]');
  const btnUpload = zone.querySelector('[data-action="upload"]');
  const btnClear = zone.querySelector('[data-action="clear"]');

  return { orderId, oid, index, input, btnEdit, btnUpload, btnClear, zone };
}

function setZoneButtonsEnabled(zone, enabled) {
  const { btnEdit, btnUpload, btnClear } = zoneInfoFromEl(zone);
  if (btnEdit) btnEdit.disabled = !enabled;
  if (btnUpload) btnUpload.disabled = !enabled;
  if (btnClear) btnClear.disabled = !enabled;
}

function onPickInZone(zone, file) {
  const { oid, orderId, index, input } = zoneInfoFromEl(zone);
  if (!oid || !Number.isFinite(index)) return;
  if (!file) return;

  const k = keyFile(oid, index);
  PENDING_FILES[k] = file;
  delete EDITED_BLOBS[k];
  delete EDITED_NAMES[k];

  try { if (input) input.value = ""; } catch {}

  renderLocalPreview(orderId, index, file);
  setZoneButtonsEnabled(zone, true);
}

async function uploadFromZone(zone) {
  const { oid, orderId, index, input } = zoneInfoFromEl(zone);
  if (!oid || !Number.isFinite(index)) return;

  const k = keyFile(oid, index);
  const editedBlob = EDITED_BLOBS[k];
  const editedName = EDITED_NAMES[k];
  const originalFile = PENDING_FILES[k];

  if (!originalFile && !editedBlob) {
    alert("No hay archivo seleccionado para este producto.");
    return;
  }

  const payloadFile = editedBlob
    ? new File([editedBlob], editedName || originalFile?.name || `edit_${index}.png`, {
        type: editedBlob.type || "image/png",
      })
    : originalFile;

  const ok = await subirImagenProductoFile(orderId, index, payloadFile, { edited: !!editedBlob });
  if (!ok) return;

  delete PENDING_FILES[k];
  delete EDITED_BLOBS[k];
  delete EDITED_NAMES[k];

  try { if (input) input.value = ""; } catch {}
  clearLocalPreview(orderId, index);
  setZoneButtonsEnabled(zone, false);
}

function clearZone(zone) {
  const { oid, orderId, index, input } = zoneInfoFromEl(zone);
  if (!oid || !Number.isFinite(index)) return;

  const k = keyFile(oid, index);
  delete PENDING_FILES[k];
  delete EDITED_BLOBS[k];
  delete EDITED_NAMES[k];

  try { if (input) input.value = ""; } catch {}
  clearLocalPreview(orderId, index);
  setZoneButtonsEnabled(zone, false);
}

function setupDropzoneDelegation() {
  document.addEventListener("click", async (e) => {
    const btn = e.target?.closest?.("[data-action]");
    if (btn) {
      const zone = btn.closest('[data-dropzone="1"]');
      if (!zone) return;

      e.preventDefault();

      const action = btn.getAttribute("data-action");
      if (action === "clear") return clearZone(zone);

      if (action === "edit") {
        const { oid, index } = zoneInfoFromEl(zone);
        const k = keyFile(oid, index);
        const file = PENDING_FILES[k];
        if (!file) return alert("Primero selecciona una imagen para poder editar.");
        return openImageEditor(oid, index, file);
      }

      if (action === "upload") {
        btn.disabled = true;
        try {
          await uploadFromZone(zone);
        } finally {
          const { oid, index } = zoneInfoFromEl(zone);
          const k = keyFile(oid, index);
          btn.disabled = !(PENDING_FILES[k] || EDITED_BLOBS[k]);
        }
        return;
      }
    }

    const zone = e.target?.closest?.('[data-dropzone="1"]');
    if (!zone) return;

    if (e.target?.closest?.("a")) return;

    const { input } = zoneInfoFromEl(zone);
    if (!input) return;

    try { input.value = ""; } catch {}
    input.click();
  });

  document.addEventListener("change", (e) => {
    const input = e.target;
    if (!(input instanceof HTMLInputElement)) return;
    if (input.type !== "file") return;

    const zone = input.closest?.('[data-dropzone="1"]');
    if (!zone) return;

    const file = input.files?.[0];
    onPickInZone(zone, file);
  });

  document.addEventListener("dragover", (e) => {
    const zone = e.target?.closest?.('[data-dropzone="1"]');
    if (!zone) return;
    e.preventDefault();
    zone.classList.add("border-blue-500");
  });

  document.addEventListener("dragleave", (e) => {
    const zone = e.target?.closest?.('[data-dropzone="1"]');
    if (!zone) return;
    zone.classList.remove("border-blue-500");
  });

  document.addEventListener("drop", (e) => {
    const zone = e.target?.closest?.('[data-dropzone="1"]');
    if (!zone) return;

    e.preventDefault();
    zone.classList.remove("border-blue-500");

    const file = e.dataTransfer?.files?.[0];
    onPickInZone(zone, file);
  });
}

/* =====================================================
   INIT
===================================================== */
document.addEventListener("DOMContentLoaded", () => {
  $("btnTraer5")?.addEventListener("click", () => traerPedidos(5));
  $("btnTraer10")?.addEventListener("click", () => traerPedidos(10));
  $("btnDevolver")?.addEventListener("click", devolverPedidos);

  setupDropzoneDelegation();
  setupOrderNoteDelegation(); // ✅ Nota global (incluye autosave)

  cargarMiCola();
});
