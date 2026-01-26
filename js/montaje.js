// public/js/montaje.js
const API_BASE = String(window.API_BASE || "").replace(/\/$/, "");

const ENDPOINT_QUEUE = `${API_BASE}/montaje/my-queue`;
const ENDPOINT_PULL  = `${API_BASE}/montaje/pull`;
const ENDPOINT_RETURN_ALL = `${API_BASE}/montaje/return-all`;

const ENDPOINT_REALIZADO = `${API_BASE}/montaje/realizado`; // recomendado
const ENDPOINT_CARGADO_FALLBACK = `${API_BASE}/montaje/cargado`; // compatibilidad
const ENDPOINT_ENVIAR = `${API_BASE}/montaje/enviar`; // ✅ nuevo

let pedidosCache = [];
let pedidosFiltrados = [];
let isLoading = false;

function $(id){ return document.getElementById(id); }

function setTotalPedidos(n) {
  const el = $("total-pedidos");
  if (el) el.textContent = String(n ?? 0);
}

function showLoader(on) {
  const el = $("globalLoader");
  if (!el) return;
  el.classList.toggle("hidden", !on);
}

function getCsrfHeaders() {
  const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content");
  const header = document.querySelector('meta[name="csrf-header"]')?.getAttribute("content");
  if (!token || !header) return {};
  return { [header]: token };
}

async function apiGet(url) {
  const res = await fetch(url, {
    method: "GET",
    headers: { Accept: "application/json" },
    credentials: "same-origin",
  });
  const text = await res.text();
  let data = null;
  try { data = JSON.parse(text); } catch {}
  return { res, data, raw: text };
}

async function apiPost(url, payload) {
  const res = await fetch(url, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      ...getCsrfHeaders(),
    },
    credentials: "same-origin",
    body: JSON.stringify(payload ?? {}),
  });

  const text = await res.text();
  let data = null;
  try { data = JSON.parse(text); } catch {}
  return { res, data, raw: text };
}

function extractOrdersPayload(payload) {
  if (!payload || typeof payload !== "object") return { ok:false, orders:[] };
  if (payload.ok === true) return { ok:true, orders: Array.isArray(payload.data) ? payload.data : [] };
  if (payload.success === true) return { ok:true, orders: Array.isArray(payload.orders) ? payload.orders : [] };
  return { ok:false, orders:[] };
}

function norm(s) {
  return String(s ?? "")
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "");
}

function pedidoKey(p) {
  const id = String(p?.id ?? "");
  const shopifyId = String(p?.shopify_order_id ?? "");
  return (shopifyId && shopifyId !== "0") ? shopifyId : id;
}

function escapeHtml(str) {
  return String(str ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

// =====================================================
// PARSEO DETALLES (imagenes/archivos) - tolerante
// =====================================================
function safeJsonParse(v) {
  if (v == null) return null;
  if (typeof v === "object") return v;
  const s = String(v).trim();
  if (!s) return null;
  try { return JSON.parse(s); } catch { return null; }
}

function isUrl(s) {
  const v = String(s ?? "").trim();
  return /^https?:\/\/\S+/i.test(v);
}

function extractUrlsDeep(any) {
  const out = [];
  const seen = new Set();

  const push = (u) => {
    const v = String(u ?? "").trim();
    if (!v || !isUrl(v)) return;
    if (seen.has(v)) return;
    seen.add(v);
    out.push(v);
  };

  const walk = (x) => {
    if (x == null) return;

    if (typeof x === "string") {
      // puede venir "url1, url2" o texto con urls
      const parts = x.split(/[\s,]+/).map(t => t.trim()).filter(Boolean);
      parts.forEach(push);
      return;
    }

    if (Array.isArray(x)) {
      x.forEach(walk);
      return;
    }

    if (typeof x === "object") {
      for (const k of Object.keys(x)) {
        const val = x[k];
        // claves comunes
        if (typeof val === "string" && isUrl(val)) push(val);
        walk(val);
      }
    }
  };

  walk(any);
  return out;
}

function splitImagesAndFiles(urls) {
  const imgs = [];
  const files = [];
  for (const u of (urls || [])) {
    const lower = u.toLowerCase();
    if (/\.(png|jpe?g|webp|gif)(\?.*)?$/.test(lower)) imgs.push(u);
    else files.push(u);
  }
  return { imgs, files };
}

function getPedidoAssets(p) {
  // 1) columnas extra probables
  const directFields = [
    p.imagenes, p.imagenes_json,
    p.archivos, p.archivos_json,
    p.archivo_diseno, p.archivos_diseno, p.diseno_url, p.diseno_urls,
    p.archivo_confirmacion, p.archivos_confirmacion, p.confirmacion_url, p.confirmacion_urls,
  ];

  const urls = [];

  // URLs directas / strings con urls
  directFields.forEach(v => urls.push(...extractUrlsDeep(v)));

  // 2) pedido_json en pedidos
  urls.push(...extractUrlsDeep(safeJsonParse(p.pedido_json)));

  // 3) pedido_json del último historial
  urls.push(...extractUrlsDeep(safeJsonParse(p.pedido_json_historial)));

  // 4) articulos (a veces trae urls)
  urls.push(...extractUrlsDeep(safeJsonParse(p.articulos) ?? p.articulos));

  const unique = [...new Set(urls)];
  const { imgs, files } = splitImagesAndFiles(unique);

  // heurística: si la url contiene "diseno" o "design" => diseño; si contiene "confirm" => confirmación
  const diseno = [];
  const confirm = [];
  const otherFiles = [];

  for (const f of files) {
    const l = f.toLowerCase();
    if (l.includes("diseno") || l.includes("diseño") || l.includes("design")) diseno.push(f);
    else if (l.includes("confirm") || l.includes("aprob")) confirm.push(f);
    else otherFiles.push(f);
  }

  return { imgs, diseno, confirm, files: otherFiles };
}

function assetsHtml(p) {
  const a = getPedidoAssets(p);
  if ((!a.imgs || !a.imgs.length) && (!a.diseno.length) && (!a.confirm.length) && (!a.files.length)) return "";

  const imgBlock = a.imgs?.length ? `
    <div class="mt-3">
      <div class="text-xs font-extrabold text-slate-700 mb-1">Imágenes</div>
      <div class="flex flex-wrap gap-2">
        ${a.imgs.slice(0, 8).map(u => `
          <a href="${escapeHtml(u)}" target="_blank" rel="noopener" class="block">
            <img src="${escapeHtml(u)}" alt="img" class="h-16 w-16 object-cover rounded-xl border border-slate-200" />
          </a>
        `).join("")}
      </div>
    </div>
  ` : "";

  const linksList = (title, arr) => {
    if (!arr || !arr.length) return "";
    return `
      <div class="mt-3">
        <div class="text-xs font-extrabold text-slate-700 mb-1">${escapeHtml(title)}</div>
        <div class="flex flex-col gap-1">
          ${arr.slice(0, 8).map(u => `
            <a href="${escapeHtml(u)}" target="_blank" rel="noopener"
               class="text-xs text-indigo-700 hover:underline break-all">
              ${escapeHtml(u)}
            </a>
          `).join("")}
        </div>
      </div>
    `;
  };

  return `
    <div class="mt-2">
      ${imgBlock}
      ${linksList("Archivos de diseño", a.diseno)}
      ${linksList("Archivos de confirmación", a.confirm)}
      ${linksList("Otros archivos", a.files)}
    </div>
  `;
}

// =====================================================
// RENDER
// =====================================================
function renderEmpty(target) {
  if (!target) return;
  target.innerHTML = `
    <div class="px-5 py-10 text-slate-500 text-sm">No hay pedidos en Diseñado.</div>
  `;
}

function tagsMiniHtml(etiquetas) {
  if (!etiquetas) return "";
  const parts = String(etiquetas).split(",").map(x => x.trim()).filter(Boolean).slice(0, 6);
  if (!parts.length) return "";
  return `
    <div class="tags-wrap-mini mt-1">
      ${parts.map(t => `<span class="tag-mini">${escapeHtml(t)}</span>`).join("")}
    </div>
  `;
}

function rowHtml(p) {
  const key = pedidoKey(p);

  const numero = escapeHtml(p.numero ?? ("#" + (p.id ?? "")));
  const fecha = escapeHtml(p.created_at ?? "—");
  const cliente = escapeHtml(p.cliente ?? "—");
  const total = escapeHtml(p.total ?? "—");
  const estado = escapeHtml(p.estado_bd ?? "Diseñado");
  const estadoActualizado = escapeHtml(p.estado_actualizado ?? "—");
  const estadoPor = escapeHtml(p.estado_por ?? "—");
  const items = escapeHtml(p.articulos ?? "—");
  const entrega = escapeHtml(p.estado_envio ?? "—");
  const metodo = escapeHtml(p.forma_envio ?? "—");

  return `
    <div data-order-id="${escapeHtml(key)}"
         class="prod-grid-cols px-4 py-3 border-b border-slate-100 hover:bg-slate-50 text-sm">
      <div class="font-extrabold text-slate-900 truncate">${numero}</div>
      <div class="text-slate-600 truncate">${fecha}</div>

      <div class="min-w-0">
        <div class="font-extrabold truncate">${cliente}</div>
        ${tagsMiniHtml(p.etiquetas)}
        ${assetsHtml(p)}
      </div>

      <div class="text-right font-extrabold">${total}</div>
      <div class="font-extrabold">${estado}</div>

      <div class="text-slate-600">
        <div class="font-extrabold text-slate-700 truncate">${estadoActualizado}</div>
        <div class="text-xs truncate">por ${estadoPor}</div>
      </div>

      <div class="text-center font-extrabold">${items}</div>
      <div class="text-slate-700 truncate">${entrega}</div>
      <div class="text-slate-700 truncate">${metodo}</div>

      <div class="text-right flex items-center justify-end gap-2">
        <button type="button"
          class="h-9 px-3 rounded-2xl bg-indigo-600 text-white font-extrabold text-xs hover:bg-indigo-700 transition"
          onclick="window.enviarPedido('${escapeHtml(key)}')">
          Enviar
        </button>

        <button type="button"
          class="h-9 px-3 rounded-2xl bg-emerald-600 text-white font-extrabold text-xs hover:bg-emerald-700 transition"
          onclick="window.marcarRealizado('${escapeHtml(key)}')">
          Realizado
        </button>
      </div>
    </div>
  `;
}

function cardHtml(p) {
  const key = pedidoKey(p);

  const numero = escapeHtml(p.numero ?? ("#" + (p.id ?? "")));
  const cliente = escapeHtml(p.cliente ?? "—");
  const total = escapeHtml(p.total ?? "—");
  const estado = escapeHtml(p.estado_bd ?? "Diseñado");
  const fecha = escapeHtml(p.created_at ?? "—");

  return `
    <div data-order-id="${escapeHtml(key)}"
         class="rounded-3xl border border-slate-200 bg-white shadow-sm p-4 mb-3">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <div class="text-sm font-extrabold text-slate-900 truncate">${numero}</div>
          <div class="text-xs text-slate-500 mt-1 truncate">${fecha}</div>

          <div class="text-sm text-slate-700 mt-2 truncate"><b>Cliente:</b> ${cliente}</div>
          <div class="text-sm text-slate-700 truncate"><b>Total:</b> ${total}</div>
          <div class="text-sm text-slate-700 truncate"><b>Estado:</b> ${estado}</div>

          ${p.etiquetas ? `<div class="mt-3">${tagsMiniHtml(p.etiquetas)}</div>` : ``}
          ${assetsHtml(p)}
        </div>

        <div class="flex flex-col gap-2 shrink-0">
          <button type="button"
            class="h-10 px-4 rounded-2xl bg-indigo-600 text-white font-extrabold text-xs hover:bg-indigo-700 transition"
            onclick="window.enviarPedido('${escapeHtml(key)}')">
            Enviar
          </button>

          <button type="button"
            class="h-10 px-4 rounded-2xl bg-emerald-600 text-white font-extrabold text-xs hover:bg-emerald-700 transition"
            onclick="window.marcarRealizado('${escapeHtml(key)}')">
            Realizado
          </button>
        </div>
      </div>
    </div>
  `;
}

function renderListado(pedidos) {
  const contXL = $("tablaPedidosTable");
  const cont2XL = $("tablaPedidos");
  const contMobile = $("cardsPedidos");

  if (!pedidos || !pedidos.length) {
    renderEmpty(contXL);
    renderEmpty(cont2XL);
    renderEmpty(contMobile);
    return;
  }

  const rows = pedidos.map(rowHtml).join("");
  if (contXL) contXL.innerHTML = rows;
  if (cont2XL) cont2XL.innerHTML = rows;

  if (contMobile) contMobile.innerHTML = pedidos.map(cardHtml).join("");
}

// =====================================================
// FILTRO
// =====================================================
function aplicarFiltro() {
  const q = norm($("inputBuscar")?.value || "");
  if (!q) {
    pedidosFiltrados = [...pedidosCache];
  } else {
    pedidosFiltrados = pedidosCache.filter(p => {
      const blob = norm([
        pedidoKey(p),
        p.numero,
        p.cliente,
        p.etiquetas,
        p.estado_bd,
        p.forma_envio,
        p.articulos,
        // incluir campos extra por si quieres buscar por url/archivos
        p.pedido_json,
        p.pedido_json_historial,
        p.imagenes, p.archivos, p.archivo_diseno, p.archivo_confirmacion,
      ].join(" "));
      return blob.includes(q);
    });
  }

  renderListado(pedidosFiltrados);
  setTotalPedidos(pedidosFiltrados.length);
}

// =====================================================
// LOAD
// =====================================================
async function cargarMiCola() {
  if (isLoading) return;
  isLoading = true;
  showLoader(true);

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

    pedidosCache = extracted.orders || [];
    aplicarFiltro();

  } catch (e) {
    console.error("cargarMiCola error:", e);
    pedidosCache = [];
    pedidosFiltrados = [];
    renderListado([]);
    setTotalPedidos(0);
  } finally {
    isLoading = false;
    showLoader(false);
  }
}

// =====================================================
// ACTIONS
// =====================================================
async function traerPedidos(count) {
  showLoader(true);
  try {
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
  } finally {
    showLoader(false);
  }
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

// Util: sacar de UI y cache (optimista)
function removePedidoFromUI(orderKey) {
  pedidosCache = pedidosCache.filter(p => String(pedidoKey(p)) !== String(orderKey));
  aplicarFiltro();
  document.querySelectorAll(`[data-order-id="${CSS.escape(String(orderKey))}"]`).forEach(n => n.remove());
}

// ✅ Enviar => Por preparar + desasigna + sale de la lista
async function enviarPedido(orderKey) {
  const before = [...pedidosCache];

  // UI optimista
  removePedidoFromUI(orderKey);

  const payload = { order_id: String(orderKey) };
  const resp = await apiPost(ENDPOINT_ENVIAR, payload);

  if (!resp.res.ok || !resp.data || (resp.data.ok !== true && resp.data.success !== true)) {
    // revertimos si falla
    pedidosCache = before;
    aplicarFiltro();
    alert(resp.data?.error || resp.data?.message || "No se pudo enviar el pedido");
    return;
  }

  // OK: backend ya lo dejó en Por preparar y desasignado
}

// ✅ Realizado => Por producir + desasigna + sale de la lista
async function marcarRealizado(orderKey) {
  const before = [...pedidosCache];

  // UI optimista
  removePedidoFromUI(orderKey);

  const payload = { order_id: String(orderKey) };

  // intenta /realizado; si no existe, usa /cargado
  let resp = await apiPost(ENDPOINT_REALIZADO, payload);
  if (resp.res.status === 404) {
    resp = await apiPost(ENDPOINT_CARGADO_FALLBACK, payload);
  }

  if (!resp.res.ok || !resp.data || (resp.data.ok !== true && resp.data.success !== true)) {
    // revertimos si falla
    pedidosCache = before;
    aplicarFiltro();
    alert(resp.data?.error || resp.data?.message || "No se pudo marcar como realizado");
    return;
  }

  // OK: backend ya lo dejó en Por producir y desasignado
}

// Exponer global para onclick
window.marcarRealizado = marcarRealizado;
window.enviarPedido = enviarPedido;

// Para tu botón "Actualizar" del HTML (onclick window.__montajeRefresh())
window.__montajeRefresh = cargarMiCola;

// =====================================================
// BINDS
// =====================================================
document.addEventListener("DOMContentLoaded", () => {
  $("btnTraer5")?.addEventListener("click", () => traerPedidos(5));
  $("btnTraer10")?.addEventListener("click", () => traerPedidos(10));

  $("inputBuscar")?.addEventListener("input", aplicarFiltro);
  $("btnLimpiarBusqueda")?.addEventListener("click", () => {
    if ($("inputBuscar")) $("inputBuscar").value = "";
    aplicarFiltro();
  });

  // si más adelante agregas botón devolver, ya quedaría listo:
  // $("btnDevolver")?.addEventListener("click", () => devolverPedidosRestantes());

  cargarMiCola();
});
