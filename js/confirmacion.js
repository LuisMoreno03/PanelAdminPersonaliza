/**
 * confirmacion.js — FINAL ADAPTADO
 * Módulo Confirmación (sin dashboard)
 */

/* =====================================================
  CSRF
===================================================== */
function getCsrfHeaders() {
  const token  = document.querySelector('meta[name="csrf-token"]')?.content;
  const header = document.querySelector('meta[name="csrf-header"]')?.content;
  return token && header ? { [header]: token } : {};
}

/* =====================================================
  CONFIG
===================================================== */
const API = window.API || {};
const ENDPOINT_QUEUE   = API.myQueue;
const ENDPOINT_PULL    = API.pull;
const ENDPOINT_RETURN  = API.returnAll;
const ENDPOINT_DETALLES = API.detalles;
const ENDPOINT_GUARDAR_ESTADO = "/confirmacion/guardar-estado";

let pedidosCache = [];
let loading = false;

/* =====================================================
  HELPERS
===================================================== */
const $ = (id) => document.getElementById(id);

function escapeHtml(str) {
  return String(str ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function esImagenUrl(url) {
  return /https?:\/\/.*\.(jpg|jpeg|png|gif|webp|svg)(\?.*)?$/i.test(String(url || ""));
}

function setLoader(show) {
  $("globalLoader")?.classList.toggle("hidden", !show);
}

/* =====================================================
  LISTADO
===================================================== */
function renderPedidos(pedidos) {
  const cont = $("tablaPedidos");
  cont.innerHTML = "";

  if (!pedidos.length) {
    cont.innerHTML = `<div class="p-8 text-center text-slate-500">No hay pedidos</div>`;
    $("total-pedidos").textContent = "0";
    return;
  }

  pedidos.forEach(p => {
    const row = document.createElement("div");
    row.className = "orders-grid cols px-4 py-3 border-b text-sm";

    row.innerHTML = `
      <div class="font-extrabold">${escapeHtml(p.numero)}</div>
      <div>${escapeHtml((p.created_at || "").slice(0,10))}</div>
      <div class="truncate">${escapeHtml(p.cliente)}</div>
      <div class="font-bold">${Number(p.total).toFixed(2)} €</div>

      <div>
        <span class="px-3 py-1 rounded-full text-xs font-extrabold bg-blue-600 text-white">
          POR PREPARAR
        </span>
      </div>

      <div>—</div>
      <div>—</div>

      <div class="text-center">${p.articulos || 1}</div>

      <div>
        <span class="px-3 py-1 rounded-full text-xs font-bold bg-slate-100">
          Pendiente
        </span>
      </div>

      <div class="text-xs">${escapeHtml(p.forma_envio || "-")}</div>

      <div class="text-right">
        <button
          onclick="verDetalles('${p.shopify_order_id}')"
          class="px-4 py-2 rounded-2xl bg-blue-600 text-white font-extrabold hover:bg-blue-700">
          VER DETALLES →
        </button>
      </div>
    `;
    cont.appendChild(row);
  });

  $("total-pedidos").textContent = pedidos.length;
}

/* =====================================================
  CARGAR COLA
===================================================== */
async function cargarMiCola() {
  if (loading) return;
  loading = true;
  setLoader(true);

  try {
    const r = await fetch(ENDPOINT_QUEUE, { credentials: "same-origin" });
    const d = await r.json();

    pedidosCache = d?.ok ? (d.data || []) : [];
    renderPedidos(pedidosCache);

  } catch (e) {
    console.error(e);
    renderPedidos([]);
  } finally {
    loading = false;
    setLoader(false);
  }
}

/* =====================================================
  ACCIONES
===================================================== */
async function traerPedidos(n) {
  setLoader(true);
  await fetch(ENDPOINT_PULL, {
    method: "POST",
    headers: { "Content-Type": "application/json", ...getCsrfHeaders() },
    body: JSON.stringify({ count: n }),
    credentials: "same-origin"
  });
  await cargarMiCola();
  setLoader(false);
}

async function devolverPedidos() {
  if (!confirm("¿Devolver todos los pedidos?")) return;
  setLoader(true);
  await fetch(ENDPOINT_RETURN, {
    method: "POST",
    headers: getCsrfHeaders(),
    credentials: "same-origin"
  });
  await cargarMiCola();
  setLoader(false);
}

/* =====================================================
  DETALLES
===================================================== */
window.verDetalles = async function (shopifyOrderId) {
  setLoader(true);

  try {
    const r = await fetch(`${ENDPOINT_DETALLES}/${shopifyOrderId}`, {
      headers: { Accept: "application/json" },
      credentials: "same-origin"
    });
    const d = await r.json();

    if (!d?.success) {
      alert(d?.message || "Error cargando detalles");
      return;
    }

    const items = d.order.line_items || [];
    const locales = d.imagenes_locales || {};

    window.imagenesRequeridas = [];
    window.imagenesCargadas = [];

    $("modalDetalles")?.classList.remove("hidden");
    $("detTitulo").textContent = `Pedido ${d.order.name}`;

    $("detProductos").innerHTML = items.map((it, i) => {
      const requiere = requiereImagenModificada(it);
      const local = locales[i] || "";

      window.imagenesRequeridas[i] = requiere;
      window.imagenesCargadas[i]  = !!local;

      return `
        <div class="border rounded-xl p-3">
          <div class="font-bold">${escapeHtml(it.title)}</div>

          ${local
            ? `<img src="${local}" class="mt-2 w-32 rounded-xl">`
            : requiere
              ? `<input type="file" accept="image/*"
                  onchange="subirImagenProducto('${shopifyOrderId}', ${i}, this)">`
              : `<div class="text-xs text-slate-500">No requiere imagen</div>`
          }
        </div>
      `;
    }).join("");

  } catch (e) {
    console.error(e);
    alert("Error de red");
  } finally {
    setLoader(false);
  }
};

function cerrarDetallesFull() {
  $("modalDetalles")?.classList.add("hidden");
}

/* =====================================================
  IMÁGENES
===================================================== */
function requiereImagenModificada(item) {
  const props = Array.isArray(item.properties) ? item.properties : [];
  if (props.some(p => esImagenUrl(p?.value))) return true;

  const title = String(item.title || "").toLowerCase();
  return title.includes("llavero");
}

window.subirImagenProducto = async function (shopifyOrderId, index, input) {
  const file = input.files?.[0];
  if (!file) return;

  const fd = new FormData();
  fd.append("shopify_order_id", shopifyOrderId);
  fd.append("line_index", index);
  fd.append("file", file);

  await fetch("/api/pedidos/imagenes/subir", {
    method: "POST",
    body: fd,
    headers: getCsrfHeaders(),
    credentials: "same-origin"
  });

  window.imagenesCargadas[index] = true;
  validarEstadoAuto(shopifyOrderId);
};

/* =====================================================
  AUTO ESTADO
===================================================== */
async function validarEstadoAuto(shopifyOrderId) {
  const req = window.imagenesRequeridas || [];
  const ok  = window.imagenesCargadas || [];

  const falta = req.some((r, i) => r && !ok[i]);
  const estado = falta ? "Faltan imágenes" : "Confirmado";

  const r = await fetch(ENDPOINT_GUARDAR_ESTADO, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      ...getCsrfHeaders()
    },
    credentials: "same-origin",
    body: JSON.stringify({
      shopify_order_id: shopifyOrderId,
      estado
    })
  });

  const d = await r.json();
  if (!r.ok || !d.success) {
    console.error("Error guardando estado:", d);
    return;
  }

  // ✅ Si queda confirmado → eliminar de la lista
  if (estado === "Confirmado") {
    pedidosCache = pedidosCache.filter(
      p => String(p.shopify_order_id) !== String(shopifyOrderId)
    );
    renderPedidos(pedidosCache);
    cerrarDetallesFull();
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
