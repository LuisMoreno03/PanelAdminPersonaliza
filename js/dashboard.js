// =====================================================
// DASHBOARD.JS (COMPLETO) — Moderno + cómodo + rápido
// - Tabla optimizada (zebra + hover + acciones claras)
// - Responsive real: columnas se esconden en pantallas pequeñas (sin scroll horizontal)
// - Cards móviles (si existe #cardsPedidos)
// - Usuarios online/offline (ping + estado)
// =====================================================

// =====================================================
// VARIABLES GLOBALES
// =====================================================
let nextPageInfo = null;
let isLoading = false;
let etiquetasSeleccionadas = [];
window.imagenesCargadas = [];
window.imagenesLocales = {}; // imágenes locales por índice

// Historial page_info para botón "Anterior"
let pageHistory = [];

// Intervalos usuarios
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
// INIT
// =====================================================
document.addEventListener("DOMContentLoaded", () => {
  // Botón Anterior
  const btnAnterior = document.getElementById("btnAnterior");
  if (btnAnterior) {
    btnAnterior.addEventListener("click", (e) => {
      e.preventDefault();
      if (!btnAnterior.disabled) paginaAnterior();
    });
  }

  // Usuarios: ping + estado
  pingUsuario();
  userPingInterval = setInterval(pingUsuario, 30000); // 30s

  cargarUsuariosEstado();
  userStatusInterval = setInterval(cargarUsuariosEstado, 15000); // 15s

  // Pedidos
  cargarPedidos();
});

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

// Inicial para avatar (A, B, C…) desde nombre
function inicialNombre(nombre) {
  const s = String(nombre ?? "").trim();
  if (!s) return "U";
  const parts = s.split(/\s+/).filter(Boolean);
  const first = parts[0]?.[0] ?? "U";
  const second = parts.length > 1 ? parts[parts.length - 1]?.[0] : "";
  return (first + second).toUpperCase();
}

// Detecta si el “estado” viene como HTML de badge
function esBadgeHtml(valor) {
  const s = String(valor ?? "").trim();
  return s.startsWith("<span") || s.includes("<span") || s.includes("</span>");
}

// Render seguro del estado
function renderEstado(valor) {
  if (esBadgeHtml(valor)) return String(valor);
  return escapeHtml(valor ?? "-");
}

// =====================================================
// CARGAR PEDIDOS
// =====================================================
function cargarPedidos(pageInfo = null) {
  if (isLoading) return;
  isLoading = true;

  showLoader();

  let url = "/dashboard/filter";
  if (pageInfo) url += "?page_info=" + encodeURIComponent(pageInfo);

  fetch(url, { headers: { Accept: "application/json" } })
    .then((res) => res.json())
    .then((data) => {
      if (!data || !data.success) return;

      // historial
      if (pageInfo) {
        if (pageHistory[pageHistory.length - 1] !== pageInfo) {
          pageHistory.push(pageInfo);
        }
      } else {
        pageHistory = [];
      }

      nextPageInfo = data.next_page_info ?? null;

      actualizarTabla(data.orders || []);

      // botones paginación
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
    })
    .catch((err) => console.error("Error cargando pedidos:", err))
    .finally(() => {
      isLoading = false;
      hideLoader();
    });
}

// =====================================================
// SIGUIENTE / ANTERIOR
// =====================================================
function paginaSiguiente() {
  if (nextPageInfo) cargarPedidos(nextPageInfo);
}

function paginaAnterior() {
  if (pageHistory.length === 0) {
    cargarPedidos(null);
    return;
  }
  pageHistory.pop();
  const prev = pageHistory.length ? pageHistory[pageHistory.length - 1] : null;
  cargarPedidos(prev);
}

// =====================================================
// TABLA + CARDS (MODERNO + RÁPIDO)
// - Mantiene tbody único (#tablaPedidos)
// - En pantallas pequeñas, ocultamos columnas pesadas con hidden lg/xl/2xl
// - Cards móviles si existe #cardsPedidos
// =====================================================
function actualizarTabla(pedidos) {
  const tbody = document.getElementById("tablaPedidos");
  const cards = document.getElementById("cardsPedidos");

  // ==========================
  // DESKTOP TABLE
  // ==========================
  if (tbody) {
    tbody.innerHTML = "";

    if (!pedidos.length) {
      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td colspan="11" class="py-10 text-center text-slate-500">
          No se encontraron pedidos
        </td>`;
      tbody.appendChild(tr);
    } else {
      const frag = document.createDocumentFragment();

      pedidos.forEach((p, idx) => {
        const id = p.id ?? "";
        const numero = escapeHtml(p.numero ?? "-");
        const fecha = escapeHtml(p.fecha ?? "-");
        const cliente = escapeHtml(p.cliente ?? "-");
        const total = escapeHtml(p.total ?? "-");
        const articulos = escapeHtml(p.articulos ?? "-");
        const estadoEnvio = escapeHtml(p.estado_envio ?? "-");
        const formaEnvio = escapeHtml(p.forma_envio ?? "-");

        const tr = document.createElement("tr");

        tr.className =
          `border-b border-slate-100 transition
           ${idx % 2 === 0 ? "bg-white" : "bg-slate-50/50"}
           hover:bg-blue-50/40`;

        tr.innerHTML = `
          <!-- Pedido -->
          <td class="py-4 px-4">
            <div class="flex items-center gap-3">
              <div class="h-10 w-10 rounded-2xl bg-white border border-slate-200 shadow-sm flex items-center justify-center">
                <span class="text-slate-700">🧾</span>
              </div>
              <div class="min-w-0">
                <div class="font-extrabold text-slate-900 truncate">${numero}</div>
                <div class="text-xs text-slate-500 truncate">
                  ID: <span class="font-mono">${escapeHtml(String(id))}</span>
                </div>
              </div>
            </div>
          </td>

          <!-- Fecha (solo lg+) -->
          <td class="py-4 px-4 text-slate-700 font-medium hidden lg:table-cell">
            ${fecha}
          </td>

          <!-- Cliente -->
          <td class="py-4 px-4">
            <div class="flex items-center gap-3">
              <div class="h-9 w-9 rounded-xl bg-slate-900 text-white flex items-center justify-center text-xs font-extrabold">
                ${inicialNombre(cliente)}
              </div>
              <div class="min-w-0">
                <div class="font-semibold text-slate-900 truncate">${cliente}</div>
                <div class="text-xs text-slate-500">Cliente</div>
              </div>
            </div>
          </td>

          <!-- Total -->
          <td class="py-4 px-4 text-right">
            <div class="font-extrabold text-slate-900">${total}</div>
            <div class="text-xs text-slate-500">${articulos} art.</div>
          </td>

          <!-- Estado -->
          <td class="py-4 px-4">
            <button onclick="abrirModal(${id})"
              class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl bg-white border border-slate-200 shadow-sm
                     hover:shadow-md hover:border-slate-300 transition font-semibold text-slate-800">
              ${renderEstado(p.estado ?? "-")}
              <span class="text-slate-400">✎</span>
            </button>
          </td>

          <!-- Último cambio (solo xl+) -->
          <td class="py-4 px-4 hidden xl:table-cell" data-lastchange="${id}">
            ${renderLastChange(p)}
          </td>

          <!-- Etiquetas (solo 2xl+) -->
          <td class="py-4 px-4 hidden 2xl:table-cell">
            ${formatearEtiquetas(p.etiquetas ?? "", id)}
          </td>

          <!-- Artículos (solo xl+) -->
          <td class="py-4 px-4 hidden xl:table-cell text-center">
            <span class="inline-flex px-3 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-700">
              ${articulos}
            </span>
          </td>

          <!-- Estado entrega (solo xl+) -->
          <td class="py-4 px-4 hidden xl:table-cell">
            <span class="inline-flex px-3 py-1 rounded-full text-xs font-bold bg-indigo-50 text-indigo-800 border border-indigo-100">
              ${estadoEnvio}
            </span>
          </td>

          <!-- Forma entrega (solo 2xl+) -->
          <td class="py-4 px-4 hidden 2xl:table-cell text-slate-700">
            ${formaEnvio}
          </td>

          <!-- Detalles -->
          <td class="py-4 px-4 text-right">
            <button onclick="verDetalles(${id})"
              class="inline-flex items-center justify-center px-4 py-2 rounded-2xl bg-blue-600 text-white text-sm font-extrabold
                     hover:bg-blue-700 active:scale-[0.99] transition shadow-sm">
              Ver
            </button>
          </td>
        `;

        frag.appendChild(tr);
      });

      tbody.appendChild(frag);
    }
  }

  // ==========================
  // MOBILE CARDS (si existe)
  // ==========================
  if (cards) {
    cards.innerHTML = "";

    if (!pedidos.length) {
      cards.innerHTML = `<div class="py-10 text-center text-slate-500">No se encontraron pedidos</div>`;
      return;
    }

    const html = pedidos
      .map((p) => {
        const id = p.id ?? "";
        const numero = escapeHtml(p.numero ?? "-");
        const fecha = escapeHtml(p.fecha ?? "-");
        const cliente = escapeHtml(p.cliente ?? "-");
        const total = escapeHtml(p.total ?? "-");
        const envio = escapeHtml(p.forma_envio ?? "-");
        const estadoEnvio = escapeHtml(p.estado_envio ?? "-");
        const articulos = escapeHtml(p.articulos ?? "0");

        return `
        <div class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden hover:shadow-md transition">
          <div class="p-4">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="flex items-center gap-2">
                  <span class="inline-flex h-9 w-9 items-center justify-center rounded-2xl bg-white border border-slate-200 shadow-sm">🧾</span>
                  <div class="min-w-0">
                    <div class="text-sm font-extrabold text-slate-900 truncate">${numero}</div>
                    <div class="text-xs text-slate-500 mt-0.5">${fecha}</div>
                  </div>
                </div>

                <div class="mt-3 flex items-center gap-2">
                  <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-slate-900 text-white text-xs font-extrabold">
                    ${inicialNombre(cliente)}
                  </span>
                  <div class="text-sm font-semibold text-slate-800 truncate">${cliente}</div>
                </div>
              </div>

              <div class="text-right">
                <div class="text-sm font-extrabold text-slate-900">${total}</div>
                <div class="text-xs text-slate-500 mt-0.5">${articulos} artículos</div>
              </div>
            </div>

            <div class="mt-4 flex items-center justify-between gap-3">
              <button onclick="abrirModal(${id})"
                class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl bg-white border border-slate-200 shadow-sm font-semibold text-slate-800">
                ${renderEstado(p.estado ?? "-")}
                <span class="text-slate-400">✎</span>
              </button>

              <button onclick="verDetalles(${id})"
                class="px-4 py-2 rounded-2xl bg-blue-600 text-white text-sm font-extrabold shadow-sm">
                Ver
              </button>
            </div>

            <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50/60 p-3 text-sm">
              <div class="flex items-center justify-between gap-3">
                <span class="text-slate-500">Entrega</span>
                <span class="font-semibold text-slate-800">${estadoEnvio}</span>
              </div>
              <div class="flex items-center justify-between gap-3 mt-1">
                <span class="text-slate-500">Forma</span>
                <span class="font-semibold text-slate-800">${envio}</span>
              </div>
            </div>

            <div class="mt-4" data-lastchange="${id}">
              ${renderLastChange(p)}
            </div>

            <div class="mt-4">
              <div class="text-xs uppercase tracking-wide text-slate-500 mb-2">Etiquetas</div>
              ${formatearEtiquetas(p.etiquetas ?? "", id)}
            </div>
          </div>
        </div>`;
      })
      .join("");

    cards.innerHTML = html;
  }
}

// =====================================================
// ETIQUETAS
// =====================================================
function formatearEtiquetas(etiquetas, orderId) {
  if (!etiquetas) {
    return `<button onclick="abrirModalEtiquetas(${orderId}, '')"
            class="inline-flex items-center gap-2 text-blue-600 font-semibold underline">
            + Agregar
            </button>`;
  }

  let lista = String(etiquetas)
    .split(",")
    .map((t) => t.trim())
    .filter(Boolean);

  const tagsHtml = lista
    .map((tag) => {
      const cls = colorEtiqueta(tag);
      return `<span class="px-2.5 py-1 rounded-full text-xs font-bold ${cls}">
        ${escapeHtml(tag)}
      </span>`;
    })
    .join("");

  return `
    <div class="flex flex-wrap gap-2 items-center">
      ${tagsHtml}
      <button onclick="abrirModalEtiquetas(${orderId}, '${escapeJsString(etiquetas)}')"
              class="text-blue-600 underline text-xs font-semibold ml-1">
        Editar
      </button>
    </div>`;
}

function abrirModalEtiquetas(orderId, textos = "") {
  const idInput = document.getElementById("modalTagOrderId");
  if (!idInput) return;

  idInput.value = orderId;

  etiquetasSeleccionadas = textos
    ? String(textos).split(",").map((s) => s.trim()).filter(Boolean)
    : [];

  renderEtiquetasSeleccionadas();
  mostrarEtiquetasRapidas();

  const modal = document.getElementById("modalEtiquetas");
  if (modal) modal.classList.remove("hidden");
}

function cerrarModalEtiquetas() {
  const modal = document.getElementById("modalEtiquetas");
  if (modal) modal.classList.add("hidden");
}

async function guardarEtiquetas() {
  const idEl = document.getElementById("modalTagOrderId");
  if (!idEl) return;

  let id = idEl.value;
  let tags = etiquetasSeleccionadas.join(", ");

  let r = await fetch("/api/estado/etiquetas/guardar", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ id, tags }),
  });

  let d = await r.json().catch(() => null);

  if (d && d.success) {
    cerrarModalEtiquetas();
    cargarPedidos();
  }
}

function renderEtiquetasSeleccionadas() {
  let cont = document.getElementById("etiquetasSeleccionadas");
  if (!cont) return;

  cont.innerHTML = "";

  etiquetasSeleccionadas.forEach((tag, index) => {
    cont.innerHTML += `
      <span class="px-2.5 py-1 bg-slate-100 border border-slate-200 rounded-full text-xs font-semibold text-slate-800 inline-flex items-center gap-2">
        ${escapeHtml(tag)}
        <button onclick="eliminarEtiqueta(${index})" class="text-rose-600 font-extrabold">×</button>
      </span>`;
  });
}

function eliminarEtiqueta(i) {
  etiquetasSeleccionadas.splice(i, 1);
  renderEtiquetasSeleccionadas();
}

function mostrarEtiquetasRapidas() {
  let cont = document.getElementById("listaEtiquetasRapidas");
  if (!cont) return;

  cont.innerHTML = "";

  (window.etiquetasPredeterminadas || []).forEach((tag) => {
    cont.innerHTML += `
      <button onclick="agregarEtiqueta('${escapeJsString(tag)}')"
              class="px-3 py-2 bg-white border border-slate-200 hover:border-slate-300 hover:shadow-sm rounded-2xl text-sm font-semibold text-slate-800 transition">
        ${escapeHtml(tag)}
      </button>`;
  });
}

function agregarEtiqueta(tag) {
  if (!etiquetasSeleccionadas.includes(tag)) {
    etiquetasSeleccionadas.push(tag);
    renderEtiquetasSeleccionadas();
  }
}

function colorEtiqueta(tag) {
  tag = String(tag).toLowerCase().trim();
  if (tag.startsWith("d.")) return "bg-emerald-100 text-emerald-900 border border-emerald-200";
  if (tag.startsWith("p.")) return "bg-amber-100 text-amber-900 border border-amber-200";
  return "bg-slate-100 text-slate-700 border border-slate-200";
}

// =====================================================
// ÚLTIMO CAMBIO (FORMATEO + RENDER)
// =====================================================
function formatDateFull(dtStr) {
  if (!dtStr) return "-";
  const d = new Date(String(dtStr).replace(" ", "T"));
  if (isNaN(d)) return dtStr;

  const fecha = d.toLocaleDateString("es-ES", {
    weekday: "short",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  });

  const hora = d.toLocaleTimeString("es-ES", {
    hour12: false,
    hour: "2-digit",
    minute: "2-digit",
  });

  return `${fecha} ${hora}`;
}

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

function renderLastChange(p) {
  const info = p?.last_status_change;

  if (!info || !info.changed_at) {
    return `<span class="text-slate-400 text-sm">—</span>`;
  }

  const user = info.user_name ? escapeHtml(info.user_name) : "—";
  const full = escapeHtml(formatDateFull(info.changed_at));
  const ago = escapeHtml(timeAgo(info.changed_at));

  return `
    <div class="rounded-2xl border border-slate-200 bg-white px-3 py-2 shadow-sm">
      <div class="flex items-center justify-between gap-3">
        <div class="min-w-0">
          <div class="font-semibold text-slate-900 truncate">${user}</div>
          <div class="text-xs text-slate-500">${full}</div>
        </div>
        <span class="text-xs font-bold text-slate-600 bg-slate-100 border border-slate-200 px-2 py-1 rounded-full">
          Hace ${ago}
        </span>
      </div>
    </div>
  `;
}
window.renderLastChange = renderLastChange;

// =====================================================
// ESTADO MANUAL (MODAL)
// =====================================================
function abrirModal(orderId) {
  const idEl = document.getElementById("modalOrderId");
  const modal = document.getElementById("modalEstado");
  if (!idEl || !modal) return;

  idEl.value = orderId;
  modal.classList.remove("hidden");
}

function cerrarModal() {
  const modal = document.getElementById("modalEstado");
  if (modal) modal.classList.add("hidden");
}

async function guardarEstado(nuevoEstado) {
  const idEl = document.getElementById("modalOrderId");
  if (!idEl) return;

  const id = idEl.value;

  const r = await fetch("/api/estado/guardar", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ id, estado: nuevoEstado }),
  });

  const d = await r.json().catch(() => null);

  if (d && d.success) {
    cerrarModal();

    // actualizar en vivo si viene last_status_change
    if (d.last_status_change) {
      const cell = document.querySelector(`[data-lastchange="${id}"]`);
      if (cell) {
        cell.innerHTML = renderLastChange({ last_status_change: d.last_status_change });
      }
    } else {
      cargarPedidos();
    }
  }
}

// =====================================================
// MODAL DETALLES
// =====================================================
function verDetalles(orderId) {
  const modal = document.getElementById("modalDetalles");
  if (modal) modal.classList.remove("hidden");

  const detalleProductos = document.getElementById("detalleProductos");
  const detalleCliente = document.getElementById("detalleCliente");
  const detalleEnvio = document.getElementById("detalleEnvio");
  const detalleTotales = document.getElementById("detalleTotales");
  const tituloPedido = document.getElementById("tituloPedido");

  if (detalleProductos) detalleProductos.innerHTML = "Cargando...";
  if (detalleCliente) detalleCliente.innerHTML = "";
  if (detalleEnvio) detalleEnvio.innerHTML = "";
  if (detalleTotales) detalleTotales.innerHTML = "";
  if (tituloPedido) tituloPedido.innerHTML = "Cargando...";

  fetch(`/index.php/dashboard/detalles/${orderId}`)
    .then((r) => r.json())
    .then((data) => {
      if (!data.success) {
        if (detalleProductos) detalleProductos.innerHTML = "<p class='text-rose-600 font-semibold'>Error cargando detalles.</p>";
        return;
      }

      const o = data.order;
      window.imagenesLocales = data.imagenes_locales ?? {};

      if (tituloPedido) tituloPedido.innerHTML = `Detalles del pedido ${escapeHtml(o.name)}`;

      if (detalleCliente) {
        detalleCliente.innerHTML = `
          <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="font-extrabold text-slate-900">${escapeHtml((o.customer?.first_name ?? "") + " " + (o.customer?.last_name ?? ""))}</p>
            <p class="text-sm text-slate-600 mt-1">Email: <span class="font-semibold">${escapeHtml(o.email ?? "-")}</span></p>
            <p class="text-sm text-slate-600">Teléfono: <span class="font-semibold">${escapeHtml(o.phone ?? "-")}</span></p>
          </div>`;
      }

      const a = o.shipping_address ?? {};
      if (detalleEnvio) {
        detalleEnvio.innerHTML = `
          <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm text-sm text-slate-700">
            <p class="font-semibold text-slate-900 mb-1">Dirección</p>
            <p>${escapeHtml(a.address1 ?? "")}</p>
            <p>${escapeHtml((a.city ?? "") + ", " + (a.zip ?? ""))}</p>
            <p>${escapeHtml(a.country ?? "")}</p>
          </div>`;
      }

      if (detalleTotales) {
        detalleTotales.innerHTML = `
          <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm text-sm text-slate-700">
            <div class="flex items-center justify-between"><span>Subtotal</span><span class="font-extrabold text-slate-900">${escapeHtml(o.subtotal_price)} €</span></div>
            <div class="flex items-center justify-between mt-1"><span>Envío</span><span class="font-extrabold text-slate-900">${escapeHtml(o.total_shipping_price_set?.shop_money?.amount ?? "0")} €</span></div>
            <div class="flex items-center justify-between mt-2 pt-2 border-t border-slate-200"><span class="font-semibold">Total</span><span class="text-base font-extrabold text-slate-900">${escapeHtml(o.total_price)} €</span></div>
          </div>`;
      }

      window.imagenesCargadas = new Array(o.line_items.length).fill(false);

      let html = "";
      o.line_items.forEach((item, index) => {
        let propsHTML = "";

        if (item.properties?.length) {
          propsHTML = item.properties
            .map((p) => {
              if (esImagen(p.value)) {
                return `
                  <div class="mt-3">
                    <div class="text-xs font-bold text-slate-500 uppercase tracking-wide">${escapeHtml(p.name)}</div>
                    <img src="${escapeHtml(p.value)}" class="w-28 rounded-2xl shadow-sm border border-slate-200 mt-2">
                  </div>`;
              }
              return `<p class="text-sm text-slate-700 mt-1"><strong>${escapeHtml(p.name)}:</strong> ${escapeHtml(p.value)}</p>`;
            })
            .join("");
        }

        let imagenLocalHTML = "";
        if (window.imagenesLocales[index]) {
          imagenLocalHTML = `
            <div class="mt-4">
              <p class="text-xs font-bold text-slate-500 uppercase tracking-wide">Imagen cargada</p>
              <img src="${escapeHtml(window.imagenesLocales[index])}" class="w-32 rounded-2xl shadow-sm border border-slate-200 mt-2">
            </div>`;
        }

        html += `
          <div class="p-4 rounded-3xl border border-slate-200 bg-white shadow-sm hover:shadow-md transition">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <h4 class="font-extrabold text-slate-900 truncate">${escapeHtml(item.title)}</h4>
                <p class="text-sm text-slate-600 mt-1">Cantidad: <span class="font-semibold text-slate-900">${escapeHtml(item.quantity)}</span></p>
                <p class="text-sm text-slate-600">Precio: <span class="font-semibold text-slate-900">${escapeHtml(item.price)} €</span></p>
              </div>
              <span class="inline-flex h-10 w-10 items-center justify-center rounded-2xl bg-slate-900 text-white text-xs font-extrabold">
                ${index + 1}
              </span>
            </div>

            ${propsHTML}
            ${imagenLocalHTML}

            <div class="mt-4">
              <label class="text-xs font-bold text-slate-500 uppercase tracking-wide">Subir nueva imagen</label>
              <input type="file"
                     onchange="subirImagenProducto(${orderId}, ${index}, this)"
                     class="mt-2 w-full border border-slate-200 rounded-2xl p-3 bg-slate-50/50">
              <div id="preview_${orderId}_${index}" class="mt-3"></div>
            </div>
          </div>`;
      });

      if (detalleProductos) detalleProductos.innerHTML = html;
    })
    .catch((e) => {
      console.error(e);
      if (detalleProductos) detalleProductos.innerHTML = "<p class='text-rose-600 font-semibold'>Error de red cargando detalles.</p>";
    });
}

function cerrarModalDetalles() {
  const modal = document.getElementById("modalDetalles");
  if (modal) modal.classList.add("hidden");
}

function abrirPanelCliente() {
  const panel = document.getElementById("panelCliente");
  if (panel) panel.classList.remove("hidden");
}
function cerrarPanelCliente() {
  const panel = document.getElementById("panelCliente");
  if (panel) panel.classList.add("hidden");
}

// =====================================================
// SUBIR IMAGEN
// =====================================================
function subirImagenProducto(orderId, index, input) {
  if (!input.files.length) return;
  const file = input.files[0];

  // preview local
  const reader = new FileReader();
  reader.onload = (e) => {
    const prev = document.getElementById(`preview_${orderId}_${index}`);
    if (prev) {
      prev.innerHTML = `
        <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
          <div class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Preview</div>
          <img src="${e.target.result}" class="w-36 rounded-2xl shadow-sm border border-slate-200">
        </div>`;
    }
  };
  reader.readAsDataURL(file);

  showLoader();

  const form = new FormData();
  form.append("orderId", orderId);
  form.append("index", index);
  form.append("file", file);

  fetch("/index.php/dashboard/subirImagenProducto", {
    method: "POST",
    body: form,
  })
    .then((r) => r.json())
    .then((res) => {
      hideLoader();

      if (!res.success) {
        alert("Error subiendo imagen");
        return;
      }

      const prev = document.getElementById(`preview_${orderId}_${index}`);
      if (prev) {
        prev.innerHTML = `
          <div class="rounded-2xl border border-emerald-200 bg-emerald-50/40 p-3">
            <div class="text-xs font-bold text-emerald-800 uppercase tracking-wide mb-2">Subida OK</div>
            <img src="${escapeHtml(res.url)}" class="w-36 rounded-2xl shadow-sm border border-emerald-200">
          </div>`;
      }

      window.imagenesLocales[index] = res.url;
      window.imagenesCargadas[index] = true;

      validarEstadoFinal(orderId);
    })
    .catch((e) => {
      hideLoader();
      console.error(e);
      alert("Error de red subiendo imagen");
    });
}

// =====================================================
// VALIDAR ESTADO FINAL
// =====================================================
function validarEstadoFinal(orderId) {
  const listo = window.imagenesCargadas.every((v) => v === true);
  const nuevoEstado = listo ? "Producción" : "Faltan diseños";

  fetch("/api/estado/guardar", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ id: orderId, estado: nuevoEstado }),
  })
    .then((r) => r.json())
    .then(() => cargarPedidos())
    .catch((e) => console.error(e));
}

// =====================================================
// USUARIOS ONLINE/OFFLINE
// =====================================================
function renderUsersStatus(payload) {
  const users = payload?.users || [];

  const onlineList = document.getElementById("onlineUsers");
  const offlineList = document.getElementById("offlineUsers");

  const onlineCountEl = document.getElementById("onlineCount");
  const offlineCountEl = document.getElementById("offlineCount");

  if (!onlineList || !offlineList) return;

  onlineList.innerHTML = "";
  offlineList.innerHTML = "";

  const onlineFrag = document.createDocumentFragment();
  const offlineFrag = document.createDocumentFragment();

  let onlineCount = 0;
  let offlineCount = 0;

  users.forEach((u) => {
    const name = escapeHtml(u.nombre ?? "Usuario");
    const online = !!u.online;

    const li = document.createElement("li");
    li.className =
      "flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-3 py-2 shadow-sm hover:shadow-md transition";

    li.innerHTML = `
      <div class="flex items-center gap-3 min-w-0">
        <div class="h-9 w-9 rounded-2xl ${online ? "bg-emerald-600" : "bg-rose-600"} text-white flex items-center justify-center text-xs font-extrabold shadow-sm">
          ${inicialNombre(name)}
        </div>
        <div class="min-w-0">
          <div class="font-semibold text-slate-900 truncate">${name}</div>
          <div class="text-xs ${online ? "text-emerald-700" : "text-rose-700"} font-semibold">
            ${online ? "Activo ahora" : "Inactivo"}
          </div>
        </div>
      </div>

      <span class="inline-flex items-center gap-2">
        <span class="h-2.5 w-2.5 rounded-full ${online ? "bg-emerald-500" : "bg-rose-500"}"></span>
      </span>
    `;

    if (online) {
      onlineFrag.appendChild(li);
      onlineCount++;
    } else {
      offlineFrag.appendChild(li);
      offlineCount++;
    }
  });

  onlineList.appendChild(onlineFrag);
  offlineList.appendChild(offlineFrag);

  if (onlineCountEl) onlineCountEl.textContent = onlineCount;
  if (offlineCountEl) offlineCountEl.textContent = offlineCount;
}

async function pingUsuario() {
  try {
    await fetch("/dashboard/ping", { headers: { Accept: "application/json" } });
  } catch (e) {
    // silencioso
  }
}

async function cargarUsuariosEstado() {
  try {
    const r = await fetch("/dashboard/usuarios-estado", { headers: { Accept: "application/json" } });
    const d = await r.json().catch(() => null);
    if (d && d.success) renderUsersStatus(d);
  } catch (e) {
    console.error("Error usuarios estado:", e);
  }
}

// =====================================================
// EXPONE FUNCIONES NECESARIAS A HTML INLINE (onclick)
// =====================================================
window.paginaSiguiente = paginaSiguiente;
window.paginaAnterior = paginaAnterior;
window.abrirModal = abrirModal;
window.cerrarModal = cerrarModal;
window.guardarEstado = guardarEstado;

window.abrirModalEtiquetas = abrirModalEtiquetas;
window.cerrarModalEtiquetas = cerrarModalEtiquetas;
window.guardarEtiquetas = guardarEtiquetas;
window.eliminarEtiqueta = eliminarEtiqueta;
window.agregarEtiqueta = agregarEtiqueta;

window.verDetalles = verDetalles;
window.cerrarModalDetalles = cerrarModalDetalles;
window.abrirPanelCliente = abrirPanelCliente;
window.cerrarPanelCliente = cerrarPanelCliente;

window.subirImagenProducto = subirImagenProducto;
