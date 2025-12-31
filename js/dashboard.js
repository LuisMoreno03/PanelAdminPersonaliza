// =====================================================
// DASHBOARD.JS - ESTABLE + FALLBACK CI4
// - Pedidos siempre cargan
// - guardarEstado definido (fix ReferenceError)
// - ping/usuarios-estado si dan 500 NO rompen el dashboard
// =====================================================

let nextPageInfo = null;
let isLoading = false;

let etiquetasSeleccionadas = [];
window.imagenesCargadas = [];
window.imagenesLocales = {};

let pageHistory = [];

let userPingInterval = null;
let userStatusInterval = null;

// --------------------- Loader
function showLoader() {
  const el = document.getElementById("globalLoader");
  if (el) el.classList.remove("hidden");
}
function hideLoader() {
  const el = document.getElementById("globalLoader");
  if (el) el.classList.add("hidden");
}

// --------------------- Fetch robusto con fallback CI4
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

      if (!res.ok) {
        const txt = await res.text().catch(() => "");
        lastErr = new Error(`HTTP ${res.status} en ${url}\n${txt?.slice(0, 500)}`);
        continue;
      }

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

function ciFallback(path) {
  if (!String(path).startsWith("/")) return [path];
  return [path, "/index.php" + path];
}

// --------------------- Helpers
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

// --------------------- Entrega pill
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

// --------------------- timeAgo
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
// PEDIDOS (carga principal)
// =====================================================
async function cargarPedidos(pageInfo = null) {
  if (isLoading) return;
  isLoading = true;
  showLoader();

  let url = "/dashboard/filter";
  if (pageInfo) url += "?page_info=" + encodeURIComponent(pageInfo);

  const result = await fetchJsonWithFallback(ciFallback(url), { method: "GET" });

  if (!result.ok) {
    console.error("Error cargando pedidos:", result.error);
    isLoading = false;
    hideLoader();
    return;
  }

  const data = result.data;
  if (!data?.success) {
    console.warn("Respuesta cargarPedidos:", data);
    isLoading = false;
    hideLoader();
    return;
  }

  if (pageInfo) {
    if (pageHistory[pageHistory.length - 1] !== pageInfo) pageHistory.push(pageInfo);
  } else {
    pageHistory = [];
  }

  nextPageInfo = data.next_page_info ?? null;

  actualizarTabla(data.orders || []);

  const btnSig = document.getElementById("btnSiguiente");
  if (btnSig) {
    btnSig.disabled = !nextPageInfo;
    btnSig.classList.toggle("opacity-50", btnSig.disabled);
    btnSig.classList.toggle("cursor-not-allowed", btnSig.disabled);
  }

  const btnAnt = document.getElementById("btnAnterior");
  if (btnAnt) {
    btnAnt.disabled = pageHistory.length === 0;
    btnAnt.classList.toggle("opacity-50", btnAnt.disabled);
    btnAnt.classList.toggle("cursor-not-allowed", btnAnt.disabled);
  }

  const total = document.getElementById("total-pedidos");
  if (total) total.textContent = data.count ?? 0;

  isLoading = false;
  hideLoader();
}

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
// TABLA/CARDS
// =====================================================
function actualizarTabla(pedidos) {
  const tbody = document.getElementById("tablaPedidos");
  const cards = document.getElementById("cardsPedidos");

  if (!tbody && !cards) {
    console.warn("No existe #tablaPedidos ni #cardsPedidos (IDs en HTML).");
    return;
  }

  // Desktop tbody
  if (tbody) {
    tbody.innerHTML = "";

    if (!pedidos.length) {
      tbody.innerHTML = `
        <tr><td colspan="11" class="py-10 text-center text-slate-500">No se encontraron pedidos</td></tr>
      `;
    } else {
      tbody.innerHTML = pedidos.map((p) => {
        const id = p.id ?? "";
        const etiquetas = p.etiquetas ?? "";

        return `
          <tr class="border-b border-slate-100 hover:bg-slate-50/60 transition">
            <td class="py-4 px-4 font-extrabold text-slate-900 whitespace-nowrap">${escapeHtml(p.numero ?? "-")}</td>
            <td class="py-4 px-4 text-slate-600 whitespace-nowrap hidden lg:table-cell">${escapeHtml(p.fecha ?? "-")}</td>

            <td class="py-4 px-4">
              <div class="font-semibold text-slate-900 truncate max-w-[260px]">${escapeHtml(p.cliente ?? "-")}</div>
            </td>

            <td class="py-4 px-4 font-extrabold text-slate-900 whitespace-nowrap">${escapeHtml(p.total ?? "-")}</td>

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

            <td class="py-4 px-4">${renderEtiquetasCompact(etiquetas, id)}</td>

            <td class="py-4 px-4 hidden lg:table-cell">
              <span class="inline-flex items-center justify-center px-3 py-1 rounded-full text-xs font-extrabold
                           bg-slate-50 border border-slate-200 text-slate-800 whitespace-nowrap">
                ${escapeHtml(p.articulos ?? "-")}
              </span>
            </td>

            <td class="py-4 px-4">${renderEntregaPill(p.estado_envio ?? "-")}</td>

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
          </tr>
        `;
      }).join("");
    }
  }

  // Mobile cards
  if (cards) {
    cards.innerHTML = "";

    if (!pedidos.length) {
      cards.innerHTML = `<div class="py-10 text-center text-slate-500">No se encontraron pedidos</div>`;
      return;
    }

    cards.innerHTML = pedidos.map((p) => {
      const id = p.id ?? "";
      const etiquetas = p.etiquetas ?? "";

      return `
        <div class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
          <div class="p-4">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="text-sm font-extrabold text-slate-900">${escapeHtml(p.numero ?? "-")}</div>
                <div class="text-xs text-slate-500 mt-0.5">${escapeHtml(p.fecha ?? "-")}</div>
                <div class="text-sm font-semibold text-slate-800 mt-1 truncate">${escapeHtml(p.cliente ?? "-")}</div>
              </div>
              <div class="text-right whitespace-nowrap">
                <div class="text-sm font-extrabold text-slate-900">${escapeHtml(p.total ?? "-")}</div>
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
        </div>
      `;
    }).join("");
  }
}

// =====================================================
// ETIQUETAS (solo render aquí; tus modales quedan igual)
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

  const pills = visibles.map((tag) => {
    const cls = colorEtiqueta(tag);
    return `
      <span class="px-2.5 py-1.5 rounded-full text-[10px] font-extrabold uppercase tracking-wide border ${cls}
                   max-w-[120px] truncate" title="${escapeHtml(tag)}">
        ${escapeHtml(tag)}
      </span>
    `;
  }).join("");

  const more = rest > 0
    ? `<span class="px-2.5 py-1.5 rounded-full text-[10px] font-extrabold uppercase tracking-wide border bg-white border-slate-200 text-slate-700">+${rest}</span>`
    : "";

  return `
    <div class="flex flex-wrap items-center gap-2">
      ${pills}${more}
      <button onclick="abrirModalEtiquetas(${orderId}, '${escapeJsString(raw)}')"
        class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl
               bg-slate-900 text-white text-[11px] font-extrabold uppercase tracking-wide
               hover:bg-slate-800 transition shadow-sm whitespace-nowrap">
        Etiquetas <span class="text-white/80">✎</span>
      </button>
    </div>
  `;
}

// =====================================================
// MODAL ESTADO ✅ (FIX: guardarEstado DEFINED)
// =====================================================
function abrirModal(orderId) {
  const idInput = document.getElementById("modalOrderId");
  if (idInput) idInput.value = orderId;
  document.getElementById("modalEstado")?.classList.remove("hidden");
}

function cerrarModal() {
  document.getElementById("modalEstado")?.classList.add("hidden");
}

// ✅ ESTA ERA LA FUNCIÓN QUE TE FALTABA
async function guardarEstado(nuevoEstado) {
  const id = document.getElementById("modalOrderId")?.value;
  if (!id) {
    alert("No se encontró el ID del pedido.");
    return;
  }

  const result = await fetchJsonWithFallback(ciFallback("/api/estado/guardar"), {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ id, estado: nuevoEstado }),
  });

  if (!result.ok) {
    console.error("guardarEstado falló:", result.error);
    alert("No se pudo actualizar el estado. Revisa consola / logs.");
    return;
  }

  if (result.data?.success) {
    cerrarModal();
    cargarPedidos();
  } else {
    console.warn("Respuesta guardarEstado:", result.data);
    alert("No se pudo actualizar el estado.");
  }
}

// =====================================================
// USUARIOS (si fallan, no dañan pedidos)
// =====================================================
async function pingUsuario() {
  const res = await fetchJsonWithFallback(ciFallback("/dashboard/ping"), { method: "GET" });
  if (!res.ok) {
    // 500 por last_seen -> backend
    console.warn("pingUsuario:", res.error?.message || res.error);
  }
}

async function cargarUsuariosEstado() {
  const res = await fetchJsonWithFallback(ciFallback("/dashboard/usuarios-estado"), { method: "GET" });
  if (!res.ok) {
    console.warn("usuarios-estado:", res.error?.message || res.error);
    return;
  }
  if (res.data?.success) renderUsersStatus(res.data);
}

function renderUsersStatus(payload) {
  const users = payload?.users || [];

  const onlineList = document.getElementById("onlineUsers");
  const offlineList = document.getElementById("offlineUsers");
  const onlineCountEl = document.getElementById("onlineCount");
  const offlineCountEl = document.getElementById("offlineCount");

  if (!onlineList || !offlineList) return;

  onlineList.innerHTML = "";
  offlineList.innerHTML = "";

  let onlineCount = 0;
  let offlineCount = 0;

  users.forEach((u) => {
    const name = escapeHtml(u.nombre ?? "Usuario");
    const online = !!u.online;

    const li = document.createElement("li");
    li.className = "flex items-center gap-2";
    li.innerHTML = `
      <span class="h-2.5 w-2.5 rounded-full ${online ? "bg-emerald-500" : "bg-rose-500"}"></span>
      <span class="font-semibold text-slate-800">${name}</span>
    `;

    if (online) { onlineList.appendChild(li); onlineCount++; }
    else { offlineList.appendChild(li); offlineCount++; }
  });

  if (onlineCountEl) onlineCountEl.textContent = onlineCount;
  if (offlineCountEl) offlineCountEl.textContent = offlineCount;
}

// =====================================================
// INIT
// =====================================================
document.addEventListener("DOMContentLoaded", () => {
  const btnAnterior = document.getElementById("btnAnterior");
  if (btnAnterior) btnAnterior.addEventListener("click", (e) => { e.preventDefault(); paginaAnterior(); });

  const btnSiguiente = document.getElementById("btnSiguiente");
  if (btnSiguiente) btnSiguiente.addEventListener("click", (e) => { e.preventDefault(); paginaSiguiente(); });

  cargarPedidos();

  pingUsuario();
  userPingInterval = setInterval(pingUsuario, 30000);

  cargarUsuariosEstado();
  userStatusInterval = setInterval(cargarUsuariosEstado, 15000);
});

// =====================================================
// EXPORTS para onclick
// =====================================================
window.cargarPedidos = cargarPedidos;
window.paginaSiguiente = paginaSiguiente;
window.paginaAnterior = paginaAnterior;

window.abrirModal = abrirModal;
window.cerrarModal = cerrarModal;
window.guardarEstado = guardarEstado;

// Si tu modal de etiquetas ya existe en tu HTML:
window.abrirModalEtiquetas = window.abrirModalEtiquetas || function () {};
