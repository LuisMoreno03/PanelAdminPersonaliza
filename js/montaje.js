/**
 * montaje.js (CI4)
 * - Pull 5/10 pedidos en estado Diseñado
 * - Cola muestra SOLO "Diseñado"
 * - Botón Cargado => estado "Por producir" + se quita de la lista
 * - Endpoints con fallback con/sin index.php
 */

const API_BASE = String(window.API_BASE || "").replace(/\/$/, "");

const ENDPOINT_QUEUE = `${API_BASE}/montaje/my-queue`;
const ENDPOINT_PULL  = `${API_BASE}/montaje/pull`;
const ENDPOINT_DONE  = `${API_BASE}/montaje/subir-pedido`;

let pedidosCache = [];
let pedidosFiltrados = [];
let isLoading = false;
let silentFetch = false;
let liveInterval = null;

function $(id) { return document.getElementById(id); }

function setLoader(show) {
  if (silentFetch) return;
  const el = $("globalLoader");
  if (!el) return;
  el.classList.toggle("hidden", !show);
}

function setTotalPedidos(n) {
  document.querySelectorAll("#total-pedidos").forEach((el) => {
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

function escapeJsString(str) {
  return String(str ?? "").replaceAll("\\", "\\\\").replaceAll("'", "\\'");
}

function safeText(v) {
  return (v === null || v === undefined || v === "") ? "" : String(v);
}

function moneyFormat(v) {
  if (v === null || v === undefined || v === "") return "—";
  const num = Number(v);
  if (Number.isNaN(num)) return escapeHtml(String(v));
  try {
    return num.toLocaleString("es-CO", { style: "currency", currency: "COP" });
  } catch {
    return escapeHtml(String(v));
  }
}

function getCsrfHeaders() {
  const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content");
  const header = document.querySelector('meta[name="csrf-header"]')?.getAttribute("content");
  if (!token || !header) return {};
  return { [header]: token };
}

async function apiGet(url) {
  const res = await fetch(url, { method: "GET", headers: { Accept: "application/json" }, credentials: "same-origin" });
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch { data = null; }
  return { res, data, raw: text };
}

async function apiPost(url, payload) {
  const headers = {
    "Content-Type": "application/json",
    Accept: "application/json",
    ...getCsrfHeaders(),
  };
  const res = await fetch(url, {
    method: "POST",
    headers,
    body: JSON.stringify(payload ?? {}),
    credentials: "same-origin",
  });
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch { data = null; }
  return { res, data, raw: text };
}

async function apiGetWithFallback(candidates) {
  let last = null;
  for (const url of candidates) {
    try {
      const out = await apiGet(url);
      if (out.res.status === 404) continue;
      return out;
    } catch (e) { last = e; }
  }
  throw last || new Error("GET fallback failed");
}

async function apiPostWithFallback(candidates, payload) {
  let last = null;
  for (const url of candidates) {
    try {
      const out = await apiPost(url, payload);
      if (out.res.status === 404) continue;
      return out;
    } catch (e) { last = e; }
  }
  throw last || new Error("POST fallback failed");
}

function extractOrdersPayload(payload) {
  if (!payload || typeof payload !== "object") return { ok: false, orders: [] };
  if (payload.success === true) return { ok: true, orders: Array.isArray(payload.orders) ? payload.orders : [] };
  if (payload.ok === true) return { ok: true, orders: Array.isArray(payload.data) ? payload.data : [] };
  return { ok: false, orders: [] };
}

function aplicarFiltroBusqueda() {
  const q = ($("inputBuscar")?.value || "").trim().toLowerCase();
  if (!q) {
    pedidosFiltrados = [...pedidosCache];
    pintarTabla(pedidosFiltrados);
    setTotalPedidos(pedidosFiltrados.length);
    return;
  }

  pedidosFiltrados = pedidosCache.filter((p) => {
    const haystack = [
      p.id, p.shopify_order_id, p.numero,
      p.cliente, p.estado,
      p.etiquetas, p.tags,
      p.forma_envio, p.estado_envio,
    ].map(safeText).join(" ").toLowerCase();
    return haystack.includes(q);
  });

  pintarTabla(pedidosFiltrados);
  setTotalPedidos(pedidosFiltrados.length);
}

function pintarTabla(pedidos) {
  const tbody = $("tablaPedidosTable");
  if (!tbody) return;

  if (!pedidos || !pedidos.length) {
    tbody.innerHTML = `
      <tr>
        <td colspan="9" class="px-5 py-8 text-slate-500 text-sm text-center">
          No hay pedidos en Diseñado.
        </td>
      </tr>
    `;
    return;
  }

  tbody.innerHTML = pedidos.map((p) => {
    const internalId = String(p.id ?? "");
    const shopifyId  = String(p.shopify_order_id ?? "");
    const orderKey   = (shopifyId && shopifyId !== "0") ? shopifyId : internalId;

    const numero = String(p.numero ?? ("#" + internalId));
    const fecha  = p.fecha ?? p.created_at ?? "—";
    const cliente= p.cliente ?? "—";
    const total  = p.total ?? "";
    const estado = p.estado ?? "Diseñado";
    const items  = p.articulos ?? p.items_count ?? "-";
    const entrega= p.estado_envio ?? "-";

    return `
      <tr class="border-b border-slate-200 hover:bg-slate-50 transition">
        <td class="px-4 py-3 font-extrabold text-slate-900 whitespace-nowrap">${escapeHtml(numero)}</td>
        <td class="px-4 py-3 text-slate-600 whitespace-nowrap">${escapeHtml(String(fecha || "—"))}</td>
        <td class="px-4 py-3 min-w-0 font-semibold text-slate-800 truncate">${escapeHtml(String(cliente || "—"))}</td>
        <td class="px-4 py-3 font-extrabold text-slate-900 whitespace-nowrap text-right">${moneyFormat(total)}</td>
        <td class="px-4 py-3 whitespace-nowrap">
          <span class="inline-flex items-center px-3 py-1.5 rounded-full text-[11px] font-extrabold
            bg-blue-600 text-white border border-blue-700 whitespace-nowrap">🎨 ${escapeHtml(estado)}</span>
        </td>
        <td class="px-4 py-3 text-center font-extrabold">${escapeHtml(String(items ?? "-"))}</td>
        <td class="px-4 py-3 whitespace-nowrap">
          <span class="inline-flex items-center px-3 py-1.5 rounded-full text-[11px] font-extrabold
            bg-slate-100 text-slate-800 border border-slate-200 whitespace-nowrap">${escapeHtml(entrega)}</span>
        </td>
        <td class="px-4 py-3 text-right whitespace-nowrap">
          <button type="button"
            onclick="window.marcarCargado('${escapeJsString(orderKey)}')"
            class="h-9 px-3 rounded-2xl bg-emerald-600 text-white text-[11px] font-extrabold uppercase tracking-wide hover:bg-emerald-700 transition">
            Cargado ✓
          </button>
        </td>
      </tr>
    `;
  }).join("");
}

async function cargarMiCola() {
  if (isLoading) return;
  isLoading = true;
  setLoader(true);

  try {
    const candidates = [
      ENDPOINT_QUEUE,
      `/montaje/my-queue`,
      `/index.php/montaje/my-queue`,
    ];

    const { res, data, raw } = await apiGetWithFallback(candidates);

    if (!res.ok || !data) {
      console.error("Queue FAIL:", res.status, raw);
      pedidosCache = [];
      pedidosFiltrados = [];
      pintarTabla([]);
      setTotalPedidos(0);
      return;
    }

    const extracted = extractOrdersPayload(data);
    if (!extracted.ok) {
      console.error("Queue payload inválido:", data);
      pedidosCache = [];
      pedidosFiltrados = [];
      pintarTabla([]);
      setTotalPedidos(0);
      return;
    }

    pedidosCache = extracted.orders || [];
    pedidosFiltrados = [...pedidosCache];

    const q = ($("inputBuscar")?.value || "").trim();
    if (q) aplicarFiltroBusqueda();
    else {
      pintarTabla(pedidosFiltrados);
      setTotalPedidos(pedidosFiltrados.length);
    }
  } catch (e) {
    console.error("cargarMiCola error:", e);
    pedidosCache = [];
    pedidosFiltrados = [];
    pintarTabla([]);
    setTotalPedidos(0);
  } finally {
    isLoading = false;
    silentFetch = false;
    setLoader(false);
  }
}

async function traerPedidos(count) {
  setLoader(true);
  try {
    const candidates = [
      ENDPOINT_PULL,
      `/montaje/pull`,
      `/index.php/montaje/pull`,
    ];

    const { res, data, raw } = await apiPostWithFallback(candidates, { count });

    if (!res.ok || !data) {
      console.error("PULL FAIL:", res.status, raw);
      alert("No se pudo traer pedidos (error de red o sesión).");
      return;
    }

    const ok = data.ok === true || data.success === true;
    if (!ok) {
      console.error("PULL backend:", data);
      alert(data.error || data.message || "Error interno asignando pedidos");
      return;
    }

    await cargarMiCola();
  } finally {
    setLoader(false);
  }
}

window.marcarCargado = async function (orderId) {
  const ok = confirm("¿Marcar como Cargado y pasar a Por producir?");
  if (!ok) return;

  setLoader(true);
  try {
    const candidates = [
      ENDPOINT_DONE,
      `/montaje/subir-pedido`,
      `/index.php/montaje/subir-pedido`,
    ];

    const { res, data, raw } = await apiPostWithFallback(candidates, { order_id: String(orderId) });

    if (!res.ok || !data) {
      console.error("DONE FAIL:", res.status, raw);
      alert("No se pudo marcar como cargado.");
      return;
    }

    const ok2 = data.ok === true || data.success === true;
    if (!ok2) {
      console.error("DONE backend:", data);
      alert(data.error || data.message || "No se pudo marcar como cargado.");
      return;
    }

    // refresca lista (desaparece porque ya es Por producir)
    await cargarMiCola();
  } finally {
    setLoader(false);
  }
};

function bindEventos() {
  $("btnTraer5")?.addEventListener("click", () => traerPedidos(5));
  $("btnTraer10")?.addEventListener("click", () => traerPedidos(10));
  $("btnActualizar")?.addEventListener("click", () => cargarMiCola());

  $("inputBuscar")?.addEventListener("input", () => aplicarFiltroBusqueda());
  $("btnLimpiarBusqueda")?.addEventListener("click", () => {
    const el = $("inputBuscar");
    if (el) el.value = "";
    aplicarFiltroBusqueda();
  });
}

function startLive(ms = 30000) {
  if (liveInterval) clearInterval(liveInterval);
  liveInterval = setInterval(() => {
    if (!isLoading) {
      silentFetch = true;
      cargarMiCola();
    }
  }, ms);
}

document.addEventListener("DOMContentLoaded", () => {
  bindEventos();
  cargarMiCola();
  startLive(30000);
});
