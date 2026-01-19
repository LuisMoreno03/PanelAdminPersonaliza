/**
 * confirmacion.js — PULL DESDE TABLA pedidos (ESTABLE)
 * - Listado: viene de pedidos + join estado
 * - Pull: asigna desde pedidos
 * - Detalles: endpoint detalles (puede venir de Shopify o de pedido_json si existe)
 */

const API = window.API || {};
const ENDPOINT_QUEUE = API.myQueue;
const ENDPOINT_PULL = API.pull;
const ENDPOINT_RETURN_ALL = API.returnAll;
const ENDPOINT_DETALLES = API.detalles;

let pedidosCache = [];
let loading = false;

const $ = (id) => document.getElementById(id);

function setLoader(v) {
  $("globalLoader")?.classList.toggle("hidden", !v);
}

function escapeHtml(str) {
  return String(str ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function setTextSafe(id, v) {
  if ($(id)) $(id).textContent = v ?? "";
}
function setHtmlSafe(id, v) {
  if ($(id)) $(id).innerHTML = v ?? "";
}

function getCsrfHeaders() {
  const t = document.querySelector('meta[name="csrf-token"]')?.content;
  const h = document.querySelector('meta[name="csrf-header"]')?.content || "X-CSRF-TOKEN";
  return t ? { [h]: t } : {};
}

/* =====================================================
   LISTADO (desde tabla pedidos)
===================================================== */
function renderPedidos(pedidos) {
  const wrap = $("tablaPedidos");
  if (!wrap) return;

  wrap.innerHTML = "";

  if (!Array.isArray(pedidos) || pedidos.length === 0) {
    wrap.innerHTML = `<div class="p-8 text-center text-slate-500">No hay pedidos</div>`;
    setTextSafe("total-pedidos", 0);
    return;
  }

  pedidos.forEach((p) => {
    const row = document.createElement("div");
    row.className = "orders-grid cols px-4 py-3 border-b items-center";

    const numero = p.numero || p.name || ("#" + (p.order_number || p.id || ""));
    const fecha = (p.created_at || p.fecha || "").slice(0, 10);
    const cliente = p.cliente || p.customer_name || p.email || "—";
    const total = Number(p.total || p.total_price || 0);

    const estado = String(p.estado || "Por preparar");
    const estadoPill =
      estado.toLowerCase().includes("faltan")
        ? `<span class="px-3 py-1 text-xs rounded-full bg-amber-500 text-white font-extrabold">FALTAN ARCHIVOS</span>`
        : estado.toLowerCase().includes("confirm")
        ? `<span class="px-3 py-1 text-xs rounded-full bg-emerald-600 text-white font-extrabold">CONFIRMADO</span>`
        : `<span class="px-3 py-1 text-xs rounded-full bg-blue-600 text-white font-extrabold">POR PREPARAR</span>`;

    const envio = String(p.estado_envio || "");
    const envioPill =
      !envio
        ? `<span class="px-3 py-1 text-xs rounded-full bg-slate-100">Unfulfilled</span>`
        : envio.toLowerCase().includes("fulfilled")
        ? `<span class="px-3 py-1 text-xs rounded-full bg-emerald-100 border border-emerald-200 text-emerald-900 font-extrabold">Fulfilled</span>`
        : `<span class="px-3 py-1 text-xs rounded-full bg-slate-100">Unfulfilled</span>`;

    const orderKey = p.shopify_order_id || p.id;

    row.innerHTML = `
      <div class="font-extrabold">${escapeHtml(numero)}</div>
      <div>${escapeHtml(fecha || "—")}</div>
      <div class="truncate">${escapeHtml(cliente)}</div>
      <div class="font-bold">${total.toFixed(2)} €</div>
      <div>${estadoPill}</div>
      <div>${escapeHtml(p.estado_por || "—")}</div>
      <div>—</div>
      <div class="text-center">${escapeHtml(p.articulos || "—")}</div>
      <div>${envioPill}</div>
      <div class="truncate">${escapeHtml(p.forma_envio || "-")}</div>
      <div class="text-right">
        <button type="button" onclick="verDetalles('${escapeHtml(orderKey)}')"
          class="px-3 py-2 rounded-2xl bg-blue-600 text-white text-[11px] font-extrabold uppercase tracking-wide hover:bg-blue-700 transition">
          Ver detalles →
        </button>
      </div>
    `;

    wrap.appendChild(row);
  });

  setTextSafe("total-pedidos", pedidos.length);
}

/* =====================================================
   CARGAR MI COLA
===================================================== */
async function cargarMiCola() {
  if (loading) return;
  loading = true;
  setLoader(true);

  try {
    const r = await fetch(ENDPOINT_QUEUE, { credentials: "same-origin" });
    const d = await r.json().catch(() => null);

    if (!r.ok || d?.ok !== true) {
      console.error("my-queue error:", d);
      pedidosCache = [];
      renderPedidos([]);
      return;
    }

    pedidosCache = Array.isArray(d.data) ? d.data : [];
    renderPedidos(pedidosCache);
  } catch (e) {
    console.error("cargarMiCola error:", e);
    pedidosCache = [];
    renderPedidos([]);
  } finally {
    loading = false;
    setLoader(false);
  }
}

/* =====================================================
   PULL (desde tabla pedidos)
===================================================== */
async function traerPedidos(n) {
  setLoader(true);
  try {
    const r = await fetch(ENDPOINT_PULL, {
      method: "POST",
      headers: { "Content-Type": "application/json", ...getCsrfHeaders() },
      body: JSON.stringify({ count: n }),
      credentials: "same-origin",
    });

    const d = await r.json().catch(() => null);
    if (!r.ok || d?.ok !== true) {
      console.error("pull error:", d);
      alert("Error trayendo pedidos: " + (d?.message || "Error"));
      return;
    }

    await cargarMiCola();

    // Si asignó pero cola quedó vacía -> aviso claro
    if (Number(d.assigned || 0) > 0 && (pedidosCache?.length || 0) === 0) {
      alert(
        "Pull asignó pedidos (" +
          d.assigned +
          "), pero tu cola está vacía.\n" +
          "Esto indica que my-queue está filtrando de más (estado_envio/estado). Revisa el response de my-queue."
      );
    }
  } catch (e) {
    console.error("pull exception:", e);
    alert("Error en pull (console).");
  } finally {
    setLoader(false);
  }
}

/* =====================================================
   DEVOLVER
===================================================== */
async function devolverPedidos() {
  if (!confirm("¿Devolver todos los pedidos?")) return;

  setLoader(true);
  try {
    await fetch(ENDPOINT_RETURN_ALL, {
      method: "POST",
      headers: { ...getCsrfHeaders() },
      credentials: "same-origin",
    });
    await cargarMiCola();
  } catch (e) {
    console.error("return-all error:", e);
  } finally {
    setLoader(false);
  }
}

/* =====================================================
   MODAL DETALLES
===================================================== */
function abrirDetallesFull() {
  $("modalDetallesFull")?.classList.remove("hidden");
  document.documentElement.classList.add("overflow-hidden");
  document.body.classList.add("overflow-hidden");
}
function cerrarModalDetalles() {
  $("modalDetallesFull")?.classList.add("hidden");
  document.documentElement.classList.remove("overflow-hidden");
  document.body.classList.remove("overflow-hidden");
}
window.cerrarModalDetalles = cerrarModalDetalles;

/* =====================================================
   DETALLES (lo dejas como ya lo venías usando)
===================================================== */
window.verDetalles = async function (orderId) {
  abrirDetallesFull();
  setTextSafe("detTitulo", "Cargando pedido…");
  setHtmlSafe("detProductos", `<div class="p-6 text-slate-500">Cargando…</div>`);
  setHtmlSafe("detResumen", "");

  try {
    const r = await fetch(`${ENDPOINT_DETALLES}/${encodeURIComponent(orderId)}`, {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    });

    const d = await r.json().catch(() => null);
    if (!r.ok || !d?.success) throw new Error(d?.message || "No success");

    // Si tu detalles devuelve line_items, aquí renderizas como antes.
    // Si no devuelve, al menos muestra algo:
    const order = d.order || {};
    const titulo = order?.name || order?.numero || ("#" + (order?.id || orderId));
    setTextSafe("detTitulo", `Pedido ${titulo}`);

    // si no hay line_items (porque no hay pedido_json ni Shopify fetch)
    const items = Array.isArray(order?.line_items) ? order.line_items : [];
    if (!items.length) {
      setHtmlSafe(
        "detProductos",
        `<div class="p-6 text-amber-600 font-extrabold">
           Este pedido no trae productos en el endpoint de detalles.
           Si no tienes pedido_json guardado, activa el fetch a Shopify en el controller.
         </div>`
      );
      return;
    }

    // Aquí puedes pegar tu renderDetalles completo actual si ya te funciona.
    // Para no romperte, lo mínimo:
    setHtmlSafe(
      "detProductos",
      `<pre class="p-4 text-xs bg-slate-50 border rounded-2xl overflow-auto">${escapeHtml(
        JSON.stringify(d, null, 2)
      )}</pre>`
    );
  } catch (e) {
    console.error("verDetalles error:", e);
    setHtmlSafe("detProductos", `<div class="p-6 text-rose-600 font-extrabold">Error cargando detalles.</div>`);
  }
};

/* =====================================================
   INIT
===================================================== */
document.addEventListener("DOMContentLoaded", () => {
  $("btnTraer5")?.addEventListener("click", () => traerPedidos(5));
  $("btnTraer10")?.addEventListener("click", () => traerPedidos(10));
  $("btnDevolver")?.addEventListener("click", devolverPedidos);
  cargarMiCola();
});
