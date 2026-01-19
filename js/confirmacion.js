/**
 * confirmacion.js — FINAL VALIDADO
 * Compatible con confirmacion.php + modal_detalles.php
 * Compatible con EstadoController.php
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

function escapeHtml(str) {
  return String(str ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function esImagenUrl(url) {
  return /https?:\/\/.*\.(jpg|jpeg|png|webp|gif|svg)(\?.*)?$/i.test(String(url || ""));
}

function setTextSafe(id, value) {
  const el = $(id);
  if (el) el.textContent = value ?? "";
}

function setHtmlSafe(id, html) {
  const el = $(id);
  if (el) el.innerHTML = html ?? "";
}

function setLoader(show) {
  $("globalLoader")?.classList.toggle("hidden", !show);
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
   NORMALIZAR LINE ITEMS (REST + GRAPHQL)
===================================================== */
function extraerLineItems(order) {
  // REST
  if (Array.isArray(order?.line_items)) {
    return order.line_items;
  }

  // GraphQL Admin API
  if (order?.lineItems?.edges) {
    return order.lineItems.edges.map(({ node }) => ({
      title: node.title,
      quantity: node.quantity || 1,
      price: Number(node.originalUnitPrice?.amount || 0),
      variant_title: node.variant?.title || "",
      properties: Array.isArray(node.customAttributes)
        ? node.customAttributes.map(p => ({ name: p.key, value: p.value }))
        : []
    }));
  }

  return [];
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
      <div><span class="px-3 py-1 rounded-full bg-blue-600 text-white text-xs font-bold">POR PREPARAR</span></div>
      <div>${escapeHtml(p.estado_por || "—")}</div>
      <div>—</div>
      <div class="text-center">${p.articulos || 1}</div>
      <div><span class="px-3 py-1 rounded-full bg-slate-100 text-xs">Sin preparar</span></div>
      <div class="truncate">${escapeHtml(p.forma_envio || "-")}</div>
      <div class="text-right">
        <button onclick="verDetalles('${p.shopify_order_id}')"
          class="px-3 py-1 rounded-2xl bg-blue-600 text-white font-bold">
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

  setTextSafe("detTitle", "Cargando pedido…");
  setTextSafe("detSubtitle", "");
  setTextSafe("detItemsCount", "—");
  setHtmlSafe("detItems", `<div class="text-slate-500">Cargando productos…</div>`);
  setHtmlSafe("detResumen", `<div class="text-slate-500">Cargando resumen…</div>`);

  try {
    const r = await fetch(`${ENDPOINT_DETALLES}/${orderId}`, {
      headers: { Accept: "application/json" },
      credentials: "same-origin"
    });

    const d = await r.json();
    if (!r.ok || !d.success) throw new Error(d.message || "Error");

    pintarDetallesPedido(d.order, d.imagenes_locales || {});
    setHtmlSafe("detJson", JSON.stringify(d, null, 2));
  } catch (e) {
    setHtmlSafe("detItems", `<div class="text-red-600">${escapeHtml(e.message)}</div>`);
  }
};

/* =====================================================
   PINTAR PRODUCTOS
===================================================== */
function pintarDetallesPedido(order, imagenesLocales = {}) {
  const items = extraerLineItems(order);

  imagenesRequeridas = [];
  imagenesCargadas = [];

  setTextSafe("detTitle", `Pedido ${order.name || order.id}`);
  setTextSafe("detSubtitle", `ID ${order.id}`);
  setTextSafe("detItemsCount", items.length);

  if (!items.length) {
    setHtmlSafe("detItems", `<div class="text-slate-500">Sin productos</div>`);
    return;
  }

  const html = items.map((item, index) => {
    const requiere = item.properties.some(p => esImagenUrl(p.value));
    const imgLocal = imagenesLocales[index] || "";

    imagenesRequeridas[index] = requiere;
    imagenesCargadas[index] = !!imgLocal;

    return `
      <div class="rounded-3xl border bg-white p-5 space-y-4">
        <div class="font-extrabold">${escapeHtml(item.title)}</div>
        <div class="text-sm text-slate-600">
          Cant: ${item.quantity} · ${item.price.toFixed(2)} €
        </div>

        ${
          requiere ? `
            <input type="file" accept="image/*"
              onchange="subirImagenProducto('${order.id}', ${index}, this)">`
          : `<span class="text-xs bg-slate-100 px-2 py-1 rounded">No requiere imagen</span>`
        }

        ${
          imgLocal ? `<img src="${escapeHtml(imgLocal)}" class="h-40 rounded-xl border">` : ""
        }
      </div>
    `;
  }).join("");

  setHtmlSafe("detItems", html);
  actualizarResumenAuto(order.id);
}

/* =====================================================
   RESUMEN + ESTADO
===================================================== */
function actualizarResumenAuto(orderId) {
  const total = imagenesRequeridas.filter(Boolean).length;
  const ok = imagenesRequeridas.filter((v, i) => v && imagenesCargadas[i]).length;

  setHtmlSafe("detResumen", `
    <b>${ok} / ${total}</b> imágenes cargadas
  `);

  if (total > 0) {
    guardarEstadoAuto(orderId, ok === total ? "Confirmado" : "Faltan archivos");
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
  if (!r.ok || !d.url) return alert("Error subiendo imagen");

  imagenesCargadas[index] = true;
  actualizarResumenAuto(orderId);
};

/* =====================================================
   AUTO ESTADO
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

/* =====================================================
   INIT
===================================================== */
document.addEventListener("DOMContentLoaded", () => {
  $("btnTraer5")?.addEventListener("click", () => traerPedidos(5));
  $("btnTraer10")?.addEventListener("click", () => traerPedidos(10));
  $("btnDevolver")?.addEventListener("click", devolverPedidos);
  cargarMiCola();
});
