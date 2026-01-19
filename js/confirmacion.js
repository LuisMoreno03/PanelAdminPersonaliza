/**
 * confirmacion.js — FINAL
 * - SOLO pedidos asignados al usuario
 * - SOLO estado "por preparar"
 * - Prioriza express
 * - NO usa lógica del dashboard
 * - Compatible con dashboard.js SIN modificarlo
 */

const ENDPOINT_QUEUE = window.API.myQueue;
const ENDPOINT_PULL = window.API.pull;
const ENDPOINT_RETURN_ALL = window.API.returnAll;

let pedidosCache = [];
let isLoading = false;

/* =====================================================
   RENDER PRINCIPAL (TABLA CONFIRMACIÓN)
===================================================== */
function renderPedidos(pedidos) {
  const wrap = document.getElementById('tablaPedidos');
  if (!wrap) return;

  wrap.innerHTML = '';

  if (!pedidos || !pedidos.length) {
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
      p.forma_envio.toLowerCase().includes('express');

    const row = document.createElement('div');
    row.className = 'orders-grid cols px-4 py-3 items-center border-b';

    row.innerHTML = `
      <div class="font-extrabold">#${p.numero}</div>

      <div>${(p.created_at || '').slice(0, 10)}</div>

      <div class="truncate">${escapeHtml(p.cliente)}</div>

      <div class="font-bold">${Number(p.total || 0).toFixed(2)} €</div>

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
        ${
          isExpress
            ? `<span class="text-rose-600 font-extrabold">🚀 ${escapeHtml(p.forma_envio)}</span>`
            : escapeHtml(p.forma_envio || '-')
        }
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

  setTotalPedidos(pedidos.length);
}

/* =====================================================
   CARGAR MI COLA (SOLO CONFIRMACIÓN)
===================================================== */
async function cargarMiCola() {
  if (isLoading) return;
  isLoading = true;
  setLoader(true);

  try {
    const res = await fetch(ENDPOINT_QUEUE, {
      credentials: 'same-origin'
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
    console.error('Error cargando cola:', e);
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
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        ...getCsrfHeaders()
      },
      body: JSON.stringify({ count: n }),
      credentials: 'same-origin'
    });
    await cargarMiCola();
  } finally {
    setLoader(false);
  }
}

async function devolverPedidos() {
  if (!confirm('¿Devolver todos los pedidos asignados?')) return;

  setLoader(true);
  try {
    await fetch(ENDPOINT_RETURN_ALL, {
      method: 'POST',
      headers: getCsrfHeaders(),
      credentials: 'same-origin'
    });
    await cargarMiCola();
  } finally {
    setLoader(false);
  }
}

/* =====================================================
   MODAL DETALLES (COMPATIBLE CON dashboard.js)
===================================================== */
function abrirModalPedido(pedidoId) {
  // Si dashboard.js existe, reutilizamos SU modal
  if (typeof window.verDetalles === 'function') {
    window.verDetalles(pedidoId);
    return;
  }

  // Fallback seguro
  console.warn('Modal de detalles no disponible');
  alert('No se pudo abrir el detalle del pedido');
}

/* =====================================================
   HELPERS
===================================================== */
function setLoader(show) {
  const el = document.getElementById('globalLoader');
  if (!el) return;
  el.classList.toggle('hidden', !show);
}

function setTotalPedidos(n) {
  document.querySelectorAll('#total-pedidos').forEach(el => {
    el.textContent = String(n || 0);
  });
}

function escapeHtml(str) {
  return String(str ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function getCsrfHeaders() {
  const token = document.querySelector('meta[name="csrf-token"]')?.content;
  const header = document.querySelector('meta[name="csrf-header"]')?.content;
  return token && header ? { [header]: token } : {};
}

/* =====================================================
   INIT
===================================================== */
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('btnTraer5')?.addEventListener('click', () => traerPedidos(5));
  document.getElementById('btnTraer10')?.addEventListener('click', () => traerPedidos(10));
  document.getElementById('btnDevolver')?.addEventListener('click', devolverPedidos);

  // ⚠️ IMPORTANTE: cargamos SOLO nuestra cola
  cargarMiCola();
});
