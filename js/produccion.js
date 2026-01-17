/**
 * produccion.js (CI4)
 * UI estilo Dashboard para Producción:
 * - Grid (desktop) + Cards (mobile)
 * - Estado pill (clic para abrir modal de estado si existe window.abrirModal)
 * - Último cambio (user + fecha)
 * - Etiquetas (pills + botón editar si existe window.abrirModalEtiquetas)
 * - Entrega pill
 * - Botón Ver detalles (usa window.verDetalles si existe, si no verDetallesPedido hook)
 * - Acciones: Traer 5 / 10, Devolver
 * - Búsqueda local
 */

const API_BASE = String(window.API_BASE || "").replace(/\/$/, "");
const ENDPOINT_QUEUE = `${API_BASE}/produccion/my-queue`;
const ENDPOINT_PULL = `${API_BASE}/produccion/pull`;
const ENDPOINT_RETURN_ALL = `${API_BASE}/produccion/return-all`;

let pedidosCache = [];
let pedidosFiltrados = [];
let isLoading = false;
let liveMode = true;
let liveInterval = null;
let silentFetch = false;

// ==============
// Helpers DOM/UI
// ==============
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
    // Ajusta moneda si necesitas
    return num.toLocaleString("es-CO", { style: "currency", currency: "COP" });
  } catch {
    return escapeHtml(String(v));
  }
}

function normalizeTags(tags) {
  if (!tags) return [];
  if (Array.isArray(tags)) return tags.filter(Boolean).map(String);
  if (typeof tags === "string") {
    return tags.split(",").map(s => s.trim()).filter(Boolean);
  }
  return [String(tags)];
}

function esBadgeHtml(valor) {
  const s = String(valor ?? "").trim();
  return s.startsWith("<span") || s.includes("<span") || s.includes("</span>");
}

function normalizeEstado(estado) {
  const s = String(estado || "").trim().toLowerCase();
  if (s.includes("por preparar")) return "Por preparar";
  if (s.includes("faltan archivos") || s.includes("faltan_archivos")) return "Faltan archivos";
  if (s.includes("confirmado")) return "Confirmado";
  if (s.includes("diseñado") || s.includes("disenado")) return "Diseñado";
  if (s.includes("por producir")) return "Por producir";
  if (s.includes("fabricando")) return "Fabricando";
  if (s.includes("enviado")) return "Enviado";
  if (s.includes("repetir")) return "Repetir";
  return estado ? String(estado).trim() : "Por preparar";
}

function estadoStyle(estado) {
  const label = normalizeEstado(estado);
  const s = String(estado || "").toLowerCase().trim();
  const base =
    "inline-flex items-center gap-2 px-3 py-1.5 rounded-2xl border " +
    "text-xs font-extrabold shadow-sm tracking-wide uppercase";
  const dotBase = "h-2.5 w-2.5 rounded-full ring-2 ring-white/40";

  if (s.includes("por preparar")) {
    return { label, icon: "⏳", wrap: `${base} bg-slate-900 border-slate-700 text-white`, dot: `${dotBase} bg-slate-300` };
  }
  if (s.includes("faltan archivos")) {
    return { label, icon: "⚠️", wrap: `${base} bg-yellow-400 border-yellow-500 text-black`, dot: `${dotBase} bg-black/80` };
  }
  if (s.includes("confirmado")) {
    return { label, icon: "✅", wrap: `${base} bg-fuchsia-600 border-fuchsia-700 text-white`, dot: `${dotBase} bg-white` };
  }
  if (s.includes("diseñado") || s.includes("disenado")) {
    return { label, icon: "🎨", wrap: `${base} bg-blue-600 border-blue-700 text-white`, dot: `${dotBase} bg-sky-200` };
  }
  if (s.includes("por producir")) {
    return { label, icon: "🏗️", wrap: `${base} bg-orange-600 border-orange-700 text-white`, dot: `${dotBase} bg-amber-200` };
  }
  if (s.includes("fabricando")) {
    return { label, icon: "🛠️", wrap: `${base} bg-indigo-600 border-indigo-700 text-white`, dot: `${dotBase} bg-indigo-200` };
  }
  if (s.includes("enviado")) {
    return { label, icon: "🚚", wrap: `${base} bg-emerald-600 border-emerald-700 text-white`, dot: `${dotBase} bg-lime-200` };
  }
  if (s.includes("repetir")) {
    return { label: "Repetir", icon: "🔁", wrap: `${base} bg-slate-800 border-slate-700 text-white`, dot: `${dotBase} bg-slate-300` };
  }

  return { label: label || "—", icon: "📍", wrap: `${base} bg-slate-700 border-slate-600 text-white`, dot: `${dotBase} bg-slate-200` };
}

function renderEstadoPill(estado) {
  if (esBadgeHtml(estado)) return String(estado);
  const st = estadoStyle(estado);
  return `
    <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-xl border ${st.wrap}
                shadow-sm font-extrabold text-[10px] uppercase tracking-wide whitespace-nowrap">
      <span class="h-2 w-2 rounded-full ${st.dot}"></span>
      <span class="text-sm leading-none">${st.icon}</span>
      <span class="leading-none">${escapeHtml(st.label)}</span>
    </span>
  `;
}

function parseDateSafe(dtStr) {
  if (!dtStr) return null;
  if (dtStr instanceof Date) return isNaN(dtStr) ? null : dtStr;

  let s = String(dtStr).trim();
  if (!s) return null;

  if (/^\d+$/.test(s)) {
    const n = Number(s);
    const ms = s.length <= 10 ? n * 1000 : n;
    const d = new Date(ms);
    return isNaN(d) ? null : d;
  }

  if (/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/.test(s)) s = s.replace(" ", "T");
  const d = new Date(s);
  return isNaN(d) ? null : d;
}

function formatDateTime(dtStr) {
  const d = parseDateSafe(dtStr);
  if (!d) return "—";
  const pad = (n) => String(n).padStart(2, "0");
  return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function normalizeLastStatusChange(raw) {
  if (!raw) return null;
  if (typeof raw === "string") {
    const t = raw.trim();
    if (t.startsWith("{") && t.endsWith("}")) { try { return JSON.parse(t); } catch { return null; } }
    return { user_name: null, changed_at: raw };
  }
  if (typeof raw === "object") {
    return {
      user_name: raw.user_name ?? raw.user ?? raw.nombre ?? raw.name ?? null,
      changed_at: raw.changed_at ?? raw.date ?? raw.datetime ?? raw.updated_at ?? null,
    };
  }
  return null;
}

function renderLastChangeCompact(p) {
  const info = normalizeLastStatusChange(p?.last_status_change);
  if (!info?.changed_at) return "—";
  const exact = formatDateTime(info.changed_at);
  if (exact === "—") return "—";
  const user = info.user_name ? escapeHtml(info.user_name) : "—";
  return `
    <div class="leading-tight min-w-0 pointer-events-none select-none">
      <div class="text-[12px] font-extrabold text-slate-900 truncate">${user}</div>
      <div class="text-[11px] text-slate-600 whitespace-nowrap">${escapeHtml(exact)}</div>
    </div>
  `;
}

function colorEtiqueta(tag) {
  tag = String(tag).toLowerCase().trim();
  if (tag.startsWith("d.")) return "bg-emerald-50 border-emerald-200 text-emerald-900";
  if (tag.startsWith("p.")) return "bg-amber-50 border-amber-200 text-amber-900";
  return "bg-slate-50 border-slate-200 text-slate-800";
}

function renderEtiquetasCompact(etiquetas, orderId, numero = "") {
  const raw = String(etiquetas || "").trim();
  const list = raw ? raw.split(",").map(t => t.trim()).filter(Boolean) : [];
  const max = 6;
  const visibles = list.slice(0, max);
  const rest = list.length - visibles.length;

  const pills = visibles.map((tag) => {
    const cls = colorEtiqueta(tag);
    return `<span class="px-2.5 py-1.5 rounded-full text-[10px] font-extrabold uppercase tracking-wide border ${cls}">
      ${escapeHtml(tag)}
    </span>`;
  }).join("");

  const more = rest > 0
    ? `<span class="px-2.5 py-1.5 rounded-full text-[10px] font-extrabold uppercase tracking-wide border bg-white border-slate-200 text-slate-700">+${rest}</span>`
    : "";

  // Si existe el modal de etiquetas del dashboard, úsalo
  const onClick = (typeof window.abrirModalEtiquetas === "function")
    ? `window.abrirModalEtiquetas('${escapeJsString(String(orderId))}', '${escapeJsString(raw)}', '${escapeJsString(numero)}')`
    : null;

  const btn = onClick
    ? `<button onclick="${onClick}"
        class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl
              bg-slate-900 text-white text-[11px] font-extrabold uppercase tracking-wide
              hover:bg-slate-800 transition shadow-sm whitespace-nowrap">
        Etiquetas <span class="text-white/80">✎</span>
      </button>`
    : "";

  if (!list.length) {
    return btn
      ? `<div class="flex items-center gap-2">${btn}</div>`
      : `<span class="text-slate-400">—</span>`;
  }

  return `
    <div class="flex flex-wrap items-center gap-2">
      ${pills}${more}
      ${btn}
    </div>
  `;
}

function renderEntregaPill(estadoEnvio) {
  const s = String(estadoEnvio ?? "").toLowerCase().trim();

  if (!s || s === "-" || s === "null") {
    return `<span class="inline-flex items-center px-3 py-1.5 rounded-full text-[11px] font-extrabold
                  bg-slate-100 text-slate-800 border border-slate-200 whitespace-nowrap">⏳ Sin preparar</span>`;
  }
  if (s.includes("fulfilled") || s.includes("entregado")) {
    return `<span class="inline-flex items-center px-3 py-1.5 rounded-full text-[11px] font-extrabold
                  bg-emerald-100 text-emerald-900 border border-emerald-200 whitespace-nowrap">✅ Preparado / enviado</span>`;
  }
  if (s.includes("partial")) {
    return `<span class="inline-flex items-center px-3 py-1.5 rounded-full text-[11px] font-extrabold
                  bg-amber-100 text-amber-900 border border-amber-200 whitespace-nowrap">🟡 Parcial</span>`;
  }
  if (s.includes("unfulfilled") || s.includes("pend")) {
    return `<span class="inline-flex items-center px-3 py-1.5 rounded-full text-[11px] font-extrabold
                  bg-slate-100 text-slate-800 border border-slate-200 whitespace-nowrap">⏳ Pendiente</span>`;
  }
  return `<span class="inline-flex items-center px-3 py-1.5 rounded-full text-[11px] font-extrabold
                bg-white text-slate-900 border border-slate-200 whitespace-nowrap">📦 ${escapeHtml(estadoEnvio)}</span>`;
}

// ==============
// CSRF headers
// ==============
function getCsrfHeaders() {
  const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content");
  const header = document.querySelector('meta[name="csrf-header"]')?.getAttribute("content");
  if (!token || !header) return {};
  return { [header]: token };
}

// ==============
// API
// ==============
async function apiGet(url) {
  const res = await fetch(url, { method: "GET", headers: { "Accept": "application/json" }, credentials: "same-origin" });
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch { data = null; }
  return { res, data, raw: text };
}

async function apiPost(url, payload) {
  const headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
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

// ==============
// Normalizador de payload (ok/data o success/orders)
// ==============
function extractOrdersPayload(payload) {
  if (!payload || typeof payload !== "object") return { ok: false, orders: [] };

  // Formato nuevo recomendado
  if (payload.success === true) {
    return { ok: true, orders: Array.isArray(payload.orders) ? payload.orders : [] };
  }

  // Formato viejo
  if (payload.ok === true) {
    return { ok: true, orders: Array.isArray(payload.data) ? payload.data : [] };
  }

  return { ok: false, orders: [] };
}

// ==============
// Render (GRID + CARDS)
// ==============
function actualizarTabla(pedidos) {
  const cont = $("tablaPedidos");
  const cards = $("cardsPedidos");

  const useCards = window.innerWidth <= 1280;

  // Guardar cache global por si algún modal lo necesita
  window.ordersCache = pedidos || [];
  window.ordersById = new Map((pedidos || []).map(o => [String(o.id), o]));

  // Desktop grid
  if (cont) {
    cont.innerHTML = "";
    if (useCards) {
      cont.classList.add("hidden");
    } else {
      cont.classList.remove("hidden");

      if (!pedidos || !pedidos.length) {
        cont.innerHTML = `<div class="p-8 text-center text-slate-500">No se encontraron pedidos</div>`;
      } else {
        cont.innerHTML = pedidos.map((p) => {
          const id = String(p.id ?? "");
          const numero = String(p.numero ?? ("#" + id));
          const fecha = p.fecha ?? p.created_at ?? "";
          const cliente = p.cliente ?? "";
          const total = p.total ?? "";
          const estado = p.estado ?? p.estado_bd ?? "Por producir";
          const etiquetas = p.etiquetas ?? "";
          const articulos = p.articulos ?? "-";
          const estadoEnvio = p.estado_envio ?? p.estado_entrega ?? "-";
          const formaEnvio = p.forma_envio ?? p.forma_entrega ?? "-";

          const estadoBtn = (typeof window.abrirModal === "function")
            ? `
              <button type="button" onclick="window.abrirModal('${escapeJsString(id)}')"
                class="group inline-flex items-center gap-1 rounded-xl px-1 py-0.5 bg-transparent hover:bg-slate-100 transition"
                title="Cambiar estado">
                ${renderEstadoPill(estado)}
              </button>
            `
            : renderEstadoPill(estado);

          const detallesBtn = (typeof window.verDetalles === "function")
            ? `
              <button type="button" onclick="window.verDetalles('${escapeJsString(id)}')"
                class="px-3 py-2 rounded-2xl bg-blue-600 text-white text-[11px] font-extrabold uppercase tracking-wide hover:bg-blue-700 transition">
                Ver detalles →
              </button>
            `
            : `
              <button type="button" onclick="verDetallesPedido('${escapeJsString(id)}')"
                class="px-3 py-2 rounded-2xl bg-blue-600 text-white text-[11px] font-extrabold uppercase tracking-wide hover:bg-blue-700 transition">
                Ver detalles →
              </button>
            `;

          return `
            <div class="orders-grid cols px-4 py-3 text-[13px] border-b hover:bg-slate-50 transition">
              <div class="font-extrabold text-slate-900 whitespace-nowrap">${escapeHtml(numero)}</div>
              <div class="text-slate-600 whitespace-nowrap">${escapeHtml(String(fecha || "—"))}</div>
              <div class="min-w-0 font-semibold text-slate-800 truncate">${escapeHtml(String(cliente || "—"))}</div>
              <div class="font-extrabold text-slate-900 whitespace-nowrap">${moneyFormat(total)}</div>

              <div class="whitespace-nowrap relative z-10">${estadoBtn}</div>

              <div class="min-w-0">${renderLastChangeCompact(p)}</div>

              <div class="min-w-0">${renderEtiquetasCompact(etiquetas, id, numero)}</div>

              <div class="text-center font-extrabold">${escapeHtml(String(articulos ?? "-"))}</div>

              <div class="whitespace-nowrap">${renderEntregaPill(estadoEnvio)}</div>

              <div class="min-w-0 text-xs text-slate-700 metodo-entrega">${escapeHtml(String(formaEnvio || "—"))}</div>

              <div class="text-right whitespace-nowrap">${detallesBtn}</div>
            </div>
          `;
        }).join("");
      }
    }
  }

  // Mobile cards
  if (cards) {
    cards.innerHTML = "";
    if (!useCards) {
      cards.classList.add("hidden");
      return;
    }
    cards.classList.remove("hidden");

    if (!pedidos || !pedidos.length) {
      cards.innerHTML = `<div class="p-8 text-center text-slate-500">No se encontraron pedidos</div>`;
      return;
    }

    cards.innerHTML = pedidos.map((p) => {
      const id = String(p.id ?? "");
      const numero = String(p.numero ?? ("#" + id));
      const fecha = p.fecha ?? p.created_at ?? "";
      const cliente = p.cliente ?? "";
      const total = p.total ?? "";
      const estado = p.estado ?? p.estado_bd ?? "Por producir";
      const etiquetas = p.etiquetas ?? "";
      const articulos = p.articulos ?? "-";
      const estadoEnvio = p.estado_envio ?? p.estado_entrega ?? "-";
      const formaEnvio = p.forma_envio ?? p.forma_entrega ?? "-";

      const last = normalizeLastStatusChange(p?.last_status_change);
      const lastText = last?.changed_at
        ? `${escapeHtml(last.user_name || "—")} · ${escapeHtml(formatDateTime(last.changed_at))}`
        : "—";

      const estadoBtn = (typeof window.abrirModal === "function")
        ? `<button onclick="window.abrirModal('${escapeJsString(id)}')"
              class="inline-flex items-center gap-2 rounded-2xl bg-transparent border-0 p-0 relative z-10">
              ${renderEstadoPill(estado)}
            </button>`
        : renderEstadoPill(estado);

      const detallesBtn = (typeof window.verDetalles === "function")
        ? `<button onclick="window.verDetalles('${escapeJsString(id)}')"
              class="px-3 py-2 rounded-2xl bg-blue-600 text-white text-[11px] font-extrabold uppercase tracking-wide hover:bg-blue-700 transition">
              Ver detalles →
            </button>`
        : `<button onclick="verDetallesPedido('${escapeJsString(id)}')"
              class="px-3 py-2 rounded-2xl bg-blue-600 text-white text-[11px] font-extrabold uppercase tracking-wide hover:bg-blue-700 transition">
              Ver detalles →
            </button>`;

      return `
        <div class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden mb-3">
          <div class="p-4">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="text-sm font-extrabold text-slate-900">${escapeHtml(numero)}</div>
                <div class="text-xs text-slate-500 mt-0.5">${escapeHtml(String(fecha || "—"))}</div>
                <div class="text-sm font-semibold text-slate-800 mt-1 truncate">${escapeHtml(String(cliente || "—"))}</div>
              </div>
              <div class="text-right whitespace-nowrap">
                <div class="text-sm font-extrabold text-slate-900">${moneyFormat(total)}</div>
              </div>
            </div>

            <div class="mt-3 flex items-center justify-between gap-3">
              ${estadoBtn}
              <div class="text-right whitespace-nowrap">${detallesBtn}</div>
            </div>

            <div class="mt-3">${renderEntregaPill(estadoEnvio)}</div>
            <div class="mt-3">${renderEtiquetasCompact(etiquetas, id, numero)}</div>

            <div class="mt-3 text-xs text-slate-600 space-y-1">
              <div><b>Artículos:</b> ${escapeHtml(String(articulos ?? "-"))}</div>
              <div><b>Forma:</b> ${escapeHtml(String(formaEnvio || "—"))}</div>
              <div><b>Último cambio:</b> ${lastText}</div>
            </div>
          </div>
        </div>
      `;
    }).join("");
  }
}

// ==============
// Búsqueda local
// ==============
function aplicarFiltroBusqueda() {
  const q = ($("inputBuscar")?.value || "").trim().toLowerCase();
  if (!q) {
    pedidosFiltrados = [...pedidosCache];
    actualizarTabla(pedidosFiltrados);
    setTotalPedidos(pedidosFiltrados.length);
    return;
  }

  pedidosFiltrados = pedidosCache.filter((p) => {
    const haystack = [
      p.id, p.numero,
      p.cliente,
      p.estado, p.estado_bd,
      p.etiquetas, p.tags,
      p.forma_envio, p.forma_entrega,
      p.estado_envio, p.estado_entrega,
    ].map(safeText).join(" ").toLowerCase();

    return haystack.includes(q);
  });

  actualizarTabla(pedidosFiltrados);
  setTotalPedidos(pedidosFiltrados.length);
}

// ==============
// Cargar cola
// ==============
async function cargarMiCola() {
  if (isLoading) return;
  isLoading = true;
  setLoader(true);

  try {
    const { res, data, raw } = await apiGet(ENDPOINT_QUEUE);

    if (!res.ok || !data) {
      console.error("Queue FAIL:", res.status, raw);
      pedidosCache = [];
      pedidosFiltrados = [];
      actualizarTabla([]);
      setTotalPedidos(0);
      return;
    }

    const extracted = extractOrdersPayload(data);
    if (!extracted.ok) {
      console.error("Queue payload inválido:", data);
      pedidosCache = [];
      pedidosFiltrados = [];
      actualizarTabla([]);
      setTotalPedidos(0);
      return;
    }

    // Normaliza a shape dashboard mínimo
    const incoming = extracted.orders.map((r) => {
      // Si ya viene en formato dashboard, lo deja.
      // Si viene en formato viejo, mapea llaves clave.
      return {
        id: r.id ?? r.pedido_id ?? r.shopify_order_id ?? "",
        numero: r.numero ?? (r.name ?? (r.id ? ("#" + r.id) : "")),
        fecha: r.fecha ?? r.created_at ?? r.order_date ?? r.created_at ?? null,
        cliente: r.cliente ?? r.customer_name ?? r.customer ?? null,
        total: r.total ?? r.total_price ?? null,
        estado: r.estado ?? r.estado_bd ?? "Por producir",
        etiquetas: r.etiquetas ?? r.tags ?? "",
        articulos: r.articulos ?? r.items_count ?? r.items ?? "",
        estado_envio: r.estado_envio ?? r.estado_entrega ?? r.fulfillment_status ?? "",
        forma_envio: r.forma_envio ?? r.forma_entrega ?? r.shipping_method ?? r.metodo_entrega ?? "",
        last_status_change: r.last_status_change ?? {
          user_name: r.estado_por ?? r.estado_changed_by ?? null,
          changed_at: r.estado_actualizado ?? r.estado_changed_at ?? null,
        },
      };
    });

    pedidosCache = incoming;
    pedidosFiltrados = [...pedidosCache];

    // aplica filtro si hay búsqueda
    const q = ($("inputBuscar")?.value || "").trim();
    if (q) aplicarFiltroBusqueda();
    else {
      actualizarTabla(pedidosFiltrados);
      setTotalPedidos(pedidosFiltrados.length);
    }
  } catch (e) {
    console.error("cargarMiCola error:", e);
    pedidosCache = [];
    pedidosFiltrados = [];
    actualizarTabla([]);
    setTotalPedidos(0);
  } finally {
    isLoading = false;
    silentFetch = false;
    setLoader(false);
  }
}

// ==============
// Acciones Producción
// ==============
async function traerPedidos(count) {
  setLoader(true);
  try {
    const { res, data, raw } = await apiPost(ENDPOINT_PULL, { count });

    if (!res.ok || !data) {
      console.error("PULL FAIL:", res.status, raw);
      alert("No se pudo traer pedidos (error de red o sesión).");
      return;
    }

    // soporta {ok:true} o {success:true}
    const ok = data.ok === true || data.success === true;
    if (!ok) {
      alert(data.error || data.message || "No se pudo traer pedidos.");
      return;
    }

    await cargarMiCola();
  } finally {
    setLoader(false);
  }
}

async function devolverPedidosRestantes() {
  const ok = confirm("¿Seguro que quieres devolver TODOS tus pedidos pendientes en Producción?");
  if (!ok) return;

  setLoader(true);
  try {
    const { res, data, raw } = await apiPost(ENDPOINT_RETURN_ALL, {});

    if (!res.ok || !data) {
      console.error("RETURN ALL FAIL:", res.status, raw);
      alert("No se pudo devolver pedidos (error de red o sesión).");
      return;
    }

    const ok2 = data.ok === true || data.success === true;
    if (!ok2) {
      alert(data.error || data.message || "No se pudo devolver pedidos.");
      return;
    }

    await cargarMiCola();
  } finally {
    setLoader(false);
  }
}

// ==============
// Detalles (hook)
// ==============
window.verDetallesPedido = function (pedidoId) {
  // si existe verDetalles del dashboard, úsalo
  if (typeof window.verDetalles === "function") {
    window.verDetalles(String(pedidoId));
    return;
  }
  // si tienes tu propia función de detalles:
  if (typeof window.abrirModalDetalles === "function") {
    window.abrirModalDetalles(String(pedidoId));
    return;
  }
  // fallback
  alert("Detalles no configurados en Producción.\nPedido: " + pedidoId);
};

// ==============
// Eventos
// ==============
function bindEventos() {
  $("btnTraer5")?.addEventListener("click", () => traerPedidos(5));
  $("btnTraer10")?.addEventListener("click", () => traerPedidos(10));
  $("btnDevolver")?.addEventListener("click", () => devolverPedidosRestantes());

  $("inputBuscar")?.addEventListener("input", () => aplicarFiltroBusqueda());
  $("btnLimpiarBusqueda")?.addEventListener("click", () => {
    const el = $("inputBuscar");
    if (el) el.value = "";
    aplicarFiltroBusqueda();
  });

  window.addEventListener("resize", () => {
    // re-render sin pedir al backend
    actualizarTabla(pedidosFiltrados.length ? pedidosFiltrados : pedidosCache);
  });
}

// ==============
// Live
// ==============
function startLive(ms = 30000) {
  if (liveInterval) clearInterval(liveInterval);
  liveInterval = setInterval(() => {
    if (liveMode && !isLoading) {
      silentFetch = true;
      cargarMiCola();
    }
  }, ms);
}

// ==============
// Init
// ==============
document.addEventListener("DOMContentLoaded", () => {
  bindEventos();
  cargarMiCola();
  startLive(30000);
});


// ===============================
// DETALLES (FULL) EN PRODUCCIÓN
// - Trae detalle completo del pedido
// - Refleja cambios: estado, etiquetas, last_status_change, imágenes, etc.
// ===============================

// Endpoints posibles (usa el de dashboard si ya lo tienes hecho allí)
function buildDetallesEndpoints(orderId) {
  const id = encodeURIComponent(String(orderId || ""));
  const base = (typeof window.apiUrl === "function")
    ? window.apiUrl(`/dashboard/detalles/${id}`)
    : `${API_BASE}/dashboard/detalles/${id}`;

  return [
    base,
    `${API_BASE}/dashboard/detalles/${id}`,
    `/dashboard/detalles/${id}`,
    `/index.php/dashboard/detalles/${id}`,
    `/index.php/index.php/dashboard/detalles/${id}`,
    `${API_BASE}/produccion/detalles/${id}`,       // por si luego creas endpoint dedicado
    `/produccion/detalles/${id}`,
    `/index.php/produccion/detalles/${id}`,
  ];
}

function abrirModalDetalles() {
  const modal = document.getElementById("modalDetalles");
  if (modal) modal.classList.remove("hidden");
  document.documentElement.classList.add("overflow-hidden");
  document.body.classList.add("overflow-hidden");
}

function cerrarModalDetalles() {
  const modal = document.getElementById("modalDetalles");
  if (modal) modal.classList.add("hidden");
  document.documentElement.classList.remove("overflow-hidden");
  document.body.classList.remove("overflow-hidden");
}

function abrirPanelCliente() {
  document.getElementById("panelCliente")?.classList.remove("hidden");
}

function cerrarPanelCliente() {
  document.getElementById("panelCliente")?.classList.add("hidden");
}

// helpers
function esUrl(u) {
  return /^https?:\/\//i.test(String(u || "").trim());
}
function esImagenUrl(url) {
  if (!url) return false;
  const u = String(url).trim();
  return /https?:\/\/.*\.(jpeg|jpg|png|gif|webp|svg)(\?.*)?$/i.test(u);
}
function totalLinea(price, qty) {
  const p = Number(price);
  const q = Number(qty);
  if (isNaN(p) || isNaN(q)) return null;
  return (p * q).toFixed(2);
}
function fmtMoneyEUR(v) {
  if (v === null || v === undefined || v === "") return "0";
  const n = Number(v);
  if (isNaN(n)) return String(v);
  return n.toFixed(2);
}

function setHtml(id, html) {
  const el = document.getElementById(id);
  if (el) el.innerHTML = html;
}
function setText(id, txt) {
  const el = document.getElementById(id);
  if (el) el.textContent = txt ?? "";
}

// ✅ Detalle completo (similar al dashboard)
window.verDetalles = async function (orderId) {
  const id = String(orderId || "");
  if (!id) return;

  // Si ya existe el verDetalles del dashboard (porque lo importaste), úsalo
  // y sal de aquí.
  if (window.__DASHBOARD_VERDETALLES && typeof window.__DASHBOARD_VERDETALLES === "function") {
    window.__DASHBOARD_VERDETALLES(id);
    return;
  }

  // placeholders
  abrirModalDetalles();
  setText("tituloPedido", "Cargando detalles...");
  setHtml("detalleProductos", `<div class="text-slate-500">Cargando productos…</div>`);
  setHtml("detalleTotales", `<div class="text-slate-500">Cargando…</div>`);
  setHtml("detalleCliente", `<div class="text-slate-500">Cargando…</div>`);
  setHtml("detalleEnvio", `<div class="text-slate-500">Cargando…</div>`);
  setHtml("detalleResumen", `<div class="text-slate-500">Cargando…</div>`);

  // fetch robusto
  let payload = null;
  let lastErr = null;

  for (const url of buildDetallesEndpoints(id)) {
    try {
      const r = await fetch(url, { headers: { Accept: "application/json" }, credentials: "same-origin" });
      if (r.status === 404) continue;

      const d = await r.json().catch(() => null);
      if (!r.ok || !d) throw new Error(d?.message || `HTTP ${r.status}`);

      // dashboard suele devolver {success:true, order:{...}, imagenes_locales:{}, product_images:{}}
      if (d.success !== true) throw new Error(d.message || "Respuesta inválida (success!=true)");
      payload = d;
      break;
    } catch (e) {
      lastErr = e;
    }
  }

  if (!payload) {
    console.error("Detalle error:", lastErr);
    setHtml("detalleProductos", `<div class="text-rose-600 font-extrabold">Error cargando detalles del pedido.</div>`);
    return;
  }

  const o = payload.order || {};
  const lineItems = Array.isArray(o.line_items) ? o.line_items : [];

  const imagenesLocales = payload.imagenes_locales || {};
  const productImages = payload.product_images || {};

  // ==========================
  // Header / Resumen (incluye cambios)
  // ==========================
  const name = o.name || ("#" + (o.id || id));
  setText("tituloPedido", `Detalles ${name}`);

  // ✅ estado y etiquetas (los cambios vienen del backend)
  const estado = o.estado ?? o.status ?? o.estado_bd ?? "—";
  const tags = String(o.tags ?? o.etiquetas ?? "").trim();

  const last = o.last_status_change || payload.last_status_change || null;
  const lastInfo = normalizeLastStatusChange(last);
  const lastText = lastInfo?.changed_at
    ? `${escapeHtml(lastInfo.user_name || "—")} · ${escapeHtml(formatDateTime(lastInfo.changed_at))}`
    : "—";

  // Cliente
  const clienteNombre = o.customer
    ? `${o.customer.first_name || ""} ${o.customer.last_name || ""}`.trim()
    : (o.customer_name || "");

  setHtml("detalleCliente", `
    <div class="space-y-2">
      <div class="font-extrabold text-slate-900">${escapeHtml(clienteNombre || "—")}</div>
      <div><span class="text-slate-500">Email:</span> ${escapeHtml(o.email || "—")}</div>
      <div><span class="text-slate-500">Tel:</span> ${escapeHtml(o.phone || "—")}</div>
      <div><span class="text-slate-500">ID:</span> ${escapeHtml(o.customer?.id || "—")}</div>
    </div>
  `);

  // Envío
  const a = o.shipping_address || {};
  setHtml("detalleEnvio", `
    <div class="space-y-1">
      <div class="font-extrabold text-slate-900">${escapeHtml(a.name || "—")}</div>
      <div>${escapeHtml(a.address1 || "")}</div>
      <div>${escapeHtml(a.address2 || "")}</div>
      <div>${escapeHtml((a.zip || "") + " " + (a.city || ""))}</div>
      <div>${escapeHtml(a.province || "")}</div>
      <div>${escapeHtml(a.country || "")}</div>
      <div class="pt-2"><span class="text-slate-500">Tel envío:</span> ${escapeHtml(a.phone || "—")}</div>
    </div>
  `);

  // Totales
  const envio =
    o.total_shipping_price_set?.shop_money?.amount ??
    o.total_shipping_price_set?.presentment_money?.amount ??
    "0";
  const impuestos = o.total_tax ?? "0";

  setHtml("detalleTotales", `
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div class="text-sm sm:text-base font-extrabold">
        <span class="text-slate-500">Subtotal:</span> ${escapeHtml(fmtMoneyEUR(o.subtotal_price || "0"))} €
        <span class="mx-2 text-slate-300">|</span>
        <span class="text-slate-500">Envío:</span> ${escapeHtml(fmtMoneyEUR(envio))} €
        <span class="mx-2 text-slate-300">|</span>
        <span class="text-slate-500">Impuestos:</span> ${escapeHtml(fmtMoneyEUR(impuestos))} €
      </div>
      <div class="text-lg font-extrabold text-slate-900">
        Total: ${escapeHtml(fmtMoneyEUR(o.total_price || "0"))} €
      </div>
    </div>
  `);

  // Resumen (incluye estado/tags/último cambio)
  setHtml("detalleResumen", `
    <div class="space-y-2 text-sm">
      <div><span class="text-slate-500 font-bold">Estado:</span> ${renderEstadoPill(estado)}</div>
      <div><span class="text-slate-500 font-bold">Etiquetas:</span> ${tags ? escapeHtml(tags) : "—"}</div>
      <div><span class="text-slate-500 font-bold">Último cambio:</span> ${lastText}</div>
      <div><span class="text-slate-500 font-bold">Pago:</span> ${escapeHtml(o.financial_status || "—")}</div>
      <div><span class="text-slate-500 font-bold">Entrega:</span> ${escapeHtml(o.fulfillment_status || "—")}</div>
      <div><span class="text-slate-500 font-bold">Creado:</span> ${escapeHtml(o.created_at || "—")}</div>
    </div>
  `);

  // ==========================
  // Productos (con imágenes y personalización)
  // ==========================
  if (!lineItems.length) {
    setHtml("detalleProductos", `<div class="text-slate-500">Este pedido no tiene productos.</div>`);
    return;
  }

  const itemsHtml = lineItems.map((item, index) => {
    const props = Array.isArray(item.properties) ? item.properties : [];

    const propsImg = [];
    const propsTxt = [];

    for (const p of props) {
      const name = String(p?.name ?? "").trim() || "Campo";
      const value = p?.value;
      const v =
        value === null || value === undefined
          ? ""
          : typeof value === "object"
          ? JSON.stringify(value)
          : String(value);

      if (esImagenUrl(v)) propsImg.push({ name, value: v });
      else propsTxt.push({ name, value: v });
    }

    const pid = String(item.product_id || "");
    const productImg = pid && productImages?.[pid] ? String(productImages[pid]) : "";

    const productImgHtml = productImg
      ? `
        <a href="${escapeHtml(productImg)}" target="_blank"
          class="h-16 w-16 rounded-2xl overflow-hidden border border-slate-200 shadow-sm bg-white flex-shrink-0">
          <img src="${escapeHtml(productImg)}" class="h-full w-full object-cover">
        </a>
      `
      : `
        <div class="h-16 w-16 rounded-2xl border border-slate-200 bg-slate-50 flex items-center justify-center text-slate-400 flex-shrink-0">
          🧾
        </div>
      `;

    const localUrl = imagenesLocales?.[index] ? String(imagenesLocales[index]) : "";

    const propsTxtHtml = propsTxt.length
      ? `
        <div class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 p-3">
          <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500 mb-2">Personalización</div>
          <div class="space-y-1 text-sm">
            ${propsTxt.map(({ name, value }) => {
              const safeV = escapeHtml(value || "—");
              const safeName = escapeHtml(name);

              const val = esUrl(value)
                ? `<a href="${escapeHtml(value)}" target="_blank" class="underline font-semibold text-slate-900">${safeV}</a>`
                : `<span class="font-semibold text-slate-900 break-words">${safeV}</span>`;

              return `
                <div class="flex gap-2">
                  <div class="min-w-[130px] text-slate-500 font-bold">${safeName}:</div>
                  <div class="flex-1">${val}</div>
                </div>
              `;
            }).join("")}
          </div>
        </div>
      `
      : "";

    const propsImgsHtml = propsImg.length
      ? `
        <div class="mt-3">
          <div class="text-xs font-extrabold text-slate-500 mb-2">Imagen original (cliente)</div>
          <div class="flex flex-wrap gap-3">
            ${propsImg.map(({ name, value }) => `
              <a href="${escapeHtml(value)}" target="_blank"
                class="block rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
                <img src="${escapeHtml(value)}" class="h-28 w-28 object-cover">
                <div class="px-3 py-2 text-xs font-bold text-slate-700 bg-white border-t border-slate-200">
                  ${escapeHtml(name)}
                </div>
              </a>
            `).join("")}
          </div>
        </div>
      `
      : "";

    const modificadaHtml = localUrl
      ? `
        <div class="mt-3">
          <div class="text-xs font-extrabold text-slate-500">Imagen modificada (subida)</div>
          <a href="${escapeHtml(localUrl)}" target="_blank"
            class="inline-block mt-2 rounded-2xl overflow-hidden border border-slate-200 shadow-sm">
            <img src="${escapeHtml(localUrl)}" class="h-40 w-40 object-cover">
          </a>
        </div>
      `
      : "";

    const variant = item.variant_title && item.variant_title !== "Default Title" ? item.variant_title : "";
    const sku = item.sku || "";
    const qty = item.quantity ?? 1;
    const price = item.price ?? "0";
    const tot = totalLinea(price, qty);

    const datosProductoHtml = `
      <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
        ${variant ? `<div><span class="text-slate-500 font-bold">Variante:</span> <span class="font-semibold">${escapeHtml(variant)}</span></div>` : ""}
        ${sku ? `<div><span class="text-slate-500 font-bold">SKU:</span> <span class="font-semibold">${escapeHtml(sku)}</span></div>` : ""}
        ${item.product_id ? `<div><span class="text-slate-500 font-bold">Product ID:</span> <span class="font-semibold">${escapeHtml(item.product_id)}</span></div>` : ""}
        ${item.variant_id ? `<div><span class="text-slate-500 font-bold">Variant ID:</span> <span class="font-semibold">${escapeHtml(item.variant_id)}</span></div>` : ""}
      </div>
    `;

    return `
      <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-4">
        <div class="flex items-start gap-4">
          ${productImgHtml}
          <div class="min-w-0 flex-1">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="font-extrabold text-slate-900 truncate">${escapeHtml(item.title || item.name || "Producto")}</div>
                <div class="text-sm text-slate-600 mt-1">
                  Cant: <b>${escapeHtml(qty)}</b> · Precio: <b>${escapeHtml(price)} €</b>
                  ${tot ? ` · Total: <b>${escapeHtml(tot)} €</b>` : ""}
                </div>
              </div>
            </div>

            ${datosProductoHtml}
            ${propsTxtHtml}
            ${propsImgsHtml}
            ${modificadaHtml}
          </div>
        </div>
      </div>
    `;
  }).join("");

  setHtml("detalleProductos", itemsHtml);
};

// hooks para tus botones HTML
window.cerrarModalDetalles = cerrarModalDetalles;
window.abrirPanelCliente = abrirPanelCliente;
window.cerrarPanelCliente = cerrarPanelCliente;

// Si te quedan tus botones "Todos / Preparados" y aún no tienes filtro:
window.mostrarTodos = function () {
  // no hace nada (placeholder) — si quieres filtrado por imagen, te lo meto igual que dashboard
};
window.filtrarPreparados = function () {
  // placeholder
};
