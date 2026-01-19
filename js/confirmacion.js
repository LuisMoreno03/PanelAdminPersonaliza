/**
 * confirmacion.js — FINAL ESTABLE FIXED
 * Compatible con EstadoController.php
 * Sin null errors
 * Cierre modal completo
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
function $(id) {
  return document.getElementById(id);
}

/* =====================================================
   NORMALIZAR LINE ITEMS (REST + GRAPHQL)
===================================================== */
function extraerLineItems(order) {
  // REST clásico
  if (Array.isArray(order?.line_items)) {
    return order.line_items;
  }

  // GraphQL Admin API (edges/node)
  if (order?.lineItems?.edges) {
    return order.lineItems.edges.map(e => e.node);
  }

  // GraphQL plano (por si acaso)
  if (Array.isArray(order?.lineItems)) {
    return order.lineItems;
  }

  return [];
}

function escapeHtml(str) {
  return String(str ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function esUrl(str) {
  return /^https?:\/\//i.test(String(str || ""));
}
function esImagenUrl(url) {
  return /https?:\/\/.*\.(jpg|jpeg|png|webp|gif|svg)(\?.*)?$/i.test(String(url || ""));
}

function setLoader(show) {
  $("globalLoader")?.classList.toggle("hidden", !show);
}

function setTextSafe(id, value) {
  const el = $(id);
  if (!el) return;
  el.textContent = value ?? "";
}

function setHtmlSafe(id, html) {
  const el = $(id);
  if (!el) return;
  el.innerHTML = html ?? "";
}

/* =====================================================
   CSRF
===================================================== */
function getCsrfHeaders() {
  const token = document.querySelector('meta[name="csrf-token"]')?.content;
  const header = document.querySelector('meta[name="csrf-header"]')?.content;
  return token && header ? { [header]: token } : {};
}

/* =====================================================
   LISTADO
===================================================== */
function renderPedidos(pedidos) {
  const wrap = $("tablaPedidos");
  if (!wrap) return;

  wrap.innerHTML = "";

  if (!pedidos.length) {
    wrap.innerHTML = `<div class="p-8 text-center text-slate-500">No hay pedidos asignados</div>`;
    setTextSafe("total-pedidos", "0");
    return;
  }

  pedidos.forEach(p => {
    const row = document.createElement("div");
    row.className = "orders-grid cols px-4 py-3 border-b items-center";

    row.innerHTML = `
      <div class="font-extrabold">${escapeHtml(p.numero)}</div>
      <div>${escapeHtml((p.created_at || "").slice(0, 10))}</div>
      <div class="truncate">${escapeHtml(p.cliente)}</div>
      <div class="font-bold">${Number(p.total).toFixed(2)} €</div>

      <div>
        <span class="px-3 py-1 rounded-full text-xs font-extrabold bg-blue-600 text-white">
          POR PREPARAR
        </span>
      </div>

      <div>${escapeHtml(p.estado_por || "—")}</div>
      <div>—</div>
      <div class="text-center">${p.articulos || 1}</div>

      <div>
        <span class="px-3 py-1 rounded-full text-xs font-bold bg-slate-100">
          Sin preparar
        </span>
      </div>

      <div class="truncate">${escapeHtml(p.forma_envio || "-")}</div>

      <div class="text-right">
        <button
          onclick="verDetalles('${p.shopify_order_id}')"
          class="px-3 py-1 rounded-2xl bg-blue-600 text-white font-extrabold hover:bg-blue-700">
          VER DETALLES →
        </button>
      </div>
    `;
    wrap.appendChild(row);
  });

  setTextSafe("total-pedidos", pedidos.length);
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

    pedidosCache = (r.ok && d.ok) ? d.data : [];
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
  if (!confirm("¿Devolver todos los pedidos asignados?")) return;
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
  const modal = $("modalDetallesFull");
  if (!modal) return;
  modal.classList.remove("hidden");
  document.body.classList.add("overflow-hidden");
}

function cerrarModalDetalles() {
  const modal = $("modalDetallesFull");
  if (!modal) return;
  modal.classList.add("hidden");
  document.body.classList.remove("overflow-hidden");
}

window.cerrarModalDetalles = cerrarModalDetalles;

/* =====================================================
   DETALLES
===================================================== */
window.verDetalles = async function (orderId) {
  pedidoActualId = orderId;
  abrirDetallesFull();
  pintarCargandoDetalles();

  try {
    const r = await fetch(`${ENDPOINT_DETALLES}/${orderId}`, {
      headers: { Accept: "application/json" },
      credentials: "same-origin"
    });

    const d = await r.json();
    if (!r.ok || !d.success) throw new Error(d.message || "Error");

    pintarDetallesPedido(d.order, d.imagenes_locales || {});
  } catch (e) {
    pintarErrorDetalles(e.message);
  }
};


function pintarCargandoDetalles() {
  setTextSafe("detTitulo", "Cargando pedido…");
  setTextSafe("detItemsCount", "—");
  setHtmlSafe("detItems", `<div class="text-slate-500">Cargando productos…</div>`);
  setHtmlSafe("detResumen", `<div class="text-slate-500">Cargando resumen…</div>`);
}


function pintarErrorDetalles(msg) {
  setHtmlSafe("detProductos", `<div class="text-rose-600 font-extrabold">${escapeHtml(msg)}</div>`);
}

/* =====================================================
   REGLAS IMÁGENES
===================================================== */
function isLlaveroItem(item) {
  return String(item?.title || "").toLowerCase().includes("llavero");
}

function requiereImagenModificada(item) {
  const props = Array.isArray(item?.properties) ? item.properties : [];
  const tieneImagen = props.some(p => esImagenUrl(p?.value));
  return isLlaveroItem(item) || tieneImagen;
}

function extraerLineItems(order) {
  // Shopify REST
  if (Array.isArray(order?.line_items)) {
    return order.line_items;
  }

  // Shopify GraphQL Admin API
  if (order?.lineItems?.edges) {
    return order.lineItems.edges.map(({ node }) => {
      return {
        title: node.title,
        quantity: node.quantity || 1,
        price: Number(node.originalUnitPrice?.amount || 0),
        product_id: node.product?.id || null,
        variant_id: node.variant?.id || null,
        variant_title: node.variant?.title || "",
        sku: node.variant?.sku || "",
        properties: Array.isArray(node.customAttributes)
          ? node.customAttributes.map(p => ({
              name: p.key,
              value: p.value
            }))
          : []
      };
    });
  }

  return [];
}


/* =====================================================
   PINTAR PRODUCTOS
===================================================== */
function pintarDetallesPedido(order, imagenesLocales = {}) {
  const items = extraerLineItems(order);

  imagenesRequeridas = [];
  imagenesCargadas = [];

  setTextSafe("detTitulo", `Pedido ${order.name || order.id}`);

  if (!items.length) {
    setHtmlSafe("detProductos", `
      <div class="text-slate-500 p-6 text-center">
        ⚠️ Este pedido no tiene productos detectables
      </div>
    `);
    setHtmlSafe("detResumen", "");
    return;
  }

  const html = items.map((item, index) => {
    const requiere = requiereImagenModificada(item);
    const imgLocal = imagenesLocales[index] || "";

    imagenesRequeridas[index] = requiere;
    imagenesCargadas[index] = !!imgLocal;

    const estadoBadge = requiere
      ? imgLocal
        ? `<span class="px-3 py-1 text-xs rounded-full bg-emerald-100 text-emerald-800 font-bold">Listo</span>`
        : `<span class="px-3 py-1 text-xs rounded-full bg-amber-100 text-amber-800 font-bold">Falta imagen</span>`
      : `<span class="px-3 py-1 text-xs rounded-full bg-slate-100 text-slate-600">No requiere</span>`;

    const propsImg = item.properties.filter(p => esImagenUrl(p.value));
    const propsTxt = item.properties.filter(p => !esImagenUrl(p.value));

    return `
      <div class="rounded-3xl border bg-white p-5 shadow-sm space-y-4">

        <div class="flex justify-between items-start">
          <div>
            <div class="font-extrabold">${escapeHtml(item.title)}</div>
            <div class="text-sm text-slate-600">
              Cant: <b>${item.quantity}</b> · 
              Precio: <b>${item.price.toFixed(2)} €</b> · 
              Total: <b>${(item.price * item.quantity).toFixed(2)} €</b>
            </div>
          </div>
          ${estadoBadge}
        </div>

        ${
          item.variant_title ? `
            <div class="text-sm"><b>Variante:</b> ${escapeHtml(item.variant_title)}</div>
          ` : ""
        }

        ${
          propsTxt.length ? `
            <div class="bg-slate-50 rounded-xl p-3 text-sm">
              <div class="font-bold mb-1">Personalización</div>
              ${propsTxt.map(p => `
                <div><b>${escapeHtml(p.name)}:</b> ${escapeHtml(p.value)}</div>
              `).join("")}
            </div>
          ` : ""
        }

        ${
          propsImg.length ? `
            <div>
              <div class="text-sm font-bold mb-2">Imagen original (cliente)</div>
              <div class="flex gap-3 flex-wrap">
                ${propsImg.map(p => `
                  <a href="${escapeHtml(p.value)}" target="_blank">
                    <img src="${escapeHtml(p.value)}" class="h-28 w-28 rounded-xl object-cover border">
                  </a>
                `).join("")}
              </div>
            </div>
          ` : ""
        }

        ${
          imgLocal ? `
            <div>
              <div class="text-sm font-bold mb-2">Imagen modificada</div>
              <img src="${escapeHtml(imgLocal)}"
                   class="h-40 rounded-xl border object-cover">
            </div>
          ` : ""
        }

        ${
          requiere ? `
            <div>
              <div class="text-sm font-bold mb-2">Subir imagen modificada</div>
              <input type="file" accept="image/*"
                onchange="subirImagenProducto('${order.id}', ${index}, this)">
            </div>
          ` : ""
        }

      </div>
    `;
  }).join("");

  setHtmlSafe("detProductos", html);
  actualizarResumenAuto(order.id);
}




/* =====================================================
   RESUMEN + AUTO ESTADO
===================================================== */
function actualizarResumenAuto(orderId) {
  const total = imagenesRequeridas.filter(Boolean).length;
  const ok = imagenesRequeridas.filter((v, i) => v && imagenesCargadas[i]).length;
  const falta = total - ok;

  setHtmlSafe("detResumen", `
    <div class="font-extrabold">${ok} / ${total} imágenes cargadas</div>
    <div class="mt-2 font-bold ${falta ? "text-amber-600" : "text-emerald-600"}">
      ${falta ? `🟡 Faltan ${falta} imágenes` : "🟢 Todo listo"}
    </div>
  `);

  if (total > 0) {
    guardarEstadoAuto(orderId, falta === 0 ? "Confirmado" : "Faltan archivos");
  }
}

/* =====================================================
   SUBIR IMAGEN
===================================================== */
window.subirImagenProducto = async function (orderId, index, input) {
  const file = input.files[0];
  if (!file) return;

  const fd = new FormData();
  fd.append("order_id", orderId);
  fd.append("line_index", index);
  fd.append("file", file);

  const r = await fetch("/api/pedidos/imagenes/subir", {
    method: "POST",
    body: fd,
    headers: getCsrfHeaders(),
    credentials: "same-origin"
  });

  const d = await r.json();
  if (!r.ok || !d.url) {
    alert("Error subiendo imagen");
    return;
  }

  imagenesCargadas[index] = true;
  actualizarResumenAuto(orderId);
};

/* =====================================================
   AUTO ESTADO (FIX PAYLOAD)
===================================================== */
async function guardarEstadoAuto(orderId, estado) {
  await fetch("/api/estado/guardar", {
    method: "POST",
    headers: { "Content-Type": "application/json", ...getCsrfHeaders() },
    body: JSON.stringify({ id: orderId, estado }),
    credentials: "same-origin"
  });

  if (estado === "Confirmado") {
    setTimeout(() => {
      cerrarModalDetalles();
      cargarMiCola();
    }, 600);
  }
}

function cerrarDetallesFull() {
  const modal = $("modalDetallesFull");
  if (modal) modal.classList.add("hidden");
  document.documentElement.classList.remove("overflow-hidden");
  document.body.classList.remove("overflow-hidden");
}
/* =====================================================
   EVENTOS GLOBALES
===================================================== */
document.addEventListener("keydown", e => {
  if (e.key === "Escape") cerrarModalDetalles();
});

$("modalDetallesFull")?.addEventListener("click", e => {
  if (e.target.id === "modalDetallesFull") cerrarModalDetalles();
});

/* =====================================================
   INIT
===================================================== */
document.addEventListener("DOMContentLoaded", () => {
  $("btnTraer5")?.addEventListener("click", () => traerPedidos(5));
  $("btnTraer10")?.addEventListener("click", () => traerPedidos(10));
  $("btnDevolver")?.addEventListener("click", devolverPedidos);
  cargarMiCola();
});
