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
  <div class="rounded-3xl border bg-white p-5 shadow-sm space-y-4">
    <div class="flex gap-4">
      ${imgProducto ? `
        <a href="${imgProducto}" target="_blank">
          <img src="${imgProducto}" class="h-20 w-20 rounded-xl border object-cover">
        </a>
      ` : ""}

      <div class="flex-1">
        <div class="flex justify-between">
          <div>
            <div class="font-extrabold">${escapeHtml(item.title)}</div>
            <div class="text-sm text-slate-600">
              Cant: ${item.quantity} · ${Number(item.price).toFixed(2)} € · ${(Number(item.price) * Number(item.quantity)).toFixed(2)} €
            </div>
          </div>

          <div id="badge_item_${order.id}_${i}">
            ${
              requiere
                ? imgMod
                  ? `<span class="text-emerald-600 font-bold">Listo</span>`
                  : `<span class="text-amber-600 font-bold">Falta imagen</span>`
                : `<span class="text-slate-400">Sin imagen</span>`
            }
          </div>
        </div>

        ${item.variant_title ? `<div class="text-sm"><b>Variante:</b> ${escapeHtml(item.variant_title)}</div>` : ""}
        ${item.product_id ? `<div class="text-xs text-slate-500"><b>Product ID:</b> ${escapeHtml(item.product_id)}</div>` : ""}
        ${item.variant_id ? `<div class="text-xs text-slate-500"><b>Variant ID:</b> ${escapeHtml(item.variant_id)}</div>` : ""}

        ${imgCliente ? `
          <div class="mt-3">
            <div class="text-xs font-bold mb-1">Imagen original (cliente)</div>
            <a href="${imgCliente}" target="_blank">
              <img src="${imgCliente}" class="h-32 rounded-xl border object-cover">
            </a>
          </div>
        ` : ""}

        <!-- ✅ Imagen modificada actual (si existe) -->
        ${imgMod ? `
          <div class="mt-3">
            <div class="text-xs font-bold mb-1">Imagen modificada (subida)</div>
            <a href="${imgMod}" target="_blank">
              <img src="${imgMod}" class="h-32 rounded-xl border object-cover">
            </a>
          </div>
        ` : ""}

        <!-- ✅ Preview SIEMPRE existe (aquí pintamos al subir) -->
        <div id="preview_${order.id}_${i}" class="mt-3"></div>

        ${
          requiere
            ? `<div class="mt-3">
                <div class="text-xs font-bold mb-1">Subir imagen modificada</div>
                <input type="file" class="mt-1" accept="image/*"
                  onchange="subirImagenProducto('${order.id}', ${i}, this)">
              </div>`
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
        const ok = r.ok && (d?.success === true) && d?.url;
        if (!ok) throw new Error(d?.message || `HTTP ${r.status}`);

        const urlFinal = String(d.url);

        // ✅ 1) Preview inmediato
        const prev = document.getElementById(`preview_${orderId}_${index}`);
        if (prev) {
          prev.innerHTML = `
            <div>
              <div class="text-xs font-bold mb-1">Vista previa (subida ✅)</div>
              <a href="${urlFinal}" target="_blank">
                <img src="${urlFinal}" class="h-32 rounded-xl border object-cover">
              </a>
            </div>
          `;
        }

        // ✅ 2) Marcar cargada
        if (!Array.isArray(window.imagenesCargadas)) window.imagenesCargadas = [];
        if (!Array.isArray(window.imagenesRequeridas)) window.imagenesRequeridas = [];
        window.imagenesCargadas[index] = true;

        // ✅ 3) Guardar en imagenesLocales local
        if (typeof window.imagenesLocales !== "object" || window.imagenesLocales === null) {
          window.imagenesLocales = {};
        }
        window.imagenesLocales[index] = urlFinal;

        // ✅ 4) Badge del item a "Listo"
        const badge = document.getElementById(`badge_item_${orderId}_${index}`);
        if (badge) badge.innerHTML = `<span class="text-emerald-600 font-bold">Listo</span>`;

        // ✅ 5) Resumen derecha (2/2 etc.)
        if (typeof actualizarResumenAuto === "function") actualizarResumenAuto(orderId);

        // ✅ 6) Auto-estado + refrescar lista si queda confirmado
        if (typeof window.validarEstadoAuto === "function") {
          await window.validarEstadoAuto(orderId);
        }

        return; // ✅ éxito
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
      if (!r.ok || !(d?.success || d?.ok)) {
        throw new Error(d?.message || `HTTP ${r.status}`);
      }

      return true;
    } catch (e) {
      lastErr = e;
    }
  }

  console.error("guardarEstado failed:", lastErr);
  return false;
};
window.validarEstadoAuto = async function (orderId) {
  try {
    const oid = String(orderId || "");
    if (!oid) return;

    const req = Array.isArray(window.imagenesRequeridas) ? window.imagenesRequeridas : [];
    const ok  = Array.isArray(window.imagenesCargadas) ? window.imagenesCargadas : [];

    const requiredIdx = req.map((v, i) => (v ? i : -1)).filter(i => i >= 0);
    const requiredCount = requiredIdx.length;

    // si no requiere imágenes, no forzamos estado
    if (requiredCount < 1) return;

    const uploadedCount = requiredIdx.filter(i => ok[i] === true).length;
    const faltaAlguna = uploadedCount < requiredCount;

    // ✅ estados que quieres
    const nuevoEstado = faltaAlguna ? "Faltan archivos" : "Confirmado";

    // ✅ si ya está en ese estado, no repitas
    const pedidoLocal = Array.isArray(pedidosCache)
      ? pedidosCache.find(p => String(p.shopify_order_id) === oid || String(p.id) === oid)
      : null;

    const estadoActual = String(pedidoLocal?.estado || "").toLowerCase().trim();
    if (nuevoEstado.toLowerCase().includes("faltan") && estadoActual.includes("faltan")) return;
    if (nuevoEstado.toLowerCase().includes("confirm") && estadoActual.includes("confirm")) return;

    // ✅ guardar en backend
    const saved = await window.guardarEstado(oid, nuevoEstado);

    // ✅ actualizar cache local (para que la lista cambie)
    if (pedidoLocal) pedidoLocal.estado = nuevoEstado;

    // ✅ si falta => se queda en confirmación y el resumen debe decir faltan
    if (faltaAlguna) {
      if (typeof cargarMiCola === "function") await cargarMiCola();
      return;
    }

    // ✅ si ya quedó confirmado => quitar de la lista
    if (saved && nuevoEstado === "Confirmado") {
      // refresca lista desde backend (lo ideal)
      if (typeof cargarMiCola === "function") await cargarMiCola();

      // opcional: cerrar modal automáticamente
      if (typeof cerrarModalDetalles === "function") cerrarModalDetalles();
    }
  } catch (e) {
    console.error("validarEstadoAuto error:", e);
  }
};
