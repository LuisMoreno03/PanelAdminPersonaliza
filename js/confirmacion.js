/**
 * confirmacion.js — COMPLETO
 * Módulo Confirmación
 * Independiente de dashboard.js
 */

/* =====================================================
  CONFIG
===================================================== */
const API = window.API || {};
const ENDPOINT_QUEUE = API.myQueue;
const ENDPOINT_PULL = API.pull;
const ENDPOINT_RETURN_ALL = API.returnAll;
const ENDPOINT_DETALLES = "/dashboard/detalles";

let pedidosCache = [];
let loading = false;

/* =====================================================
  HELPERS
===================================================== */
function $(id) {
  return document.getElementById(id);
}

function escapeHtml(str) {
  return String(str ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function esUrl(u) {
  return /^https?:\/\//i.test(String(u || "").trim());
}

function esImagenUrl(url) {
  if (!url) return false;
  return /https?:\/\/.*\.(jpeg|jpg|png|gif|webp|svg)(\?.*)?$/i.test(String(url));
}

function setLoader(show) {
  const el = $("globalLoader");
  if (!el) return;
  el.classList.toggle("hidden", !show);
}

/* =====================================================
  LISTADO PEDIDOS
===================================================== */
function renderPedidos(pedidos) {
  const cont = $("tablaPedidos");
  cont.innerHTML = "";

  if (!pedidos.length) {
    cont.innerHTML = `<div class="p-8 text-center text-slate-500">No hay pedidos</div>`;
    $("total-pedidos").textContent = "0";
    return;
  }

  pedidos.forEach(p => {
    const row = document.createElement("div");
    row.className = "orders-grid cols px-4 py-3 border-b items-center text-sm";

    row.innerHTML = `
      <div class="font-extrabold">${escapeHtml(p.numero)}</div>
      <div>${escapeHtml((p.created_at || "").slice(0,10))}</div>
      <div class="truncate">${escapeHtml(p.cliente)}</div>
      <div class="font-bold">${Number(p.total).toFixed(2)} €</div>

      <div>
        <span class="px-3 py-1 rounded-full text-xs font-extrabold bg-blue-600 text-white">
          POR PREPARAR
        </span>
      </div>

      <div>—</div>

      <div>
        <button class="px-3 py-1 rounded-full text-xs font-bold border">
          ETIQUETAS +
        </button>
      </div>

      <div class="text-center">${p.articulos || 1}</div>

      <div>
        <span class="px-3 py-1 rounded-full text-xs font-bold bg-slate-100">
          Sin preparar
        </span>
      </div>

      <div class="text-xs">${escapeHtml(p.forma_envio || "-")}</div>

      <div class="text-right">
        <button
          onclick="verDetalles('${p.shopify_order_id}')"
          class="px-4 py-2 rounded-2xl bg-blue-600 text-white font-extrabold hover:bg-blue-700">
          VER DETALLES →
        </button>
      </div>
    `;
    cont.appendChild(row);
  });

  $("total-pedidos").textContent = pedidos.length;
}

/* =====================================================
  CARGAR COLA
===================================================== */
async function cargarMiCola() {
  if (loading) return;
  loading = true;
  setLoader(true);

  try {
    const r = await fetch(ENDPOINT_QUEUE, { credentials: "same-origin" });
    const d = await r.json();

    if (!r.ok || d.ok !== true) {
      pedidosCache = [];
      renderPedidos([]);
      return;
    }

    pedidosCache = d.data || [];
    renderPedidos(pedidosCache);

  } catch (e) {
    console.error(e);
    renderPedidos([]);
  } finally {
    loading = false;
    setLoader(false);
  }
}

/* =====================================================
  ACCIONES
===================================================== */
async function traerPedidos(n) {
  setLoader(true);
  try {
    await fetch(ENDPOINT_PULL, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ count: n }),
      credentials: "same-origin"
    });
    await cargarMiCola();
  } finally {
    setLoader(false);
  }
}

async function devolverPedidos() {
  if (!confirm("¿Devolver todos los pedidos asignados?")) return;
  setLoader(true);
  try {
    await fetch(ENDPOINT_RETURN_ALL, {
      method: "POST",
      credentials: "same-origin"
    });
    await cargarMiCola();
  } finally {
    setLoader(false);
  }
}

// ===============================
// DETALLES (FULL SCREEN) - FIX IDs
// ===============================

function $(id) {
  return document.getElementById(id);
}

function setHtml(id, html) {
  const el = $(id);
  if (!el) {
    console.warn("Falta en el DOM:", id);
    return false;
  }
  el.innerHTML = html;
  return true;
}

function setText(id, txt) {
  const el = $(id);
  if (!el) {
    console.warn("Falta en el DOM:", id);
    return false;
  }
  el.textContent = txt ?? "";
  return true;
}

function abrirDetallesFull() {
  const modal = $("modalDetallesFull");
  if (modal) modal.classList.remove("hidden");
  document.documentElement.classList.add("overflow-hidden");
  document.body.classList.add("overflow-hidden");
}

function cerrarDetallesFull() {
  const modal = $("modalDetallesFull");
  if (modal) modal.classList.add("hidden");
  document.documentElement.classList.remove("overflow-hidden");
  document.body.classList.remove("overflow-hidden");
}

function toggleJsonDetalles() {
  const pre = $("detJson");
  if (!pre) return;
  pre.classList.toggle("hidden");
}

function copiarDetallesJson() {
  const pre = $("detJson");
  if (!pre) return;
  const text = pre.textContent || "";
  navigator.clipboard?.writeText(text).then(
    () => alert("JSON copiado ✅"),
    () => alert("No se pudo copiar ❌")
  );
}

// Helpers imagen
function esImagen(url) {
  if (!url) return false;
  return /\.(jpeg|jpg|png|gif|webp|svg)$/i.test(String(url));
}

function escapeHtml(str) {
  return String(str ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

// =====================================================
// DETALLES: TAGS visibles + repintado al guardar
// (reemplaza tu window.verDetalles actual por este)
// =====================================================
window.verDetalles = async function (orderId) {
  const id = String(orderId || "");
  if (!id) return;

  // -----------------------------
  // Helpers DOM
  // -----------------------------
  function $(x) { return document.getElementById(x); }

  function setHtml(elId, html) {
    const el = $(elId);
    if (!el) return false;
    el.innerHTML = html;
    return true;
  }

  function setText(elId, txt) {
    const el = $(elId);
    if (!el) return false;
    el.textContent = txt ?? "";
    return true;
  }

  function abrirDetallesFull() {
    const modal = $("modalDetallesFull");
    if (modal) modal.classList.remove("hidden");
    document.documentElement.classList.add("overflow-hidden");
    document.body.classList.add("overflow-hidden");
  }

  // -----------------------------
  // Helpers sanitize
  // -----------------------------
  function escapeHtml(str) {
    return String(str ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function escapeAttr(str) {
    return String(str ?? "")
      .replace(/&/g, "&amp;")
      .replace(/"/g, "&quot;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;");
  }

  function esUrl(u) {
    return /^https?:\/\//i.test(String(u || "").trim());
  }

  function esImagenUrl(url) {
    if (!url) return false;
    const u = String(url).trim();
    return /https?:\/\/.*\.(jpeg|jpg|png|gif|webp|svg)(\?.*)?$/i.test(u);
  }

  function totalLinea(price, qty) {
    const p = Number(price);
    const q = Number(qty);
    if (isNaN(p) || isNaN(q)) return null;
    return (p * q).toFixed(2);
  }
/* =====================================================
  CSRF
===================================================== */
function getCsrfHeaders() {
  const token  = document.querySelector('meta[name="csrf-token"]')?.content;
  const header = document.querySelector('meta[name="csrf-header"]')?.content;
  return token && header ? { [header]: token } : {};
}

  // -----------------------------
  // Open modal + placeholders
  // -----------------------------
  abrirDetallesFull();

  setText("detTitle", "Cargando…");
  setText("detSubtitle", "—");
  setText("detItemsCount", "0");
  setHtml("detItems", `<div class="text-slate-500">Cargando productos…</div>`);
  setHtml("detResumen", `<div class="text-slate-500">Cargando…</div>`);
  setHtml("detCliente", `<div class="text-slate-500">Cargando…</div>`);
  setHtml("detEnvio", `<div class="text-slate-500">Cargando…</div>`);
  setHtml("detTotales", `<div class="text-slate-500">Cargando…</div>`);

  const pre = $("detJson");
  if (pre) pre.textContent = "";

  // -----------------------------
  // Fetch detalles
  // -----------------------------
  try {
    const url =
      typeof apiUrl === "function"
        ? apiUrl(`/dashboard/detalles/${encodeURIComponent(id)}`)
        : `/index.php/dashboard/detalles/${encodeURIComponent(id)}`;

    const r = await fetch(url, { headers: { Accept: "application/json" } });
    const d = await r.json().catch(() => null);

    if (!r.ok || !d || d.success !== true) {
      setHtml("detItems", `<div class="text-rose-600 font-extrabold">Error cargando detalles. Revisa endpoint.</div>`);
      if (pre) pre.textContent = JSON.stringify({ http: r.status, payload: d }, null, 2);
      return;
    }

    if (pre) pre.textContent = JSON.stringify(d, null, 2);

    const o = d.order || {};
    const lineItems = Array.isArray(o.line_items) ? o.line_items : [];

    const imagenesLocales = d.imagenes_locales || {};
    const productImages = d.product_images || {};

    // -----------------------------
    // Header
    // -----------------------------
    setText("detTitle", `Pedido ${o.name || ("#" + id)}`);

    const clienteNombre = o.customer
      ? `${o.customer.first_name || ""} ${o.customer.last_name || ""}`.trim()
      : "";

    setText("detSubtitle", clienteNombre ? clienteNombre : (o.email || "—"));

    // -----------------------------
    // Cliente
    // -----------------------------
    setHtml("detCliente", `
      <div class="space-y-2">
        <div class="font-extrabold text-slate-900">${escapeHtml(clienteNombre || "—")}</div>
        <div><span class="text-slate-500">Email:</span> ${escapeHtml(o.email || "—")}</div>
        <div><span class="text-slate-500">Tel:</span> ${escapeHtml(o.phone || "—")}</div>
        <div><span class="text-slate-500">ID:</span> ${escapeHtml(o.customer?.id || "—")}</div>
      </div>
    `);

    // -----------------------------
    // Envío
    // -----------------------------
    const a = o.shipping_address || {};
    setHtml("detEnvio", `
      <div class="space-y-1">
        <div class="font-extrabold text-slate-900">${escapeHtml(a.name || "—")}</div>
        <div>${escapeHtml(a.address1 || "")}</div>
        <div>${escapeHtml(a.address2 || "")}</div>
        <div>${escapeHtml((a.zip || "") + " " + (a.city || ""))}</div>
        <div>${escapeHtml(a.province || "")}</div>
        <div>${escapeHtml(a.country || "")}</div>
        <div class="pt-2"><span class="text-slate-500">Tel envío:</span> ${escapeHtml(a.phone || "—")}</div>
      </div>
    `);

    // -----------------------------
    // Totales
    // -----------------------------
    const envio =
      o.total_shipping_price_set?.shop_money?.amount ??
      o.total_shipping_price_set?.presentment_money?.amount ??
      "0";
    const impuestos = o.total_tax ?? "0";

    setHtml("detTotales", `
      <div class="space-y-1">
        <div><b>Subtotal:</b> ${escapeHtml(o.subtotal_price || "0")} €</div>
        <div><b>Envío:</b> ${escapeHtml(envio)} €</div>
        <div><b>Impuestos:</b> ${escapeHtml(impuestos)} €</div>
        <div class="text-lg font-extrabold"><b>Total:</b> ${escapeHtml(o.total_price || "0")} €</div>
      </div>
    `);

    // -----------------------------
    // ✅ TAGS: fuente robusta
    // -----------------------------
    const fromDetalles = String(o.tags ?? o.etiquetas ?? "").trim();
    const fromCache =
      String(
        (window.ordersById?.get(String(o.id))?.etiquetas) ??
        (window.ordersById?.get(String(id))?.etiquetas) ??
        ""
      ).trim();

    const tagsActuales = (fromDetalles || fromCache || "").trim();

    // ✅ repintado global (se usa cuando guardas en el modal)
    window.__pintarTagsEnDetalle = function (tagsStr) {
      const wrap = document.getElementById("det-tags-view");
      if (!wrap) return;

      const clean = String(tagsStr || "").trim();
      wrap.innerHTML = clean
        ? clean.split(",").map(t => `
            <span class="px-3 py-1 rounded-full text-xs font-semibold border bg-white">
              ${escapeHtml(t.trim())}
            </span>
          `).join("")
        : `<span class="text-xs text-slate-400">—</span>`;

      const btn = document.getElementById("btnEtiquetasDetalle");
      if (btn) btn.dataset.orderTags = clean;
    };

    // -----------------------------
    // Resumen
    // -----------------------------
    setHtml("detResumen", `
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
          <div class="flex items-center justify-between">
            <div class="text-xs text-slate-500 font-extrabold uppercase">Etiquetas</div>

            <button
              id="btnEtiquetasDetalle"
              type="button"
              class="px-3 py-1 rounded-full border border-slate-200 bg-white text-[11px] font-extrabold tracking-wide shadow-sm hover:bg-slate-50 active:scale-[0.99]"
              data-order-id="${escapeAttr(o.id || id)}"
              data-order-label="${escapeAttr(o.name || ('#' + (o.id || id)))}"
              data-order-tags="${escapeAttr(tagsActuales)}"
              onclick="abrirEtiquetasDesdeDetalle(this)"
            >
              ETIQUETAS <span class="ml-1 font-black">+</span>
            </button>
          </div>

          <div id="det-tags-view" class="mt-2 flex flex-wrap gap-2"></div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
          <div class="text-xs text-slate-500 font-extrabold uppercase">Pago</div>
          <div class="mt-1 font-semibold">${escapeHtml(o.financial_status || "—")}</div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
          <div class="text-xs text-slate-500 font-extrabold uppercase">Entrega</div>
          <div class="mt-1 font-semibold">${escapeHtml(o.fulfillment_status || "—")}</div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
          <div class="text-xs text-slate-500 font-extrabold uppercase">Creado</div>
          <div class="mt-1 font-semibold">${escapeHtml(o.created_at || "—")}</div>
        </div>
      </div>
    `);

    // ✅ pintar tags DESPUÉS de insertar el HTML (esto era lo que faltaba)
    window.__pintarTagsEnDetalle(tagsActuales);

    // ✅ Detalles -> abre el MISMO modal del dashboard y marca "viene de detalles"
    window.abrirEtiquetasDesdeDetalle = function (btn) {
      try {
        const orderId = btn?.dataset?.orderId;
        const label = btn?.dataset?.orderLabel || ("#" + orderId);
        const tagsStr = btn?.dataset?.orderTags || "";

        // marca para que guardarEtiquetasModal repinte detalles al guardar
        window.__ETQ_DETALLE_ORDER_ID = Number(orderId) || null;

        if (typeof window.abrirModalEtiquetas === "function") {
          window.abrirModalEtiquetas(orderId, tagsStr, label);
          return;
        }

        const modal = document.getElementById("modalEtiquetas");
        if (modal) modal.classList.remove("hidden");
      } catch (e) {
        console.error("abrirEtiquetasDesdeDetalle error:", e);
      }
    };

    // -----------------------------
    // Productos
    // -----------------------------
    setText("detItemsCount", String(lineItems.length));

    if (!lineItems.length) {
      setHtml("detItems", `<div class="text-slate-500">Este pedido no tiene productos.</div>`);
      return;
    }

    window.imagenesLocales = imagenesLocales || {};
    window.imagenesCargadas = new Array(lineItems.length).fill(false);
    window.imagenesRequeridas = new Array(lineItems.length).fill(false);

    const itemsHtml = lineItems.map((item, index) => {
      const props = Array.isArray(item.properties) ? item.properties : [];

      const propsImg = [];
      const propsTxt = [];

      for (const p of props) {
        const name = String(p?.name ?? "").trim() || "Campo";
        const value = p?.value;

        const v =
          value === null || value === undefined
            ? ""
            : typeof value === "object"
            ? JSON.stringify(value)
            : String(value);

        if (esImagenUrl(v)) propsImg.push({ name, value: v });
        else propsTxt.push({ name, value: v });
      }

      const requiere = requiereImagenModificada(item);


      const pid = String(item.product_id || "");
      const productImg = pid && productImages?.[pid] ? String(productImages[pid]) : "";

      const productImgHtml = productImg
        ? `
          <a href="${escapeHtml(productImg)}" target="_blank"
            class="h-16 w-16 rounded-2xl overflow-hidden border border-slate-200 shadow-sm bg-white flex-shrink-0">
            <img src="${escapeHtml(productImg)}" class="h-full w-full object-cover">
          </a>
        `
        : `
          <div class="h-16 w-16 rounded-2xl border border-slate-200 bg-slate-50 flex items-center justify-center text-slate-400 flex-shrink-0">
            🧾
          </div>
        `;

      const localUrl = imagenesLocales?.[index] ? String(imagenesLocales[index]) : "";

      window.imagenesRequeridas[index] = !!requiere;
      window.imagenesCargadas[index] = !!localUrl;

      const estadoItem = requiere ? (localUrl ? "LISTO" : "FALTA") : "NO REQUIERE";
      const badgeCls =
        estadoItem === "LISTO"
          ? "bg-emerald-50 border-emerald-200 text-emerald-900"
          : estadoItem === "FALTA"
          ? "bg-amber-50 border-amber-200 text-amber-900"
          : "bg-slate-50 border-slate-200 text-slate-700";
      const badgeText =
        estadoItem === "LISTO" ? "Listo" : estadoItem === "FALTA" ? "Falta imagen" : "Sin imagen";

      const propsTxtHtml = propsTxt.length
        ? `
          <div class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 p-3">
            <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500 mb-2">Personalización</div>
            <div class="space-y-1 text-sm">
              ${propsTxt.map(({ name, value }) => {
                const safeV = escapeHtml(value || "—");
                const safeName = escapeHtml(name);

                const val = esUrl(value)
                  ? `<a href="${escapeHtml(value)}" target="_blank" class="underline font-semibold text-slate-900">${safeV}</a>`
                  : `<span class="font-semibold text-slate-900 break-words">${safeV}</span>`;

                return `
                  <div class="flex gap-2">
                    <div class="min-w-[130px] text-slate-500 font-bold">${safeName}:</div>
                    <div class="flex-1">${val}</div>
                  </div>
                `;
              }).join("")}
            </div>
          </div>
        `
        : "";

      const propsImgsHtml = propsImg.length
        ? `
          <div class="mt-3">
            <div class="text-xs font-extrabold text-slate-500 mb-2">Imagen original (cliente)</div>
            <div class="flex flex-wrap gap-3">
              ${propsImg.map(({ name, value }) => `
                <a href="${escapeHtml(value)}" target="_blank"
                  class="block rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
                  <img src="${escapeHtml(value)}" class="h-28 w-28 object-cover">
                  <div class="px-3 py-2 text-xs font-bold text-slate-700 bg-white border-t border-slate-200">
                    ${escapeHtml(name)}
                  </div>
                </a>
              `).join("")}
            </div>
          </div>
        `
        : "";

      const modificadaHtml = localUrl
        ? `
          <div class="mt-3">
            <div class="text-xs font-extrabold text-slate-500">Imagen modificada (subida)</div>
            <a href="${escapeHtml(localUrl)}" target="_blank"
              class="inline-block mt-2 rounded-2xl overflow-hidden border border-slate-200 shadow-sm">
              <img src="${escapeHtml(localUrl)}" class="h-40 w-40 object-cover">
            </a>
          </div>
        `
        : requiere
        ? `<div class="mt-3 text-rose-600 font-extrabold text-sm">Falta imagen modificada</div>`
        : "";

      const variant = item.variant_title && item.variant_title !== "Default Title" ? item.variant_title : "";
      const sku = item.sku || "";
      const qty = item.quantity ?? 1;
      const price = item.price ?? "0";
      const tot = totalLinea(price, qty);

      const datosProductoHtml = `
        <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
          ${variant ? `<div><span class="text-slate-500 font-bold">Variante:</span> <span class="font-semibold">${escapeHtml(variant)}</span></div>` : ""}
          ${sku ? `<div><span class="text-slate-500 font-bold">SKU:</span> <span class="font-semibold">${escapeHtml(sku)}</span></div>` : ""}
          ${item.product_id ? `<div><span class="text-slate-500 font-bold">Product ID:</span> <span class="font-semibold">${escapeHtml(item.product_id)}</span></div>` : ""}
          ${item.variant_id ? `<div><span class="text-slate-500 font-bold">Variant ID:</span> <span class="font-semibold">${escapeHtml(item.variant_id)}</span></div>` : ""}
        </div>
      `;

      const uploadHtml = requiere
        ? `
          <div class="mt-4">
            <div class="text-xs font-extrabold text-slate-500 mb-2">Subir imagen modificada</div>
            <input type="file" accept="image/*"
              onchange="subirImagenProducto(${Number(orderId)}, ${index}, this)"
              class="w-full border border-slate-200 rounded-2xl p-2">
            <div id="preview_${id}_${index}" class="mt-2"></div>
          </div>
        `
        : "";

      return `
        <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-4">
          <div class="flex items-start gap-4">
            ${productImgHtml}

            <div class="min-w-0 flex-1">
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <div class="font-extrabold text-slate-900 truncate">${escapeHtml(item.title || item.name || "Producto")}</div>
                  <div class="text-sm text-slate-600 mt-1">
                    Cant: <b>${escapeHtml(qty)}</b> · Precio: <b>${escapeHtml(price)} €</b>
                    ${tot ? ` · Total: <b>${escapeHtml(tot)} €</b>` : ""}
                  </div>
                </div>

                <span class="text-xs font-extrabold px-3 py-1 rounded-full border ${badgeCls}">
                  ${badgeText}
                </span>
              </div>

              ${datosProductoHtml}
              ${propsTxtHtml}
              ${propsImgsHtml}
              ${modificadaHtml}
              ${uploadHtml}
            </div>
          </div>
        </div>
      `;
    }).join("");

    setHtml("detItems", itemsHtml);
  } catch (e) {
    console.error("verDetalles error:", e);
    setHtml("detItems", `<div class="text-rose-600 font-extrabold">Error de red cargando detalles.</div>`);
  }
};
function requiereImagenModificada(item) {
  const props = Array.isArray(item?.properties) ? item.properties : [];

  // ✅ Si hay alguna property que sea URL de imagen => requiere
  const tieneImagenEnProps = props.some((p) => {
    const v = p?.value;
    const s =
      v === null || v === undefined
        ? ""
        : typeof v === "object"
        ? JSON.stringify(v)
        : String(v);

    return esImagenUrl(s); // usa tu helper que acepta querystring
  });

  // ✅ Si el backend ya trae campos típicos de imagen
  const tieneCamposImagen =
    esImagenUrl(item?.image_original) ||
    esImagenUrl(item?.image_url) ||
    esImagenUrl(item?.imagen_original) ||
    esImagenUrl(item?.imagen_url);

  // ✅ Llavero siempre requiere (aunque no haya imagen)
  if (isLlaveroItem(item)) return true;

  // ✅ Solo requiere si hay imagen real del cliente
  return tieneImagenEnProps || tieneCamposImagen;
}
function isLlaveroItem(item) {
  const title = String(item?.title || item?.name || "").toLowerCase();
  const productType = String(item?.product_type || "").toLowerCase();
  const sku = String(item?.sku || "").toLowerCase();

  // ✅ Ajusta aquí tus palabras clave reales
  const hayLlavero =
    title.includes("llavero") ||
    productType.includes("llavero") ||
    sku.includes("llav");

  return hayLlavero;
}
/* =====================================================
  ✅ GUARDAR ESTADO (LOCAL INSTANT + BACKEND + REVERT)
  + pause live + dirty TTL
  + FIX endpoints (incluye /index.php/index.php)
===================================================== */
async function guardarEstado(nuevoEstado) {
  // ✅ intenta varios inputs por si cambió el modal
  const idInput =
    document.getElementById("modalOrderId") ||
    document.getElementById("modalEstadoOrderId") ||
    document.getElementById("estadoOrderId") ||
    document.querySelector('input[name="order_id"]');

  const id = String(idInput?.value || "");
  if (!id) {
    alert("No se encontró el ID del pedido en el modal (input). Revisa layouts/modales_estados.");
    return;
  }

  pauseLive();

  const order = ordersById.get(id);
  const prevEstado = order?.estado ?? null;
  const prevLast = order?.last_status_change ?? null;

  // 1) UI instantánea + dirty
  const userName = window.CURRENT_USER || "Sistema";
  const now = new Date();
  const nowStr = now.toISOString().slice(0, 19).replace("T", " ");
  const optimisticLast = { user_name: userName, changed_at: nowStr };

  if (order) {
    order.estado = nuevoEstado;
    order.last_status_change = optimisticLast;
    actualizarTabla(ordersCache);
  }

  dirtyOrders.set(id, {
    until: Date.now() + DIRTY_TTL_MS,
    estado: nuevoEstado,
    last_status_change: optimisticLast,
  });
  saveEstadoLS(id, nuevoEstado, optimisticLast);


  cerrarModal();

  // 2) Guardar backend
  try {
    // ✅ endpoints ampliados (incluye doble index.php)
    const endpoints = [
      window.API?.guardarEstado,   // ✅ este primero
      apiUrl("/api/estado/guardar"),
      "/api/estado/guardar",
      "/index.php/api/estado/guardar",
      "/index.php/index.php/api/estado/guardar",
      apiUrl("/index.php/api/estado/guardar"),
      apiUrl("/index.php/index.php/api/estado/guardar"),
    ];

    let lastErr = null;

    for (const url of endpoints) {
      try {
        const r = await fetch(url, {
          method: "POST",
          headers: jsonHeaders(),
          credentials: "same-origin",
          // ✅ manda id numérico (tu backend suele esperar num)
          body: JSON.stringify({
            order_id: String(id),   // ✅ clave correcta para tu DB/modelo
            id: String(id),         // ✅ por si tu controller aún usa "id"
            estado: String(nuevoEstado),
          }),
          
        });

        if (r.status === 404) continue;

        const d = await r.json().catch(() => null);

        if (!r.ok || !d?.success) {
          throw new Error(d?.message || `HTTP ${r.status}`);
        }

        // 3) Sync desde backend
        if (d?.order && order) {
          order.estado = d.order.estado ?? order.estado;
          order.last_status_change = d.order.last_status_change ?? order.last_status_change;
          actualizarTabla(ordersCache);

          dirtyOrders.set(id, {
            until: Date.now() + DIRTY_TTL_MS,
            estado: order.estado,
            last_status_change: order.last_status_change,
          });
          saveEstadoLS(id, order.estado, order.last_status_change);

        }

        // refresca si estás en pág 1
        if (currentPage === 1) cargarPedidos({ reset: false, page_info: "" });
        // ✅ NOTIFICAR a otras pestañas (Repetir Pedidos) en tiempo real

        try {
  const msg = { type: "estado_changed", order_id: String(id), estado: String(nuevoEstado), ts: Date.now() };

  // BroadcastChannel (Chrome/Edge/Firefox)
  if ("BroadcastChannel" in window) {
    const bc = new BroadcastChannel("panel_pedidos");
    bc.postMessage(msg);
    bc.close();
  }

  // Fallback: dispara evento cross-tab
  localStorage.setItem("pedido_estado_changed", JSON.stringify(msg));
} catch (e) {
  console.warn("No se pudo notificar a otras pestañas:", e);
}

        resumeLiveIfOnFirstPage();
        return;
      } catch (e) {
        lastErr = e;
      }
    }

    throw lastErr || new Error("No se encontró un endpoint válido (404).");
  } catch (e) {
    console.error("guardarEstado error:", e);

    // Revert
    dirtyOrders.delete(id);

    if (order) {
      order.estado = prevEstado;
      order.last_status_change = prevLast;
      actualizarTabla(ordersCache);
    }

    alert("No se pudo guardar el estado. Se revirtió el cambio.");
    resumeLiveIfOnFirstPage();
  }
}

// ✅ asegurar funciones globales para onclick=""
window.guardarEstado = guardarEstado;


function pauseLive() {
  liveMode = false;
}
// ===============================
// SUBIR IMAGEN MODIFICADA (ROBUSTO)
// ===============================
window.subirImagenProducto = async function (orderId, index, input) {
  try {
    const file = input?.files?.[0];
    if (!file) return;

    const fd = new FormData();
    fd.append("order_id", String(orderId));
    fd.append("line_index", String(index));
    fd.append("file", file);

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content");
    const csrfHeader = document.querySelector('meta[name="csrf-header"]')?.getAttribute("content") || "X-CSRF-TOKEN";

    const endpoints = [
      (typeof apiUrl === "function" ? apiUrl("/api/pedidos/imagenes/subir") : "/index.php/api/pedidos/imagenes/subir"),
      "/api/pedidos/imagenes/subir",
      "/index.php/api/pedidos/imagenes/subir",
      "/index.php/index.php/api/pedidos/imagenes/subir",
    ];

    let lastErr = null;

    for (const url of endpoints) {
      try {
        const headers = {};
        if (csrfToken) headers[csrfHeader] = csrfToken;

        const r = await fetch(url, {
          method: "POST",
          headers,
          body: fd,
          credentials: "same-origin", // ✅ CLAVE: manda cookies de sesión
        });

        if (r.status === 404) continue;

        // ✅ si el server devolvió 401/403: sesión muerta
        if (r.status === 401 || r.status === 403) {
          throw new Error("No autenticado. Tu sesión venció (401/403). Recarga el panel y vuelve a iniciar sesión.");
        }

        // ✅ parse inteligente (JSON o texto)
        const ct = (r.headers.get("content-type") || "").toLowerCase();
        let d = null;
        let rawText = "";

        if (ct.includes("application/json")) {
          d = await r.json().catch(() => null);
        } else {
          rawText = await r.text().catch(() => "");
          // si parece HTML (login / error page), lo marcamos
          if (rawText.trim().startsWith("<!doctype") || rawText.trim().startsWith("<html")) {
            throw new Error("El servidor devolvió HTML (probable login / sesión expirada). Recarga el panel.");
          }
          // si es texto, intentamos convertirlo
          d = { success: true, url: rawText.trim() };
        }

        // ✅ acepta varias formas
        const success = (d && (d.success === true || typeof d.url === "string"));
        const urlFinal = d?.url ? String(d.url) : "";

        if (!r.ok || !success || !urlFinal) {
          throw new Error(d?.message || `Respuesta inválida del servidor (HTTP ${r.status}).`);
        }

        // ✅ pintar preview
        const previewId = `preview_${orderId}_${index}`;
        const prev = document.getElementById(previewId);
        if (prev) {
          prev.innerHTML = `
            <div class="mt-2">
              <div class="text-xs font-extrabold text-slate-500">Imagen modificada subida ✅</div>
              <img src="${urlFinal}" class="mt-2 w-44 rounded-2xl border border-slate-200 shadow-sm object-cover">
            </div>
          `;
        }

        // ✅ marcar como cargada
        if (!Array.isArray(window.imagenesCargadas)) window.imagenesCargadas = [];
        if (!Array.isArray(window.imagenesRequeridas)) window.imagenesRequeridas = [];

        window.imagenesCargadas[index] = true;

        if (window.imagenesLocales && typeof window.imagenesLocales === "object") {
          window.imagenesLocales[index] = urlFinal;
        }

        // ✅ recalcular estado automático
        if (typeof window.validarEstadoAuto === "function") {
          window.validarEstadoAuto(orderId);
        }

        return; // ✅ éxito
      } catch (e) {
        lastErr = e;
      }
    }

    throw lastErr || new Error("No se encontró endpoint para subir imagen (404).");
  } catch (e) {
    console.error("subirImagenProducto error:", e);
    alert("Error subiendo imagen: " + (e?.message || e));
  }
};

// =====================================
// AUTO-ESTADO (2+ imágenes requeridas)
// - si falta alguna => "Faltan archivos"
// - si están todas => "Confirmado"
// =====================================
// =====================================
// AUTO-ESTADO (Confirmación)
// =====================================
window.validarEstadoAuto = async function (shopifyOrderId) {
  try {
    const req = Array.isArray(window.imagenesRequeridas) ? window.imagenesRequeridas : [];
    const ok  = Array.isArray(window.imagenesCargadas) ? window.imagenesCargadas : [];

    // solo índices que requieren imagen
    const requiredIdx = req.map((v, i) => (v ? i : -1)).filter(i => i >= 0);
    if (!requiredIdx.length) return;

    const faltaAlguna = requiredIdx.some(i => ok[i] !== true);
    const nuevoEstado = faltaAlguna ? "Faltan imágenes" : "Confirmado";

    const r = await fetch("/confirmacion/guardar-estado", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        ...getCsrfHeaders()
      },
      credentials: "same-origin",
      body: JSON.stringify({
        shopify_order_id: String(shopifyOrderId),
        estado: nuevoEstado
      })
    });

    const d = await r.json();
    if (!r.ok || !d.success) {
      console.error("❌ Error guardando estado automático:", d);
      return;
    }

    // ✅ SOLO si está confirmado → quitar de la cola
    if (nuevoEstado === "Confirmado") {
      pedidosCache = pedidosCache.filter(
        p => String(p.shopify_order_id) !== String(shopifyOrderId)
      );
      renderPedidos(pedidosCache);

      if (typeof cerrarDetallesFull === "function") {
        cerrarDetallesFull();
      }
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
