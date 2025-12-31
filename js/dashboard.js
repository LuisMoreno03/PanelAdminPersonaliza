// =====================================================
// DASHBOARD.JS - ESTABLE + CI4 AJAX FIX + FALLBACK
// - Fix clave: X-Requested-With para que CI4 devuelva JSON (request->isAJAX())
// - Pedidos: /dashboard/filter + fallback /index.php/dashboard/filter
// - Ping/usuarios-estado si dan 500 NO rompen ni spamean
// =====================================================

let nextPageInfo = null;
let isLoading = false;

let pageHistory = [];
let userPingInterval = null;
let userStatusInterval = null;

// =====================================================
// LOADER
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
// HELPERS
// =====================================================
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
// CI4 FALLBACK
// =====================================================
function ciFallback(path) {
  if (!String(path).startsWith("/")) return [path];
  return [path, "/index.php" + path];
}

// =====================================================
// FETCH ROBUSTO (JSON + debug HTML)
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
          "X-Requested-With": "XMLHttpRequest", // ✅ CLAVE para CI4 isAJAX()
          ...(options.headers || {}),
        },
      });

      // 500 / 404 / etc
      if (!res.ok) {
        const txt = await res.text().catch(() => "");
        lastErr = new Error(`HTTP ${res.status} en ${url}\n${txt.slice(0, 1000)}`);
        continue;
      }

      // Intentar JSON, si no, leer texto y tirar error útil
      const contentType = (res.headers.get("content-type") || "").toLowerCase();

      if (!contentType.includes("application/json")) {
        const txt = await res.text().catch(() => "");
        // si te devolvió HTML, aquí lo verás
        lastErr = new Error(
          `Respuesta NO JSON (content-type: ${contentType}) en ${url}\n` +
          txt.slice(0, 1200)
        );
        continue;
      }

      const data = await res.json().catch(async () => {
        const txt = await res.text().catch(() => "");
        throw new Error(`JSON inválido en ${url}\n${txt.slice(0, 1200)}`);
      });

      return { ok: true, url, data };
    } catch (e) {
      lastErr = e;
    }
  }

  return { ok: false, error: lastErr };
}

// =====================================================
// ENTREGA (pill visible)
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
// ETIQUETAS (compacto)
// =====================================================
function colorEtiqueta(tag) {
  tag = String(tag).toLowerCase().trim();
  if (tag.startsWith("d.")) return "bg-emerald-50 border-emerald-200 text-emerald-900";
  if (tag.startsWith("p.")) return "bg-amber-50 border-amber-200 text-amber-900";
  return "bg-slate-50 border-slate-200 text-slate-800";
}

function renderEtiquetasCompact(etiquetas, orderId, mobile = false) {
  const raw = String(etiquetas || "").trim();
  const list = raw ? raw.split(",").map(t => t.trim()).filter(Boolean) : [];

  const max = mobile ? 3 : 2;
  const visibles = list.slice(0, max);
  const rest = list.length - visibles.length;

  const pills = visibles.map(tag => {
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
      <button onclick="window.abrirModalEtiquetas?.(${orderId}, '${escapeJsString(raw)}')"
        class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl
               bg-slate-900 text-white text-[11px] font-extrabold uppercase tracking-wide
               hover:bg-slate-800 transition shadow-sm whitespace-nowrap">
        Etiquetas <span class="text-white/80">✎</span>
      </button>
    </div>
  `;
}

// =====================================================
// CARGAR PEDIDOS
// =====================================================
async function cargarPedidos(pageInfo = null) {
  if (isLoading) return;
  isLoading = true;
  showLoader();

  let url = "/dashboard/filter";
  if (pageInfo) url += "?page_info=" + encodeURIComponent(pageInfo);

  const result = await fetchJsonWithFallback(ciFallback(url), { method: "GET" });

  if (!result.ok) {
    console.error("cargarPedidos falló:", result.error);
    actualizarTabla([], `Error cargando pedidos.\n${result.error?.message || result.error}`);
    isLoading = false;
    hideLoader();
    return;
  }

  const data = result.data;

  if (!data?.success) {
    console.warn("Respuesta cargarPedidos (no success):", data);
    actualizarTabla([], "No se pudo cargar pedidos (success=false).");
    isLoading = false;
    hideLoader();
    return;
  }

  // historial paginación
  if (pageInfo) {
    if (pageHistory[pageHistory.length - 1] !== pageInfo) pageHistory.push(pageInfo);
  } else {
    pageHistory = [];
  }

  nextPageInfo = data.next_page_info ?? null;

  actualizarTabla(data.orders || [], null);

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
// TABLA (con mensaje opcional)
// =====================================================
function actualizarTabla(pedidos, msg = null) {
  const tbody = document.getElementById("tablaPedidos");
  const cards = document.getElementById("cardsPedidos");

  if (tbody) {
    tbody.innerHTML = "";

    if (msg) {
      tbody.innerHTML = `
        <tr>
          <td colspan="11" class="py-10 text-center text-rose-600 font-bold">
            ${escapeHtml(msg)}
          </td>
        </tr>`;
      return;
    }

    if (!pedidos.length) {
      tbody.innerHTML = `
        <tr>
          <td colspan="11" class="py-10 text-center text-slate-500">
            No se encontraron pedidos
          </td>
        </tr>`;
      return;
    }

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
            <button onclick="window.abrirModal?.(${id})"
              class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl bg-white border border-slate-200 shadow-sm
                     hover:shadow-md hover:border-slate-300 transition">
              <span class="h-2 w-2 rounded-full bg-blue-600"></span>
              <span class="text-[11px] font-extrabold uppercase tracking-wide text-slate-900">
                ${renderEstado(p.estado ?? "-")}
              </span>
            </button>
          </td>

          <td class="py-4 px-4 hidden xl:table-cell">${p?.last_status_change?.changed_at ? "—" : "—"}</td>

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
            <button onclick="window.verDetalles?.(${id})"
              class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl bg-blue-600 text-white
                     text-[11px] font-extrabold uppercase tracking-wide shadow-sm hover:bg-blue-700 transition">
              Ver <span class="text-white/90">→</span>
            </button>
          </td>
        </tr>
      `;
    }).join("");
  }

  // Cards (si existe contenedor)
  if (cards) {
    cards.innerHTML = "";
    if (msg) {
      cards.innerHTML = `<div class="py-10 text-center text-rose-600 font-bold">${escapeHtml(msg)}</div>`;
      return;
    }
    if (!pedidos.length) {
      cards.innerHTML = `<div class="py-10 text-center text-slate-500">No se encontraron pedidos</div>`;
      return;
    }
  }
}

// =====================================================
// USUARIOS / PING (silenciosos)
// =====================================================
let _pingFails = 0;
let _usersFails = 0;

async function pingUsuario() {
  const res = await fetchJsonWithFallback(ciFallback("/dashboard/ping"), { method: "GET" });
  if (!res.ok) {
    _pingFails++;
    if (_pingFails >= 3) {
      // deja de spamear si está roto
      clearInterval(userPingInterval);
      userPingInterval = null;
    }
  } else {
    _pingFails = 0;
  }
}

async function cargarUsuariosEstado() {
  const res = await fetchJsonWithFallback(ciFallback("/dashboard/usuarios-estado"), { method: "GET" });
  if (!res.ok) {
    _usersFails++;
    if (_usersFails >= 3) {
      clearInterval(userStatusInterval);
      userStatusInterval = null;
    }
  } else {
    _usersFails = 0;
  }
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

  // Si backend de usuarios está roto, se auto-detiene
  pingUsuario();
  userPingInterval = setInterval(pingUsuario, 30000);

  cargarUsuariosEstado();
  userStatusInterval = setInterval(cargarUsuariosEstado, 15000);
});

// =====================================================
// EXPORTS
// =====================================================
window.cargarPedidos = cargarPedidos;
window.paginaSiguiente = paginaSiguiente;
window.paginaAnterior = paginaAnterior;
