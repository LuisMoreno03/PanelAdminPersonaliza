// =====================================================
// DASHBOARD.JS (COMPLETO) - Limpio, moderno, NO sobrecargado
// - Tabla responsive SIN scroll horizontal (oculta columnas por breakpoints)
// - Entrega MUY visible
// - Etiquetas compactas (máximo 2 + contador)
// =====================================================

// =====================================================
// VARIABLES GLOBALES
// =====================================================
let nextPageInfo = null;
let isLoading = false;

let etiquetasSeleccionadas = [];
window.imagenesCargadas = [];
window.imagenesLocales = {};

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
  const btnAnterior = document.getElementById("btnAnterior");
  if (btnAnterior) {
    btnAnterior.addEventListener("click", (e) => {
      e.preventDefault();
      if (!btnAnterior.disabled) paginaAnterior();
    });
  }

  // usuarios (si tienes endpoints)
  pingUsuario();
  userPingInterval = setInterval(pingUsuario, 30000);

  cargarUsuariosEstado();
  userStatusInterval = setInterval(cargarUsuariosEstado, 15000);

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

function esBadgeHtml(valor) {
  const s = String(valor ?? "").trim();
  return s.startsWith("<span") || s.includes("<span") || s.includes("</span>");
}

function renderEstado(valor) {
  if (esBadgeHtml(valor)) return String(valor);
  return escapeHtml(valor ?? "-");
}

// =====================================================
// ENTREGA: MUY VISIBLE PERO LIMPIA
// =====================================================
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
// TABLA (tbody) - RESPONSIVE SIN SCROLL HORIZONTAL
// - Ocultamos celdas con "hidden lg:table-cell / hidden xl:table-cell"
// =====================================================
function actualizarTabla(pedidos) {
  const tbody = document.getElementById("tablaPedidos");
  const cards = document.getElementById("cardsPedidos");

  // ==========================
  // DESKTOP TABLE (tbody)
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
      tbody.innerHTML = pedidos
        .map((p) => {
          const id = p.id ?? "";
          const etiquetas = p.etiquetas ?? "";
          return `
          <tr class="border-b border-slate-100 hover:bg-slate-50/60 transition">
            <!-- Pedido -->
            <td class="py-4 px-4 font-extrabold text-slate-900 whitespace-nowrap">
              ${escapeHtml(p.numero ?? "-")}
            </td>

            <!-- Fecha (solo lg+) -->
            <td class="py-4 px-4 text-slate-600 whitespace-nowrap hidden lg:table-cell">
              ${escapeHtml(p.fecha ?? "-")}
            </td>

            <!-- Cliente -->
            <td class="py-4 px-4">
              <div class="font-semibold text-slate-900 truncate max-w-[280px]">
                ${escapeHtml(p.cliente ?? "-")}
              </div>
            </td>

            <!-- Total -->
            <td class="py-4 px-4 font-extrabold text-slate-900 whitespace-nowrap">
              ${escapeHtml(p.total ?? "-")}
            </td>

            <!-- Estado -->
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

            <!-- Último cambio (solo xl+) -->
            <td class="py-4 px-4 hidden xl:table-cell" data-lastchange="${id}">
              ${renderLastChangeCompact(p)}
            </td>

            <!-- Etiquetas -->
            <td class="py-4 px-4">
              ${renderEtiquetasCompact(etiquetas, id)}
            </td>

            <!-- Artículos (solo lg+) -->
            <td class="py-4 px-4 hidden lg:table-cell">
              <span class="inline-flex items-center justify-center px-3 py-1 rounded-full text-xs font-extrabold
                           bg-slate-50 border border-slate-200 text-slate-800 whitespace-nowrap">
                ${escapeHtml(p.articulos ?? "-")}
              </span>
            </td>

            <!-- Entrega (SIEMPRE visible y grande) -->
            <td class="py-4 px-4">
              ${renderEntregaPill(p.estado_envio ?? "-")}
            </td>

            <!-- Forma (solo xl+) -->
            <td class="py-4 px-4 hidden xl:table-cell">
              <span class="inline-flex items-center px-3 py-2 rounded-2xl bg-slate-50 border border-slate-200
                           text-[11px] font-extrabold uppercase tracking-wide text-slate-800 whitespace-nowrap">
                ${escapeHtml(p.forma_envio ?? "-")}
              </span>
            </td>

            <!-- Acción -->
            <td class="py-4 px-4 text-right whitespace-nowrap">
              <button onclick="verDetalles(${id})"
                class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl bg-blue-600 text-white
                       text-[11px] font-extrabold uppercase tracking-wide shadow-sm
                       hover:bg-blue-700 transition">
                Ver <span class="text-white/90">→</span>
              </button>
            </td>
          </tr>`;
        })
        .join("");
    }
  }

  // ==========================
  // MOBILE CARDS (si existen)
  // ==========================
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

            <div class="mt-3 w-40 flex items-center justify-between gap-3">
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

            <div class="mt-3">
              ${renderEntregaPill(p.estado_envio ?? "-")}
            </div>

            <div class="mt-3">
              ${renderEtiquetasCompact(etiquetas, id, true)}
            </div>
          </div>
        </div>`;
      })
      .join("");
  }
}

// =====================================================
// ÚLTIMO CAMBIO (COMPACTO, NO CAJA GRANDE)
// =====================================================
function renderLastChangeCompact(p) {
  const info = p?.last_status_change;
  if (!info || !info.changed_at) return `<span class="text-slate-400 text-sm">—</span>`;

  const user = info.user_name ? escapeHtml(info.user_name) : "—";
  return `
    <div class="text-xs text-slate-600 leading-tight">
      <div class="font-bold text-slate-900">${user}</div>
      <div class="text-slate-500">${escapeHtml(timeAgo(info.changed_at))}</div>
    </div>
  `;
}

// =====================================================
// ETIQUETAS: compactas (max 2) + botón limpio
// =====================================================
function renderEtiquetasCompact(etiquetas, orderId, mobile = false) {
  const raw = String(etiquetas || "").trim();
  const list = raw
    ? raw.split(",").map((t) => t.trim()).filter(Boolean)
    : [];

  const max = mobile ? 3 : 2;
  const visibles = list.slice(0, max);
  const rest = list.length - visibles.length;

  const pills = visibles
    .map((tag) => {
      const cls = colorEtiqueta(tag);
      return `<span class="px-2.5 py-1.5 rounded-full text-[10px] font-extrabold uppercase tracking-wide border ${cls}">
        ${escapeHtml(tag)}
      </span>`;
    })
    .join("");

  const more = rest > 0
    ? `<span class="px-2.5 py-1.5 rounded-full text-[10px] font-extrabold uppercase tracking-wide border bg-white border-slate-200 text-slate-700">
        +${rest}
      </span>`
    : "";

  // Botón pequeño (sin subrayado, sin ruido)
  const btn = `
    <button onclick="abrirModalEtiquetas(${orderId}, '${escapeJsString(raw)}')"
      class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl
             bg-slate-900 text-white text-[11px] font-extrabold uppercase tracking-wide
             hover:bg-slate-800 transition shadow-sm">
      Etiquetas
      <span class="text-white/80">✎</span>
    </button>
  `;

  if (!list.length) {
    return `
      <button onclick="abrirModalEtiquetas(${orderId}, '')"
        class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl
               bg-white border border-slate-200 text-slate-900 text-[11px] font-extrabold uppercase tracking-wide
               hover:shadow-md transition">
        Etiquetas
        <span class="text-blue-700">＋</span>
      </button>
    `;
  }

  return `
    <div class="flex flex-wrap items-center gap-2">
      ${pills}${more}
      ${btn}
    </div>
  `;
}

function colorEtiqueta(tag) {
  tag = String(tag).toLowerCase().trim();
  if (tag.startsWith("d.")) return "bg-emerald-50 border-emerald-200 text-emerald-900";
  if (tag.startsWith("p.")) return "bg-amber-50 border-amber-200 text-amber-900";
  return "bg-slate-50 border-slate-200 text-slate-800";
}

// =====================================================
// TIME AGO (igual que antes)
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

// =====================================================
// MODAL ESTADO
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
    cargarPedidos();
  }
}

// =====================================================
// DETALLES (dejo tu función como estaba si ya te funciona)
// =====================================================
function verDetalles(orderId) {
  document.getElementById("modalDetalles")?.classList.remove("hidden");

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
          "<p class='text-rose-600 font-bold'>Error cargando detalles.</p>";
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
              <img src="${escapeHtml(window.imagenesLocales[index])}" class="w-32 rounded shadow mt-1">
            </div>`;
        }

        html += `
          <div class="p-4 border rounded-lg shadow bg-white">
            <h4 class="font-semibold">${escapeHtml(item.title)}</h4>
            <p>Cantidad: ${escapeHtml(item.quantity)}</p>
            <p>Precio: ${escapeHtml(item.price)} €</p>

            ${propsHTML}
            ${imagenLocalHTML}

            <label class="font-semibold text-sm mt-3 block">Subir nueva imagen:</label>
            <input type="file"
              onchange="subirImagenProducto(${orderId}, ${index}, this)"
              class="mt-1 w-full border rounded p-2">

            <div id="preview_${orderId}_${index}" class="mt-2"></div>
          </div>`;
      });

      document.getElementById("detalleProductos").innerHTML = html;
    })
    .catch((e) => {
      console.error(e);
      document.getElementById("detalleProductos").innerHTML =
        "<p class='text-rose-600 font-bold'>Error de red cargando detalles.</p>";
    });
}

function cerrarModalDetalles() {
  document.getElementById("modalDetalles")?.classList.add("hidden");
}

// Panel cliente
function abrirPanelCliente() {
  document.getElementById("panelCliente")?.classList.remove("hidden");
}
function cerrarPanelCliente() {
  document.getElementById("panelCliente")?.classList.add("hidden");
}

// =====================================================
// SUBIR IMAGEN (igual)
// =====================================================
function subirImagenProducto(orderId, index, input) {
  if (!input.files.length) return;
  const file = input.files[0];

  const reader = new FileReader();
  reader.onload = (e) => {
    const prev = document.getElementById(`preview_${orderId}_${index}`);
    if (prev) prev.innerHTML = `<img src="${e.target.result}" class="w-32 mt-2 rounded shadow">`;
  };
  reader.readAsDataURL(file);

  showLoader();

  const form = new FormData();
  form.append("orderId", orderId);
  form.append("index", index);
  form.append("file", file);

  fetch("/index.php/dashboard/subirImagenProducto", { method: "POST", body: form })
    .then((r) => r.json())
    .then((res) => {
      hideLoader();

      if (!res.success) {
        alert("Error subiendo imagen");
        return;
      }

      const prev = document.getElementById(`preview_${orderId}_${index}`);
      if (prev) prev.innerHTML = `<img src="${escapeHtml(res.url)}" class="w-32 mt-2 rounded shadow">`;

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
