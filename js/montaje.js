// public/js/montaje.js
const API_BASE = String(window.API_BASE || "").replace(/\/$/, "");

const ENDPOINT_QUEUE = `${API_BASE}/montaje/my-queue`;
const ENDPOINT_PULL  = `${API_BASE}/montaje/pull`;
const ENDPOINT_RETURN_ALL = `${API_BASE}/montaje/return-all`;

const ENDPOINT_REALIZADO = `${API_BASE}/montaje/realizado`;
const ENDPOINT_CARGADO_FALLBACK = `${API_BASE}/montaje/cargado`;
const ENDPOINT_ENVIAR = `${API_BASE}/montaje/enviar`;
const ENDPOINT_DETAILS = `${API_BASE}/montaje/details`; // ✅ nuevo

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
      // si es json string, intenta parsear primero
      const asJson = safeJsonParse(x);
      if (asJson && typeof asJson === "object") {
        walk(asJson);
        return;
      }

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

function classifyFiles(files) {
  const diseno = [];
  const confirm = [];
  const otherFiles = [];

  for (const f of (files || [])) {
    const l = f.toLowerCase();
    // ✅ tu caso real: /uploads/confirmacion/...
    if (l.includes("/confirmacion/") || l.includes("confirm") || l.includes("aprob")) confirm.push(f);
    else if (l.includes("/diseno/") || l.includes("/disenio/") || l.includes("diseno") || l.includes("diseño") || l.includes("design")) diseno.push(f);
    else otherFiles.push(f);
  }

  return { diseno, confirm, otherFiles };
}

/**
 * ✅ Extrae assets desde:
 * - pedido (incluye imagenes_locales)
 * - historial completo (pedido_json de cada etapa)
 */
function getPedidoAssetsFrom(pedido, historial = []) {
  const urls = [];

  // 1) campos directos del pedido (incluye imagenes_locales ✅)
  const directFields = [
    pedido?.imagenes, pedido?.imagenes_json,
    pedido?.archivos, pedido?.archivos_json,

    pedido?.archivo_diseno, pedido?.archivos_diseno, pedido?.diseno_url, pedido?.diseno_urls,
    pedido?.archivo_confirmacion, pedido?.archivos_confirmacion, pedido?.confirmacion_url, pedido?.confirmacion_urls,

    // ✅ tu campo real
    pedido?.imagenes_locales,

    // opcional (si lo usas)
    pedido?.product_images,
    pedido?.product_image,
  ];

  directFields.forEach(v => urls.push(...extractUrlsDeep(v)));

  // 2) pedido_json del pedido
  urls.push(...extractUrlsDeep(pedido?.pedido_json));

  // 3) pedido_json de TODO el historial
  for (const h of (historial || [])) {
    urls.push(...extractUrlsDeep(h?.pedido_json));
  }

  const unique = [...new Set(urls)];
  const { imgs, files } = splitImagesAndFiles(unique);
  const { diseno, confirm, otherFiles } = classifyFiles(files);

  return { imgs, diseno, confirm, files: otherFiles };
}

function filenameFromUrl(u) {
  try {
    const url = new URL(u);
    const path = url.pathname || "";
    const name = path.split("/").filter(Boolean).pop() || "archivo";
    return decodeURIComponent(name);
  } catch {
    const parts = String(u).split("/").filter(Boolean);
    return parts[parts.length - 1] || "archivo";
  }
}

// =====================================================
// MODAL DETALLES
// =====================================================
function ensureDetailsModal() {
  if (document.getElementById("pedidoDetailsModal")) return;

  const modal = document.createElement("div");
  modal.id = "pedidoDetailsModal";
  modal.className = "hidden fixed inset-0 z-[9998] bg-black/40 backdrop-blur-[1px]";

  modal.innerHTML = `
    <div class="absolute inset-0 flex items-center justify-center p-4">
      <div class="w-full max-w-3xl rounded-3xl bg-white shadow-xl border border-slate-200 overflow-hidden">
        <div class="flex items-center justify-between px-5 py-4 bg-slate-50 border-b border-slate-200">
          <div class="min-w-0">
            <div id="pedidoDetailsTitle" class="text-sm font-extrabold text-slate-900 truncate">Detalles del pedido</div>
            <div id="pedidoDetailsSub" class="text-xs text-slate-600 truncate"></div>
          </div>
          <button type="button" id="pedidoDetailsClose"
            class="h-9 px-3 rounded-2xl bg-white border border-slate-200 text-slate-900 font-extrabold text-xs hover:bg-slate-100 transition">
            Cerrar
          </button>
        </div>

        <div id="pedidoDetailsBody" class="p-5 max-h-[75vh] overflow-auto"></div>

        <div class="px-5 py-4 border-t border-slate-200 bg-white flex items-center justify-end gap-2">
          <button type="button" id="pedidoDetailsEnviarBtn"
            class="h-10 px-4 rounded-2xl bg-indigo-600 text-white font-extrabold text-xs hover:bg-indigo-700 transition">
            Enviar
          </button>
          <button type="button" id="pedidoDetailsRealizadoBtn"
            class="h-10 px-4 rounded-2xl bg-emerald-600 text-white font-extrabold text-xs hover:bg-emerald-700 transition">
            Realizado
          </button>
        </div>
      </div>
    </div>
  `;

  document.body.appendChild(modal);

  const close = () => {
    modal.classList.add("hidden");
    modal.dataset.orderKey = "";
  };

  modal.addEventListener("click", (e) => {
    if (e.target === modal) close();
  });

  document.getElementById("pedidoDetailsClose")?.addEventListener("click", close);

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && !modal.classList.contains("hidden")) close();
  });

  document.getElementById("pedidoDetailsEnviarBtn")?.addEventListener("click", () => {
    const k = modal.dataset.orderKey;
    if (!k) return;
    window.enviarPedido?.(k);
    close();
  });

  document.getElementById("pedidoDetailsRealizadoBtn")?.addEventListener("click", () => {
    const k = modal.dataset.orderKey;
    if (!k) return;
    window.marcarRealizado?.(k);
    close();
  });
}

function buildHistorialHtml(historial = []) {
  if (!historial.length) {
    return `<div class="text-sm text-slate-500">—</div>`;
  }

  return `
    <div class="space-y-2">
      ${historial.map(h => {
        const estado = escapeHtml(h?.estado ?? "—");
        const user = escapeHtml(h?.user_name ?? "—");
        const at = escapeHtml(h?.created_at ?? "—");
        return `
          <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
            <div class="text-xs font-extrabold text-slate-800">${estado}</div>
            <div class="text-[11px] text-slate-600 mt-1">${at} · ${user}</div>
          </div>
        `;
      }).join("")}
    </div>
  `;
}

function buildDetailsHtml(pedido, historial) {
  const numero = escapeHtml(pedido?.numero ?? ("#" + (pedido?.id ?? "")));
  const key = escapeHtml(pedidoKey(pedido));
  const cliente = escapeHtml(pedido?.cliente ?? "—");
  const fecha = escapeHtml(pedido?.created_at ?? "—");
  const total = escapeHtml(pedido?.total ?? "—");
  const estado = escapeHtml(pedido?.estado_bd ?? pedido?.estado ?? "—");
  const entrega = escapeHtml(pedido?.estado_envio ?? "—");
  const metodo = escapeHtml(pedido?.forma_envio ?? "—");
  const etiquetas = escapeHtml(pedido?.etiquetas ?? "");

  const assets = getPedidoAssetsFrom(pedido, historial);

  const imgs = assets.imgs || [];
  const diseno = assets.diseno || [];
  const confirm = assets.confirm || [];
  const files = assets.files || [];

  const listLinks = (arr) => {
    if (!arr.length) return `<div class="text-sm text-slate-500">—</div>`;
    return `
      <div class="flex flex-col gap-2">
        ${arr.map(u => `
          <a href="${escapeHtml(u)}" target="_blank" rel="noopener"
             class="px-3 py-2 rounded-2xl border border-slate-200 bg-slate-50 text-xs font-extrabold text-indigo-700 hover:bg-slate-100 break-all">
            ${escapeHtml(filenameFromUrl(u))}
          </a>
        `).join("")}
      </div>
    `;
  };

  const imgsHtml = imgs.length ? `
    <div class="flex flex-wrap gap-2">
      ${imgs.map(u => `
        <a href="${escapeHtml(u)}" target="_blank" rel="noopener" class="block">
          <img src="${escapeHtml(u)}" class="h-24 w-24 object-cover rounded-2xl border border-slate-200" alt="img" />
        </a>
      `).join("")}
    </div>
  ` : `<div class="text-sm text-slate-500">—</div>`;

  return `
    <div class="space-y-4">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
          <div class="text-xs text-slate-500 font-extrabold uppercase">Pedido</div>
          <div class="text-sm font-extrabold text-slate-900 mt-1">${numero}</div>
          <div class="text-xs text-slate-600 mt-1">ID: ${key}</div>
          <div class="text-sm text-slate-700 mt-2"><b>Cliente:</b> ${cliente}</div>
          <div class="text-sm text-slate-700"><b>Fecha:</b> ${fecha}</div>
          <div class="text-sm text-slate-700"><b>Total:</b> ${total}</div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-4">
          <div class="text-xs text-slate-500 font-extrabold uppercase">Estado</div>
          <div class="text-sm font-extrabold text-slate-900 mt-1">${estado}</div>
          <div class="text-sm text-slate-700 mt-2"><b>Entrega:</b> ${entrega}</div>
          <div class="text-sm text-slate-700"><b>Método:</b> ${metodo}</div>
          <div class="text-sm text-slate-700 mt-2"><b>Etiquetas:</b> ${etiquetas || "—"}</div>
        </div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500 font-extrabold uppercase">Imágenes</div>
        <div class="mt-3">${imgsHtml}</div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
          <div class="text-xs text-slate-500 font-extrabold uppercase">Archivos de diseño</div>
          <div class="mt-3">${listLinks(diseno)}</div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-4">
          <div class="text-xs text-slate-500 font-extrabold uppercase">Archivos de confirmación</div>
          <div class="mt-3">${listLinks(confirm)}</div>
        </div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500 font-extrabold uppercase">Otros archivos</div>
        <div class="mt-3">${listLinks(files)}</div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500 font-extrabold uppercase">Historial de etapas</div>
        <div class="mt-3">${buildHistorialHtml(historial)}</div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500 font-extrabold uppercase">Artículos (raw)</div>
        <pre class="mt-3 text-xs bg-slate-50 border border-slate-200 rounded-2xl p-3 overflow-auto">${escapeHtml(String(pedido?.articulos ?? ""))}</pre>
      </div>
    </div>
  `;
}

// ✅ ahora el modal SIEMPRE trae detalle real del backend
async function openPedidoDetails(orderKey) {
  ensureDetailsModal();

  const modal = document.getElementById("pedidoDetailsModal");
  const title = document.getElementById("pedidoDetailsTitle");
  const sub = document.getElementById("pedidoDetailsSub");
  const body = document.getElementById("pedidoDetailsBody");

  modal.dataset.orderKey = String(orderKey);
  if (title) title.textContent = `Detalles — ${String(orderKey)}`;
  if (sub) sub.textContent = `Cargando...`;
  if (body) body.innerHTML = `<div class="text-sm text-slate-600">Cargando detalles…</div>`;
  modal.classList.remove("hidden");

  showLoader(true);
  try {
    const { res, data, raw } = await apiGet(`${ENDPOINT_DETAILS}/${encodeURIComponent(String(orderKey))}`);

    if (!res.ok || !data || data.ok !== true) {
      console.error("DETAILS FAIL:", res.status, raw);
      if (sub) sub.textContent = `No se pudieron cargar los detalles`;
      if (body) body.innerHTML = `<div class="text-sm text-rose-700 font-extrabold">Error cargando detalles</div>`;
      return;
    }

    const pedido = data.pedido || {};
    const historial = Array.isArray(data.historial) ? data.historial : [];

    const numero = pedido.numero ?? ("#" + (pedido.id ?? ""));
    const cliente = pedido.cliente ?? "—";

    if (title) title.textContent = `Detalles — ${String(numero)}`;
    if (sub) sub.textContent = `Cliente: ${String(cliente)}`;
    if (body) body.innerHTML = buildDetailsHtml(pedido, historial);

  } catch (e) {
    console.error("DETAILS exception:", e);
    if (sub) sub.textContent = `Error cargando detalles`;
    if (body) body.innerHTML = `<div class="text-sm text-rose-700 font-extrabold">Error cargando detalles</div>`;
  } finally {
    showLoader(false);
  }
}

// =====================================================
// RENDER LISTADO
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
      <div class="font-extrabold text-slate-900 truncate">
        <button type="button"
          class="text-left hover:underline"
          onclick="window.openPedidoDetails('${escapeHtml(key)}')">
          ${numero}
        </button>
      </div>

      <div class="text-slate-600 truncate">${fecha}</div>

      <div class="min-w-0">
        <button type="button"
          class="font-extrabold truncate text-left hover:underline"
          onclick="window.openPedidoDetails('${escapeHtml(key)}')">
          ${cliente}
        </button>

        ${tagsMiniHtml(p.etiquetas)}
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
          <button type="button"
            class="text-sm font-extrabold text-slate-900 truncate hover:underline text-left"
            onclick="window.openPedidoDetails('${escapeHtml(key)}')">
            ${numero}
          </button>
          <div class="text-xs text-slate-500 mt-1 truncate">${fecha}</div>

          <div class="text-sm text-slate-700 mt-2 truncate">
            <b>Cliente:</b>
            <button type="button"
              class="font-extrabold hover:underline"
              onclick="window.openPedidoDetails('${escapeHtml(key)}')">
              ${cliente}
            </button>
          </div>
          <div class="text-sm text-slate-700 truncate"><b>Total:</b> ${total}</div>
          <div class="text-sm text-slate-700 truncate"><b>Estado:</b> ${estado}</div>

          ${p.etiquetas ? `<div class="mt-3">${tagsMiniHtml(p.etiquetas)}</div>` : ``}
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
        p.imagenes_locales, // ✅
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

// Util: sacar de UI y cache (optimista)
function removePedidoFromUI(orderKey) {
  pedidosCache = pedidosCache.filter(p => String(pedidoKey(p)) !== String(orderKey));
  aplicarFiltro();
  document.querySelectorAll(`[data-order-id="${CSS.escape(String(orderKey))}"]`).forEach(n => n.remove());
}

// ✅ Enviar => Por preparar + desasigna + sale de la lista
async function enviarPedido(orderKey) {
  const before = [...pedidosCache];

  removePedidoFromUI(orderKey);

  const payload = { order_id: String(orderKey) };
  const resp = await apiPost(ENDPOINT_ENVIAR, payload);

  if (!resp.res.ok || !resp.data || (resp.data.ok !== true && resp.data.success !== true)) {
    pedidosCache = before;
    aplicarFiltro();
    alert(resp.data?.error || resp.data?.message || "No se pudo enviar el pedido");
    return;
  }
}

// ✅ Realizado => Por producir + desasigna + sale de la lista
async function marcarRealizado(orderKey) {
  const before = [...pedidosCache];

  removePedidoFromUI(orderKey);

  const payload = { order_id: String(orderKey) };

  let resp = await apiPost(ENDPOINT_REALIZADO, payload);
  if (resp.res.status === 404) {
    resp = await apiPost(ENDPOINT_CARGADO_FALLBACK, payload);
  }

  if (!resp.res.ok || !resp.data || (resp.data.ok !== true && resp.data.success !== true)) {
    pedidosCache = before;
    aplicarFiltro();
    alert(resp.data?.error || resp.data?.message || "No se pudo marcar como realizado");
    return;
  }
}

// Exponer global para onclick
window.marcarRealizado = marcarRealizado;
window.enviarPedido = enviarPedido;
window.openPedidoDetails = openPedidoDetails;

// Para tu botón "Actualizar" del HTML
window.__montajeRefresh = cargarMiCola;

document.addEventListener("DOMContentLoaded", () => {
  $("btnTraer5")?.addEventListener("click", () => traerPedidos(5));
  $("btnTraer10")?.addEventListener("click", () => traerPedidos(10));

  $("inputBuscar")?.addEventListener("input", aplicarFiltro);
  $("btnLimpiarBusqueda")?.addEventListener("click", () => {
    if ($("inputBuscar")) $("inputBuscar").value = "";
    aplicarFiltro();
  });

  cargarMiCola();
});
