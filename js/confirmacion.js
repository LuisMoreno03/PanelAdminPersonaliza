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
  if (el) el.textContent = value ?? "";
}

function setHtmlSafe(id, html) {
  const el = $(id);
  if (el) el.innerHTML = html ?? "";
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
   NORMALIZAR LINE ITEMS (ÚNICA Y DEFINITIVA)
===================================================== */
function extraerLineItems(order) {
  // REST Shopify
  if (Array.isArray(order?.line_items)) {
    return order.line_items;
  }

  // GraphQL Shopify
  if (order?.lineItems?.edges) {
    return order.lineItems.edges.map(({ node }) => ({
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
    }));
  }

  // Fallback plano
  if (Array.isArray(order?.lineItems)) {
    return order.lineItems;
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
      <div><span class="px-3 py-1 rounded-full text-xs font-extrabold bg-blue-600 text-white">POR PREPARAR</span></div>
      <div>${escapeHtml(p.estado_por || "—")}</div>
      <div>—</div>
      <div class="text-center">${p.articulos || 1}</div>
      <div><span class="px-3 py-1 rounded-full text-xs font-bold bg-slate-100">Sin preparar</span></div>
      <div class="truncate">${escapeHtml(p.forma_envio || "-")}</div>
      <div class="text-right">
        <button onclick="verDetalles('${p.shopify_order_id}')"
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

/* Alias seguro */
function cerrarDetallesFull() {
  cerrarModalDetalles();
}

/* =====================================================
   DETALLES (BACKEND + FALLBACK MANUAL)
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
    if (!r.ok || !d?.success || !d.order) {
      throw new Error(d?.message || "Respuesta inválida");
    }

    pintarDetallesPedido(d.order, d.imagenes_locales || {});
  } catch (e) {
    console.warn("Usando fallback manual", e);
    const pedido = pedidosCache.find(p => String(p.shopify_order_id) === String(orderId));
    if (pedido) {
      pintarDetallesManual(pedido);
    } else {
      pintarErrorDetalles("No se pudieron cargar los detalles del pedido");
    }
  }
};

function pintarCargandoDetalles() {
  setTextSafe("detTitulo", "Cargando pedido…");
  setHtmlSafe("detProductos", `<div class="text-slate-500 p-6 text-center">Cargando productos…</div>`);
  setHtmlSafe("detResumen", `<div class="text-slate-500 p-4">Cargando resumen…</div>`);
}

function pintarErrorDetalles(msg) {
  setHtmlSafe("detProductos", `<div class="text-rose-600 font-extrabold">${escapeHtml(msg)}</div>`);
}

/* =====================================================
   DETALLES MANUAL (FALLBACK)
===================================================== */
function pintarDetallesManual(order) {
  setTextSafe("detTitulo", `Pedido ${order.numero || order.shopify_order_id}`);

  setHtmlSafe("detProductos", `
    <div class="rounded-3xl border bg-white p-5 shadow-sm">
      <div class="font-extrabold text-lg mb-2">Pedido (modo manual)</div>
      <div class="text-sm text-slate-600 space-y-1">
        <div><b>Cliente:</b> ${escapeHtml(order.cliente || "—")}</div>
        <div><b>Total:</b> ${Number(order.total || 0).toFixed(2)} €</div>
        <div><b>Artículos:</b> ${order.articulos || 1}</div>
        <div><b>Método envío:</b> ${escapeHtml(order.forma_envio || "—")}</div>
        <div><b>Estado:</b> Por preparar</div>
      </div>
    </div>
  `);

  setHtmlSafe("detResumen", `
    <div class="font-extrabold text-amber-600">
      ⚠️ Detalles cargados en modo manual
    </div>
    <div class="text-sm text-slate-600 mt-2">
      El backend de detalles no respondió.
    </div>
  `);
}

/* =====================================================
   EVENTOS GLOBALES + INIT
===================================================== */
document.addEventListener("keydown", e => {
  if (e.key === "Escape") cerrarModalDetalles();
});

$("modalDetallesFull")?.addEventListener("click", e => {
  if (e.target.id === "modalDetallesFull") cerrarModalDetalles();
});

document.addEventListener("DOMContentLoaded", () => {
  $("btnTraer5")?.addEventListener("click", () => traerPedidos(5));
  $("btnTraer10")?.addEventListener("click", () => traerPedidos(10));
  $("btnDevolver")?.addEventListener("click", devolverPedidos);
  cargarMiCola();
});
