// =====================================================
// VARIABLES GLOBALES
// =====================================================
let nextPageInfo = null;
let isLoading = false;
let etiquetasSeleccionadas = [];
window.imagenesCargadas = [];
window.imagenesLocales = {}; // NUEVO: almacenará imágenes locales por índice

// NUEVO: historial de page_info para botón "Anterior"
let pageHistory = []; // guarda page_info visitados (excepto primera)

// Loader global
function showLoader() {
  document.getElementById("globalLoader").classList.remove("hidden");
}
function hideLoader() {
  document.getElementById("globalLoader").classList.add("hidden");
}

// =====================================================
// INICIALIZAR
// =====================================================
document.addEventListener("DOMContentLoaded", () => {
  cargarPedidos();
});

// =====================================================
// DETECTAR SI UNA URL ES UNA IMAGEN REAL
// =====================================================
function esImagen(url) {
  if (!url) return false;
  return url.match(/\.(jpeg|jpg|png|gif|webp|svg)$/i);
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
      if (!data.success) return;

      // Guardar historial para botón anterior
      if (pageInfo) {
        // solo agrega si es diferente al último
        if (pageHistory[pageHistory.length - 1] !== pageInfo) {
          pageHistory.push(pageInfo);
        }
      } else {
        pageHistory = []; // si volvemos a primera página, resetea
      }

      nextPageInfo = data.next_page_info ?? null;

      actualizarTabla(data.orders || []);
      document.getElementById("btnSiguiente").disabled = !nextPageInfo;

      // Botón anterior
      const btnAnterior = document.getElementById("btnAnterior");
      if (btnAnterior) {
        btnAnterior.disabled = pageHistory.length === 0;
        btnAnterior.classList.toggle("opacity-50", btnAnterior.disabled);
        btnAnterior.classList.toggle("cursor-not-allowed", btnAnterior.disabled);
      }

      document.getElementById("total-pedidos").textContent = data.count ?? 0;
    })
    .finally(() => (isLoading = false));
}

// =====================================================
// SIGUIENTE / ANTERIOR
// =====================================================
function paginaSiguiente() {
  if (nextPageInfo) cargarPedidos(nextPageInfo);
}

function paginaAnterior() {
  // Para volver atrás: si estoy en página 2, el "anterior" es primera (pageInfo null)
  if (pageHistory.length === 0) {
    cargarPedidos(null);
    return;
  }

  // Quita el último page_info (página actual)
  pageHistory.pop();

  // El nuevo "actual" sería el último guardado, o null si ya no hay
  const prev = pageHistory.length ? pageHistory[pageHistory.length - 1] : null;
  cargarPedidos(prev);
}

// =====================================================
// TABLA PRINCIPAL
// =====================================================
function actualizarTabla(pedidos) {
  const tbody = document.getElementById("tablaPedidos");
  tbody.innerHTML = "";

  // ✅ ahora hay 11 columnas (porque agregaste "ÚLTIMO CAMBIO")
  if (!pedidos.length) {
    tbody.innerHTML = `
      <tr>
        <td colspan="11" class="py-4 text-center text-gray-500">
          No se encontraron pedidos
        </td>
      </tr>`;
    return;
  }

  pedidos.forEach((p) => {
    tbody.innerHTML += `
      <tr class="border-b hover:bg-gray-50 transition">
        <td class="py-2 px-4">${p.numero ?? "-"}</td>
        <td class="py-2 px-4">${p.fecha ?? "-"}</td>
        <td class="py-2 px-4">${p.cliente ?? "-"}</td>
        <td class="py-2 px-4">${p.total ?? "-"}</td>

        <td class="py-2 px-2">
          <button onclick="abrirModal(${p.id})" class="font-semibold">
            ${p.estado ?? "-"}
          </button>
        </td>

        <!-- ✅ COLUMNA NUEVA: ÚLTIMO CAMBIO -->
        <td class="py-2 px-4" data-lastchange="${p.id}">
          ${renderLastChange(p)}
        </td>

        <td class="py-2 px-4">${formatearEtiquetas(p.etiquetas, p.id)}</td>
        <td class="py-2 px-4">${p.articulos ?? "-"}</td>
        <td class="py-2 px-4">${p.estado_envio ?? "-"}</td>
        <td class="py-2 px-4">${p.forma_envio ?? "-"}</td>

        <td class="py-2 px-4">
          <button onclick="verDetalles(${p.id})" class="text-blue-600 underline">
            Ver detalles
          </button>
        </td>
      </tr>`;
  });
}

// =====================================================
// FORMATO ETIQUETAS
// =====================================================
function formatearEtiquetas(etiquetas, orderId) {
  if (!etiquetas) {
    return `<button onclick="abrirModalEtiquetas(${orderId}, '')"
            class="text-blue-600 underline">Agregar</button>`;
  }

  let lista = etiquetas.split(",").map((t) => t.trim());

  return `
    <div class="flex flex-wrap gap-2">
      ${lista
        .map(
          (tag) => `
          <span class="px-2 py-1 rounded-full text-xs font-semibold ${colorEtiqueta(tag)}">
            ${tag}
          </span>`
        )
        .join("")}
      <button onclick="abrirModalEtiquetas(${orderId}, '${etiquetas}')"
              class="text-blue-600 underline text-xs ml-2">
        Editar
      </button>
    </div>`;
}

// =====================================================
// VER DETALLES DEL PEDIDO
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

      let o = data.order;

      // NUEVO: guardar imágenes locales recibidas desde el backend
      window.imagenesLocales = data.imagenes_locales ?? {};

      document.getElementById("tituloPedido").innerHTML = `Detalles del pedido ${o.name}`;

      // CLIENTE
      document.getElementById("detalleCliente").innerHTML = `
        <p><strong>${o.customer?.first_name ?? ""} ${o.customer?.last_name ?? ""}</strong></p>
        <p>Email: ${o.email ?? "-"}</p>
        <p>Teléfono: ${o.phone ?? "-"}</p>
      `;

      // ENVÍO
      let a = o.shipping_address ?? {};
      document.getElementById("detalleEnvio").innerHTML = `
        <p>${a.address1 ?? ""}</p>
        <p>${a.city ?? ""}, ${a.zip ?? ""}</p>
        <p>${a.country ?? ""}</p>
      `;

      // TOTALES
      document.getElementById("detalleTotales").innerHTML = `
        <p><strong>Subtotal:</strong> ${o.subtotal_price} €</p>
        <p><strong>Envío:</strong> ${o.total_shipping_price_set?.shop_money?.amount ?? "0"} €</p>
        <p><strong>Total:</strong> ${o.total_price} €</p>
      `;

      // PRODUCTOS
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
                    <span class="font-semibold">${p.name}</span><br>
                    <img src="${p.value}" class="w-28 rounded shadow">
                  </div>`;
              }

              return `<p><strong>${p.name}:</strong> ${p.value}</p>`;
            })
            .join("");
        }

        // NUEVO: Si la imagen local existe → mostrarla
        let imagenLocalHTML = "";
        if (window.imagenesLocales[index]) {
          imagenLocalHTML = `
            <div class="mt-3">
              <p class="font-semibold text-sm">Imagen cargada:</p>
              <img src="${window.imagenesLocales[index]}"
                   class="w-32 rounded shadow mt-1">
            </div>`;
        }

        html += `
          <div class="p-4 border rounded-lg shadow bg-white">
            <h4 class="font-semibold">${item.title}</h4>
            <p>Cantidad: ${item.quantity}</p>
            <p>Precio: ${item.price} €</p>

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

// =====================================================
// SUBIR IMAGEN AL SERVIDOR Y MOSTRARLA
// =====================================================
function subirImagenProducto(orderId, index, input) {
  if (!input.files.length) return;
  let file = input.files[0];

  // Preview inmediata
  const reader = new FileReader();
  reader.onload = (e) => {
    document.getElementById(`preview_${orderId}_${index}`).innerHTML =
      `<img src="${e.target.result}" class="w-32 mt-2 rounded shadow">`;
  };
  reader.readAsDataURL(file);

  showLoader();

  let form = new FormData();
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

      // Mostrar imagen final guardada en el servidor
      document.getElementById(`preview_${orderId}_${index}`).innerHTML =
        `<img src="${res.url}" class="w-32 mt-2 rounded shadow">`;

      // Guardar en memoria local para próximas aperturas
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

// =====================================================
// CERRAR MODALES
// =====================================================
function cerrarModalDetalles() {
  document.getElementById("modalDetalles").classList.add("hidden");
}

// =====================================================
// PANEL CLIENTE
// =====================================================
function abrirPanelCliente() {
  document.getElementById("panelCliente").classList.remove("hidden");
}
function cerrarPanelCliente() {
  document.getElementById("panelCliente").classList.add("hidden");
}

// =====================================================
// ESTADO MANUAL
// =====================================================
function abrirModal(orderId) {
  document.getElementById("modalOrderId").value = orderId;
  document.getElementById("modalEstado").classList.remove("hidden");
}

function cerrarModal() {
  document.getElementById("modalEstado").classList.add("hidden");
}

async function guardarEstado(nuevoEstado) {
  let id = document.getElementById("modalOrderId").value;

  let r = await fetch("/api/estado/guardar", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ id, estado: nuevoEstado }),
  });

  let d = await r.json();

  if (d.success) {
    cerrarModal();

    // ✅ Si tu backend ya devuelve last_status_change aquí, se puede actualizar sin recargar.
    // Por ahora recargamos para asegurar consistencia.
    cargarPedidos();
  }
}

// =====================================================
// ETIQUETAS
// =====================================================
function abrirModalEtiquetas(orderId, textos = "") {
  document.getElementById("modalTagOrderId").value = orderId;

  etiquetasSeleccionadas = textos ? textos.split(",").map((s) => s.trim()) : [];

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
        ${tag}
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

  etiquetasPredeterminadas.forEach((tag) => {
    cont.innerHTML += `
      <button onclick="agregarEtiqueta('${tag}')"
              class="px-2 py-1 bg-gray-100 hover:bg-gray-200 rounded text-sm">
        ${tag}
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
  tag = tag.toLowerCase().trim();
  if (tag.startsWith("d.")) return "bg-green-200 text-green-900";
  if (tag.startsWith("p.")) return "bg-yellow-200 text-yellow-900";
  return "bg-gray-200 text-gray-700";
}

// =====================================================
// ÚLTIMO CAMBIO: FORMATEO + RENDER
// =====================================================
function formatDateFull(dtStr) {
  if (!dtStr) return "-";
  const d = new Date(dtStr.replace(" ", "T"));
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
  const d = new Date(dtStr.replace(" ", "T"));
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
  const info = p.last_status_change;
  if (!info || !info.changed_at)
    return `<span class="text-gray-400 text-sm">—</span>`;

  return `
    <div class="text-sm">
      <div class="font-semibold text-gray-800">${info.user_name}</div>
      <div class="text-gray-600">${formatDateFull(info.changed_at)}</div>
      <div class="text-xs text-gray-500">Hace ${timeAgo(info.changed_at)}</div>
    </div>
  `;
}

// alias por compatibilidad (por si ya llamabas renderLastChange en algún lado)
function renderLastChangeWrapper(p) {
  return renderLastChange(p);
}
window.renderLastChange = renderLastChange; // por si el HTML lo llama

// =====================================================
// OPCIONAL: CAMBIO ESTADO EN VIVO (si usas endpoint que devuelva last_status_change)
// =====================================================
async function guardarCambioEstado(pedidoId, nuevoEstado) {
  const res = await fetch("/api/estado/guardar", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ id: pedidoId, estado: nuevoEstado }),
  });

  const data = await res.json();

  if (!data.success) {
    alert(data.message || "Error");
    return;
  }

  // Si el backend devuelve last_status_change, actualiza celda
  if (data.last_status_change) {
    const cell = document.querySelector(`[data-lastchange="${pedidoId}"]`);
    if (cell) {
      const fakePedido = { last_status_change: data.last_status_change };
      cell.innerHTML = renderLastChange(fakePedido);
    }
  } else {
    // fallback seguro
    cargarPedidos();
  }
}

// =====================================================
// ENLAZAR BOTÓN ANTERIOR SI EXISTE
// =====================================================
document.addEventListener("click", (e) => {
  const btn = e.target?.id === "btnAnterior" ? e.target : null;
  if (btn) {
    e.preventDefault();
    if (!btn.disabled) paginaAnterior();
  }
});
