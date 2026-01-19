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
   DEVOLVER PEDIDOS (FIX)
===================================================== */
async function devolverPedidos() {
  if (!confirm("¿Devolver todos los pedidos asignados?")) return;

  setLoader(true);

  try {
    await fetch(ENDPOINT_RETURN_ALL, {
      method: "POST",
      headers: getCsrfHeaders(),
      credentials: "same-origin"
    });

    await cargarMiCola();
  } catch (e) {
    console.error("Error devolviendo pedidos", e);
    alert("Error devolviendo pedidos");
  } finally {
    setLoader(false);
  }
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
function pintarDetallesManual(order, imagenesLocales = {}) {
  const items = extraerLineItems(order);

  setTextSafe("detTitulo", `Pedido #${order.name || order.id}`);
  setTextSafe("detCliente", order.customer?.name || order.cliente || "—");

  imagenesRequeridas = [];
  imagenesCargadas = [];

  /* ================= PRODUCTOS ================= */
  const productosHtml = items.map((item, index) => {
    const requiere = requiereImagenModificada(item);
    const imgLocal = imagenesLocales[index] || "";

    imagenesRequeridas[index] = requiere;
    imagenesCargadas[index] = !!imgLocal;

    const estado = requiere
      ? imgLocal
        ? `<span class="px-3 py-1 rounded-full text-xs bg-emerald-100 text-emerald-800 font-bold">Listo</span>`
        : `<span class="px-3 py-1 rounded-full text-xs bg-amber-100 text-amber-800 font-bold">Falta imagen</span>`
      : `<span class="px-3 py-1 rounded-full text-xs bg-slate-100 text-slate-600">Sin imagen</span>`;

    const propsTxt = item.properties.filter(p => !esImagenUrl(p.value));
    const propsImg = item.properties.filter(p => esImagenUrl(p.value));

    return `
      <div class="rounded-3xl border bg-white p-6 shadow-sm space-y-4">
        <div class="flex justify-between items-start">
          <div>
            <div class="font-extrabold text-lg">${escapeHtml(item.title)}</div>
            <div class="text-sm text-slate-600">
              Cant: ${item.quantity} · Precio: ${item.price.toFixed(2)} € ·
              Total: ${(item.price * item.quantity).toFixed(2)} €
            </div>

            ${item.variant_title ? `
              <div class="text-sm mt-1">
                <b>Variante:</b> ${escapeHtml(item.variant_title)}
              </div>` : ""}
          </div>
          ${estado}
        </div>

        <div class="grid grid-cols-2 gap-3 text-xs text-slate-600">
          <div><b>Product ID:</b> ${item.product_id || "—"}</div>
          <div><b>Variant ID:</b> ${item.variant_id || "—"}</div>
        </div>

        ${propsTxt.length ? `
          <div class="bg-slate-50 rounded-xl p-3 text-sm">
            <div class="font-bold mb-1">Personalización</div>
            ${propsTxt.map(p => `
              <div><b>${escapeHtml(p.name)}:</b> ${escapeHtml(p.value)}</div>
            `).join("")}
          </div>` : ""}

        ${propsImg.length ? `
          <div>
            <div class="text-sm font-bold mb-2">Imagen original (cliente)</div>
            <div class="flex gap-3 flex-wrap">
              ${propsImg.map(p => `
                <a href="${p.value}" target="_blank">
                  <img src="${p.value}" class="h-32 w-32 rounded-xl border object-cover">
                </a>
              `).join("")}
            </div>
          </div>` : ""}

        ${imgLocal ? `
          <div>
            <div class="text-sm font-bold mb-2">Imagen modificada</div>
            <img src="${imgLocal}" class="h-40 rounded-xl border object-cover">
          </div>` : ""}

        ${requiere ? `
          <div>
            <div class="text-sm font-bold mb-2">Subir imagen modificada</div>
            <input type="file" accept="image/*"
              onchange="subirImagenProducto('${order.id}', ${index}, this)">
          </div>` : ""}
      </div>
    `;
  }).join("");

  setHtmlSafe("detProductos", `
    <div class="rounded-3xl border bg-white shadow-sm p-5 space-y-5">
      <div class="flex items-center justify-between">
        <h3 class="font-extrabold text-slate-900">Productos</h3>
        <span class="text-xs font-extrabold px-3 py-1 rounded-full bg-slate-100">
          ${items.length}
        </span>
      </div>
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        ${productosHtml}
      </div>
    </div>
  `);

  /* ================= RESUMEN ================= */
  actualizarResumenAuto(order.id);
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
