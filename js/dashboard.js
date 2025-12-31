// =====================================================
// DASHBOARD.JS (COMPLETO) - UI moderna + entrega más visible + etiquetas pro
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

// Intervalos para usuarios
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
// INICIALIZAR
// =====================================================
document.addEventListener("DOMContentLoaded", () => {
  const btnAnterior = document.getElementById("btnAnterior");
  if (btnAnterior) {
    btnAnterior.addEventListener("click", (e) => {
      e.preventDefault();
      if (!btnAnterior.disabled) paginaAnterior();
    });
  }

  // ping y estado usuarios (si existen endpoints)
  pingUsuario();
  userPingInterval = setInterval(pingUsuario, 30000); // 30s

  cargarUsuariosEstado();
  userStatusInterval = setInterval(cargarUsuariosEstado, 15000); // 15s

  cargarPedidos();
});

// =====================================================
// Helpers
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

// Detecta si el “estado” viene como HTML de badge
function esBadgeHtml(valor) {
  const s = String(valor ?? "").trim();
  return s.startsWith("<span") || s.includes("<span") || s.includes("</span>");
}

// Render seguro del estado (badge html o texto)
function renderEstado(valor) {
  if (esBadgeHtml(valor)) return String(valor);
  return escapeHtml(valor ?? "-");
}

// =====================================================
// ENTREGA - Pill súper visible (MODERNO)
// =====================================================
function entregaStyle(estado) {
  const s = String(estado || "").toLowerCase();

  if (!s || s === "-" || s === "null") {
    return {
      wrap: "bg-slate-50 border-slate-200 text-slate-800",
      dot: "bg-slate-400",
      icon: "📦",
      label: "Sin estado",
    };
  }

  if (s.includes("entregado") || s.includes("delivered")) {
    return {
      wrap: "bg-emerald-50 border-emerald-200 text-emerald-900",
      dot: "bg-emerald-500",
      icon: "✅",
      label: "Entregado",
    };
  }

  if (s.includes("enviado") || s.includes("shipped")) {
    return {
      wrap: "bg-blue-50 border-blue-200 text-blue-900",
      dot: "bg-blue-500",
      icon: "🚚",
      label: "Enviado",
    };
  }

  if (s.includes("prepar") || s.includes("processing") || s.includes("pendiente")) {
    return {
      wrap: "bg-amber-50 border-amber-200 text-amber-900",
      dot: "bg-amber-500",
      icon: "📦",
      label: "Preparando",
    };
  }

  if (s.includes("cancel") || s.includes("devuelto") || s.includes("return") || s.includes("refunded")) {
    return {
      wrap: "bg-rose-50 border-rose-200 text-rose-900",
      dot: "bg-rose-500",
      icon: "⛔",
      label: "Incidencia",
    };
  }

  return {
    wrap: "bg-slate-50 border-slate-200 text-slate-900",
    dot: "bg-slate-400",
    icon: "📍",
    label: estado || "—",
  };
}

function renderEntregaPill(estadoEnvio) {
  const st = entregaStyle(estadoEnvio);
  return `
    <span class="inline-flex items-center gap-2 px-3.5 py-2 rounded-2xl border ${st.wrap}
                 shadow-sm font-extrabold text-xs uppercase tracking-wide">
      <span class="h-2.5 w-2.5 rounded-full ${st.dot}"></span>
      <span class="text-sm leading-none">${st.icon}</span>
      <span class="leading-none">${escapeHtml(st.label)}</span>
    </span>
  `;
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
// TABLA PRINCIPAL + CARDS
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
      tbody.innerHTML = `
        <tr>
          <td colspan="11" class="py-8 text-center text-slate-500">
            No se encontraron pedidos
          </td>
        </tr>`;
    } else {
      const rows = pedidos
        .map((p) => {
          const id = p.id ?? "";
          const estadoEnvio = p.estado_envio ?? "-";

          return `
          <tr class="border-b border-slate-100 hover:bg-slate-50/60 transition">
            <!-- Pedido -->
            <td class="py-4 px-4 font-extrabold text-slate-900 whitespace-nowrap">
              ${escapeHtml(p.numero ?? "-")}
              <div class="text-xs font-semibold text-slate-500 mt-0.5 hidden 2xl:block">
                ID: <span class="font-mono">${escapeHtml(String(id))}</span>
              </div>
            </td>

            <!-- Fecha -->
            <td class="py-4 px-4 text-slate-700 whitespace-nowrap">
              ${escapeHtml(p.fecha ?? "-")}
            </td>

            <!-- Cliente -->
            <td class="py-4 px-4 text-slate-800">
              <div class="font-semibold">${escapeHtml(p.cliente ?? "-")}</div>
            </td>

            <!-- Total -->
            <td class="py-4 px-4 font-extrabold text-slate-900 whitespace-nowrap">
              ${escapeHtml(p.total ?? "-")}
            </td>

            <!-- Estado -->
            <td class="py-4 px-3">
              <button onclick="abrirModal(${id})"
                class="inline-flex items-center gap-2 rounded-2xl px-3 py-2 bg-white border border-slate-200 shadow-sm
                       hover:shadow-md hover:border-slate-300 transition">
                <span class="h-2 w-2 rounded-full bg-blue-600"></span>
                <span class="font-extrabold text-xs uppercase tracking-wide text-slate-900">
                  ${renderEstado(p.estado ?? "-")}
                </span>
              </button>
            </td>

            <!-- Último cambio -->
            <td class="py-4 px-4" data-lastchange="${id}">
              ${renderLastChange(p)}
            </td>

            <!-- Etiquetas (botón moderno incluido) -->
            <td class="py-4 px-4">
              ${formatearEtiquetas(p.etiquetas ?? "", id)}
            </td>

            <!-- Artículos -->
            <td class="py-4 px-4 text-slate-700 whitespace-nowrap">
              <span class="inline-flex items-center justify-center min-w-[42px] px-3 py-1 rounded-full text-xs font-extrabold
                           bg-slate-50 border border-slate-200 text-slate-800">
                ${escapeHtml(p.articulos ?? "-")}
              </span>
            </td>

            <!-- Estado entrega (MUCHO más visible) -->
            <td class="py-4 px-4">
              ${renderEntregaPill(estadoEnvio)}
            </td>

            <!-- Forma entrega -->
            <td class="py-4 px-4 text-slate-700">
              <span class="inline-flex items-center px-3 py-2 rounded-2xl bg-slate-50 border border-slate-200 text-xs font-bold">
                ${escapeHtml(p.forma_envio ?? "-")}
              </span>
            </td>

            <!-- Detalles -->
            <td class="py-4 px-4">
              <button onclick="verDetalles(${id})"
                class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl bg-blue-600 text-white text-xs font-extrabold uppercase tracking-wide
                       hover:bg-blue-700 active:scale-[0.99] transition shadow-sm">
                Ver
                <span class="text-white/90">→</span>
              </button>
            </td>
          </tr>
        `;
        })
        .join("");

      tbody.innerHTML = rows;
    }
  }

  // ==========================
  // MOBILE CARDS
  // ==========================
  if (cards) {
    cards.innerHTML = "";

    if (!pedidos.length) {
      cards.innerHTML = `
        <div class="py-10 text-center text-slate-500">
          No se encontraron pedidos
        </div>`;
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
        const estadoEnvio = p.estado_envio ?? "-";
        const articulos = escapeHtml(p.articulos ?? "0");

        return `
        <div class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
          <div class="p-4">

            <div class="flex items-start justify-between gap-3">
              <div>
                <div class="text-sm font-extrabold text-slate-900">${numero}</div>
                <div class="text-xs text-slate-500 mt-0.5">${fecha}</div>
                <div class="text-sm text-slate-700 mt-1 font-semibold">${cliente}</div>
              </div>

              <div class="text-right">
                <div class="text-sm font-extrabold text-slate-900">${total}</div>
                <div class="text-xs text-slate-500 mt-0.5">${articulos} artículos</div>
              </div>
            </div>

            <div class="mt-3 flex items-center justify-between gap-3">
              <button onclick="abrirModal(${id})"
                class="inline-flex items-center gap-2 rounded-2xl px-3 py-2 bg-white border border-slate-200 shadow-sm hover:shadow-md transition">
                <span class="h-2 w-2 rounded-full bg-blue-600"></span>
                <span class="font-extrabold text-xs uppercase tracking-wide text-slate-900">
                  ${renderEstado(p.estado ?? "-")}
                </span>
              </button>

              <button onclick="verDetalles(${id})"
                class="px-3 py-2 rounded-2xl bg-blue-600 text-white text-xs font-extrabold uppercase tracking-wide shadow-sm
                       hover:bg-blue-700 transition">
                Ver →
              </button>
            </div>

            <div class="mt-3">
              <div class="text-xs uppercase tracking-wider text-slate-500 mb-2">Entrega</div>
              ${renderEntregaPill(estadoEnvio)}
              <div class="mt-2 text-xs text-slate-600">
                <span class="font-bold">Forma:</span> ${envio}
              </div>
            </div>

            <div class="mt-3" data-lastchange="${id}">
              ${renderLastChange(p)}
            </div>

            <div class="mt-3">
              <div class="text-xs uppercase tracking-wide text-slate-500 mb-2">Etiquetas</div>
              ${formatearEtiquetas(p.etiquetas ?? "", id)}
            </div>

            <details class="mt-3">
              <summary class="cursor-pointer text-sm font-semibold text-slate-700 select-none">
                Ver más
              </summary>
              <div class="mt-2 text-sm text-slate-600">
                <div class="flex items-center justify-between">
                  <span class="text-slate-500">ID</span>
                  <span class="font-mono text-xs">${escapeHtml(String(id))}</span>
                </div>
              </div>
            </details>

          </div>
        </div>
      `;
      })
      .join("");

    cards.innerHTML = html;
  }
}

// =====================================================
// ETIQUETAS (BOTÓN/ESTILO MÁS MODERNO)
// =====================================================
function formatearEtiquetas(etiquetas, orderId) {
  if (!etiquetas) {
    // Botón pro cuando no hay etiquetas
    return `
      <button onclick="abrirModalEtiquetas(${orderId}, '')"
        class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl
               bg-white border border-slate-200 shadow-sm
               hover:shadow-md hover:border-slate-300 transition
               text-slate-900 font-extrabold text-xs uppercase tracking-wide">
        <span class="h-2 w-2 rounded-full bg-blue-600"></span>
        Etiquetas
        <span class="text-blue-700">＋</span>
      </button>
    `;
  }

  let lista = String(etiquetas)
    .split(",")
    .map((t) => t.trim())
    .filter(Boolean);

  const tagsHtml = lista
    .map((tag) => {
      const cls = colorEtiqueta(tag);
      return `<span class="px-2.5 py-1.5 rounded-full text-[11px] font-extrabold uppercase tracking-wide ${cls}">
        ${escapeHtml(tag)}
      </span>`;
    })
    .join("");

  return `
    <div class="flex flex-wrap items-center gap-2">
      ${tagsHtml}
      <span class="w-full h-0 lg:hidden"></span>
      <button onclick="abrirModalEtiquetas(${orderId}, '${escapeJsString(etiquetas)}')"
        class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl
               bg-slate-900 text-white shadow-sm
               hover:bg-slate-800 hover:shadow-md transition
               text-xs font-extrabold uppercase tracking-wide">
        <span class="h-2 w-2 rounded-full bg-blue-400"></span>
        Editar
      </button>
    </div>
  `;
}

function abrirModalEtiquetas(orderId, textos = "") {
  const input = document.getElementById("modalTagOrderId");
  if (input) input.value = orderId;

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
  const id = document.getElementById("modalTagOrderId")?.value;
  const tags = etiquetasSeleccionadas.join(", ");

  const r = await fetch("/api/estado/etiquetas/guardar", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ id, tags }),
  });

  const d = await r.json().catch(() => null);
  if (d?.success) {
    cerrarModalEtiquetas();
    cargarPedidos();
  }
}

function renderEtiquetasSeleccionadas() {
  const cont = document.getElementById("etiquetasSeleccionadas");
  if (!cont) return;

  cont.innerHTML = "";

  etiquetasSeleccionadas.forEach((tag, index) => {
    cont.innerHTML += `
      <span class="inline-flex items-center gap-2 px-3 py-2 bg-slate-100 border border-slate-200 rounded-2xl text-xs font-bold">
        ${escapeHtml(tag)}
        <button onclick="eliminarEtiqueta(${index})" class="text-rose-600 font-extrabold">×</button>
      </span>
    `;
  });
}

function eliminarEtiqueta(i) {
  etiquetasSeleccionadas.splice(i, 1);
  renderEtiquetasSeleccionadas();
}

function mostrarEtiquetasRapidas() {
  const cont = document.getElementById("listaEtiquetasRapidas");
  if (!cont) return;

  cont.innerHTML = "";

  (window.etiquetasPredeterminadas || []).forEach((tag) => {
    cont.innerHTML += `
      <button onclick="agregarEtiqueta('${escapeJsString(tag)}')"
        class="px-3 py-2 rounded-2xl bg-white border border-slate-200 shadow-sm hover:shadow-md hover:border-slate-300 transition
               text-xs font-extrabold uppercase tracking-wide text-slate-900">
        ${escapeHtml(tag)}
      </button>
    `;
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
  return "bg-slate-100 text-slate-800 border border-slate-200";
}

// =====================================================
// ÚLTIMO CAMBIO (FORMATEO + RENDER)
// =====================================================
function formatDateFull(dtStr) {
  if (!dtStr) return "-";
  const d = new Date(String(dtStr).replace(" ", "T"));
  if (isNaN(d)) return String(dtStr);

  const fecha = d.toLocaleDateString("es-ES", {
    weekday: "long",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  });

  const hora = d.toLocaleTimeString("es-ES", {
    hour12: false,
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
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

  return `
    <div class="rounded-2xl border border-slate-200 bg-white px-3 py-2 shadow-sm">
      <div class="text-sm font-extrabold text-slate-900">${user}</div>
      <div class="text-xs text-slate-600 mt-0.5">${escapeHtml(formatDateFull(info.changed_at))}</div>
      <div class="text-[11px] text-slate-500 mt-1 font-bold uppercase tracking-wide">
        Hace ${escapeHtml(timeAgo(info.changed_at))}
      </div>
    </div>
  `;
}
window.renderLastChange = renderLastChange;

// =====================================================
// ESTADO MANUAL (MODAL)
// =====================================================
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

    // actualizar en vivo si viene last_status_change
    if (d.last_status_change) {
      const cell = document.querySelector(`[data-lastchange="${id}"]`);
      if (cell) cell.innerHTML = renderLastChange({ last_status_change: d.last_status_change });
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

  const prod = document.getElementById("detalleProductos");
  const cli = document.getElementById("detalleCliente");
  const env = document.getElementById("detalleEnvio");
  const tot = document.getElementById("detalleTotales");
  const tit = document.getElementById("tituloPedido");

  if (prod) prod.innerHTML = "Cargando...";
  if (cli) cli.innerHTML = "";
  if (env) env.innerHTML = "";
  if (tot) tot.innerHTML = "";
  if (tit) tit.innerHTML = "Cargando...";

  fetch(`/index.php/dashboard/detalles/${orderId}`)
    .then((r) => r.json())
    .then((data) => {
      if (!data.success) {
        if (prod) prod.innerHTML = "<p class='text-rose-600 font-bold'>Error cargando detalles.</p>";
        return;
      }

      const o = data.order;
      window.imagenesLocales = data.imagenes_locales ?? {};

      if (tit) tit.innerHTML = `Detalles del pedido ${escapeHtml(o.name)}`;

      if (cli) {
        cli.innerHTML = `
          <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="font-extrabold text-slate-900">
              ${escapeHtml((o.customer?.first_name ?? "") + " " + (o.customer?.last_name ?? ""))}
            </p>
            <p class="text-sm text-slate-600 mt-1">Email: ${escapeHtml(o.email ?? "-")}</p>
            <p class="text-sm text-slate-600">Teléfono: ${escapeHtml(o.phone ?? "-")}</p>
          </div>
        `;
      }

      const a = o.shipping_address ?? {};
      if (env) {
        env.innerHTML = `
          <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm text-sm text-slate-700">
            <p class="font-bold text-slate-900 mb-1">Dirección</p>
            <p>${escapeHtml(a.address1 ?? "")}</p>
            <p>${escapeHtml((a.city ?? "") + ", " + (a.zip ?? ""))}</p>
            <p>${escapeHtml(a.country ?? "")}</p>
          </div>
        `;
      }

      if (tot) {
        tot.innerHTML = `
          <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p><strong>Subtotal:</strong> ${escapeHtml(o.subtotal_price)} €</p>
            <p><strong>Envío:</strong> ${escapeHtml(o.total_shipping_price_set?.shop_money?.amount ?? "0")} €</p>
            <p class="text-lg font-extrabold text-slate-900 mt-1"><strong>Total:</strong> ${escapeHtml(o.total_price)} €</p>
          </div>
        `;
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
                  <div class="mt-2">
                    <span class="font-bold text-slate-800">${escapeHtml(p.name)}</span><br>
                    <img src="${escapeHtml(p.value)}" class="w-28 rounded-2xl shadow-sm border border-slate-200 mt-2">
                  </div>`;
              }
              return `<p class="text-sm text-slate-700"><strong>${escapeHtml(p.name)}:</strong> ${escapeHtml(p.value)}</p>`;
            })
            .join("");
        }

        let imagenLocalHTML = "";
        if (window.imagenesLocales[index]) {
          imagenLocalHTML = `
            <div class="mt-3">
              <p class="font-extrabold text-xs uppercase tracking-wide text-slate-500">Imagen cargada</p>
              <img src="${escapeHtml(window.imagenesLocales[index])}"
                class="w-36 rounded-2xl shadow-sm border border-slate-200 mt-2">
            </div>`;
        }

        html += `
          <div class="p-4 rounded-3xl border border-slate-200 bg-white shadow-sm">
            <h4 class="font-extrabold text-slate-900">${escapeHtml(item.title)}</h4>
            <p class="text-sm text-slate-700 mt-1">Cantidad: <span class="font-bold">${escapeHtml(item.quantity)}</span></p>
            <p class="text-sm text-slate-700">Precio: <span class="font-bold">${escapeHtml(item.price)} €</span></p>

            <div class="mt-3 space-y-1">${propsHTML}</div>
            ${imagenLocalHTML}

            <label class="font-extrabold text-xs uppercase tracking-wide text-slate-500 mt-4 block">
              Subir nueva imagen
            </label>
            <input type="file"
              onchange="subirImagenProducto(${orderId}, ${index}, this)"
              class="mt-2 w-full border border-slate-200 rounded-2xl p-3 text-sm bg-slate-50">

            <div id="preview_${orderId}_${index}" class="mt-3"></div>
          </div>`;
      });

      if (prod) prod.innerHTML = html;
    })
    .catch((e) => {
      console.error(e);
      const prod = document.getElementById("detalleProductos");
      if (prod) prod.innerHTML = "<p class='text-rose-600 font-bold'>Error de red cargando detalles.</p>";
    });
}

function cerrarModalDetalles() {
  const modal = document.getElementById("modalDetalles");
  if (modal) modal.classList.add("hidden");
}

// Panel cliente
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

  const reader = new FileReader();
  reader.onload = (e) => {
    const prev = document.getElementById(`preview_${orderId}_${index}`);
    if (prev) {
      prev.innerHTML = `
        <img src="${e.target.result}"
          class="w-40 rounded-2xl shadow-sm border border-slate-200">`;
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
          <img src="${escapeHtml(res.url)}"
            class="w-40 rounded-2xl shadow-sm border border-slate-200">`;
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
// USUARIOS ONLINE / OFFLINE
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

  let onlineCount = 0;
  let offlineCount = 0;

  users.forEach((u) => {
    const name = escapeHtml(u.nombre ?? "Usuario");
    const online = !!u.online;

    const li = document.createElement("li");
    li.className =
      "flex items-center justify-between gap-3 p-2 rounded-2xl bg-white border border-slate-200 shadow-sm";

    li.innerHTML = `
      <div class="flex items-center gap-2">
        <span class="h-2.5 w-2.5 rounded-full ${online ? "bg-emerald-500" : "bg-rose-500"}"></span>
        <span class="font-bold text-slate-900">${name}</span>
      </div>
      <span class="text-[11px] font-extrabold uppercase tracking-wide ${
        online ? "text-emerald-700" : "text-rose-700"
      }">
        ${online ? "Online" : "Offline"}
      </span>
    `;

    if (online) {
      onlineList.appendChild(li);
      onlineCount++;
    } else {
      offlineList.appendChild(li);
      offlineCount++;
    }
  });

  if (onlineCountEl) onlineCountEl.textContent = onlineCount;
  if (offlineCountEl) offlineCountEl.textContent = offlineCount;
}

async function pingUsuario() {
  try {
    await fetch("/dashboard/ping", { headers: { Accept: "application/json" } });
  } catch (e) {}
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
