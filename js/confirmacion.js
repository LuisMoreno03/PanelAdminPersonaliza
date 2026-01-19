/**
 * confirmacion.js — COMPLETO
 * Módulo Confirmación
 * Independiente de dashboard.js
 */

/* =====================================================
  CONFIG
===================================================== */
const API = window.API || {};
const ENDPOINT_QUEUE = API.myQueue;
const ENDPOINT_PULL = API.pull;
const ENDPOINT_RETURN_ALL = API.returnAll;
const ENDPOINT_DETALLES = "/dashboard/detalles";

let pedidosCache = [];
let loading = false;

/* =====================================================
  HELPERS
===================================================== */
function $(id) {
  return document.getElementById(id);
}

function escapeHtml(str) {
  return String(str ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function esUrl(u) {
  return /^https?:\/\//i.test(String(u || "").trim());
}

function esImagenUrl(url) {
  if (!url) return false;
  return /https?:\/\/.*\.(jpeg|jpg|png|gif|webp|svg)(\?.*)?$/i.test(String(url));
}

function setLoader(show) {
  const el = $("globalLoader");
  if (!el) return;
  el.classList.toggle("hidden", !show);
}

/* =====================================================
  LISTADO PEDIDOS
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
    row.className = "orders-grid cols px-4 py-3 border-b items-center text-sm";

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
      headers: { "Content-Type": "application/json" },
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
      credentials: "same-origin"
    });
    await cargarMiCola();
  } finally {
    setLoader(false);
  }
}

/* =====================================================
  MODAL DETALLES (FULL - CLON DASHBOARD)
===================================================== */
window.verDetalles = async function (orderId) {
  const id = String(orderId || "");
  if (!id) return;

  const modal = $("modalEtiquetas");
  modal?.classList.remove("hidden");
  document.body.classList.add("overflow-hidden");

  const setHtml = (id, html) => $(id) && ($(id).innerHTML = html);
  const setText = (id, txt) => $(id) && ($(id).textContent = txt ?? "");

  setText("detTitle", "Cargando…");
  setText("detSubtitle", "—");
  setText("detItemsCount", "0");

  setHtml("detItems", `<div class="text-slate-500">Cargando productos…</div>`);
  setHtml("detResumen", `<div class="text-slate-500">Cargando…</div>`);
  setHtml("detCliente", `<div class="text-slate-500">Cargando…</div>`);
  setHtml("detEnvio", `<div class="text-slate-500">Cargando…</div>`);
  setHtml("detTotales", `<div class="text-slate-500">Cargando…</div>`);

  const pre = $("detJson");
  if (pre) pre.textContent = "";

  try {
    const r = await fetch(`${ENDPOINT_DETALLES}/${encodeURIComponent(id)}`, {
      headers: { Accept: "application/json" }
    });
    const d = await r.json();

    if (!r.ok || !d || d.success !== true) {
      setHtml("detItems", `<div class="text-rose-600 font-extrabold">Pedido sin información Shopify</div>`);
      if (pre) pre.textContent = JSON.stringify(d, null, 2);
      return;
    }

    if (pre) pre.textContent = JSON.stringify(d, null, 2);

    const o = d.order || {};
    const items = Array.isArray(o.line_items) ? o.line_items : [];

    setText("detTitle", `Pedido ${o.name || "#" + id}`);

    const clienteNombre = o.customer
      ? `${o.customer.first_name || ""} ${o.customer.last_name || ""}`.trim()
      : "";

    setText("detSubtitle", clienteNombre || o.email || "—");

    setHtml("detCliente", `
      <div class="space-y-2">
        <div class="font-extrabold">${escapeHtml(clienteNombre || "—")}</div>
        <div>Email: ${escapeHtml(o.email || "—")}</div>
        <div>Tel: ${escapeHtml(o.phone || "—")}</div>
      </div>
    `);

    const a = o.shipping_address || {};
    setHtml("detEnvio", `
      <div class="space-y-1">
        <div class="font-extrabold">${escapeHtml(a.name || "—")}</div>
        <div>${escapeHtml(a.address1 || "")}</div>
        <div>${escapeHtml(a.zip || "")} ${escapeHtml(a.city || "")}</div>
        <div>${escapeHtml(a.country || "")}</div>
      </div>
    `);

    setHtml("detTotales", `
      <div>
        <div>Subtotal: ${escapeHtml(o.subtotal_price)} €</div>
        <div>Envío: ${escapeHtml(o.total_shipping_price_set?.shop_money?.amount || "0")} €</div>
        <div class="font-extrabold text-lg">Total: ${escapeHtml(o.total_price)} €</div>
      </div>
    `);

    setText("detItemsCount", items.length);

    const productImages = d.product_images || {};
    const imagenesLocales = d.imagenes_locales || {};

    const html = items.map((item, index) => {
      const props = Array.isArray(item.properties) ? item.properties : [];
      const imgs = props.filter(p => esImagenUrl(p.value));
      const textos = props.filter(p => !esImagenUrl(p.value));

      const pid = item.product_id;
      const prodImg = productImages?.[pid] || "";

      return `
        <div class="rounded-3xl border bg-white shadow-sm p-4">
          <div class="flex gap-4">
            ${
              prodImg
                ? `<img src="${escapeHtml(prodImg)}" class="h-16 w-16 rounded-xl object-cover">`
                : `<div class="h-16 w-16 bg-slate-100 rounded-xl flex items-center justify-center">🧾</div>`
            }
            <div class="flex-1">
              <div class="font-extrabold">${escapeHtml(item.title)}</div>
              <div class="text-sm">Cant: ${item.quantity} · ${item.price} €</div>

              ${
                textos.length
                  ? `<div class="mt-2 text-sm">
                      ${textos.map(p => `<div><b>${escapeHtml(p.name)}:</b> ${escapeHtml(p.value)}</div>`).join("")}
                    </div>`
                  : ""
              }

              ${
                imgs.length
                  ? `<div class="mt-3 flex gap-2">
                      ${imgs.map(p => `<img src="${escapeHtml(p.value)}" class="h-24 rounded-xl">`).join("")}
                    </div>`
                  : ""
              }

              ${
                imagenesLocales[index]
                  ? `<div class="mt-3">
                      <div class="text-xs font-bold">Imagen modificada</div>
                      <img src="${escapeHtml(imagenesLocales[index])}" class="h-32 rounded-xl">
                    </div>`
                  : ""
              }
            </div>
          </div>
        </div>
      `;
    }).join("");

    setHtml("detItems", html);

  } catch (e) {
    console.error(e);
    setHtml("detItems", `<div class="text-rose-600 font-extrabold">Error cargando detalles</div>`);
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
