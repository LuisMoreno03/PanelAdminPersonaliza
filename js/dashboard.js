// ===============================
// DASHBOARD.JS - Shopify 50 en 50 + modales guardan
// ===============================

let nextPageInfo = null;
let isLoading = false;

let etiquetasSeleccionadas = [];
window.imagenesCargadas = [];
window.imagenesLocales = {};

let pageHistory = [];

let userPingInterval = null;
let userStatusInterval = null;

// =====================================================
// Loader global
// =====================================================
function showLoader() {
  const el = document.getElementById("globalLoader");
  if (el) el.classList.remove("hidden");
}
function hideLoader() {
  const el = document.getElementById("globalLoader");
  if (el) el.classList.add("hidden");
}

// =====================================================
// UTIL: Fetch robusto con fallback + debug de 500
// =====================================================
async function fetchJsonWithFallback(urls, options = {}) {
  const list = Array.isArray(urls) ? urls : [urls];

  let lastErr = null;

  for (const url of list) {
    try {
      const res = await fetch(url, {
        credentials: "same-origin",
        ...options,
        headers: {
          Accept: "application/json",
          ...(options.headers || {}),
        },
      });

      // Si no es 2xx, intentamos leer texto para debug
      if (!res.ok) {
        const txt = await res.text().catch(() => "");
        lastErr = new Error(`HTTP ${res.status} en ${url}\n${txt?.slice(0, 500)}`);
        continue;
      }

      // Intentar JSON
      const data = await res.json().catch(async () => {
        const txt = await res.text().catch(() => "");
        throw new Error(`Respuesta NO JSON en ${url}\n${txt?.slice(0, 500)}`);
      });

      return { ok: true, url, data };
    } catch (e) {
      lastErr = e;
    }
  }

  return { ok: false, error: lastErr };
}

function ciFallback(urlNoIndex) {
  // Si el server requiere index.php, lo probamos como fallback.
  // Ej: /api/estado/guardar -> /index.php/api/estado/guardar
  if (!urlNoIndex.startsWith("/")) return [urlNoIndex];
  return [urlNoIndex, "/index.php" + urlNoIndex];
}

// =====================================================
// HELPERS
// =====================================================
function esImagen(url) {
  if (!url) return false;
  return /\.(jpeg|jpg|png|gif|webp|svg)$/i.test(url);
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

function esBadgeHtml(valor) {
  const s = String(valor ?? "").trim();
  return s.startsWith("<span") || s.includes("<span") || s.includes("</span>");
}

function renderEstado(valor) {
  if (esBadgeHtml(valor)) return String(valor);
  return escapeHtml(valor ?? "-");
}

// =====================================================
// ENTREGA (MUY visible)
// =====================================================
function entregaStyle(estado) {
  const s = String(estado || "").toLowerCase().trim();

  if (!s || s === "-" || s === "null" || s === "sin estado") {
    return { wrap: "bg-slate-50 border-slate-200 text-slate-800", dot: "bg-slate-400", icon: "📦", label: "Sin estado" };
  }
  if (s.includes("entregado") || s.includes("delivered")) {
    return { wrap: "bg-emerald-50 border-emerald-200 text-emerald-900", dot: "bg-emerald-500", icon: "✅", label: "Entregado" };
  }
  if (s.includes("enviado") || s.includes("shipped")) {
    return { wrap: "bg-blue-50 border-blue-200 text-blue-900", dot: "bg-blue-500", icon: "🚚", label: "Enviado" };
  }
  if (s.includes("prepar") || s.includes("pendiente") || s.includes("processing")) {
    return { wrap: "bg-amber-50 border-amber-200 text-amber-900", dot: "bg-amber-500", icon: "⏳", label: "Preparando" };
  }
  if (s.includes("cancel") || s.includes("devuelto") || s.includes("return")) {
    return { wrap: "bg-rose-50 border-rose-200 text-rose-900", dot: "bg-rose-500", icon: "⛔", label: "Incidencia" };
  }
  return { wrap: "bg-slate-50 border-slate-200 text-slate-900", dot: "bg-slate-400", icon: "📍", label: estado || "—" };
}

function renderEntregaPill(estadoEnvio) {
  const st = entregaStyle(estadoEnvio);
  return `
    <span class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl border ${st.wrap}
                 shadow-sm font-extrabold text-[11px] uppercase tracking-wide max-w-[220px] truncate"
          title="${escapeHtml(st.label)}">
      <span class="h-2.5 w-2.5 rounded-full ${st.dot}"></span>
      <span class="text-sm leading-none">${st.icon}</span>
      <span class="leading-none truncate">${escapeHtml(st.label)}</span>
    </span>
  `;
}

// =====================================================
// TIME AGO
// =====================================================
function timeAgo(dtStr) {
  if (!dtStr) return "";
  const d = new Date(String(dtStr).replace(" ", "T"));
  if (isNaN(d)) return "";

  const diff = Date.now() - d.getTime();
  const sec = Math.floor(diff / 1000);
  const min = Math.floor(sec / 60);
  const hr = Math.floor(min / 60);
  const day = Math.floor(hr / 24);

  if (day > 0) return `${day}d ${hr % 24}h`;
  if (hr > 0) return `${hr}h ${min % 60}m`;
  if (min > 0) return `${min}m`;
  return `${sec}s`;
}

function renderLastChangeCompact(p) {
  const info = p?.last_status_change;
  if (!info || !info.changed_at) return `<span class="text-slate-400 text-sm">—</span>`;

  const user = info.user_name ? escapeHtml(info.user_name) : "—";
  return `
    <div class="text-xs text-slate-600 leading-tight">
      <div class="font-bold text-slate-900 truncate max-w-[180px]" title="${user}">${user}</div>
      <div class="text-slate-500">Hace ${escapeHtml(timeAgo(info.changed_at))}</div>
    </div>
  `;
}

// =====================================================
// ETIQUETAS (compactas + modal)
// =====================================================
function colorEtiqueta(tag) {
  tag = String(tag).toLowerCase().trim();
  if (tag.startsWith("d.")) return "bg-emerald-50 border-emerald-200 text-emerald-900";
  if (tag.startsWith("p.")) return "bg-amber-50 border-amber-200 text-amber-900";
  return "bg-slate-50 border-slate-200 text-slate-800";
}

function renderEtiquetasCompact(etiquetas, orderId, mobile = false) {
  const raw = String(etiquetas || "").trim();
  const list = raw ? raw.split(",").map((t) => t.trim()).filter(Boolean) : [];

  const max = mobile ? 3 : 2;
  const visibles = list.slice(0, max);
  const rest = list.length - visibles.length;

  const pills = visibles
    .map((tag) => {
      const cls = colorEtiqueta(tag);
      return `
        <span class="px-2.5 py-1.5 rounded-full text-[10px] font-extrabold uppercase tracking-wide border ${cls}
                     max-w-[120px] truncate" title="${escapeHtml(tag)}">
          ${escapeHtml(tag)}
        </span>
      `;
    })
    .join("");

  const more =
    rest > 0
      ? `<span class="px-2.5 py-1.5 rounded-full text-[10px] font-extrabold uppercase tracking-wide border bg-white border-slate-200 text-slate-700">
          +${rest}
        </span>`
      : "";

    return `
      <tr data-order-id="${o.id}">
        <td class="py-3">${escapeHtml(o.numero || "-")}</td>
        <td class="py-3">${escapeHtml(o.fecha || "-")}</td>
        <td class="py-3">${escapeHtml(o.cliente || "-")}</td>
        <td class="py-3">${escapeHtml(o.total || "-")}</td>
        <td class="py-3">
          <button class="btnEstado px-3 py-1 rounded bg-gray-100" data-id="${o.id}" data-estado="${escapeAttr(o.estado || "")}">
            ${escapeHtml(o.estado || "-")}
          </button>
        </td>
        <td class="py-3">${escapeHtml(last)}</td>
        <td class="py-3">
          <button class="btnEtiquetas px-3 py-1 rounded bg-gray-100" data-id="${o.id}" data-tags="${escapeAttr(o.etiquetas || "")}">
            ${escapeHtml((o.etiquetas || "").slice(0, 35) || "Editar")}
          </button>
        </td>
        <td class="py-3">${escapeHtml(String(o.articulos ?? 0))}</td>
      </tr>
    `;
  }).join("");

  if (append) tbody.insertAdjacentHTML("beforeend", rows);
  else tbody.innerHTML = rows;

  bindRowButtons();
}

function bindRowButtons() {
  document.querySelectorAll(".btnEstado").forEach(btn => {
    btn.onclick = () => openEstadoModal(btn.dataset.id, btn.dataset.estado || "");
  });

  document.querySelectorAll(".btnEtiquetas").forEach(btn => {
    btn.onclick = () => openEtiquetasModal(btn.dataset.id, btn.dataset.tags || "");
  });
}

// ===============================
// Cargar 1 página (50)
// ===============================
async function loadOrdersPage(pageInfo = null, append = false) {
  if (isLoading) return;
  isLoading = true;
  showLoader();

  try {
    const url = new URL("/dashboard/filter", window.location.origin);
    url.searchParams.set("limit", "50");
    if (pageInfo) url.searchParams.set("page_info", pageInfo);

    const res = await fetch(url.toString(), { credentials: "include" });
    const data = await res.json();

    if (!data.success) throw new Error(data.message || "Error cargando pedidos");

    nextPageInfo = data.next_page_info || null;

    // guardamos en map
    (data.orders || []).forEach(o => allOrdersMap.set(String(o.id), o));

    renderOrders(data.orders || [], append);

    setProgress(`Cargados: ${allOrdersMap.size}`);

  } catch (e) {
    console.error(e);
    alert("Error: " + (e.message || e));
  } finally {
    hideLoader();
    isLoading = false;
  }
}

// =====================================================
// PAGINACIÓN
// =====================================================
function paginaSiguiente() {
  if (nextPageInfo) cargarPedidos(nextPageInfo);
}
function paginaAnterior() {
  if (pageHistory.length === 0) return cargarPedidos(null);
  pageHistory.pop();
  const prev = pageHistory.length ? pageHistory[pageHistory.length - 1] : null;
  cargarPedidos(prev);
}

// =====================================================
// TABLA (tbody) - RESPONSIVE
// =====================================================
function actualizarTabla(pedidos) {
  const tbody = document.getElementById("tablaPedidos");
  const cards = document.getElementById("cardsPedidos");

  if (tbody) {
    tbody.innerHTML = "";

    if (!pedidos.length) {
      tbody.innerHTML = `
        <tr>
          <td colspan="11" class="py-10 text-center text-slate-500">No se encontraron pedidos</td>
        </tr>`;
    } else {
      tbody.innerHTML = pedidos
        .map((p) => {
          const id = p.id ?? "";
          const etiquetas = p.etiquetas ?? "";

          return `
          <tr class="border-b border-slate-100 hover:bg-slate-50/60 transition">
            <td class="py-4 px-4 font-extrabold text-slate-900 whitespace-nowrap">
              ${escapeHtml(p.numero ?? "-")}
            </td>

            <td class="py-4 px-4 text-slate-600 whitespace-nowrap hidden lg:table-cell">
              ${escapeHtml(p.fecha ?? "-")}
            </td>

            <td class="py-4 px-4">
              <div class="font-semibold text-slate-900 truncate max-w-[260px]">
                ${escapeHtml(p.cliente ?? "-")}
              </div>
            </td>

            <td class="py-4 px-4 font-extrabold text-slate-900 whitespace-nowrap">
              ${escapeHtml(p.total ?? "-")}
            </td>

            <td class="py-4 w-40 px-3">
              <button onclick="abrirModal(${id})"
                class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl bg-white border border-slate-200 shadow-sm
                       hover:shadow-md hover:border-slate-300 transition">
                <span class="h-2 w-2 rounded-full bg-blue-600"></span>
                <span class="text-[11px] font-extrabold uppercase tracking-wide text-slate-900">
                  ${renderEstado(p.estado ?? "-")}
                </span>
              </button>
            </td>

            <td class="py-4 px-4 hidden xl:table-cell" data-lastchange="${id}">
              ${renderLastChangeCompact(p)}
            </td>

            <td class="py-4 px-4">
              ${renderEtiquetasCompact(etiquetas, id)}
            </td>

            <td class="py-4 px-4 hidden lg:table-cell">
              <span class="inline-flex items-center justify-center px-3 py-1 rounded-full text-xs font-extrabold
                           bg-slate-50 border border-slate-200 text-slate-800 whitespace-nowrap">
                ${escapeHtml(p.articulos ?? "-")}
              </span>
            </td>

            <td class="py-4 px-4">
              ${renderEntregaPill(p.estado_envio ?? "-")}
            </td>

            <td class="py-4 px-4 hidden xl:table-cell">
              <span class="inline-flex items-center px-3 py-2 rounded-2xl bg-slate-50 border border-slate-200
                           text-[11px] font-extrabold uppercase tracking-wide text-slate-800 max-w-[220px] truncate"
                    title="${escapeHtml(p.forma_envio ?? "-")}">
                ${escapeHtml(p.forma_envio ?? "-")}
              </span>
            </td>

            <td class="py-4 px-4 text-right whitespace-nowrap">
              <button onclick="verDetalles(${id})"
                class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl bg-blue-600 text-white
                       text-[11px] font-extrabold uppercase tracking-wide shadow-sm hover:bg-blue-700 transition">
                Ver <span class="text-white/90">→</span>
              </button>
            </td>
          </tr>`;
        })
        .join("");
    }
  }

  // Cards (si existe el contenedor)
  if (cards) {
    cards.innerHTML = "";

    if (!pedidos.length) {
      cards.innerHTML = `<div class="py-10 text-center text-slate-500">No se encontraron pedidos</div>`;
      return;
    }

    cards.innerHTML = pedidos
      .map((p) => {
        const id = p.id ?? "";
        const numero = escapeHtml(p.numero ?? "-");
        const fecha = escapeHtml(p.fecha ?? "-");
        const cliente = escapeHtml(p.cliente ?? "-");
        const total = escapeHtml(p.total ?? "-");
        const etiquetas = p.etiquetas ?? "";

        return `
        <div class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
          <div class="p-4">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="text-sm font-extrabold text-slate-900">${numero}</div>
                <div class="text-xs text-slate-500 mt-0.5">${fecha}</div>
                <div class="text-sm font-semibold text-slate-800 mt-1 truncate">${cliente}</div>
              </div>
              <div class="text-right whitespace-nowrap">
                <div class="text-sm font-extrabold text-slate-900">${total}</div>
              </div>
            </div>

            <div class="mt-3 flex items-center justify-between gap-3">
              <button onclick="abrirModal(${id})"
                class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl bg-white border border-slate-200 shadow-sm">
                <span class="h-2 w-2 rounded-full bg-blue-600"></span>
                <span class="text-[11px] font-extrabold uppercase tracking-wide text-slate-900">
                  ${renderEstado(p.estado ?? "-")}
                </span>
              </button>

              <button onclick="verDetalles(${id})"
                class="px-3 py-2 rounded-2xl bg-blue-600 text-white text-[11px] font-extrabold uppercase tracking-wide">
                Ver →
              </button>
            </div>

            <div class="mt-3">${renderEntregaPill(p.estado_envio ?? "-")}</div>
            <div class="mt-3">${renderEtiquetasCompact(etiquetas, id, true)}</div>
          </div>
        </div>`;
      })
      .join("");
  }
}

// ===============================
// Modales
// ===============================

function openEstadoModal(orderId, estadoActual) {
  $("#modalEstado")?.classList.remove("hidden");
  $("#estadoOrderId").value = orderId;
  $("#estadoSelect").value = estadoActual || "Por preparar";
}

function closeEstadoModal() {
  $("#modalEstado")?.classList.add("hidden");
}


async function saveEstadoModal() {
  const id = $("#estadoOrderId").value;
  const estado = $("#estadoSelect").value;

  const res = await fetch("/dashboard/save-estado", {
    method: "POST",
    credentials: "include",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ id, estado })
  });

  const data = await res.json();
  if (!data.success) return alert(data.message || "No se pudo guardar estado");

  // refrescar solo la fila en UI
  updateRowLocal(id, { estado, last_status_change: { user_name: "Tú", changed_at: new Date().toISOString().slice(0,19).replace("T"," ") } });
  closeEstadoModal();
}

function openEtiquetasModal(orderId, tagsActuales) {
  $("#modalEtiquetas")?.classList.remove("hidden");
  $("#tagsOrderId").value = orderId;
  $("#tagsInput").value = tagsActuales || "";
}

function closeEtiquetasModal() {
  $("#modalEtiquetas")?.classList.add("hidden");
}

async function saveEtiquetasModal(syncShopify = true) {
  const id = $("#tagsOrderId").value;
  const tags = $("#tagsInput").value;

  // 1) guardar BD
  const res = await fetch("/dashboard/save-etiquetas", {
    method: "POST",
    credentials: "include",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ id, tags })
  });

  const data = await res.json();
  if (!data.success) return alert(data.message || "No se pudo guardar etiquetas");

  // 2) opcional: sync Shopify
  if (syncShopify) {
    const r2 = await fetch("/shopify/update-tags", {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id, tags })
    });
    const d2 = await r2.json();
    if (!d2.success) console.warn("No se sincronizó Shopify:", d2.error);
  }

  // refrescar fila
  updateRowLocal(id, { etiquetas: tags });
  closeEtiquetasModal();
}

// ===============================
// Update local row UI
// ===============================
function updateRowLocal(id, patch) {
  const key = String(id);
  const current = allOrdersMap.get(key) || { id };
  const updated = { ...current, ...patch };
  allOrdersMap.set(key, updated);

  // actualizar fila DOM
  const tr = document.querySelector(`tr[data-order-id="${CSS.escape(key)}"]`);
  if (!tr) return;

  // Estado button
  if (patch.estado !== undefined) {
    const btn = tr.querySelector(".btnEstado");
    if (btn) {
      btn.textContent = updated.estado || "-";
      btn.dataset.estado = updated.estado || "";
    }
  }

  // Etiquetas button
  if (patch.etiquetas !== undefined) {
    const btn = tr.querySelector(".btnEtiquetas");
    if (btn) {
      btn.textContent = (updated.etiquetas || "").slice(0, 35) || "Editar";
      btn.dataset.tags = updated.etiquetas || "";
    }
  }
}

function escapeHtml(s) {
  return String(s ?? "").replace(/[&<>"']/g, m => ({ "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;" }[m]));
}
function escapeAttr(s) { return escapeHtml(s).replace(/"/g, "&quot;"); }

// =====================================================
// ✅ EXPORTAR PARA onclick
// =====================================================
window.cargarPedidos = cargarPedidos;
window.paginaSiguiente = paginaSiguiente;
window.paginaAnterior = paginaAnterior;

window.abrirModal = abrirModal;
window.cerrarModal = cerrarModal;
window.guardarEstado = guardarEstado;

window.verDetalles = verDetalles;
window.cerrarModalDetalles = cerrarModalDetalles;
window.abrirPanelCliente = abrirPanelCliente;
window.cerrarPanelCliente = cerrarPanelCliente;

window.subirImagenProducto = subirImagenProducto;

window.abrirModalEtiquetas = abrirModalEtiquetas;
window.cerrarModalEtiquetas = cerrarModalEtiquetas;
window.guardarEtiquetas = guardarEtiquetas;
window.agregarEtiqueta = agregarEtiqueta;
window.eliminarEtiqueta = eliminarEtiqueta;
