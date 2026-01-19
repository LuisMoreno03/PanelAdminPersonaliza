// =====================================================
// CONFIRMACION.JS
// - Cola personal por usuario
// - Pedidos Por preparar
// - Express primero
// - Auto estado por imágenes
// =====================================================

let isLoading = false;
let ordersCache = [];
let ordersById = new Map();

// protección para no sobreescribir cambios recientes
const dirtyOrders = new Map();
const DIRTY_TTL_MS = 15000;

// =====================================================
// HELPERS
// =====================================================
function jsonHeaders() {
  const h = { Accept: "application/json", "Content-Type": "application/json" };
  const t = document.querySelector('meta[name="csrf-token"]')?.content;
  const k = document.querySelector('meta[name="csrf-header"]')?.content || "X-CSRF-TOKEN";
  if (t) h[k] = t;
  return h;
}

function showLoader() {
  document.getElementById("globalLoader")?.classList.remove("hidden");
}
function hideLoader() {
  document.getElementById("globalLoader")?.classList.add("hidden");
}

function escapeHtml(s) {
  return String(s ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");
}

// =====================================================
// INIT
// =====================================================
document.addEventListener("DOMContentLoaded", () => {
  document.getElementById("btnPull")?.addEventListener("click", pullPedidos);
  cargarMiCola();
});

// =====================================================
// FETCH: MI COLA (ASIGNADOS)
// =====================================================
async function cargarMiCola() {
  if (isLoading) return;
  isLoading = true;
  showLoader();

  try {
    const limit = document.getElementById("limitSelect")?.value || 10;
    const r = await fetch(`${window.API_CONFIRMACION.myQueue}?limit=${limit}`, {
      headers: jsonHeaders(),
      credentials: "same-origin",
    });

    const d = await r.json().catch(() => null);
    if (!d || !d.success) {
      renderLista([]);
      return;
    }

    let incoming = Array.isArray(d.orders) ? d.orders : [];

    // dirty protection
    const now = Date.now();
    incoming = incoming.map(o => {
      const dirty = dirtyOrders.get(String(o.id));
      if (dirty && dirty.until > now) {
        return { ...o, estado: dirty.estado, last_status_change: dirty.last_status_change };
      }
      if (dirty) dirtyOrders.delete(String(o.id));
      return o;
    });

    ordersCache = incoming;
    ordersById = new Map(incoming.map(o => [String(o.id), o]));

    renderLista(ordersCache);
  } catch (e) {
    console.error("confirmacion cargar cola error:", e);
    renderLista([]);
  } finally {
    hideLoader();
    isLoading = false;
  }
}

// =====================================================
// PULL DESDE SHOPIFY
// =====================================================
async function pullPedidos() {
  if (isLoading) return;
  isLoading = true;
  showLoader();

  try {
    const limit = document.getElementById("limitSelect")?.value || 10;

    const r = await fetch(window.API_CONFIRMACION.pull, {
      method: "POST",
      headers: jsonHeaders(),
      credentials: "same-origin",
      body: JSON.stringify({ limit }),
    });

    const d = await r.json().catch(() => null);
    if (!d || !d.success) {
      alert(d?.message || "Error trayendo pedidos");
      return;
    }

    await cargarMiCola();
  } catch (e) {
    console.error("pullPedidos error:", e);
    alert("Error interno haciendo pull");
  } finally {
    hideLoader();
    isLoading = false;
  }
}

// =====================================================
// RENDER
// =====================================================
function renderLista(pedidos) {
  const cont = document.getElementById("confirmacionList");
  const empty = document.getElementById("confirmacionEmpty");

  if (!cont) return;
  cont.innerHTML = "";

  if (!pedidos.length) {
    empty?.classList.remove("hidden");
    return;
  }
  empty?.classList.add("hidden");

  cont.innerHTML = pedidos.map(p => `
    <div class="orders-grid cols px-4 py-3 text-sm hover:bg-slate-50">
      <div class="font-extrabold">${escapeHtml(p.numero)}</div>
      <div>${escapeHtml(p.fecha)}</div>
      <div class="truncate font-semibold">${escapeHtml(p.cliente)}</div>
      <div class="font-extrabold">${escapeHtml(p.total)}</div>

      <div>
        <button onclick="abrirModal('${p.id}')">
          ${renderEstadoPill(p.estado)}
        </button>
      </div>

      <div class="text-right">
        <button onclick="verDetalles('${p.id}')"
          class="px-3 py-2 rounded-xl bg-blue-600 text-white text-xs font-extrabold hover:bg-blue-700">
          Ver →
        </button>
      </div>
    </div>
  `).join("");
}

// =====================================================
// ESTADO (pill reutilizada del dashboard)
// =====================================================
function renderEstadoPill(estado) {
  const s = String(estado || "").toLowerCase();
  if (s.includes("confirmado")) return pill("Confirmado", "bg-fuchsia-600");
  if (s.includes("faltan")) return pill("Faltan archivos", "bg-yellow-400 text-black");
  if (s.includes("por preparar")) return pill("Por preparar", "bg-slate-900");
  return pill(estado || "—", "bg-slate-600");
}

function pill(txt, cls) {
  return `
    <span class="inline-flex px-3 py-1.5 rounded-xl text-xs font-extrabold text-white ${cls}">
      ${escapeHtml(txt)}
    </span>
  `;
}

// =====================================================
// GUARDAR ESTADO (OPTIMISTA)
// =====================================================
window.guardarEstado = async function (nuevoEstado) {
  const id = document.getElementById("modalOrderId")?.value;
  if (!id) return;

  const order = ordersById.get(String(id));
  if (!order) return;

  const user = window.CURRENT_USER || "Sistema";
  const nowStr = new Date().toISOString().slice(0, 19).replace("T", " ");

  order.estado = nuevoEstado;
  order.last_status_change = { user_name: user, changed_at: nowStr };
  renderLista(ordersCache);

  dirtyOrders.set(String(id), {
    until: Date.now() + DIRTY_TTL_MS,
    estado: nuevoEstado,
    last_status_change: order.last_status_change,
  });

  try {
    const r = await fetch("/dashboard/guardar-estado", {
      method: "POST",
      headers: jsonHeaders(),
      credentials: "same-origin",
      body: JSON.stringify({ order_id: id, estado: nuevoEstado }),
    });

    const d = await r.json().catch(() => null);
    if (!d?.success) throw new Error(d?.message);
  } catch (e) {
    alert("Error guardando estado");
    console.error(e);
  }
};

// =====================================================
// AUTO-ESTADO DESDE DETALLES (REUTILIZA TU LÓGICA)
// =====================================================
window.validarEstadoAuto = async function (orderId) {
  const oid = String(orderId);
  const req = window.imagenesRequeridas || [];
  const ok = window.imagenesCargadas || [];

  const indices = req.map((v, i) => v ? i : -1).filter(i => i >= 0);
  if (!indices.length) return;

  const faltan = indices.some(i => !ok[i]);
  const nuevo = faltan ? "Faltan archivos" : "Confirmado";

  const order = ordersById.get(oid);
  if (!order || order.estado === nuevo) return;

  document.getElementById("modalOrderId").value = oid;
  await window.guardarEstado(nuevo);
};

// =====================================================
// MODAL ESTADO
// =====================================================
window.abrirModal = function (id) {
  document.getElementById("modalOrderId").value = id;
  document.getElementById("modalEstado")?.classList.remove("hidden");
};
window.cerrarModal = function () {
  document.getElementById("modalEstado")?.classList.add("hidden");
};
