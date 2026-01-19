/**
 * confirmacion.js — CI4
 * - Cola por usuario
 * - SOLO pedidos en "Por preparar"
 * - Excluye enviados
 * - Prioriza envío EXPRESS
 * - CSRF OK
 * - Loader global
 */

const API_BASE = String(window.API_BASE || "").replace(/\/$/, "");
const ENDPOINT_QUEUE = `${API_BASE}/confirmacion/my-queue`;
const ENDPOINT_PULL  = `${API_BASE}/confirmacion/pull`;
const ENDPOINT_RETURN_ALL = `${API_BASE}/confirmacion/return-all`;

let pedidosCache = [];
let pedidosFiltrados = [];
let isLoading = false;


function renderPedidos(pedidos) {
  const wrap = document.getElementById('tablaPedidos');
  wrap.innerHTML = '';

  pedidos.forEach(p => {
    const row = document.createElement('div');
    row.className = 'orders-grid cols px-4 py-3 items-center';

    const isExpress =
      p.forma_envio && p.forma_envio.toLowerCase().includes('express');

    row.innerHTML = `
      <div class="font-extrabold">#${p.numero}</div>
      <div>${(p.created_at || '').slice(0,10)}</div>
      <div class="truncate">${p.cliente || '-'}</div>
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

      <div class="metodo-entrega">
        ${isExpress
          ? `<span class="text-rose-600 font-extrabold">🚀 ${p.forma_envio}</span>`
          : p.forma_envio}
      </div>

      <div class="text-right">
        <button
          onclick="abrirModalPedido(${p.id})"
          class="px-4 py-2 rounded-2xl bg-blue-600 text-white font-extrabold hover:bg-blue-700">
          VER DETALLES →
        </button>
      </div>
    `;

    wrap.appendChild(row);
  });

  document.getElementById('total-pedidos').textContent = pedidos.length;
}

async function cargarMiCola() {
  const r = await fetch(window.API.myQueue);
  const j = await r.json();
  pedidosCache = j.data || [];
  renderPedidos(pedidosCache);
}

document.addEventListener('DOMContentLoaded', () => {
  cargarMiCola();

  document.getElementById('btnTraer5').onclick = () => pull(5);
  document.getElementById('btnTraer10').onclick = () => pull(10);
  document.getElementById('btnDevolver').onclick = returnAll;
});

async function pull(n) {
  await fetch(window.API.pull, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...getCsrfHeaders()
    },
    body: JSON.stringify({ count: n })
  });
  cargarMiCola();
}

async function returnAll() {
  await fetch(window.API.returnAll, {
    method: 'POST',
    headers: getCsrfHeaders()
  });
  cargarMiCola();
}

// =========================
// Helpers DOM
// =========================
function $(id) { return document.getElementById(id); }

function setLoader(show) {
  const el = $("globalLoader");
  if (!el) return;
  el.classList.toggle("hidden", !show);
}

function setTotalPedidos(n) {
  document.querySelectorAll("#total-pedidos").forEach(el => {
    el.textContent = String(n ?? 0);
  });
}

function escapeHtml(str) {
  return String(str ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

// =========================
// CSRF
// =========================
function getCsrfHeaders() {
  const token  = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content");
  const header = document.querySelector('meta[name="csrf-header"]')?.getAttribute("content");
  if (!token || !header) return {};
  return { [header]: token };
}

// =========================
// API helpers
// =========================
async function apiGet(url) {
  const res = await fetch(url, {
    method: "GET",
    headers: { Accept: "application/json" },
    credentials: "same-origin"
  });
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch { data = null; }
  return { res, data, raw: text };
}

async function apiPost(url, payload) {
  const res = await fetch(url, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "Accept": "application/json",
      ...getCsrfHeaders()
    },
    body: JSON.stringify(payload ?? {}),
    credentials: "same-origin"
  });

  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch { data = null; }
  return { res, data, raw: text };
}

// =========================
// Render responsive
// =========================
function getMode() {
  const w = window.innerWidth || 0;
  if (w >= 1536) return "grid";
  if (w >= 1280) return "table";
  return "cards";
}

function renderEntregaBadge(forma) {
  const s = String(forma || "").toLowerCase();
  if (s.includes("express")) {
    return `<span class="px-3 py-1 rounded-full text-xs font-extrabold bg-red-600 text-white">🚀 EXPRESS</span>`;
  }
  return `<span class="px-3 py-1 rounded-full text-xs font-extrabold bg-slate-200 text-slate-800">Normal</span>`;
}

function actualizarListado(pedidos) {
  const mode = getMode();
  const grid  = $("tablaPedidos");
  const table = $("tablaPedidosTable");
  const cards = $("cardsPedidos");

  if (grid) grid.innerHTML = "";
  if (table) table.innerHTML = "";
  if (cards) cards.innerHTML = "";

  if (!pedidos || !pedidos.length) {
    const empty = `<div class="p-8 text-center text-slate-500">No hay pedidos por confirmar.</div>`;
    if (grid) grid.innerHTML = empty;
    if (table) table.innerHTML = `<tr><td class="p-8">${empty}</td></tr>`;
    if (cards) cards.innerHTML = empty;
    return;
  }

  // GRID
  if (mode === "grid" && grid) {
    grid.innerHTML = pedidos.map(p => `
      <div class="px-4 py-3 border-b flex items-center justify-between">
        <div>
          <div class="font-extrabold">${escapeHtml(p.numero)}</div>
          <div class="text-xs text-slate-500">${escapeHtml(p.cliente)}</div>
        </div>
        ${renderEntregaBadge(p.forma_envio)}
      </div>
    `).join("");
    return;
  }

  // TABLE
  if (mode === "table" && table) {
    table.innerHTML = pedidos.map(p => `
      <tr class="border-b">
        <td class="px-4 py-3 font-bold">${escapeHtml(p.numero)}</td>
        <td class="px-4 py-3">${escapeHtml(p.cliente)}</td>
        <td class="px-4 py-3">${renderEntregaBadge(p.forma_envio)}</td>
      </tr>
    `).join("");
    return;
  }

  // CARDS
  if (cards) {
    cards.innerHTML = pedidos.map(p => `
      <div class="mb-3 rounded-3xl border bg-white p-4 shadow-sm">
        <div class="font-extrabold">${escapeHtml(p.numero)}</div>
        <div class="text-sm text-slate-600">${escapeHtml(p.cliente)}</div>
        <div class="mt-2">${renderEntregaBadge(p.forma_envio)}</div>
      </div>
    `).join("");
  }
}

// =========================
// Búsqueda local
// =========================
function aplicarFiltroBusqueda() {
  const q = ($("inputBuscar")?.value || "").toLowerCase().trim();
  if (!q) {
    pedidosFiltrados = [...pedidosCache];
  } else {
    pedidosFiltrados = pedidosCache.filter(p =>
      `${p.numero} ${p.cliente}`.toLowerCase().includes(q)
    );
  }
  actualizarListado(pedidosFiltrados);
  setTotalPedidos(pedidosFiltrados.length);
}

// =========================
// Cargar cola
// =========================
async function cargarMiCola() {
  if (isLoading) return;
  isLoading = true;
  setLoader(true);

  try {
    const { res, data } = await apiGet(ENDPOINT_QUEUE);
    if (!res.ok || !data || data.ok !== true) {
      pedidosCache = [];
      actualizarListado([]);
      setTotalPedidos(0);
      return;
    }

    pedidosCache = data.data || [];
    pedidosFiltrados = [...pedidosCache];

    actualizarListado(pedidosFiltrados);
    setTotalPedidos(pedidosFiltrados.length);

  } finally {
    isLoading = false;
    setLoader(false);
  }
}

// =========================
// Acciones
// =========================
async function traerPedidos(n) {
  setLoader(true);
  try {
    const { res, data } = await apiPost(ENDPOINT_PULL, { count: n });
    if (!res.ok || !data || data.ok !== true) {
      alert(data?.error || "No se pudieron traer pedidos");
      return;
    }
    await cargarMiCola();
  } finally {
    setLoader(false);
  }
}

async function devolverPedidosRestantes() {
  if (!confirm("¿Devolver todos tus pedidos de confirmación?")) return;

  setLoader(true);
  try {
    const { res, data } = await apiPost(ENDPOINT_RETURN_ALL, {});
    if (!res.ok || !data || data.ok !== true) {
      alert("No se pudieron devolver");
      return;
    }
    await cargarMiCola();
  } finally {
    setLoader(false);
  }
}

// =========================
// Eventos
// =========================
document.addEventListener("DOMContentLoaded", () => {
  $("btnTraer5")?.addEventListener("click", () => traerPedidos(5));
  $("btnTraer10")?.addEventListener("click", () => traerPedidos(10));
  $("btnDevolver")?.addEventListener("click", devolverPedidosRestantes);

  $("inputBuscar")?.addEventListener("input", aplicarFiltroBusqueda);
  $("btnLimpiarBusqueda")?.addEventListener("click", () => {
    const i = $("inputBuscar");
    if (i) i.value = "";
    aplicarFiltroBusqueda();
  });

  window.addEventListener("resize", () => {
    actualizarListado(pedidosFiltrados.length ? pedidosFiltrados : pedidosCache);
  });

  cargarMiCola();
});
