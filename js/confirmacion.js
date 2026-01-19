/**
 * confirmacion.js — FIX COMPLETO (LISTO)
 * ✔ Backend + fallback unificados
 * ✔ Render detallado por producto (tipo Shopify)
 * ✔ Imagen producto desde product_images (Shopify)
 * ✔ Imagen cliente (properties) + imagen modificada (local)
 * ✔ Preview inmediato al subir (SIEMPRE visible)
 * ✔ Auto-estado: Faltan archivos / Confirmado
 * ✔ Si Confirmado => se quita de la lista (recarga cola) + cierra modal
 * ✔ Sin variables rotas (imgCliente, imagenesLocales global, etc.)
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

// ✅ para previews y refrescos sin perder data
window.imagenesLocales = window.imagenesLocales || {};
window.__CONF_productImages = window.__CONF_productImages || {};

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

const esImagenUrl = (url) =>
  /https?:\/\/.*\.(jpg|jpeg|png|webp|gif|svg)(\?.*)?$/i.test(String(url || ""));

const esUrl = (u) => /^https?:\/\//i.test(String(u || "").trim());

const setLoader = (v) => $("globalLoader")?.classList.toggle("hidden", !v);
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
  // REST Shopify
  if (Array.isArray(order?.line_items)) {
    return order.line_items.map((i) => ({
      title: i.title,
      quantity: Number(i.quantity || 1),
      price: Number(i.price || 0),
      product_id: i.product_id,
      variant_id: i.variant_id,
      variant_title: i.variant_title || "",
      // Nota: Shopify REST no siempre trae image aquí
      image: i.image?.src || i?.image || "",
      featured_image: i.featured_image || "",
      properties: Array.isArray(i.properties) ? i.properties : [],
      sku: i.sku || "",
    }));
  }

  // GraphQL style
  if (order?.lineItems?.edges) {
    return order.lineItems.edges.map(({ node }) => ({
      title: node.title,
      quantity: Number(node.quantity || 1),
      price: Number(node.originalUnitPrice?.amount || 0),
      product_id: node.product?.id || null,
      variant_id: node.variant?.id || null,
      variant_title: node.variant?.title || "",
      image: node.product?.featuredImage?.url || "",
      featured_image: node.product?.featuredImage?.url || "",
      properties: Array.isArray(node.customAttributes)
        ? node.customAttributes.map((p) => ({ name: p.key, value: p.value }))
        : [],
      sku: node.sku || "",
    }));
  }

  return [];
}

/* =====================================================
   REGLAS IMÁGENES
===================================================== */
const isLlaveroItem = (item) =>
  String(item?.title || "").toLowerCase().includes("llavero");

const requiereImagenModificada = (item) => {
  const props = Array.isArray(item?.properties) ? item.properties : [];
  const tieneImagenCliente = props.some((p) => esImagenUrl(p?.value));
  return isLlaveroItem(item) || tieneImagenCliente;
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
    setTextSafe("total-pedidos", 0);
    return;
  }

  pedidos.forEach((p) => {
    const row = document.createElement("div");
    row.className = "orders-grid cols px-4 py-3 border-b items-center";

    row.innerHTML = `
      <div class="font-extrabold">${escapeHtml(p.numero ?? p.name ?? "-")}</div>
      <div>${escapeHtml((p.created_at || "").slice(0, 10))}</div>
      <div class="truncate">${escapeHtml(p.cliente ?? p.email ?? "-")}</div>
      <div class="font-bold">${Number(p.total || 0).toFixed(2)} €</div>
      <div><span class="px-3 py-1 text-xs rounded-full bg-blue-600 text-white">POR PREPARAR</span></div>
      <div>${escapeHtml(p.estado_por || "—")}</div>
      <div>—</div>
      <div class="text-center">${escapeHtml(p.articulos || 1)}</div>
      <div><span class="px-3 py-1 text-xs rounded-full bg-slate-100">Sin preparar</span></div>
      <div class="truncate">${escapeHtml(p.forma_envio || "-")}</div>
      <div class="text-right">
        <button onclick="verDetalles('${escapeHtml(p.shopify_order_id ?? p.id)}')"
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
    const d = await r.json().catch(() => null);
    pedidosCache = r.ok && d?.ok ? d.data : [];
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
    await fetch(ENDPOINT_PULL, {
      method: "POST",
      headers: { "Content-Type": "application/json", ...getCsrfHeaders() },
      body: JSON.stringify({ count: n }),
      credentials: "same-origin",
    });
  } catch (e) {
    console.error("traerPedidos error:", e);
  }
  await cargarMiCola();
  setLoader(false);
}

async function devolverPedidos() {
  if (!confirm("¿Devolver todos los pedidos?")) return;
  setLoader(true);
  try {
    await fetch(ENDPOINT_RETURN_ALL, {
      method: "POST",
      headers: getCsrfHeaders(),
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
  pedidoActualId = String(orderId || "");
  abrirDetallesFull();
  setTextSafe("detTitulo", "Cargando pedido…");

  // limpia estado anterior
  imagenesRequeridas = [];
  imagenesCargadas = [];
  window.imagenesLocales = {};
  window.__CONF_productImages = {};

  try {
    const r = await fetch(`${ENDPOINT_DETALLES}/${encodeURIComponent(pedidoActualId)}`, {
      credentials: "same-origin",
    });
    const d = await r.json().catch(() => null);
    if (!d?.success) throw new Error("fallback");

    // ✅ guardar para usar en previews / refresh
    window.imagenesLocales = d.imagenes_locales || {};
    window.__CONF_productImages = d.product_images || {};

    renderDetalles(d.order, window.imagenesLocales, window.__CONF_productImages);
  } catch (e) {
    console.warn("verDetalles fallback:", e);
    const pedido = pedidosCache.find((p) => String(p.shopify_order_id) === String(pedidoActualId));
    if (pedido) renderDetalles(pedido, {}, {});
  }
};

/* =====================================================
   RENDER DETALLES COMPLETO
===================================================== */
function renderDetalles(order, imagenesLocales = {}, productImages = {}) {
  const items = extraerLineItems(order);

  // ✅ exponer para subirImagenProducto
  window.imagenesLocales = imagenesLocales || {};
  window.__CONF_productImages = productImages || {};

  imagenesRequeridas = [];
  imagenesCargadas = [];

  // ---- Header ----
  const orderLabel = order?.name || order?.numero || (order?.id ? `#${order.id}` : "—");
  setTextSafe("detTitulo", `Pedido ${orderLabel}`);

  const cliente = (() => {
    const c = order?.customer;
    const full = `${c?.first_name || ""} ${c?.last_name || ""}`.trim();
    return full || order?.email || order?.cliente || "—";
  })();
  setTextSafe("detCliente", cliente);

  if (!items.length) {
    setHtmlSafe(
      "detProductos",
      `<div class="p-6 text-center text-slate-500">Este pedido no tiene productos</div>`
    );
    setHtmlSafe("detResumen", "");
    return;
  }

  const toNum = (v) => {
    const n = Number(v);
    return Number.isFinite(n) ? n : 0;
  };

  const getProductImg = (item) => {
    // 1) imagen directa (si existe)
    const direct =
      String(item?.image || "").trim() ||
      String(item?.featured_image || "").trim() ||
      String(item?.image_url || "").trim();

    if (direct && esUrl(direct)) return direct;

    // 2) mapa product_images por product_id (lo que te devuelve tu backend)
    const pid = item?.product_id != null ? String(item.product_id) : "";
    if (pid && productImages && productImages[pid]) return String(productImages[pid]);

    return "";
  };

  const separarProps = (propsArr) => {
    const props = Array.isArray(propsArr) ? propsArr : [];
    const imgs = [];
    const txt = [];

    for (const p of props) {
      const name = String(p?.name ?? "").trim() || "Campo";
      const raw = p?.value;
      const value =
        raw === null || raw === undefined
          ? ""
          : typeof raw === "object"
          ? JSON.stringify(raw)
          : String(raw);

      if (esImagenUrl(value)) imgs.push({ name, value });
      else txt.push({ name, value });
    }
    return { imgs, txt };
  };

  const cardsHtml = items
    .map((item, i) => {
      const qty = toNum(item.quantity || 1);
      const price = toNum(item.price || 0);
      const total = (price * qty).toFixed(2);

      const requiere = requiereImagenModificada(item);
      const imgMod = window.imagenesLocales?.[i] ? String(window.imagenesLocales[i]) : "";

      imagenesRequeridas[i] = !!requiere;
      imagenesCargadas[i] = !!imgMod;

      const { imgs: propsImg, txt: propsTxt } = separarProps(item.properties);

      // ✅ toma UNA imagen cliente principal (la primera)
      const imgClientePrincipal = propsImg?.[0]?.value || "";

      const imgProducto = getProductImg(item);
      const variant =
        item.variant_title && item.variant_title !== "Default Title" ? item.variant_title : "";

      const pid = item.product_id != null ? String(item.product_id) : "";
      const vid = item.variant_id != null ? String(item.variant_id) : "";
      const sku = item.sku ? String(item.sku) : "";

      const badge = requiere
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
                    ? `<a href="${escapeHtml(value)}" target="_blank" class="underline font-semibold text-slate-900 break-words">${safeV}</a>`
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

      const propsImgsHtml = propsImg.length
        ? `
          <div class="mt-3">
            <div class="text-xs font-extrabold text-slate-500 mb-2">Imagen original (cliente)</div>
            <div class="flex flex-wrap gap-3">
              ${propsImg
                .map(
                  ({ name, value }) => `
                  <a href="${escapeHtml(value)}" target="_blank"
                    class="block rounded-2xl border border-slate-200 overflow-hidden shadow-sm bg-white">
                    <img src="${escapeHtml(value)}" class="h-28 w-28 object-cover">
                    <div class="px-3 py-2 text-xs font-bold text-slate-700 bg-white border-t border-slate-200">
                      ${escapeHtml(name)}
                    </div>
                  </a>
                `
                )
                .join("")}
            </div>
          </div>
        `
        : "";

      const modificadaHtml = imgMod
        ? `
          <div class="mt-3">
            <div class="text-xs font-extrabold text-slate-500">Imagen modificada (subida)</div>
            <a href="${escapeHtml(imgMod)}" target="_blank"
              class="inline-block mt-2 rounded-2xl overflow-hidden border border-slate-200 shadow-sm">
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
              onchange="subirImagenProducto('${escapeHtml(String(order.id || pedidoActualId))}', ${i}, this)"
              class="w-full border border-slate-200 rounded-2xl p-2">
            <div id="preview_${escapeHtml(String(order.id || pedidoActualId))}_${i}" class="mt-2"></div>
          </div>
        `
        : "";

      return `
        <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-4">
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
                  <div class="font-extrabold text-slate-900 truncate">${escapeHtml(
                    item.title || "Producto"
                  )}</div>
                  <div class="text-sm text-slate-600 mt-1">
                    Cant: <b>${escapeHtml(qty)}</b> · Precio: <b>${escapeHtml(price.toFixed(2))} €</b>
                    · Total: <b>${escapeHtml(total)} €</b>
                  </div>
                </div>

                <div id="badge_item_${escapeHtml(String(order.id || pedidoActualId))}_${i}">
                  ${badge}
                </div>
              </div>

              <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
                ${variant ? `<div><span class="text-slate-500 font-bold">Variante:</span> <span class="font-semibold">${escapeHtml(variant)}</span></div>` : ""}
                ${sku ? `<div><span class="text-slate-500 font-bold">SKU:</span> <span class="font-semibold">${escapeHtml(sku)}</span></div>` : ""}
                ${pid ? `<div><span class="text-slate-500 font-bold">Product ID:</span> <span class="font-semibold">${escapeHtml(pid)}</span></div>` : ""}
                ${vid ? `<div><span class="text-slate-500 font-bold">Variant ID:</span> <span class="font-semibold">${escapeHtml(vid)}</span></div>` : ""}
              </div>

              ${propsTxtHtml}
              ${propsImgsHtml}
              ${modificadaHtml}

              <!-- ✅ Preview SIEMPRE -->
              <div id="preview_${escapeHtml(String(order.id || pedidoActualId))}_${i}" class="mt-2"></div>

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
          <span class="px-3 py-1 rounded-full text-xs bg-slate-100 font-extrabold">
            ${items.length}
          </span>
        </div>
        ${cardsHtml}
      </div>
    `
  );

  actualizarResumenAuto(order.id || pedidoActualId);
}

/* =====================================================
   RESUMEN
===================================================== */
function actualizarResumenAuto() {
  const total = imagenesRequeridas.filter(Boolean).length;
  const ok = imagenesRequeridas
    .map((req, i) => (req ? i : -1))
    .filter((i) => i >= 0)
    .filter((i) => imagenesCargadas[i] === true).length;

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
   GUARDAR ESTADO (BACKEND)
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
   AUTO-ESTADO (FALTAN ARCHIVOS / CONFIRMADO)
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

    // evita repetir si ya estaba
    const pedidoLocal = Array.isArray(pedidosCache)
      ? pedidosCache.find((p) => String(p.shopify_order_id) === oid || String(p.id) === oid)
      : null;

    const estadoActual = String(pedidoLocal?.estado || "").toLowerCase().trim();
    if (nuevoEstado.toLowerCase().includes("faltan") && estadoActual.includes("faltan")) return;
    if (nuevoEstado.toLowerCase().includes("confirm") && estadoActual.includes("confirm")) return;

    const saved = await window.guardarEstado(oid, nuevoEstado);
    if (pedidoLocal) pedidoLocal.estado = nuevoEstado;

    // si falta => se queda en confirmación
    if (faltaAlguna) {
      await cargarMiCola();
      return;
    }

    // si confirmado => quitar de lista y cerrar
    if (saved && nuevoEstado === "Confirmado") {
      await cargarMiCola();
      cerrarModalDetalles();
    }
  } catch (e) {
    console.error("validarEstadoAuto error:", e);
  }
};

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
        const okResp = r.ok && d?.success === true && d?.url;
        if (!okResp) throw new Error(d?.message || `HTTP ${r.status}`);

        const urlFinal = String(d.url);

        // 1) Preview inmediato (siempre existe)
        const prev = document.getElementById(`preview_${orderId}_${index}`);
        if (prev) {
          prev.innerHTML = `
            <div>
              <div class="text-xs font-extrabold text-slate-500">Vista previa (subida ✅)</div>
              <a href="${escapeHtml(urlFinal)}" target="_blank">
                <img src="${escapeHtml(urlFinal)}" class="mt-2 h-40 w-40 rounded-2xl border border-slate-200 shadow-sm object-cover">
              </a>
            </div>
          `;
        }

        // 2) marcar cargada
        imagenesCargadas[index] = true;

        // 3) guardar local para no perder al re-render
        if (typeof window.imagenesLocales !== "object" || window.imagenesLocales === null) {
          window.imagenesLocales = {};
        }
        window.imagenesLocales[index] = urlFinal;

        // 4) badge a listo
        const badge = document.getElementById(`badge_item_${orderId}_${index}`);
        if (badge) {
          badge.innerHTML = `
            <span class="px-3 py-1 rounded-full text-xs font-extrabold bg-emerald-50 border border-emerald-200 text-emerald-900">Listo</span>
          `;
        }

        // 5) resumen
        actualizarResumenAuto(orderId);

        // 6) auto-estado + refrescar lista
        await window.validarEstadoAuto(orderId);

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
   INIT
===================================================== */
document.addEventListener("DOMContentLoaded", () => {
  $("btnTraer5")?.addEventListener("click", () => traerPedidos(5));
  $("btnTraer10")?.addEventListener("click", () => traerPedidos(10));
  $("btnDevolver")?.addEventListener("click", devolverPedidos);
  cargarMiCola();
});
