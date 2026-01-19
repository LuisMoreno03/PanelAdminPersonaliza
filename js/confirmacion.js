/**
 * confirmacion.js — FINAL ABSOLUTO (FIXED)
 * - Sin errores null
 * - IDs correctos
 * - Subida imágenes por producto
 * - Llaveros siempre requieren imagen
 * - Estado automático real
 */

/* =====================================================
  CONFIG
===================================================== */
const API = window.API || {};
const ENDPOINT_QUEUE = API.myQueue;
const ENDPOINT_PULL = API.pull;
const ENDPOINT_RETURN_ALL = API.returnAll;
const ENDPOINT_DETALLES = API.detalles;

let pedidosCache = [];
let loading = false;

/* =====================================================
  HELPERS
===================================================== */
function $(id) { return document.getElementById(id); }

function escapeHtml(str) {
  return String(str ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function esImagenUrl(url) {
  if (!url) return false;
  return /https?:\/\/.*\.(jpg|jpeg|png|webp|gif|svg)(\?.*)?$/i.test(String(url));
}

function setLoader(show) {
  const el = $("globalLoader");
  if (el) el.classList.toggle("hidden", !show);
}

function setTextSafe(id, value) {
  const el = $(id);
  if (!el) return false;
  el.textContent = value ?? "";
  return true;
}

function setHtmlSafe(id, html) {
  const el = $(id);
  if (!el) return false;
  el.innerHTML = html ?? "";
  return true;
}

/* =====================================================
  CSRF
===================================================== */
function getCsrfHeaders() {
  const token  = document.querySelector('meta[name="csrf-token"]')?.content;
  const header = document.querySelector('meta[name="csrf-header"]')?.content;
  return token && header ? { [header]: token } : {};
}

/* =====================================================
  LISTADO
===================================================== */
function renderPedidos(pedidos) {
  const wrap = $("tablaPedidos");
  if (!wrap) return;

  wrap.innerHTML = "";

  if (!pedidos.length) {
    wrap.innerHTML = `<div class="p-8 text-center text-slate-500">No hay pedidos asignados</div>`;
    setTextSafe("total-pedidos", "0");
    return;
  }

  pedidos.forEach(p => {
    const row = document.createElement("div");
    row.className = "orders-grid cols px-4 py-3 border-b items-center";

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

      <div>${escapeHtml(p.estado_por || "—")}</div>
      <div>—</div>
      <div class="text-center">${p.articulos || 1}</div>

      <div>
        <span class="px-3 py-1 rounded-full text-xs font-bold bg-slate-100">
          Sin preparar
        </span>
      </div>

      <div class="truncate">${escapeHtml(p.forma_envio || "-")}</div>

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

  setTextSafe("total-pedidos", pedidos.length);
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

    if (!r.ok || d.ok !== true) {
      pedidosCache = [];
      renderPedidos([]);
      return;
    }

    pedidosCache = d.data || [];
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
  try {
    await fetch(ENDPOINT_PULL, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        ...getCsrfHeaders()
      },
      body: JSON.stringify({ count: n }),
      credentials: "same-origin"
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
      credentials: "same-origin"
    });
    await cargarMiCola();
  } finally {
    setLoader(false);
  }
}

/* =====================================================
  MODAL
===================================================== */
function abrirDetallesFull() {
  const modal = $("modalDetallesFull");
  if (!modal) return;
  modal.classList.remove("hidden");
  document.body.classList.add("overflow-hidden");
}

function cerrarModalDetalles() {
  const modal = $("modalDetallesFull");
  if (!modal) return;
  modal.classList.add("hidden");
  document.body.classList.remove("overflow-hidden");
}

/* =====================================================
  DETALLES
===================================================== */
window.verDetalles = async function (shopifyOrderId) {
  abrirDetallesFull();
  pintarCargandoDetalles();

  try {
    const r = await fetch(`${ENDPOINT_DETALLES}/${shopifyOrderId}`, {
      headers: { Accept: "application/json" },
      credentials: "same-origin"
    });

    const d = await r.json();
    if (!r.ok || !d.success) throw new Error(d.message || "Error");

    pintarDetallesPedido(d.order, d.imagenes_locales || {});

  } catch (e) {
    pintarErrorDetalles(e.message);
  }
};

function pintarCargandoDetalles() {
  setTextSafe("detTitulo", "Cargando pedido…");
  setHtmlSafe("detProductos", `<div class="text-slate-500">Cargando productos…</div>`);
  setHtmlSafe("detResumen", `<div class="text-slate-500">Cargando resumen…</div>`);
}

function pintarErrorDetalles(msg) {
  setHtmlSafe(
    "detProductos",
    `<div class="text-rose-600 font-extrabold">${escapeHtml(msg)}</div>`
  );
}

/* =====================================================
  REGLAS IMÁGENES
===================================================== */
function isLlaveroItem(item) {
  const t = String(item?.title || "").toLowerCase();
  const sku = String(item?.sku || "").toLowerCase();
  return t.includes("llavero") || sku.includes("llav");
}

function requiereImagenModificada(item) {
  const props = Array.isArray(item?.properties) ? item.properties : [];
  const tieneImagen = props.some(p => esImagenUrl(p?.value));
  return isLlaveroItem(item) || tieneImagen;
}

/* =====================================================
  PINTAR DETALLE
===================================================== */
function pintarDetallesPedido(order, imagenesLocales) {
  const items = order.line_items || [];

  window.imagenesRequeridas = [];
  window.imagenesCargadas = [];

  setTextSafe("detTitulo", `Pedido ${order.name}`);

  setHtmlSafe("detProductos", items.map((item, index) => {
    const requiere = requiereImagenModificada(item);
    const localImg = imagenesLocales[index] || "";

    window.imagenesRequeridas[index] = requiere;
    window.imagenesCargadas[index] = !!localImg;

    return `
      <div class="border rounded-2xl p-4 bg-white">
        <div class="font-extrabold">${escapeHtml(item.title)}</div>

        ${requiere ? `
          <input type="file" accept="image/*"
            onchange="subirImagenProducto('${order.id}', ${index}, this)"
            class="mt-3">
        ` : ""}

        ${localImg ? `
          <img src="${escapeHtml(localImg)}"
            class="mt-3 h-32 rounded-xl border">
        ` : ""}
      </div>
    `;
  }).join(""));

  actualizarResumenAuto(order.id);
}

/* =====================================================
  RESUMEN + AUTO ESTADO
===================================================== */
function actualizarResumenAuto(orderId) {
  const total = window.imagenesRequeridas.filter(Boolean).length;
  const ok = window.imagenesRequeridas.filter((v,i)=>v && window.imagenesCargadas[i]).length;
  const falta = total - ok;

  setHtmlSafe("detResumen", `
    <div class="font-extrabold">${ok} / ${total} imágenes cargadas</div>
    <div class="mt-2 font-bold ${falta ? "text-amber-600" : "text-emerald-600"}">
      ${falta ? `🟡 Faltan ${falta} imágenes` : "🟢 Todo listo"}
    </div>
  `);

  if (total > 0) {
    guardarEstadoAuto(orderId, falta === 0 ? "Confirmado" : "Faltan archivos");
  }
}

/* =====================================================
  SUBIR IMAGEN
===================================================== */
window.subirImagenProducto = async function (orderId, index, input) {
  const file = input.files[0];
  if (!file) return;

  const fd = new FormData();
  fd.append("order_id", orderId);
  fd.append("line_index", index);
  fd.append("file", file);

  const r = await fetch("/api/pedidos/imagenes/subir", {
    method: "POST",
    body: fd,
    headers: getCsrfHeaders(),
    credentials: "same-origin"
  });

  const d = await r.json();
  if (!r.ok || !d.url) {
    alert("Error subiendo imagen");
    return;
  }

  window.imagenesCargadas[index] = true;
  actualizarResumenAuto(orderId);
};

/* =====================================================
  AUTO ESTADO
===================================================== */
async function guardarEstadoAuto(orderId, estado) {
  await fetch("/api/estado/guardar", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      ...getCsrfHeaders()
    },
    body: JSON.stringify({ order_id: orderId, estado }),
    credentials: "same-origin"
  });

  if (estado === "Confirmado") {
    setTimeout(() => {
      cerrarModalDetalles();
      cargarMiCola();
    }, 600);
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
