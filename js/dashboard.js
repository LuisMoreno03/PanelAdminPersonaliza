// =====================================================
// DASHBOARD.JS (COMPLETO) - REAL TIME + PAGINACIÓN ESTABLE
// - Página 1: refresca en vivo (trae últimos 50 pedidos)
// - Si el usuario navega a página 2+: pausa live automáticamente
// - Paginación Shopify REAL (page_info)
// - Render Desktop: GRID (1 línea) sin scroll horizontal
// - Render Mobile/Tablet: CARDS
// =====================================================

/* =====================================================
   VARIABLES GLOBALES
===================================================== */
let nextPageInfo = null;
let prevPageInfo = null;
let isLoading = false;
let currentPage = 1;

// ✅ LIVE MODE
let liveMode = true;
let liveInterval = null;

let userPingInterval = null;
let userStatusInterval = null;

/* =====================================================
   Loader global
===================================================== */
function showLoader() {
  const el = document.getElementById("globalLoader");
  if (el) el.classList.remove("hidden");
}
function hideLoader() {
  const el = document.getElementById("globalLoader");
  if (el) el.classList.add("hidden");
}

/* =====================================================
   INIT
===================================================== */
document.addEventListener("DOMContentLoaded", () => {
  const btnAnterior = document.getElementById("btnAnterior");
  const btnSiguiente = document.getElementById("btnSiguiente");

  if (btnAnterior) {
    btnAnterior.addEventListener("click", (e) => {
      e.preventDefault();
      if (!btnAnterior.disabled) paginaAnterior();
    });
  }

  if (btnSiguiente) {
    btnSiguiente.addEventListener("click", (e) => {
      e.preventDefault();
      if (!btnSiguiente.disabled) paginaSiguiente();
    });
  }

  // Usuarios online/offline
  pingUsuario();
  userPingInterval = setInterval(pingUsuario, 30000);

  cargarUsuariosEstado();
  userStatusInterval = setInterval(cargarUsuariosEstado, 15000);

  // ✅ Inicial pedidos (página 1)
  resetToFirstPage({ withFetch: true });

  // ✅ LIVE refresca la página 1 cada 12s
  startLive(12000);

  // ✅ refresca render según ancho (desktop/cards)
  window.addEventListener("resize", () => {
    // solo fuerza re-render visual si ya hay contenido
    // (no vuelve a pedir al backend)
    const cont = document.getElementById("tablaPedidos");
    if (cont && cont.dataset.lastOrders) {
      try {
        const orders = JSON.parse(cont.dataset.lastOrders);
        actualizarTabla(orders);
      } catch {}
    }
  });
});

/* =====================================================
   LIVE CONTROL
===================================================== */
function startLive(ms = 12000) {
  if (liveInterval) clearInterval(liveInterval);

  liveInterval = setInterval(() => {
    if (liveMode && currentPage === 1 && !isLoading) {
      cargarPedidos({ reset: false, page_info: "" });
    }
  }, ms);
}

function pauseLive() { liveMode = false; }
function resumeLiveIfOnFirstPage() { if (currentPage === 1) liveMode = true; }

/* =====================================================
   HELPERS
===================================================== */
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

function stripHtml(html) {
  const d = document.createElement("div");
  d.innerHTML = String(html || "");
  return d.textContent || "";
}

/* =====================================================
   ENTREGA PILL
===================================================== */
function entregaStyle(estado) {
  const s = String(estado || "").toLowerCase().trim();

  if (!s || s === "-" || s === "null") {
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
                 shadow-sm font-extrabold text-[11px] uppercase tracking-wide whitespace-nowrap">
      <span class="h-2.5 w-2.5 rounded-full ${st.dot}"></span>
      <span class="text-sm leading-none">${st.icon}</span>
      <span class="leading-none">${escapeHtml(st.label)}</span>
    </span>
  `;
}

/* =====================================================
   PÍLDORA PÁGINA
===================================================== */
function setPaginaUI({ totalPages = null } = {}) {
  const pill = document.getElementById("pillPagina");
  if (pill) pill.textContent = `Página ${currentPage}`;

  const pillTotal = document.getElementById("pillPaginaTotal");
  if (pillTotal) {
    if (totalPages) pillTotal.textContent = `Página ${currentPage} de ${totalPages}`;
    else pillTotal.textContent = `Página ${currentPage}`;
  }
}

/* =====================================================
   RESET a página 1
===================================================== */
function resetToFirstPage({ withFetch = false } = {}) {
  currentPage = 1;
  nextPageInfo = null;
  prevPageInfo = null;
  liveMode = true;

  setPaginaUI({ totalPages: null });
  actualizarControlesPaginacion();

  if (withFetch) cargarPedidos({ reset: true, page_info: "" });
}

/* =====================================================
   CARGAR PEDIDOS (50 en 50)
===================================================== */
function cargarPedidos({ page_info = "", reset = false } = {}) {
  if (isLoading) return;
  isLoading = true;
  showLoader();

  const base = "/index.php/dashboard/pedidos";
  const fallback = "/index.php/dashboard/filter";

  if (reset) {
    currentPage = 1;
    nextPageInfo = null;
    prevPageInfo = null;
    page_info = "";
    liveMode = true;
  }

  const buildUrl = (endpoint) => {
    const u = new URL(endpoint, window.location.origin);
    u.searchParams.set("page", String(currentPage));
    if (page_info) u.searchParams.set("page_info", page_info);
    return u.toString();
  };

  fetch(buildUrl(base), { headers: { Accept: "application/json" } })
    .then(async (res) => {
      if (res.status === 404) {
        const r2 = await fetch(buildUrl(fallback), { headers: { Accept: "application/json" } });
        return r2.json();
      }
      return res.json();
    })
    .then((data) => {
      if (!data || !data.success) {
        actualizarTabla([]);
        nextPageInfo = null;
        prevPageInfo = null;
        actualizarControlesPaginacion();
        setPaginaUI({ totalPages: null });
        return;
      }

      nextPageInfo = data.next_page_info ?? null;
      prevPageInfo = data.prev_page_info ?? null;

      actualizarTabla(data.orders || []);

      // total pedidos (si tienes el badge)
      const total = document.getElementById("total-pedidos");
      if (total) total.textContent = (data.total_orders ?? data.count ?? 0);

      setPaginaUI({ totalPages: data.total_pages ?? null });
      actualizarControlesPaginacion();
    })
    .catch((err) => {
      console.error("Error cargando pedidos:", err);
      actualizarTabla([]);
      nextPageInfo = null;
      prevPageInfo = null;
      actualizarControlesPaginacion();
      setPaginaUI({ totalPages: null });
    })
    .finally(() => {
      isLoading = false;
      hideLoader();
    });
}

/* =====================================================
   CONTROLES PAGINACIÓN
===================================================== */
function actualizarControlesPaginacion() {
  const btnSig = document.getElementById("btnSiguiente");
  const btnAnt = document.getElementById("btnAnterior");

  if (btnSig) {
    btnSig.disabled = !nextPageInfo;
    btnSig.classList.toggle("opacity-50", btnSig.disabled);
    btnSig.classList.toggle("cursor-not-allowed", btnSig.disabled);
  }

  if (btnAnt) {
    btnAnt.disabled = !prevPageInfo || currentPage <= 1;
    btnAnt.classList.toggle("opacity-50", btnAnt.disabled);
    btnAnt.classList.toggle("cursor-not-allowed", btnAnt.disabled);
  }
}

function paginaSiguiente() {
  if (!nextPageInfo) return;
  pauseLive();
  currentPage += 1;
  cargarPedidos({ page_info: nextPageInfo });
}

function paginaAnterior() {
  if (!prevPageInfo || currentPage <= 1) return;
  currentPage -= 1;
  cargarPedidos({ page_info: prevPageInfo });
  resumeLiveIfOnFirstPage();
}

/* =====================================================
   RENDER ÚLTIMO CAMBIO (compacto)
===================================================== */
function renderLastChangeCompact(p) {
  const info = p?.last_status_change;
  if (!info || !info.changed_at) return "—";

  const user = info.user_name ? escapeHtml(info.user_name) : "—";
  const ago = timeAgo(info.changed_at);

  // ✅ en desktop: 2 líneas reales, sin HTML raro
  return `
    <div class="leading-tight">
      <div class="text-[12px] font-bold text-slate-900 truncate">${user}</div>
      <div class="text-[11px] text-slate-500">${escapeHtml(ago)}</div>
    </div>
  `;
}

/* =====================================================
   TIME AGO
===================================================== */
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

/* =====================================================
   ETIQUETAS
===================================================== */
function renderEtiquetasCompact(etiquetas, orderId, mobile = false) {
  const raw = String(etiquetas || "").trim();
  const list = raw ? raw.split(",").map((t) => t.trim()).filter(Boolean) : [];

  const max = mobile ? 3 : 2;
  const visibles = list.slice(0, max);
  const rest = list.length - visibles.length;

  const pills = visibles.map((tag) => {
    const cls = colorEtiqueta(tag);
    return `<span class="px-2.5 py-1.5 rounded-full text-[10px] font-extrabold uppercase tracking-wide border ${cls}">
      ${escapeHtml(tag)}
    </span>`;
  }).join("");

  const more = rest > 0
    ? `<span class="px-2.5 py-1.5 rounded-full text-[10px] font-extrabold uppercase tracking-wide border bg-white border-slate-200 text-slate-700">
      +${rest}
    </span>`
    : "";

  const onClick = typeof window.abrirModalEtiquetas === "function"
    ? `abrirModalEtiquetas(${orderId}, '${escapeJsString(raw)}')`
    : `alert('Falta implementar abrirModalEtiquetas()');`;

  if (!list.length) {
    return `
      <button onclick="${onClick}"
        class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl
               bg-white border border-slate-200 text-slate-900 text-[11px] font-extrabold uppercase tracking-wide
               hover:shadow-md transition whitespace-nowrap">
        Etiquetas <span class="text-blue-700">＋</span>
      </button>`;
  }

  return `
    <div class="flex flex-wrap items-center gap-2">
      ${pills}${more}
      <button onclick="${onClick}"
        class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl
               bg-slate-900 text-white text-[11px] font-extrabold uppercase tracking-wide
               hover:bg-slate-800 transition shadow-sm whitespace-nowrap">
        Etiquetas <span class="text-white/80">✎</span>
      </button>
    </div>`;
}

function colorEtiqueta(tag) {
  tag = String(tag).toLowerCase().trim();
  if (tag.startsWith("d.")) return "bg-emerald-50 border-emerald-200 text-emerald-900";
  if (tag.startsWith("p.")) return "bg-amber-50 border-amber-200 text-amber-900";
  return "bg-slate-50 border-slate-200 text-slate-800";
}

/* =====================================================
   TABLA / GRID + CARDS
===================================================== */
function actualizarTabla(pedidos) {
  const cont = document.getElementById("tablaPedidos");
  const cards = document.getElementById("cardsPedidos");

  // guardo para re-render al resize sin volver a pedir
  if (cont) cont.dataset.lastOrders = JSON.stringify(pedidos || []);

  // decide modo por ancho
  const useCards = window.innerWidth <= 1180;

  // ---------- DESKTOP GRID (1 línea) ----------
  if (cont) {
    cont.innerHTML = "";

    if (useCards) {
      // si estamos en modo cards, limpio desktop
      cont.innerHTML = "";
    } else {
      if (!pedidos.length) {
        cont.innerHTML = `<div class="p-8 text-center text-slate-500">No se encontraron pedidos</div>`;
      } else {
        cont.innerHTML = pedidos.map((p) => {
          const id = p.id ?? "";
          const etiquetas = p.etiquetas ?? "";

          return `
          <div class="orders-grid px-4 py-3 text-[13px] border-b hover:bg-slate-50 transition">
            <!-- Pedido -->
            <div class="font-extrabold text-slate-900 whitespace-nowrap">
              ${escapeHtml(p.numero ?? "-")}
            </div>

            <!-- Fecha -->
            <div class="text-slate-600 whitespace-nowrap">
              ${escapeHtml(p.fecha ?? "-")}
            </div>

            <!-- Cliente -->
            <div class="font-semibold text-slate-800 truncate">
              ${escapeHtml(p.cliente ?? "-")}
            </div>

            <!-- Total -->
            <div class="font-extrabold text-slate-900 whitespace-nowrap">
              ${escapeHtml(p.total ?? "-")}
            </div>

            <!-- Estado -->
            <div class="whitespace-nowrap">
              <button onclick="abrirModal(${id})"
                class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl bg-white border border-slate-200 shadow-sm">
                <span class="h-2 w-2 rounded-full bg-blue-600"></span>
                <span class="text-[11px] font-extrabold uppercase tracking-wide text-slate-900">
                  ${renderEstado(p.estado ?? "-")}
                </span>
              </button>
            </div>

            <!-- Último cambio -->
            <div class="min-w-0">
              ${renderLastChangeCompact(p)}
            </div>

            <!-- Etiquetas -->
            <div class="min-w-0">
              ${renderEtiquetasCompact(etiquetas, id)}
            </div>

            <!-- Art -->
            <div class="text-center font-extrabold">
              ${escapeHtml(p.articulos ?? "-")}
            </div>

            <!-- Entrega -->
            <div class="whitespace-nowrap">
              ${renderEntregaPill(p.estado_envio ?? "-")}
            </div>

            <!-- Forma -->
            <div class="text-xs text-slate-700 truncate">
              ${escapeHtml(p.forma_envio ?? "-")}
            </div>

            <!-- Ver -->
            <div class="text-right whitespace-nowrap">
              <button onclick="verDetalles(${id})"
                class="px-3 py-2 rounded-2xl bg-blue-600 text-white text-[11px] font-extrabold uppercase tracking-wide">
                Ver →
              </button>
            </div>
          </div>`;
        }).join("");
      }
    }
  }

  // ---------- CARDS (tablet/móvil) ----------
  if (cards) {
    cards.innerHTML = "";

    if (!useCards) {
      // si estamos en modo desktop, limpio cards
      cards.innerHTML = "";
      return;
    }

    if (!pedidos.length) {
      cards.innerHTML = `<div class="p-8 text-center text-slate-500">No se encontraron pedidos</div>`;
      return;
    }

    cards.innerHTML = pedidos.map((p) => {
      const id = p.id ?? "";
      const numero = escapeHtml(p.numero ?? "-");
      const fecha = escapeHtml(p.fecha ?? "-");
      const cliente = escapeHtml(p.cliente ?? "-");
      const total = escapeHtml(p.total ?? "-");
      const etiquetas = p.etiquetas ?? "";

      const last = p?.last_status_change?.changed_at
        ? `${escapeHtml(p.last_status_change.user_name ?? "—")} · ${escapeHtml(timeAgo(p.last_status_change.changed_at))}`
        : "—";

      return `
        <div class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden mb-3">
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

            <div class="mt-3 text-xs text-slate-600 space-y-1">
              <div><b>Artículos:</b> ${escapeHtml(p.articulos ?? "-")}</div>
              <div><b>Forma:</b> ${escapeHtml(p.forma_envio ?? "-")}</div>
              <div><b>Último cambio:</b> ${last}</div>
            </div>
          </div>
        </div>`;
    }).join("");
  }
}

/* =====================================================
   MODAL ESTADO
===================================================== */
function abrirModal(orderId) {
  const idInput = document.getElementById("modalOrderId");
  if (idInput) idInput.value = orderId;

  const modal = document.getElementById("modalEstado");
  if (modal) modal.classList.remove("hidden");
}
function cerrarModal() {
  const modal = document.getElementById("modalEstado");
  if (modal) modal.classList.add("hidden");
}

async function guardarEstado(nuevoEstado) {
  const id = document.getElementById("modalOrderId")?.value;

  const r = await fetch("/api/estado/guardar", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ id, estado: nuevoEstado }),
  });

  const d = await r.json().catch(() => null);

  if (d?.success) {
    cerrarModal();
    resetToFirstPage({ withFetch: true });
  }
}

/* =====================================================
   DETALLES (tu función real está en otro lado)
===================================================== */
function abrirModalDetalles() {
  document.getElementById("modalDetalles")?.classList.remove("hidden");
}
function cerrarModalDetalles() {
  document.getElementById("modalDetalles")?.classList.add("hidden");
}

/* =====================================================
   USERS STATUS
===================================================== */
async function pingUsuario() {
  try { await fetch("/dashboard/ping", { headers: { Accept: "application/json" } }); } catch (e) {}
}

async function cargarUsuariosEstado() {
  try {
    const r = await fetch("/dashboard/usuarios-estado", { headers: { Accept: "application/json" } });
    const d = await r.json().catch(() => null);
    if (!d) return;

    const ok = d.ok === true || d.success === true;
    if (ok) {
      if (typeof window.renderUsersStatus === "function") window.renderUsersStatus(d);
      else if (typeof window.renderUserStatus === "function") window.renderUserStatus(d);
    }
  } catch (e) {
    console.error("Error usuarios estado:", e);
  }
}
