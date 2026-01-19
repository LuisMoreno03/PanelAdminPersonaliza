/**
 * confirmacion.js — INDEPENDIENTE
 * - Cola por usuario
 * - Estado: Por preparar
 * - Vista propia
 * - verDetalles propio (sin dashboard)
 */

const ENDPOINT_QUEUE = window.API.myQueue;
const ENDPOINT_PULL = window.API.pull;
const ENDPOINT_RETURN_ALL = window.API.returnAll;
const ENDPOINT_DETALLES = window.API.detalles; // lo defines en confirmacion.php

let pedidosCache = [];
let isLoading = false;

/* =====================================================
   HELPERS BÁSICOS
===================================================== */
function $(id) {
  return document.getElementById(id);
}

function setHtml(id, html) {
  const el = $(id);
  if (el) el.innerHTML = html;
}

function setText(id, txt) {
  const el = $(id);
  if (el) el.textContent = txt ?? "";
}

function escapeHtml(str) {
  return String(str ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function setLoader(show) {
  const el = $("globalLoader");
  if (!el) return;
  el.classList.toggle("hidden", !show);
}

function setTotalPedidos(n) {
  document.querySelectorAll("#total-pedidos").forEach(el => {
    el.textContent = String(n || 0);
  });
}

function getCsrfHeaders() {
  const token = document.querySelector('meta[name="csrf-token"]')?.content;
  const header = document.querySelector('meta[name="csrf-header"]')?.content;
  return token && header ? { [header]: token } : {};
}

/* =====================================================
   RENDER LISTADO
===================================================== */
function renderPedidos(pedidos) {
  const wrap = $("tablaPedidos");
  if (!wrap) return;

  wrap.innerHTML = "";

  if (!pedidos.length) {
    wrap.innerHTML = `
      <div class="p-8 text-center text-slate-500">
        No hay pedidos por confirmar.
      </div>
    `;
    setTotalPedidos(0);
    return;
  }

  pedidos.forEach(p => {
    const isExpress =
      p.forma_envio &&
      p.forma_envio.toLowerCase().includes("express");

    const row = document.createElement("div");
    row.className = "orders-grid cols px-4 py-3 items-center border-b";

    row.innerHTML = `
      <div class="font-extrabold">#${escapeHtml(p.numero)}</div>
      <div>${(p.created_at || "").slice(0, 10)}</div>
      <div class="truncate">${escapeHtml(p.cliente || "-")}</div>
      <div class="font-bold">${Number(p.total || 0).toFixed(2)} €</div>

      <div>
        <span class="px-3 py-1 rounded-full text-xs font-extrabold bg-blue-600 text-white">
          POR PREPARAR
        </span>
      </div>

      <div>—</div>

      <div>
        <span class="text-xs text-slate-400">—</span>
      </div>

      <div class="text-center">${p.articulos || 1}</div>

      <div>
        <span class="px-3 py-1 rounded-full text-xs font-bold bg-slate-100">
          Sin preparar
        </span>
      </div>

      <div class="metodo-entrega">
        ${
          isExpress
            ? `<span class="text-rose-600 font-extrabold">🚀 ${escapeHtml(p.forma_envio)}</span>`
            : escapeHtml(p.forma_envio || "-")
        }
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

  setTotalPedidos(pedidos.length);
}

/* =====================================================
   CARGAR MI COLA
===================================================== */
async function cargarMiCola() {
  if (isLoading) return;
  isLoading = true;
  setLoader(true);

  try {
    const res = await fetch(ENDPOINT_QUEUE, {
      credentials: "same-origin",
    });
    const data = await res.json();

    if (!res.ok || data.ok !== true) {
      pedidosCache = [];
      renderPedidos([]);
      return;
    }

    pedidosCache = data.data || [];
    renderPedidos(pedidosCache);

  } catch (e) {
    console.error("Error cargando cola:", e);
    renderPedidos([]);
  } finally {
    isLoading = false;
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
        ...getCsrfHeaders(),
      },
      body: JSON.stringify({ count: n }),
      credentials: "same-origin",
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
      credentials: "same-origin",
    });
    await cargarMiCola();
  } finally {
    setLoader(false);
  }
}

/* =====================================================
   VER DETALLES (INDEPENDIENTE)
===================================================== */

async function verDetalles(orderId) {
  if (!orderId) return;

  const modal = $("modalDetallesFull");
  if (!modal) {
    alert("Modal de detalles no encontrado");
    return;
  }

  modal.classList.remove("hidden");
  document.documentElement.classList.add("overflow-hidden");
  document.body.classList.add("overflow-hidden");

  setText("detTitle", "Cargando…");
  setText("detSubtitle", "—");
  setHtml("detItems", "<div class='text-slate-500'>Cargando productos…</div>");
  setHtml("detCliente", "<div class='text-slate-500'>Cargando…</div>");
  setHtml("detEnvio", "<div class='text-slate-500'>Cargando…</div>");
  setHtml("detTotales", "<div class='text-slate-500'>Cargando…</div>");

  try {
    const res = await fetch(`${ENDPOINT_DETALLES}/${orderId}`, {
      credentials: "same-origin",
    });
    const data = await res.json();

    if (!res.ok || data.success !== true) {
      setHtml("detItems", "<div class='text-red-600 font-bold'>Error cargando detalles</div>");
      return;
    }

    const o = data.order;

    setText("detTitle", o.name || `Pedido #${orderId}`);
    setText(
      "detSubtitle",
      o.customer
        ? `${o.customer.first_name || ""} ${o.customer.last_name || ""}`.trim()
        : o.email || "—"
    );

    setHtml("detCliente", `
      <div class="space-y-1">
        <div class="font-extrabold">${escapeHtml(o.customer?.first_name || "")} ${escapeHtml(o.customer?.last_name || "")}</div>
        <div>Email: ${escapeHtml(o.email || "—")}</div>
        <div>Tel: ${escapeHtml(o.phone || "—")}</div>
      </div>
    `);

    const a = o.shipping_address || {};
    setHtml("detEnvio", `
      <div class="space-y-1">
        <div class="font-extrabold">${escapeHtml(a.name || "—")}</div>
        <div>${escapeHtml(a.address1 || "")}</div>
        <div>${escapeHtml(a.city || "")}</div>
        <div>${escapeHtml(a.country || "")}</div>
      </div>
    `);

    setHtml("detTotales", `
      <div class="space-y-1">
        <div>Subtotal: ${escapeHtml(o.subtotal_price)} €</div>
        <div>Envío: ${escapeHtml(o.total_shipping_price_set?.shop_money?.amount || 0)} €</div>
        <div class="text-lg font-extrabold">Total: ${escapeHtml(o.total_price)} €</div>
      </div>
    `);

    const items = o.line_items || [];
    setHtml(
      "detItems",
      items.length
        ? items
            .map(
              item => `
        <div class="rounded-2xl border bg-white p-4 shadow-sm">
          <div class="font-extrabold">${escapeHtml(item.title)}</div>
          <div class="text-sm text-slate-600">
            Cant: ${item.quantity} · Precio: ${item.price} €
          </div>
        </div>`
            )
            .join("")
        : "<div class='text-slate-500'>Sin productos</div>"
    );

  } catch (e) {
    console.error(e);
    setHtml("detItems", "<div class='text-red-600 font-bold'>Error de red</div>");
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
