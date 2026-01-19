/**
 * confirmacion.js — LIMPIO
 * - Misma tabla que dashboard
 * - Misma grilla
 * - Un solo render
 * - Sin renders duplicados
 */

const ENDPOINT_QUEUE = window.API.myQueue;
const ENDPOINT_PULL  = window.API.pull;
const ENDPOINT_RETURN_ALL = window.API.returnAll;

let pedidosCache = [];
let isLoading = false;

// =========================
// RENDER PRINCIPAL (TABLA DASHBOARD)
// =========================
function renderPedidos(pedidos) {
  const wrap = document.getElementById('tablaPedidos');
  wrap.innerHTML = '';

  if (!pedidos.length) {
    wrap.innerHTML = `
      <div class="p-8 text-center text-slate-500">
        No hay pedidos por confirmar.
      </div>`;
    document.getElementById('total-pedidos').textContent = '0';
    return;
  }

  pedidos.forEach(p => {
    const isExpress =
      p.forma_envio && p.forma_envio.toLowerCase().includes('express');

    const row = document.createElement('div');
    row.className = 'orders-grid cols px-4 py-3 items-center border-b';

    row.innerHTML = `
      <div class="font-extrabold">#${p.numero}</div>

      <div>${(p.created_at || '').slice(0, 10)}</div>

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
          : (p.forma_envio || '-')}
      </div>

      <div class="text-right">
        <button
          onclick="verDetalles(${p.id})"
          class="px-4 py-2 rounded-2xl bg-blue-600 text-white font-extrabold hover:bg-blue-700">
          VER DETALLES →
        </button>
      </div>
    `;

    wrap.appendChild(row);
  });

  document.getElementById('total-pedidos').textContent = pedidos.length;
}

// =========================
// CARGAR COLA
// =========================
async function cargarMiCola() {
  if (isLoading) return;
  isLoading = true;
  setLoader(true);

  try {
    const res = await fetch(ENDPOINT_QUEUE);
    const data = await res.json();

    if (!res.ok || data.ok !== true) {
      pedidosCache = [];
      renderPedidos([]);
      return;
    }

    pedidosCache = data.data || [];
    renderPedidos(pedidosCache);

  } catch (e) {
    console.error(e);
    renderPedidos([]);
  } finally {
    isLoading = false;
    setLoader(false);
  }
}

// =========================
// ACCIONES
// =========================
async function traerPedidos(n) {
  setLoader(true);
  try {
    const res = await fetch(ENDPOINT_PULL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        ...getCsrfHeaders()
      },
      body: JSON.stringify({ count: n })
    });
    await res.json();
    await cargarMiCola();
  } finally {
    setLoader(false);
  }
}

async function devolverPedidos() {
  if (!confirm('¿Devolver todos los pedidos?')) return;
  setLoader(true);
  try {
    await fetch(ENDPOINT_RETURN_ALL, {
      method: 'POST',
      headers: getCsrfHeaders()
    });
    await cargarMiCola();
  } finally {
    setLoader(false);
  }
}

// =========================
// HELPERS
// =========================
function setLoader(show) {
  const el = document.getElementById('globalLoader');
  if (!el) return;
  el.classList.toggle('hidden', !show);
}

function getCsrfHeaders() {
  const token  = document.querySelector('meta[name="csrf-token"]')?.content;
  const header = document.querySelector('meta[name="csrf-header"]')?.content;
  return token && header ? { [header]: token } : {};
}

// =========================
// INIT
// =========================
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('btnTraer5')?.addEventListener('click', () => traerPedidos(5));
  document.getElementById('btnTraer10')?.addEventListener('click', () => traerPedidos(10));
  document.getElementById('btnDevolver')?.addEventListener('click', devolverPedidos);

  cargarMiCola();
});
