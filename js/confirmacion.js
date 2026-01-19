/**
 * confirmacion.js — FINAL
 * Independiente de dashboard
 * Basado en Shopify Order ID
 */

const API = window.API || {};
const ENDPOINT_QUEUE = API.myQueue;
const ENDPOINT_PULL = API.pull;
const ENDPOINT_RETURN_ALL = API.returnAll;
const ENDPOINT_DETALLES = API.detalles; // 👈 nuevo

let pedidosCache = [];
let loading = false;

/* =====================================================
  HELPERS
===================================================== */
function $(id) {
  return document.getElementById(id);
}

function setLoader(show) {
  const el = $("globalLoader");
  if (!el) return;
  el.classList.toggle("hidden", !show);
}

function escapeHtml(str) {
  return String(str ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function getCsrfHeaders() {
  const token = document.querySelector('meta[name="csrf-token"]')?.content;
  const header = document.querySelector('meta[name="csrf-header"]')?.content;
  return token && header ? { [header]: token } : {};
}

/* =====================================================
  LISTADO (MISMA TABLA DASHBOARD)
===================================================== */
function renderPedidos(pedidos) {
  const wrap = $("tablaPedidos");
  wrap.innerHTML = "";

  if (!pedidos.length) {
    wrap.innerHTML = `
      <div class="p-8 text-center text-slate-500">
        No tienes pedidos asignados
      </div>`;
    $("total-pedidos").textContent = "0";
    return;
  }

  pedidos.forEach(p => {
    const express = p.forma_envio?.toLowerCase().includes("express");

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

      <div class="metodo-entrega">
        ${express
          ? `<span class="text-rose-600 font-extrabold">🚀 ${escapeHtml(p.forma_envio)}</span>`
          : escapeHtml(p.forma_envio || "-")}
      </div>

      <div class="text-right">
        <button
          onclick="verDetalles('${p.shopify_order_id}')"
          class="px-4 py-2 rounded-2xl bg-blue-600 text-white font-extrabold hover:bg-blue-700">
          VER DETALLES →
        </button>
      </div>
    `;

    wrap.appendChild(row);
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
      headers: {
        "Content-Type": "application/json",
        ...getCsrfHeaders()
      },
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
      headers: getCsrfHeaders(),
      credentials: "same-origin"
    });
    await cargarMiCola();
  } finally {
    setLoader(false);
  }
}

/* =====================================================
  MODAL DETALLES (SHOPIFY REAL)
===================================================== */
window.verDetalles = async function (shopifyOrderId) {
  abrirModalDetalles();
  pintarCargandoDetalles();

  try {
    const r = await fetch(`${ENDPOINT_DETALLES}/${shopifyOrderId}`, {
      headers: { Accept: "application/json" },
      credentials: "same-origin"
    });

    const d = await r.json();

    // 🧠 normalizar backend
    const order =
      d?.order ||
      (d?.id && d?.line_items ? d : null);

    if (!r.ok || !order) {
      throw new Error(d?.message || "Pedido sin información Shopify");
    }

    pintarDetallesPedido(order);

  } catch (e) {
    console.error(e);
    pintarErrorDetalles(e.message);
  }
};

/* =====================================================
  DETALLES UI
===================================================== */
function abrirModalDetalles() {
  $("modalDetalles").classList.remove("hidden");
  document.body.classList.add("overflow-hidden");
}

function cerrarModalDetalles() {
  $("modalDetalles").classList.add("hidden");
  document.body.classList.remove("overflow-hidden");
}

function pintarCargandoDetalles() {
  $("detTitulo").textContent = "Cargando…";
  $("detProductos").innerHTML = "Cargando productos…";
  $("detResumen").innerHTML = "—";
}

function pintarErrorDetalles(msg) {
  $("detProductos").innerHTML = `<span class="text-rose-600 font-extrabold">${escapeHtml(msg)}</span>`;
}

function pintarDetallesPedido(o) {
  $("detTitulo").textContent = `Pedido ${o.name || "#" + o.id}`;

  // Productos
  $("detProductos").innerHTML = o.line_items.map(i => `
    <div class="border rounded-2xl p-4 bg-white shadow-sm">
      <div class="font-extrabold">${escapeHtml(i.title)}</div>
      <div class="text-sm text-slate-600">
        Cant: ${i.quantity} · ${i.price} €
      </div>
    </div>
  `).join("");

  // Resumen
  $("detResumen").innerHTML = `
    <div><b>Total:</b> ${escapeHtml(o.total_price)} €</div>
    <div><b>Estado pago:</b> ${escapeHtml(o.financial_status)}</div>
    <div><b>Entrega:</b> ${escapeHtml(o.fulfillment_status || "—")}</div>
    <div><b>Cliente:</b> ${escapeHtml(o.customer?.first_name || "")} ${escapeHtml(o.customer?.last_name || "")}</div>
  `;
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
