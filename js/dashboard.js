// =====================================================
// CONFIG / HELPERS
// =====================================================

// Si tu proyecto usa index.php en las rutas, pon esto en true.
// Si no lo usas (rutas limpias), déjalo en false.
const USE_INDEX_PHP = true;

// Base URL helper
function url(path) {
  if (!path.startsWith("/")) path = "/" + path;
  return USE_INDEX_PHP ? "/index.php" + path : path;
}

// Escape simple para strings dentro de atributos HTML con comillas simples
function escapeForSingleQuotes(str) {
  return String(str ?? "").replace(/\\/g, "\\\\").replace(/'/g, "\\'");
}

// Loader global
function showLoader() {
  document.getElementById("globalLoader")?.classList.remove("hidden");
}
function hideLoader() {
  document.getElementById("globalLoader")?.classList.add("hidden");
}

// =====================================================
// VARIABLES GLOBALES
// =====================================================
let nextPageInfo = null;
let isLoading = false;
let etiquetasSeleccionadas = [];
window.imagenesCargadas = [];
window.imagenesLocales = {}; // imágenes locales por índice
let pageHistory = [];        // historial de page_info visitados

// =====================================================
// INICIALIZAR
// =====================================================
document.addEventListener("DOMContentLoaded", () => {
  // Enlazar botones de paginación (seguro aunque no tengan onclick)
  document.getElementById("btnSiguiente")?.addEventListener("click", (e) => {
    e.preventDefault();
    paginaSiguiente();
  });

  document.getElementById("btnAnterior")?.addEventListener("click", (e) => {
    e.preventDefault();
    paginaAnterior();
  });

  cargarPedidos();
});

// =====================================================
// DETECTAR SI UNA URL ES UNA IMAGEN REAL
// =====================================================
function esImagen(urlStr) {
  if (!urlStr) return false;
  return String(urlStr).match(/\.(jpeg|jpg|png|gif|webp|svg)$/i);
}

// =====================================================
// CARGAR PEDIDOS
// =====================================================
function cargarPedidos(pageInfo = null) {
  if (isLoading) return;
  isLoading = true;

  let endpoint = url("/dashboard/filter");
  if (pageInfo) endpoint += "?page_info=" + encodeURIComponent(pageInfo);

  fetch(endpoint)
    .then((res) => res.json())
    .then((data) => {
      if (!data || !data.success) {
        console.error("Error backend /dashboard/filter:", data);
        actualizarTabla([]);
        document.getElementById("total-pedidos").textContent = "0";
        return;
      }

      // Historial anterior
      if (pageInfo) {
        if (pageHistory[pageHistory.length - 1] !== pageInfo) {
          pageHistory.push(pageInfo);
        }
      } else {
        pageHistory = []; // primera página
      }

      nextPageInfo = data.next_page_info ?? null;

      actualizarTabla(data.orders || []);

      // Botón siguiente
      const btnSiguiente = document.getElementById("btnSiguiente");
      if (btnSiguiente) btnSiguiente.disabled = !nextPageInfo;

      // Botón anterior
      const btnAnterior = document.getElementById("btnAnterior");
      if (btnAnterior) {
        btnAnterior.disabled = pageHistory.length === 0;
        btnAnterior.classList.toggle("opacity-50", btnAnterior.disabled);
        btnAnterior.classList.toggle("cursor-not-allowed", btnAnterior.disabled);
      }

      document.getElementById("total-pedidos").textContent = data.count ?? (data.orders?.length ?? 0);
    })
    .catch((err) => {
      console.error("Error fetch pedidos:", err);
      actualizarTabla([]);
      document.getElementById("total-pedidos").textContent = "0";
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
  if (pageHistory.length === 0) {
    cargarPedidos(null);
    return;
  }

  // quitar página actual
  pageHistory.pop();

  // anterior real
  const prev = pageHistory.length ? pageHistory[pageHistory.length - 1] : null;
  cargarPedidos(prev);
}

// Exponer por si el HTML tiene onclick
window.paginaSiguiente = paginaSiguiente;
window.paginaAnterior = paginaAnterior;

// =====================================================
// TABLA PRINCIPAL
// =====================================================
function actualizarTabla(pedidos) {
  const tbody = document.getElementById("tablaPedidos");
  if (!tbody) return;

  tbody.innerHTML = "";

  if (!pedidos || !pedidos.length) {
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

        <!-- ÚLTIMO CAMBIO -->
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

  const etiquetasEsc = escapeForSingleQuotes(etiquetas);
  const lista = String(etiquetas).split(",").map((t) => t.trim()).filter(Boolean);

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
      <button onclick="abrirModalEtiquetas(${orderId}, '${etiquetasEsc}')"
              class="text-blue-600 underline text-xs ml-2">
        Editar
      </button>
    </div>`;
}

function colorEtiqueta(tag) {
  tag = String(tag ?? "").toLowerCase().trim();
  if (tag.startsWith("d.")) return "bg-green-200 text-green-900";
  if (tag.startsWith("p.")) return "bg-yellow-200 text-yellow-900";
  return "bg-gray-200 text-gray-700";
}

// =====================================================
// ÚLTIMO CAMBIO: FORMATEO + RENDER
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

  const user = info.user_name ? info.user_name : "—";

  return `
    <div class="text-sm">
      <div class="font-semibold text-gray-800">${user}</div>
      <div class="text-gray-600">${formatDateFull(info.changed_at)}</div>
      <div class="text-xs text-gray-500">Hace ${timeAgo(info.changed_at)}</div>
    </div>`;
}

window.renderLastChange = renderLastChange;

// =====================================================
// VER DETALLES DEL PEDIDO
// =====================================================
function verDetalles(orderId) {
  document.getElementById("modalDetalles")?.classList.remove("hidden");

  document.getElementById("detalleProductos").innerHTML = "Cargando...";
  document.getElementById("detalleCliente").innerHTML = "";
  document.getElementById("detalleEnvio").innerHTML = "";
  document.getElementById("detalleTotales").innerHTML = "";
  document.getElementById("tituloPedido").innerHTML = "Cargando...";

  fetch(url(`/dashboard/detalles/${orderId}`))
    .then((r) => r.json())
    .then((data) => {
      if (!data.success) {
        document.getElementById("detalleProductos").innerHTML =
          "<p class='text-red-500'>Error cargando detalles.</p>";
        return;
      }

      const o = data.order;

      window.imagenesLocales = data.imagenes_locales ?? {};

      document.getElementById("tituloPedido").innerHTML = `Detalles del pedido ${o.name}`;

      document.getElementById("detalleCliente").innerHTML = `
        <p><strong>${o.customer?.first_name ?? ""} ${o.customer?.last_name ?? ""}</strong></p>
        <p>Email: ${o.email ?? "-"}</p>
        <p>Teléfono: ${o.phone ?? "-"}</p>
      `;

      const a = o.shipping_address ?? {};
      document.getElementById("detalleEnvio").innerHTML = `
        <p>${a.address1 ?? ""}</p>
        <p>${a.city ?? ""}, ${a.zip ?? ""}</p>
        <p>${a.country ?? ""}</p>
      `;

      document.getElementById("detalleTotales").innerHTML = `
        <p><strong>Subtotal:</strong> ${o.subtotal_price} €</p>
        <p><strong>Envío:</strong> ${o.total_shipping_price_set?.shop_money?.amount ?? "0"} €</p>
        <p><strong>Total:</strong> ${o.total_price} €</p>
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
                    <span class="font-semibold">${p.name}</span><br>
                    <img src="${p.value}" class="w-28 rounded shadow">
                  </div>`;
              }
              return `<p><strong>${p.name}:</strong> ${p.value}</p>`;
            })
            .join("");
        }

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
    })
    .catch((e) => {
      console.error("Error detalles:", e);
      document.getElementById("detalleProductos").innerHTML =
        "<p class='text-red-500'>Error cargando detalles.</p>";
    });
}
window.verDetalles = verDetalles;

// =====================================================
// SUBIR IMAGEN AL SERVIDOR Y MOSTRARLA
// =====================================================
function subirImagenProducto(orderId, index, input) {
  if (!input.files.length) return;
  const file = input.files[0];

  const reader = new FileReader();
  reader.onload = (e) => {
    document.getElementById(`preview_${orderId}_${index}`).innerHTML =
      `<img src="${e.target.result}" class="w-32 mt-2 rounded shadow">`;
  };
  reader.readAsDataURL(file);

  showLoader();

  const form = new FormData();
  form.append("orderId", orderId);
  form.append("index", index);
  form.append("file", file);

  fetch(url("/dashboard/subirImagenProducto"), {
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

      document.getElementById(`preview_${orderId}_${index}`).innerHTML =
        `<img src="${res.url}" class="w-32 mt-2 rounded shadow">`;

      window.imagenesLocales[index] = res.url;
      window.imagenesCargadas[index] = true;

      validarEstadoFinal(orderId);
    })
    .catch((e) => {
      hideLoader();
      console.error("Error subir imagen:", e);
      alert("Error subiendo imagen");
    });
}
window.subirImagenProducto = subirImagenProducto;

// =====================================================
// VALIDAR ESTADO FINAL
// =====================================================
// IMPORTANTE: Esta ruta /api/estado/guardar tú dijiste que NO la tienes.
// Dejo la función, pero si no existe el endpoint te va a dar 404.
// Puedes comentar este bloque si aún no lo implementas.
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
    .catch(() => {
      // si no existe endpoint, no rompas el dashboard
      console.warn("Endpoint /api/estado/guardar no existe aún (ignorado).");
    });
}

// =====================================================
// MODALES
// =====================================================
function cerrarModalDetalles() {
  document.getElementById("modalDetalles")?.classList.add("hidden");
}
window.cerrarModalDetalles = cerrarModalDetalles;

function abrirPanelCliente() {
  document.getElementById("panelCliente")?.classList.remove("hidden");
}
function cerrarPanelCliente() {
  document.getElementById("panelCliente")?.classList.add("hidden");
}
window.abrirPanelCliente = abrirPanelCliente;
window.cerrarPanelCliente = cerrarPanelCliente;

// =====================================================
// ESTADO MANUAL
// =====================================================
function abrirModal(orderId) {
  document.getElementById("modalOrderId").value = orderId;
  document.getElementById("modalEstado")?.classList.remove("hidden");
}
function cerrarModal() {
  document.getElementById("modalEstado")?.classList.add("hidden");
}
window.abrirModal = abrirModal;
window.cerrarModal = cerrarModal;

// OJO: también usa /api/estado/guardar (si no existe, dará 404)
async function guardarEstado(nuevoEstado) {
  const id = document.getElementById("modalOrderId").value;

  try {
    const r = await fetch("/api/estado/guardar", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id, estado: nuevoEstado }),
    });

    const d = await r.json();

    if (d.success) {
      cerrarModal();
      cargarPedidos();
    } else {
      alert(d.message || "Error guardando estado");
    }
  } catch (e) {
    console.warn("Endpoint /api/estado/guardar no existe aún o falló.");
    // no romper UI
  }
}
window.guardarEstado = guardarEstado;

// =====================================================
// ETIQUETAS
// =====================================================
function abrirModalEtiquetas(orderId, textos = "") {
  document.getElementById("modalTagOrderId").value = orderId;

  etiquetasSeleccionadas = textos ? String(textos).split(",").map((s) => s.trim()).filter(Boolean) : [];

  renderEtiquetasSeleccionadas();
  mostrarEtiquetasRapidas();

  document.getElementById("modalEtiquetas")?.classList.remove("hidden");
}
function cerrarModalEtiquetas() {
  document.getElementById("modalEtiquetas")?.classList.add("hidden");
}
window.abrirModalEtiquetas = abrirModalEtiquetas;
window.cerrarModalEtiquetas = cerrarModalEtiquetas;

// OJO: también usa /api/estado/etiquetas/guardar (si no existe, dará 404)
async function guardarEtiquetas() {
  const id = document.getElementById("modalTagOrderId").value;
  const tags = etiquetasSeleccionadas.join(", ");

  try {
    const r = await fetch("/api/estado/etiquetas/guardar", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id, tags }),
    });

    const d = await r.json();

    if (d.success) {
      cerrarModalEtiquetas();
      cargarPedidos();
    } else {
      alert(d.message || "Error guardando etiquetas");
    }
  } catch (e) {
    console.warn("Endpoint /api/estado/etiquetas/guardar no existe aún o falló.");
  }
}
window.guardarEtiquetas = guardarEtiquetas;

function renderEtiquetasSeleccionadas() {
  const cont = document.getElementById("etiquetasSeleccionadas");
  if (!cont) return;
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
window.eliminarEtiqueta = eliminarEtiqueta;

function mostrarEtiquetasRapidas() {
  const cont = document.getElementById("listaEtiquetasRapidas");
  if (!cont) return;
  cont.innerHTML = "";

  (window.etiquetasPredeterminadas || []).forEach((tag) => {
    cont.innerHTML += `
      <button onclick="agregarEtiqueta('${escapeForSingleQuotes(tag)}')"
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
window.agregarEtiqueta = agregarEtiqueta;
