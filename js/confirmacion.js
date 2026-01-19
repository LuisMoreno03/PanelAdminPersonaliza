const API_BASE = String(window.API_BASE || "").replace(/\/$/, "");

const ENDPOINT_QUEUE = `${API_BASE}/confirmacion/my-queue`;
const ENDPOINT_PULL  = `${API_BASE}/confirmacion/pull`;
const ENDPOINT_RETURN_ALL = `${API_BASE}/confirmacion/return-all`;

let pedidosCache = [];
let pedidosFiltrados = [];
let isLoading = false;

// =========================
// API helpers
// =========================
async function apiGet(url) {
  const r = await fetch(url, { credentials: "same-origin" });
  return r.json();
}

async function apiPost(url, payload) {
  return fetch(url, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      ...getCsrfHeaders()
    },
    body: JSON.stringify(payload || {}),
    credentials: "same-origin"
  }).then(r => r.json());
}

// =========================
// Cargar cola
// =========================
async function cargarMiCola() {
  if (isLoading) return;
  isLoading = true;

  try {
    const data = await apiGet(ENDPOINT_QUEUE);
    pedidosCache = data?.data || [];
    pedidosFiltrados = [...pedidosCache];
    actualizarListado(pedidosFiltrados);
    setTotalPedidos(pedidosFiltrados.length);
  } finally {
    isLoading = false;
  }
}

// =========================
// Acciones
// =========================
async function traerPedidos(n) {
  await apiPost(ENDPOINT_PULL, { count: n });
  await cargarMiCola();
}

async function devolverPedidosRestantes() {
  if (!confirm("¿Devolver todos los pedidos?")) return;
  await apiPost(ENDPOINT_RETURN_ALL, {});
  await cargarMiCola();
}

// =========================
// Eventos
// =========================
document.addEventListener("DOMContentLoaded", () => {
  document.getElementById("btnTraer5")?.addEventListener("click", () => traerPedidos(5));
  document.getElementById("btnTraer10")?.addEventListener("click", () => traerPedidos(10));
  document.getElementById("btnDevolver")?.addEventListener("click", devolverPedidosRestantes);

  cargarMiCola();
});
