// =====================================================
// DASHBOARD.JS (COMPLETO) - Limpio, moderno, NO sobrecargado
// - Tabla responsive SIN scroll horizontal (oculta columnas por breakpoints)
// - Entrega MUY visible
// - Etiquetas compactas (máximo 2 + contador)
// - Paginación Shopify REAL 50 en 50 (next/prev page_info)
// - Muestra "Página X" (pestaña actual)
// =====================================================

/* =====================================================
   VARIABLES GLOBALES
===================================================== */
let nextPageInfo = null;
let prevPageInfo = null;
let isLoading = false;

let currentPage = 1;

let etiquetasSeleccionadas = [];
window.imagenesCargadas = [];
window.imagenesLocales = {};

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

  // Inicial pedidos
  currentPage = 1;
  cargarPedidos({ reset: true });
});

/* =====================================================
   HELPERS
===================================================== */
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

/* =====================================================
   ENTREGA: MUY VISIBLE PERO LIMPIA
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
   PÍLDORA PÁGINA (pestaña actual)
   Requiere un elemento con id="pillPagina"
===================================================== */
function setPaginaUI() {
  const pill = document.getElementById("pillPagina");
  if (pill) pill.textContent = `Página ${currentPage}`;
}

/* =====================================================
   CARGAR PEDIDOS (50 en 50)
   Usa /dashboard/pedidos (o fallback /dashboard/filter)
===================================================== */
function cargarPedidos({ page_info = "", direction = "next", reset = false } = {}) {
  if (isLoading) return;
  isLoading = true;
  showLoader();

  const base = "/dashboard/pedidos"; // ✅ recomendado
  const fallback = "/dashboard/filter"; // por si aún lo usas en rutas

  let url = base;
  if (page_info) url += `?page_info=${encodeURIComponent(page_info)}&direction=${direction}`;

  fetch(url, { headers: { Accept: "application/json" } })
    .then(async (res) => {
      // Si el endpoint /dashboard/pedidos no existe aún, cae al /dashboard/filter
      if (res.status === 404) {
        let url2 = fallback;
        if (page_info) url2 += `?page_info=${encodeURIComponent(page_info)}&direction=${direction}`;
        const r2 = await fetch(url2, { headers: { Accept: "application/json" } });
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
        return;
      }

      if (reset) currentPage = 1;

      nextPageInfo = data.next_page_info ?? null;
      prevPageInfo = data.prev_page_info ?? null;

      actualizarTabla(data.orders || []);

      const total = document.getElementById("total-pedidos");
      if (total) total.textContent = data.count ?? 0;

      actualizarControlesPaginacion();
    })
    .catch((err) => {
      console.error("Error cargando pedidos:", err);
      actualizarTabla([]);
      nextPageInfo = null;
      prevPageInfo = null;
      actualizarControlesPaginacion();
    })
    .finally(() => {
      isLoading = false;
      hideLoader();
    });
}

/* =====================================================
   CONTROLES PAGINACIÓN + UI
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

  setPaginaUI();
}

function paginaSiguiente() {
  if (!nextPageInfo) return;
  currentPage += 1;
  cargarPedidos({ page_info: nextPageInfo, direction: "next" });
}

function paginaAnterior() {
  if (!prevPageInfo || currentPage <= 1) return;
  currentPage -= 1;
  cargarPedidos({ page_info: prevPageInfo, direction: "prev" });
}

/* =====================================================
   TABLA (tbody) - RESPONSIVE SIN SCROLL HORIZONTAL
===================================================== */
function actualizarTabla(pedidos) {
  const tbody = document.getElementById("tablaPedidos");
  const cards = document.getElementById("cardsPedidos");

  // DESKTOP TABLE
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
            <td class="py-4 px-4 font-extrabold text-slate-900 whitespace-nowrap">
              ${escapeHtml(p.numero ?? "-")}
            </td>

            <td class="py-4 px-3">
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
                           text-[11px] font-extrabold uppercase tracking-wide text-slate-800 whitespace-nowrap">
                ${escapeHtml(p.forma_envio ?? "-")}
              </span>
            </td>

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

  // MOBILE CARDS
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

/* =====================================================
   ÚLTIMO CAMBIO (COMPACTO)
===================================================== */
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

/* =====================================================
   ETIQUETAS (compactas)
===================================================== */
function renderEtiquetasCompact(etiquetas, orderId, mobile = false) {
  const raw = String(etiquetas || "").trim();
  const list = raw ? raw.split(",").map((t) => t.trim()).filter(Boolean) : [];

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

  const more =
    rest > 0
      ? `<span class="px-2.5 py-1.5 rounded-full text-[10px] font-extrabold uppercase tracking-wide border bg-white border-slate-200 text-slate-700">
        +${rest}
      </span>`
      : "";

  // ✅ evita crash si no tienes abrirModalEtiquetas definida todavía
  const onClick = typeof window.abrirModalEtiquetas === "function"
    ? `abrirModalEtiquetas(${orderId}, '${escapeJsString(raw)}')`
    : `alert('Falta implementar abrirModalEtiquetas()');`;

  if (!list.length) {
    return `
      <button onclick="${onClick}"
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
      <button onclick="${onClick}"
        class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl
               bg-slate-900 text-white text-[11px] font-extrabold uppercase tracking-wide
               hover:bg-slate-800 transition shadow-sm">
        Etiquetas
        <span class="text-white/80">✎</span>
      </button>
    </div>
  `;
}

function colorEtiqueta(tag) {
  tag = String(tag).toLowerCase().trim();
  if (tag.startsWith("d.")) return "bg-emerald-50 border-emerald-200 text-emerald-900";
  if (tag.startsWith("p.")) return "bg-amber-50 border-amber-200 text-amber-900";
  return "bg-slate-50 border-slate-200 text-slate-800";
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
    currentPage = 1;
    cargarPedidos({ reset: true });
  }
}

/* =====================================================
   DETALLES
===================================================== */
function abrirModalDetalles() {
  document.getElementById("modalDetalles")?.classList.remove("hidden");
}
function cerrarModalDetalles() {
  document.getElementById("modalDetalles")?.classList.add("hidden");
}

function verDetalles(orderId) {
  abrirModalDetalles();

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
      if (!data?.success) {
        if (detalleProductos) {
          detalleProductos.innerHTML = "<p class='text-rose-600 font-bold'>Error cargando detalles.</p>";
        }
        return;
      }

      const o = data.order;
      window.imagenesLocales = data.imagenes_locales ?? {};

      if (tituloPedido) tituloPedido.innerHTML = `Detalles del pedido ${escapeHtml(o.name)}`;

      if (detalleCliente) {
        detalleCliente.innerHTML = `
          <p><strong>${escapeHtml((o.customer?.first_name ?? "") + " " + (o.customer?.last_name ?? ""))}</strong></p>
          <p>Email: ${escapeHtml(o.email ?? "-")}</p>
          <p>Teléfono: ${escapeHtml(o.phone ?? "-")}</p>
        `;
      }

      const a = o.shipping_address ?? {};
      if (detalleEnvio) {
        detalleEnvio.innerHTML = `
          <p>${escapeHtml(a.address1 ?? "")}</p>
          <p>${escapeHtml((a.city ?? "") + ", " + (a.zip ?? ""))}</p>
          <p>${escapeHtml(a.country ?? "")}</p>
        `;
      }

      if (detalleTotales) {
        detalleTotales.innerHTML = `
          <p><strong>Subtotal:</strong> ${escapeHtml(o.subtotal_price)} €</p>
          <p><strong>Envío:</strong> ${escapeHtml(o.total_shipping_price_set?.shop_money?.amount ?? "0")} €</p>
          <p><strong>Total:</strong> ${escapeHtml(o.total_price)} €</p>
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

      if (detalleProductos) detalleProductos.innerHTML = html;
    })
    .catch((e) => {
      console.error(e);
      if (detalleProductos) {
        detalleProductos.innerHTML = "<p class='text-rose-600 font-bold'>Error de red cargando detalles.</p>";
      }
    });
}

/* =====================================================
   SUBIR IMAGEN
===================================================== */
function subirImagenProducto(orderId, index, input) {
  if (!input?.files?.length) return;
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

      if (!res?.success) {
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
    .then(() => cargarPedidos({ reset: true }))
    .catch((e) => console.error(e));
}

/* =====================================================
   USUARIOS ONLINE/OFFLINE
===================================================== */
function renderUsersStatus(payload) {
  // Tu backend retorna: { ok:true, conectados:[...] }
  const conectados = payload?.conectados || [];
  const onlineList = document.getElementById("onlineUsers");
  const offlineList = document.getElementById("offlineUsers");
  const onlineCountEl = document.getElementById("onlineCount");
  const offlineCountEl = document.getElementById("offlineCount");

  if (!onlineList || !offlineList) return;

  // Si tú tienes una lista completa de usuarios en el payload, cambia esto.
  // Por ahora: conectados = online, y offline queda vacío.
  onlineList.innerHTML = "";
  offlineList.innerHTML = "";

  conectados.forEach((u) => {
    const name = escapeHtml(u.nombre ?? "Usuario");
    const li = document.createElement("li");
    li.className = "flex items-center gap-2";
    li.innerHTML = `
      <span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
      <span class="font-semibold text-slate-800">${name}</span>
    `;
    onlineList.appendChild(li);
  });

  if (onlineCountEl) onlineCountEl.textContent = conectados.length;
  if (offlineCountEl) offlineCountEl.textContent = "0";
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

    // ✅ tu backend devuelve ok, no success
    if (d && (d.ok === true || d.success === true)) renderUsersStatus(d);
  } catch (e) {
    console.error("Error usuarios estado:", e);
  }
}
