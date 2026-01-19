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

async function verDetalles(shopifyOrderId) {
  if (!shopifyOrderId) {
    alert("Pedido sin Shopify ID");
    return;
  }

  abrirDetallesFull();

  try {
    const res = await fetch(
      `/index.php/dashboard/detalles/${encodeURIComponent(shopifyOrderId)}`,
      { headers: { Accept: "application/json" } }
    );

    const data = await res.json();

    if (!res.ok || data.success !== true) {
      document.getElementById("detItems").innerHTML =
        `<div class="text-red-600 font-bold">Error cargando detalles</div>`;
      return;
    }

    pintarDetallesPedido(data.order, data);

  } catch (e) {
    console.error(e);
    document.getElementById("detItems").innerHTML =
      `<div class="text-red-600 font-bold">Error de red</div>`;
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
