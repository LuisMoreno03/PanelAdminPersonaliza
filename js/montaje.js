const API_BASE = String(window.API_BASE || "").replace(/\/$/, "");

const ENDPOINT_QUEUE = `${API_BASE}/montaje/my-queue`;
const ENDPOINT_PULL  = `${API_BASE}/montaje/pull`;
const ENDPOINT_RETURN_ALL = `${API_BASE}/montaje/return-all`;
const ENDPOINT_CARGADO = `${API_BASE}/montaje/cargado`;

let pedidosCache = [];
let pedidosFiltrados = [];
let isLoading = false;

function $(id){ return document.getElementById(id); }

function setTotalPedidos(n) {
  document.querySelectorAll("#total-pedidos").forEach(el => el.textContent = String(n ?? 0));
}

function getCsrfHeaders() {
  const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content");
  const header = document.querySelector('meta[name="csrf-header"]')?.getAttribute("content");
  if (!token || !header) return {};
  return { [header]: token };
}

async function apiGet(url) {
  const res = await fetch(url, { method:"GET", headers:{Accept:"application/json"}, credentials:"same-origin" });
  const text = await res.text();
  let data = null; try { data = JSON.parse(text); } catch {}
  return { res, data, raw: text };
}

async function apiPost(url, payload) {
  const res = await fetch(url, {
    method:"POST",
    headers: { "Content-Type":"application/json", Accept:"application/json", ...getCsrfHeaders() },
    credentials:"same-origin",
    body: JSON.stringify(payload ?? {})
  });
  const text = await res.text();
  let data = null; try { data = JSON.parse(text); } catch {}
  return { res, data, raw: text };
}

function extractOrdersPayload(payload) {
  if (!payload || typeof payload !== "object") return { ok:false, orders:[] };
  if (payload.ok === true) return { ok:true, orders: Array.isArray(payload.data) ? payload.data : [] };
  if (payload.success === true) return { ok:true, orders: Array.isArray(payload.orders) ? payload.orders : [] };
  return { ok:false, orders:[] };
}

// 👉 tu render: si ya tienes el mismo html de confirmacion,
// deja el mismo pintar tabla/cards y solo añade el botón "Cargado"
function renderListado(pedidos){
  const tbody = $("tablaPedidosTable");
  if (!tbody) return;

  if (!pedidos || !pedidos.length) {
    tbody.innerHTML = `
      <tr><td colspan="9" class="px-5 py-8 text-slate-500 text-sm">No hay pedidos en Diseñado.</td></tr>
    `;
    return;
  }

  tbody.innerHTML = pedidos.map(p => {
    const id = String(p.id ?? "");
    const shopifyId = String(p.shopify_order_id ?? "");
    const key = shopifyId && shopifyId !== "0" ? shopifyId : id;

    return `
      <tr class="border-b">
        <td class="px-4 py-3 font-bold">${p.numero ?? ("#" + id)}</td>
        <td class="px-4 py-3">${p.created_at ?? "—"}</td>
        <td class="px-4 py-3">${p.cliente ?? "—"}</td>
        <td class="px-4 py-3">${p.total ?? "—"}</td>
        <td class="px-4 py-3">${p.estado_bd ?? "Diseñado"}</td>
        <td class="px-4 py-3">${p.estado_por ?? "—"}</td>
        <td class="px-4 py-3">${p.articulos ?? "—"}</td>
        <td class="px-4 py-3">${p.estado_envio ?? "—"}</td>
        <td class="px-4 py-3 text-right">
          <button class="px-3 py-2 rounded-xl bg-emerald-600 text-white font-extrabold text-xs"
                  onclick="marcarCargado('${key}')">
            Cargado
          </button>
        </td>
      </tr>
    `;
  }).join("");
}

async function cargarMiCola() {
  if (isLoading) return;
  isLoading = true;

  try {
    const { res, data, raw } = await apiGet(ENDPOINT_QUEUE);
    if (!res.ok || !data) {
      console.error("Queue FAIL:", res.status, raw);
      pedidosCache = [];
      pedidosFiltrados = [];
      renderListado([]);
      setTotalPedidos(0);
      return;
    }

    const extracted = extractOrdersPayload(data);
    if (!extracted.ok) {
      console.error("Queue payload inválido:", data);
      pedidosCache = [];
      pedidosFiltrados = [];
      renderListado([]);
      setTotalPedidos(0);
      return;
    }

    pedidosCache = extracted.orders;
    pedidosFiltrados = [...pedidosCache];
    renderListado(pedidosFiltrados);
    setTotalPedidos(pedidosFiltrados.length);

  } catch (e) {
    console.error("cargarMiCola error:", e);
    pedidosCache = [];
    pedidosFiltrados = [];
    renderListado([]);
    setTotalPedidos(0);
  } finally {
    isLoading = false;
  }
}

async function traerPedidos(count) {
  const { res, data, raw } = await apiPost(ENDPOINT_PULL, { count });

  if (!res.ok || !data) {
    console.error("PULL FAIL:", res.status, raw);
    alert("No se pudo traer pedidos (error de red o sesión).");
    return;
  }

  if (data.ok !== true && data.success !== true) {
    alert(data.error || data.message || "Error interno asignando pedidos");
    return;
  }

  await cargarMiCola();
}

async function devolverPedidosRestantes() {
  if (!confirm("¿Seguro que quieres devolver TODOS tus pedidos pendientes en Montaje?")) return;

  const { res, data, raw } = await apiPost(ENDPOINT_RETURN_ALL, {});
  if (!res.ok || !data) {
    console.error("RETURN ALL FAIL:", res.status, raw);
    alert("No se pudo devolver pedidos.");
    return;
  }
  if (data.ok !== true && data.success !== true) {
    alert(data.error || data.message || "No se pudo devolver pedidos.");
    return;
  }
  await cargarMiCola();
}

async function marcarCargado(orderId) {
  const { res, data } = await apiPost(ENDPOINT_CARGADO, { order_id: String(orderId) });

  if (!res.ok || !data || (data.ok !== true && data.success !== true)) {
    alert(data?.error || data?.message || "No se pudo marcar como cargado");
    return;
  }

  // ✅ desaparece de tu lista (ya está desasignado + estado Por producir)
  await cargarMiCola();
}

// binds (igual que confirmación)
document.addEventListener("DOMContentLoaded", () => {
  $("btnTraer5")?.addEventListener("click", () => traerPedidos(5));
  $("btnTraer10")?.addEventListener("click", () => traerPedidos(10));
  $("btnDevolver")?.addEventListener("click", () => devolverPedidosRestantes());
  $("btnActualizar")?.addEventListener("click", () => cargarMiCola());

  cargarMiCola();
});
