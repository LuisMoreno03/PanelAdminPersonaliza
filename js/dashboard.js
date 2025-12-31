// =====================================================
// DASHBOARD.JS (MODERNO + LEGIBLE + RESPONSIVE)
// - Desktop: tabla con columnas adaptativas (sin scroll horizontal)
// - Mobile: cards
// - Usuarios online/offline (UI más moderna)
// =====================================================

// =====================================================
// VARIABLES GLOBALES
// =====================================================
let nextPageInfo = null;
let isLoading = false;

let etiquetasSeleccionadas = [];

window.imagenesCargadas = [];
window.imagenesLocales = {}; // imágenes locales por índice

let pageHistory = []; // page_info history para botón "Anterior"

// Intervalos
let userPingInterval = null;
let userStatusInterval = null;

// =====================================================
// LOADER GLOBAL
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
  const btnAnterior = document.getElementById("btnAnterior");
  if (btnAnterior) {
    btnAnterior.addEventListener("click", (e) => {
      e.preventDefault();
      if (!btnAnterior.disabled) paginaAnterior();
    });
  }

  // Pings usuario + estado usuarios
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

// Detecta si el “estado” viene como HTML de badge (span)
function esBadgeHtml(valor) {
  const s = String(valor ?? "").trim();
  return s.startsWith("<span") || s.includes("<span") || s.includes("</span>");
}

// Render seguro del estado:
// - Si viene HTML (badge), se devuelve tal cual.
// - Si viene texto, se escapa.
function renderEstado(valor) {
  if (esBadgeHtml(valor)) return String(valor);
  return escapeHtml(valor ?? "-");
}

// Inicial para avatar
function inicialNombre(nombre) {
  const n = String(nombre ?? "").trim();
  if (!n) return "?";
  return n[0].toUpperCase();
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

      // Historial page_info
      if (pageInfo) {
        if (pageHistory[pageHistory.length - 1] !== pageInfo) pageHistory.push(pageInfo);
      } else {
        pageHistory = [];
      }

      nextPageInfo = data.next_page_info ?? null;

      actualizarTabla(data.orders || []);

      // Botones paginación
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

      // Total
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
// PAGINACIÓN
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
// TABLA + CARDS (RESPONSIVE SIN SCROLL HORIZONTAL)
// - Desktop: ocultamos columnas en pantallas chicas con hidden xl/2xl
// - Mobile: cards
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
          <td colspan="11" class="py-10 text-center text-slate-500">
            No se encontraron pedidos
          </td>
        </tr>`;
    } else {
      const rows = pedidos
        .map((p) => {
          const id = p.id ?? "";
          const numero = escapeHtml(p.numero ?? "-");
          const fecha = escapeHtml(p.fecha ?? "-");
          const cliente = escapeHtml(p.cliente ?? "-");
          const total = escapeHtml(p.total ?? "-");
          const articulos = escapeHtml(p.articulos ?? "-");
          const estadoEnvio = escapeHtml(p.estado_envio ?? "-");
          const formaEnvio = escapeHtml(p.forma_envio ?? "-");

          // Etiquetas (ya viene HTML)
          const etiquetasHtml = formatearEtiquetas(p.etiquetas ?? "", id);

          return `
          <tr class="group border-b border-slate-100 hover:bg-slate-50/70 transition">
            <!-- Pedido -->
            <td class="py-3 px-4">
              <div class="flex items-center gap-3">
                <div class="h-9 w-9 rounded-2xl bg-white border border-slate-200 shadow-sm flex items-center justify-center text-slate-700">
                  🧾
                </div>
                <div class="min-w-0">
                  <div class="font-extrabold text-slate-900 truncate">${numero}</div>
                  <div class="text-xs text-slate-500 truncate">ID: <span class="font-mono">${escapeHtml(String(id))}</span></div>
                </div>
              </div>
            </td>

            <!-- Fecha -->
            <td class="py-3 px-4 text-slate-700 font-medium">
              ${fecha}
            </td>

            <!-- Cliente -->
            <td class="py-3 px-4">
              <div class="flex items-center gap-3">
                <div class="h-8 w-8 rounded-xl bg-slate-900 text-white flex items-center justify-center text-xs font-extrabold">
                  ${inicialNombre(cliente)}
                </div>
                <div class="min-w-0">
                  <div class="font-semibold text-slate-900 truncate">${cliente}</div>
                  <div class="text-xs text-slate-500 truncate">Cliente</div>
                </div>
              </div>
            </td>

            <!-- Total -->
            <td class="py-3 px-4 text-right">
              <div class="font-extrabold text-slate-900">${total}</div>
              <div class="text-xs text-slate-500">${articulos} art.</div>
            </td>

            <!-- Estado -->
            <td class="py-3 px-4">
              <button onclick="abrirModal(${id})"
                      class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl bg-white border border-slate-200 shadow-sm
                             hover:shadow transition font-semibold text-slate-800">
                ${renderEstado(p.estado ?? "-")}
                <span class="text-slate-400 group-hover:text-slate-700 transition">✎</span>
              </button>
            </td>

            <!-- Último cambio (oculto < xl) -->
            <td class="py-3 px-4 hidden xl:table-cell" data-lastchange="${id}">
              ${renderLastChange(p)}
            </td>

            <!-- Etiquetas (oculto < 2xl) -->
            <td class="py-3 px-4 hidden 2xl:table-cell">
              ${etiquetasHtml}
            </td>

            <!-- Artículos (oculto < xl) -->
            <td class="py-3 px-4 text-center hidden xl:table-cell">
              <span class="inline-flex px-3 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-700">
                ${articulos}
              </span>
            </td>

            <!-- Estado entrega (oculto < xl) -->
            <td class="py-3 px-4 hidden xl:table-cell">
              <span class="inline-flex px-3 py-1 rounded-full text-xs font-bold bg-indigo-50 text-indigo-800 border border-indigo-100">
                ${estadoEnvio}
              </span>
            </td>

            <!-- Forma entrega (oculto < 2xl) -->
            <td class="py-3 px-4 hidden 2xl:table-cell text-slate-700">
              ${formaEnvio}
            </td>

            <!-- Detalles -->
            <td class="py-3 px-4 text-right">
              <button onclick="verDetalles(${id})"
                      class="inline-flex items-center justify-center px-3 py-2 rounded-2xl bg-blue-600 text-white text-sm font-bold
                             hover:bg-blue-700 active:scale-[0.99] transition">
                Ver
              </button>
            </td>
          </tr>`;
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
        const estadoEnvio = escapeHtml(p.estado_envio ?? "-");
        const articulos = escapeHtml(p.articulos ?? "0");

        return `
        <div class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
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
                      class="px-3 py-2 rounded-2xl bg-blue-600 text-white text-sm font-bold">
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
    return `
      <button onclick="abrirModalEtiquetas(${orderId}, '')"
              class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl bg-white border border-slate-200 shadow-sm text-blue-700 font-semibold">
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
      return `
        <span class="px-2.5 py-1 rounded-full text-xs font-extrabold ${cls}">
          ${escapeHtml(tag)}
        </span>`;
    })
    .join("");

  return `
    <div class="flex flex-wrap items-center gap-2">
      ${tagsHtml}
      <button onclick="abrirModalEtiquetas(${orderId}, '${escapeJsString(etiquetas)}')"
              class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl bg-white border border-slate-200 shadow-sm text-slate-700 text-xs font-bold">
        Editar ✎
      </button>
    </div>`;
}

function colorEtiqueta(tag) {
  tag = String(tag).toLowerCase().trim();
  if (tag.startsWith("d.")) return "bg-emerald-100 text-emerald-800 border border-emerald-200";
  if (tag.startsWith("p.")) return "bg-amber-100 text-amber-800 border border-amber-200";
  return "bg-slate-100 text-slate-700 border border-slate-200";
}

// =====================================================
// ÚLTIMO CAMBIO
// =====================================================
function formatDateFull(dtStr) {
  if (!dtStr) return "-";
  const d = new Date(String(dtStr).replace(" ", "T"));
  if (isNaN(d)) return dtStr;

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
    <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
      <div class="flex items-center gap-3">
        <div class="h-8 w-8 rounded-xl bg-slate-900 text-white flex items-center justify-center text-xs font-extrabold">
          ${inicialNombre(user)}
        </div>
        <div class="min-w-0">
          <div class="font-extrabold text-slate-900 truncate">${user}</div>
          <div class="text-xs text-slate-500 truncate">${escapeHtml(formatDateFull(info.changed_at))}</div>
        </div>
      </div>
      <div class="mt-2 text-xs text-slate-500">
        Hace <span class="font-bold text-slate-700">${escapeHtml(timeAgo(info.changed_at))}</span>
      </div>
    </div>`;
}
window.renderLastChange = renderLastChange;

// =====================================================
// ESTADO MANUAL (MODAL)
// =====================================================
function abrirModal(orderId) {
  document.getElementById("modalOrderId").value = orderId;
  document.getElementById("modalEstado").classList.remove("hidden");
}

function cerrarModal() {
  document.getElementById("modalEstado").classList.add("hidden");
}

async function guardarEstado(nuevoEstado) {
  const id = document.getElementById("modalOrderId").value;

  const r = await fetch("/api/estado/guardar", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ id, estado: nuevoEstado }),
  });

  const d = await r.json();

  if (d.success) {
    cerrarModal();

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
  document.getElementById("modalDetalles").classList.remove("hidden");

  document.getElementById("detalleProductos").innerHTML = "Cargando...";
  document.getElementById("detalleCliente").innerHTML = "";
  document.getElementById("detalleEnvio").innerHTML = "";
  document.getElementById("detalleTotales").innerHTML = "";
  document.getElementById("tituloPedido").innerHTML = "Cargando...";

  fetch(`/index.php/dashboard/detalles/${orderId}`)
    .then((r) => r.json())
    .then((data) => {
      if (!data.success) {
        document.getElementById("detalleProductos").innerHTML =
          "<p class='text-red-500'>Error cargando detalles.</p>";
        return;
      }

      const o = data.order;
      window.imagenesLocales = data.imagenes_locales ?? {};

      document.getElementById("tituloPedido").innerHTML = `Detalles del pedido ${escapeHtml(o.name)}`;

      document.getElementById("detalleCliente").innerHTML = `
        <p><strong>${escapeHtml((o.customer?.first_name ?? "") + " " + (o.customer?.last_name ?? ""))}</strong></p>
        <p>Email: ${escapeHtml(o.email ?? "-")}</p>
        <p>Teléfono: ${escapeHtml(o.phone ?? "-")}</p>
      `;

      const a = o.shipping_address ?? {};
      document.getElementById("detalleEnvio").innerHTML = `
        <p>${escapeHtml(a.address1 ?? "")}</p>
        <p>${escapeHtml((a.city ?? "") + ", " + (a.zip ?? ""))}</p>
        <p>${escapeHtml(a.country ?? "")}</p>
      `;

      document.getElementById("detalleTotales").innerHTML = `
        <p><strong>Subtotal:</strong> ${escapeHtml(o.subtotal_price)} €</p>
        <p><strong>Envío:</strong> ${escapeHtml(o.total_shipping_price_set?.shop_money?.amount ?? "0")} €</p>
        <p><strong>Total:</strong> ${escapeHtml(o.total_price)} €</p>
      `;

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
                    <span class="font-semibold">${escapeHtml(p.name)}</span><br>
                    <img src="${escapeHtml(p.value)}" class="w-28 rounded shadow">
                  </div>`;
              }
              return `<p><strong>${escapeHtml(p.name)}:</strong> ${escapeHtml(p.value)}</p>`;
            })
            .join("");
        }

        let imagenLocalHTML = "";
        if (window.imagenesLocales[index]) {
          imagenLocalHTML = `
            <div class="mt-3">
              <p class="font-semibold text-sm">Imagen cargada:</p>
              <img src="${escapeHtml(window.imagenesLocales[index])}"
                   class="w-32 rounded shadow mt-1">
            </div>`;
        }

        html += `
          <div class="p-4 border rounded-2xl shadow-sm bg-white">
            <h4 class="font-extrabold text-slate-900">${escapeHtml(item.title)}</h4>
            <p class="text-sm text-slate-700 mt-1">Cantidad: <span class="font-semibold">${escapeHtml(item.quantity)}</span></p>
            <p class="text-sm text-slate-700">Precio: <span class="font-semibold">${escapeHtml(item.price)} €</span></p>

            ${propsHTML}
            ${imagenLocalHTML}

            <label class="font-semibold text-sm mt-4 block">Subir nueva imagen:</label>
            <input type="file"
                onchange="subirImagenProducto(${orderId}, ${index}, this)"
                class="mt-2 w-full border border-slate-200 rounded-2xl p-3">

            <div id="preview_${orderId}_${index}" class="mt-3"></div>
          </div>`;
      });

      document.getElementById("detalleProductos").innerHTML = html;
    })
    .catch((e) => {
      console.error(e);
      document.getElementById("detalleProductos").innerHTML =
        "<p class='text-red-500'>Error de red cargando detalles.</p>";
    });
}

function cerrarModalDetalles() {
  document.getElementById("modalDetalles").classList.add("hidden");
}

function abrirPanelCliente() {
  document.getElementById("panelCliente").classList.remove("hidden");
}

function cerrarPanelCliente() {
  document.getElementById("panelCliente").classList.add("hidden");
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
    if (prev) prev.innerHTML = `<img src="${e.target.result}" class="w-32 mt-2 rounded-2xl shadow-sm">`;
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
      if (prev) prev.innerHTML = `<img src="${escapeHtml(res.url)}" class="w-32 mt-2 rounded-2xl shadow-sm">`;

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
// USUARIOS ONLINE / OFFLINE (UI MODERNA)
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
      "flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-3 py-2 shadow-sm";

    li.innerHTML = `
      <div class="flex items-center gap-3 min-w-0">
        <div class="h-8 w-8 rounded-xl ${online ? "bg-emerald-600" : "bg-rose-600"} text-white flex items-center justify-center text-xs font-extrabold">
          ${inicialNombre(name)}
        </div>
        <div class="min-w-0">
          <div class="font-semibold text-slate-900 truncate">${name}</div>
          <div class="text-xs ${online ? "text-emerald-700" : "text-rose-700"}">
            ${online ? "Conectado" : "Desconectado"}
          </div>
        </div>
      </div>

      <span class="h-2.5 w-2.5 rounded-full ${online ? "bg-emerald-500" : "bg-rose-500"}"></span>
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
    const r = await fetch("/dashboard/usuarios-estado", {
      headers: { Accept: "application/json" },
    });
    const d = await r.json().catch(() => null);
    if (d && d.success) renderUsersStatus(d);
  } catch (e) {
    console.error("Error usuarios estado:", e);
  }
}
