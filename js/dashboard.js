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
  // enganchar botón anterior si existe
  const btnAnterior = document.getElementById("btnAnterior");
  if (btnAnterior) {
    btnAnterior.addEventListener("click", (e) => {
      e.preventDefault();
      if (!btnAnterior.disabled) paginaAnterior();
    });
  }

  cargarPedidos();
});

// =====================================================
// Helpers
// =====================================================
function esImagen(url) {
  if (!url) return false;
  return /\.(jpeg|jpg|png|gif|webp|svg)$/i.test(url);
}

// Escapar para evitar romper HTML/atributos
function escapeHtml(str) {
  return String(str ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function escapeJsString(str) {
  // para usar dentro de comillas simples en onclick
  return String(str ?? "").replaceAll("\\", "\\\\").replaceAll("'", "\\'");
}

// =====================================================
// CARGAR PEDIDOS
// =====================================================
function cargarPedidos(pageInfo = null) {
  if (isLoading) return;
  isLoading = true;

  let url = "/dashboard/filter";
  if (pageInfo) url += "?page_info=" + encodeURIComponent(pageInfo);

  fetch(url)
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
      if (btnSig) btnSig.disabled = !nextPageInfo;

      const btnAnt = document.getElementById("btnAnterior");
      if (btnAnt) {
        btnAnt.disabled = pageHistory.length === 0;
        btnAnt.classList.toggle("opacity-50", btnAnt.disabled);
        btnAnt.classList.toggle("cursor-not-allowed", btnAnt.disabled);
      }

      const total = document.getElementById("total-pedidos");
      if (total) total.textContent = data.count ?? 0;
    })
    .finally(() => {
      isLoading = false;
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

  // quita el actual
  pageHistory.pop();
  const prev = pageHistory.length ? pageHistory[pageHistory.length - 1] : null;
  cargarPedidos(prev);
}

// =====================================================
// TABLA PRINCIPAL (RESPETA 11 COLUMNAS)
// Orden esperado del <thead>:
// Pedido | Fecha | Cliente | Total | Estado | Último cambio | Etiquetas | Artículos | Estado entrega | Forma entrega | Detalles
// =====================================================
function actualizarTabla(pedidos) {
  const tbody = document.getElementById("tablaPedidos");
  if (!tbody) return;

  tbody.innerHTML = "";

  if (!pedidos.length) {
    tbody.innerHTML = `
      <tr>
        <td colspan="11" class="py-4 text-center text-gray-500">
          No se encontraron pedidos
        </td>
      </tr>`;
    return;
  }

  const rows = pedidos
    .map((p) => {
      const id = p.id ?? "";
      return `
      <tr class="border-b hover:bg-gray-50 transition">
        <!-- 1 Pedido -->
        <td class="py-2 px-4">${escapeHtml(p.numero ?? "-")}</td>

        <!-- 2 Fecha -->
        <td class="py-2 px-4">${escapeHtml(p.fecha ?? "-")}</td>

        <!-- 3 Cliente -->
        <td class="py-2 px-4">${escapeHtml(p.cliente ?? "-")}</td>

        <!-- 4 Total -->
        <td class="py-2 px-4">${escapeHtml(p.total ?? "-")}</td>

        <!-- 5 Estado -->
        <td class="py-2 px-2">
          <button onclick="abrirModal(${id})" class="font-semibold">
            ${escapeHtml(p.estado ?? "-")}
          </button>
        </td>

        <!-- 6 ÚLTIMO CAMBIO (NUEVA) -->
        <td class="py-2 px-4" data-lastchange="${id}">
          ${renderLastChange(p)}
        </td>

        <!-- 7 Etiquetas -->
        <td class="py-2 px-4">${formatearEtiquetas(p.etiquetas ?? "", id)}</td>

        <!-- 8 Artículos -->
        <td class="py-2 px-4">${escapeHtml(p.articulos ?? "-")}</td>

        <!-- 9 Estado entrega -->
        <td class="py-2 px-4">${escapeHtml(p.estado_envio ?? "-")}</td>

        <!-- 10 Forma entrega -->
        <td class="py-2 px-4">${escapeHtml(p.forma_envio ?? "-")}</td>

        <!-- 11 Detalles -->
        <td class="py-2 px-4">
          <button onclick="verDetalles(${id})" class="text-blue-600 underline">
            Ver detalles
          </button>
        </td>
      </tr>`;
    })
    .join("");

  tbody.innerHTML = rows;
}

// =====================================================
// ETIQUETAS
// =====================================================
function formatearEtiquetas(etiquetas, orderId) {
  if (!etiquetas) {
    return `<button onclick="abrirModalEtiquetas(${orderId}, '')"
            class="text-blue-600 underline">Agregar</button>`;
  }

  let lista = String(etiquetas)
    .split(",")
    .map((t) => t.trim())
    .filter(Boolean);

  const tagsHtml = lista
    .map((tag) => {
      const cls = colorEtiqueta(tag);
      return `<span class="px-2 py-1 rounded-full text-xs font-semibold ${cls}">
        ${escapeHtml(tag)}
      </span>`;
    })
    .join("");

  return `
    <div class="flex flex-wrap gap-2">
      ${tagsHtml}
      <button onclick="abrirModalEtiquetas(${orderId}, '${escapeJsString(etiquetas)}')"
              class="text-blue-600 underline text-xs ml-2">
        Editar
      </button>
    </div>`;
}

function abrirModalEtiquetas(orderId, textos = "") {
  document.getElementById("modalTagOrderId").value = orderId;

  etiquetasSeleccionadas = textos
    ? String(textos).split(",").map((s) => s.trim()).filter(Boolean)
    : [];

  renderEtiquetasSeleccionadas();
  mostrarEtiquetasRapidas();

  document.getElementById("modalEtiquetas").classList.remove("hidden");
}

function cerrarModalEtiquetas() {
  document.getElementById("modalEtiquetas").classList.add("hidden");
}

async function guardarEtiquetas() {
  let id = document.getElementById("modalTagOrderId").value;
  let tags = etiquetasSeleccionadas.join(", ");

  let r = await fetch("/api/estado/etiquetas/guardar", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ id, tags }),
  });

  let d = await r.json();

  if (d.success) {
    cerrarModalEtiquetas();
    cargarPedidos();
  }
}

function renderEtiquetasSeleccionadas() {
  let cont = document.getElementById("etiquetasSeleccionadas");
  cont.innerHTML = "";

  etiquetasSeleccionadas.forEach((tag, index) => {
    cont.innerHTML += `
      <span class="px-2 py-1 bg-gray-200 rounded-full text-xs">
        ${escapeHtml(tag)}
        <button onclick="eliminarEtiqueta(${index})" class="text-red-600 ml-1">×</button>
      </span>`;
  });
}

function eliminarEtiqueta(i) {
  etiquetasSeleccionadas.splice(i, 1);
  renderEtiquetasSeleccionadas();
}

function mostrarEtiquetasRapidas() {
  let cont = document.getElementById("listaEtiquetasRapidas");
  cont.innerHTML = "";

  (window.etiquetasPredeterminadas || []).forEach((tag) => {
    cont.innerHTML += `
      <button onclick="agregarEtiqueta('${escapeJsString(tag)}')"
              class="px-2 py-1 bg-gray-100 hover:bg-gray-200 rounded text-sm">
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
  if (tag.startsWith("d.")) return "bg-green-200 text-green-900";
  if (tag.startsWith("p.")) return "bg-yellow-200 text-yellow-900";
  return "bg-gray-200 text-gray-700";
}

// =====================================================
// ÚLTIMO CAMBIO (FORMATEO + RENDER)
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
    return `<span class="text-gray-400 text-sm">—</span>`;
  }

  const user = info.user_name ? escapeHtml(info.user_name) : "—";

  return `
    <div class="text-sm leading-tight">
      <div class="font-semibold text-gray-800">${user}</div>
      <div class="text-gray-600">${escapeHtml(formatDateFull(info.changed_at))}</div>
      <div class="text-xs text-gray-500">Hace ${escapeHtml(timeAgo(info.changed_at))}</div>
    </div>
  `;
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

  // OJO: si aún no tienes este endpoint, te tocará crearlo
  const r = await fetch("/api/estado/guardar", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ id, estado: nuevoEstado }),
  });

  const d = await r.json();

  if (d.success) {
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
    });
}

function cerrarModalDetalles() {
  document.getElementById("modalDetalles").classList.add("hidden");
}

// Panel cliente
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
    if (prev) prev.innerHTML = `<img src="${e.target.result}" class="w-32 mt-2 rounded shadow">`;
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
      if (prev) prev.innerHTML = `<img src="${escapeHtml(res.url)}" class="w-32 mt-2 rounded shadow">`;

      window.imagenesLocales[index] = res.url;
      window.imagenesCargadas[index] = true;

      validarEstadoFinal(orderId);
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
    .then(() => cargarPedidos());
}
