/**
 * confirmacion.js — FINAL ESTABLE
 * - Subida imagen por producto
 * - Llaveros siempre requieren imagen
 * - Estado automático
 * - Pedido sale de Confirmación al completarse
 */

/* ===============================
   CONFIG
================================ */
const API = window.API || {};
const ENDPOINT_QUEUE   = API.myQueue;
const ENDPOINT_PULL    = API.pull;
const ENDPOINT_RETURN  = API.returnAll;
const ENDPOINT_DETALLES = API.detalles;

let pedidosCache = [];
let cargando = false;
let imagenesRequeridas = [];
let imagenesCargadas   = [];
let pedidoActualId = null;

/* ===============================
   HELPERS
================================ */
const $ = id => document.getElementById(id);

function escapeHtml(str) {
  return String(str ?? "")
    .replaceAll("&","&amp;")
    .replaceAll("<","&lt;")
    .replaceAll(">","&gt;")
    .replaceAll('"',"&quot;")
    .replaceAll("'","&#039;");
}

function esImagenUrl(v) {
  return /https?:\/\/.*\.(jpg|jpeg|png|webp|gif|svg)(\?.*)?$/i.test(String(v||""));
}

function getCsrfHeaders() {
  const t = document.querySelector('meta[name="csrf-token"]')?.content;
  const h = document.querySelector('meta[name="csrf-header"]')?.content;
  return t && h ? { [h]: t } : {};
}

function setLoader(show) {
  $("globalLoader")?.classList.toggle("hidden", !show);
}

/* ===============================
   LISTADO
================================ */
function renderPedidos(pedidos) {
  const wrap = $("tablaPedidos");
  wrap.innerHTML = "";

  if (!pedidos.length) {
    wrap.innerHTML = `<div class="p-6 text-center text-slate-500">No hay pedidos asignados</div>`;
    $("total-pedidos").textContent = "0";
    return;
  }

  pedidos.forEach(p => {
    const row = document.createElement("div");
    row.className = "orders-grid cols px-4 py-3 border-b";

    row.innerHTML = `
      <div class="font-extrabold">${escapeHtml(p.numero)}</div>
      <div>${escapeHtml((p.created_at||"").slice(0,10))}</div>
      <div class="truncate">${escapeHtml(p.cliente)}</div>
      <div class="font-bold">${Number(p.total).toFixed(2)} €</div>
      <div><span class="px-3 py-1 rounded-full bg-blue-600 text-white text-xs font-extrabold">POR PREPARAR</span></div>
      <div>${escapeHtml(p.estado_por || "—")}</div>
      <div>—</div>
      <div class="text-center">${p.articulos || 1}</div>
      <div><span class="px-3 py-1 rounded-full bg-slate-100 text-xs">Sin preparar</span></div>
      <div class="truncate">${escapeHtml(p.forma_envio || "-")}</div>
      <div class="text-right">
        <button onclick="verDetalles('${p.shopify_order_id}')"
          class="px-4 py-2 rounded-2xl bg-blue-600 text-white font-extrabold hover:bg-blue-700">
          VER DETALLES →
        </button>
      </div>
    `;
    wrap.appendChild(row);
  });

  $("total-pedidos").textContent = pedidos.length;
}

/* ===============================
   CARGAR COLA
================================ */
async function cargarMiCola() {
  if (cargando) return;
  cargando = true;
  setLoader(true);

  try {
    const r = await fetch(ENDPOINT_QUEUE, { credentials:"same-origin" });
    const d = await r.json();
    pedidosCache = (r.ok && d.ok) ? d.data : [];
    renderPedidos(pedidosCache);
  } catch {
    renderPedidos([]);
  } finally {
    cargando = false;
    setLoader(false);
  }
}

/* ===============================
   ACCIONES
================================ */
async function traerPedidos(n) {
  setLoader(true);
  await fetch(ENDPOINT_PULL, {
    method:"POST",
    headers:{ "Content-Type":"application/json", ...getCsrfHeaders() },
    body:JSON.stringify({ count:n }),
    credentials:"same-origin"
  });
  await cargarMiCola();
  setLoader(false);
}

async function devolverPedidos() {
  if (!confirm("¿Devolver todos los pedidos?")) return;
  setLoader(true);
  await fetch(ENDPOINT_RETURN, {
    method:"POST",
    headers:getCsrfHeaders(),
    credentials:"same-origin"
  });
  await cargarMiCola();
  setLoader(false);
}

/* ===============================
   DETALLES
================================ */
window.verDetalles = async function(orderId) {
  pedidoActualId = orderId;
  abrirModal();
  pintarCargando();

  try {
    const r = await fetch(`${ENDPOINT_DETALLES}/${orderId}`, { credentials:"same-origin" });
    const d = await r.json();
    if (!r.ok || !d.success) throw "Error";
    pintarDetalles(d.order, d.imagenes_locales||{});
  } catch {
    $("detProductos").innerHTML = `<div class="text-rose-600 font-bold">Error cargando pedido</div>`;
  }
};

function abrirModal() {
  const m = $("modalDetallesFull");
  m.classList.remove("hidden");
  document.body.classList.add("overflow-hidden");
}

function cerrarModal() {
  $("modalDetallesFull")?.classList.add("hidden");
  document.body.classList.remove("overflow-hidden");
}

function pintarCargando() {
  $("detTitulo").textContent = "Cargando pedido…";
  $("detProductos").innerHTML = `<div class="text-slate-500">Cargando productos…</div>`;
  $("detResumen").innerHTML = "";
}

/* ===============================
   REGLAS DE IMÁGENES
================================ */
function esLlavero(item) {
  return String(item.title||"").toLowerCase().includes("llavero");
}

function requiereImagen(item) {
  if (esLlavero(item)) return true;
  return (item.properties||[]).some(p => esImagenUrl(p.value));
}

/* ===============================
   PINTAR PRODUCTOS
================================ */
function pintarDetalles(order, imagenesLocales) {
  const items = order.line_items || [];

  imagenesRequeridas = [];
  imagenesCargadas   = [];

  $("detTitulo").textContent = `Pedido ${order.name}`;

  $("detProductos").innerHTML = items.map((item, i) => {
    const req = requiereImagen(item);
    const img = imagenesLocales[i] || "";

    imagenesRequeridas[i] = req;
    imagenesCargadas[i]   = !!img;

    return `
      <div class="border rounded-2xl p-4 bg-white">
        <div class="font-extrabold">${escapeHtml(item.title)}</div>
        <div class="text-sm mt-1">
          ${req ? (img ? "🟢 Imagen cargada" : "🟡 Falta imagen") : "—"}
        </div>

        ${req ? `
          <input type="file" accept="image/*" class="mt-3"
            onchange="subirImagen('${order.id}', ${i}, this)">
        ` : ""}

        ${img ? `<img src="${img}" class="mt-3 h-32 rounded-xl border">` : ""}
      </div>
    `;
  }).join("");

  actualizarResumen(order.id);
}

/* ===============================
   CONTADOR + ESTADO
================================ */
function actualizarResumen(orderId) {
  const total = imagenesRequeridas.filter(Boolean).length;
  const ok = imagenesRequeridas.filter((v,i)=>v && imagenesCargadas[i]).length;
  const falta = total - ok;

  $("detResumen").innerHTML = `
    <div class="font-extrabold">${ok} / ${total} imágenes cargadas</div>
    <div class="mt-2 text-sm font-bold ${falta ? "text-amber-600" : "text-emerald-600"}">
      ${falta ? `🟡 Faltan ${falta}` : "🟢 Todo listo"}
    </div>
  `;

  guardarEstado(orderId, falta === 0 && total ? "Confirmado" : "Faltan archivos");
}

/* ===============================
   SUBIR IMAGEN
================================ */
window.subirImagen = async function(orderId, index, input) {
  const f = input.files[0];
  if (!f) return;

  const fd = new FormData();
  fd.append("order_id", orderId);
  fd.append("line_index", index);
  fd.append("file", f);

  const r = await fetch("/api/pedidos/imagenes/subir", {
    method:"POST",
    body:fd,
    headers:getCsrfHeaders(),
    credentials:"same-origin"
  });

  const d = await r.json();
  if (!r.ok || !d.url) return alert("Error subiendo imagen");

  imagenesCargadas[index] = true;
  actualizarResumen(orderId);
};

/* ===============================
   GUARDAR ESTADO
================================ */
async function guardarEstado(orderId, estado) {
  await fetch("/api/estado/guardar", {
    method:"POST",
    headers:{ "Content-Type":"application/json", ...getCsrfHeaders() },
    body:JSON.stringify({ id: orderId, estado }),
    credentials:"same-origin"
  });

  if (estado === "Confirmado") {
    setTimeout(() => {
      cerrarModal();
      cargarMiCola();
    }, 600);
  }
}

/* ===============================
   INIT
================================ */
document.addEventListener("DOMContentLoaded", () => {
  $("btnTraer5")?.addEventListener("click",()=>traerPedidos(5));
  $("btnTraer10")?.addEventListener("click",()=>traerPedidos(10));
  $("btnDevolver")?.addEventListener("click",devolverPedidos);
  cargarMiCola();
});
