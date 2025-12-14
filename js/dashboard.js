// =====================================================
// VARIABLES GLOBALES
// =====================================================
let nextPageInfo = null;
let isLoading = false;
let etiquetasSeleccionadas = [];
window.imagenesCargadas = []; // Para validar carga de imágenes

// =====================================================
// INICIALIZAR
// =====================================================
document.addEventListener("DOMContentLoaded", () => {
    cargarPedidos();
});

// =====================================================
// DETECTAR SI UNA URL ES UNA IMAGEN REAL DE CDN
// =====================================================
function esImagen(url) {
    if (!url) return false;

    const esURL = url.startsWith("http://") || url.startsWith("https://");
    if (!esURL) return false;

    // Detecta imágenes reales de Shopify CDN
    return url.match(/cdn.*\.(jpeg|jpg|png|gif|webp|svg)(\?.*)?$/i);
}

// =====================================================
// PETICIÓN DE PEDIDOS
// =====================================================
function cargarPedidos(pageInfo = null) {
    if (isLoading) return;
    isLoading = true;

    let url = "/dashboard/filter";
    if (pageInfo) url += "?page_info=" + encodeURIComponent(pageInfo);

    fetch(url)
        .then(res => res.json())
        .then(data => {

            if (!data.success) {
                console.error("Error cargando pedidos:", data);
                return;
            }

            nextPageInfo = data.next_page_info ?? null;

            actualizarTabla(data.orders);

            document.getElementById("btnSiguiente").disabled = !nextPageInfo;
            document.getElementById("total-pedidos").textContent = data.count;
        })
        .finally(() => isLoading = false);
}

// =====================================================
// SIGUIENTE PÁGINA
// =====================================================
function paginaSiguiente() {
    if (nextPageInfo) cargarPedidos(nextPageInfo);
}

// =====================================================
// TABLA PRINCIPAL
// =====================================================
function actualizarTabla(pedidos) {
    const tbody = document.getElementById("tablaPedidos");
    tbody.innerHTML = "";

    if (!pedidos.length) {
        tbody.innerHTML = `
            <tr>
                <td colspan="10" class="text-center text-gray-500 py-4">
                    No se encontraron pedidos
                </td>
            </tr>`;
        return;
    }

    pedidos.forEach(p => {
        tbody.innerHTML += `
            <tr class="border-b hover:bg-gray-50 transition">
                <td class="py-2 px-4">${p.numero}</td>
                <td class="py-2 px-4">${p.fecha}</td>
                <td class="py-2 px-4">${p.cliente}</td>
                <td class="py-2 px-4">${p.total}</td>

                <td class="py-2 px-2">
                    <button onclick="abrirModal(${p.id})" class="font-semibold">
                        ${p.estado}
                    </button>
                </td>

                <td class="py-2 px-4">${formatearEtiquetas(p.etiquetas, p.id)}</td>
                <td class="py-2 px-4">${p.articulos}</td>
                <td class="py-2 px-4">${p.estado_envio}</td>
                <td class="py-2 px-4">${p.forma_envio}</td>

                <td class="py-2 px-4">
                    <button onclick="verDetalles(${p.id})"
                            class="text-blue-600 underline">
                        Ver detalles
                    </button>
                </td>
            </tr>
        `;
    });
}

// =====================================================
// FORMATO DE ETIQUETAS
// =====================================================
function formatearEtiquetas(etiquetas, orderId) {
    if (!etiquetas) {
        return `<button onclick="abrirModalEtiquetas(${orderId}, '')"
                class="text-blue-600 underline">Agregar</button>`;
    }

    let lista = etiquetas.split(",").map(e => e.trim());

    return `
        <div class="flex flex-wrap gap-2">
            ${lista.map(tag => `
                <span class="px-2 py-1 rounded-full text-xs font-semibold ${colorEtiqueta(tag)}">
                    ${tag}
                </span>`).join("")}

            <button onclick="abrirModalEtiquetas(${orderId}, '${etiquetas}')"
                    class="text-blue-600 underline text-xs ml-2">
                Editar
            </button>
        </div>
    `;
}

// =====================================================
// ABRIR DETALLES DEL PEDIDO
// =====================================================
function verDetalles(orderId) {

    document.getElementById("modalDetalles").classList.remove("hidden");

    // LIMPIAR
    document.getElementById("detalleProductos").innerHTML = "Cargando productos...";
    document.getElementById("detalleCliente").innerHTML = "";
    document.getElementById("detalleEnvio").innerHTML = "";
    document.getElementById("detalleTotales").innerHTML = "";
    document.getElementById("tituloPedido").innerHTML = `Cargando...`;

    fetch(`/index.php/dashboard/detalles/${orderId}`)
        .then(r => r.json())
        .then(data => {

            if (!data.success) {
                document.getElementById("detalleProductos").innerHTML =
                    "<p class='text-red-500'>Error cargando detalles.</p>";
                return;
            }

            let o = data.order;

            // TÍTULO CORRECTO
            document.getElementById("tituloPedido").innerHTML =
                `Detalles del pedido ${o.name}`;

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

                let propsHTML = item.properties?.length
                    ? item.properties.map(p => {
                        if (esImagen(p.value)) {
                            return `
                                <div class="mt-2">
                                    <span class="font-semibold text-sm">${p.name}:</span><br>
                                    <img src="${p.value}" class="w-28 rounded shadow">
                                </div>`;
                        }
                        return `<p><strong>${p.name}:</strong> ${p.value}</p>`;
                    }).join("")
                    : "";

                html += `
                    <div class="p-4 border rounded-lg shadow bg-white">
                        <h4 class="font-semibold">${item.title}</h4>
                        <p>Cantidad: ${item.quantity}</p>
                        <p>Precio: ${item.price} €</p>

                        ${propsHTML}

                        <div class="mt-3">
                            <label class="font-semibold text-sm">Subir imagen:</label>
                            <input type="file" onchange="subirImagenProducto(${orderId}, ${index}, this)"
                                class="mt-1 block w-full border rounded p-2">
                            <div id="preview_${orderId}_${index}"></div>
                        </div>
                    </div>
                `;
            });

            document.getElementById("detalleProductos").innerHTML = html;
        });
}

// =====================================================
// SUBIR IMAGEN Y CAMBIAR ESTADO AUTOMÁTICO
// =====================================================
function subirImagenProducto(orderId, index, input) {

    if (!input.files.length) return;

    const reader = new FileReader();

    reader.onload = e => {
        document.getElementById(`preview_${orderId}_${index}`).innerHTML =
            `<img src="${e.target.result}" class="w-32 mt-2 rounded shadow">`;

        window.imagenesCargadas[index] = true;

        validarEstadoFinal(orderId);
    };

    reader.readAsDataURL(input.files[0]);
}

function validarEstadoFinal(orderId) {

    const listo = window.imagenesCargadas.every(v => v === true);
    const nuevoEstado = listo ? "Producción" : "Faltan diseños";

    fetch("/api/estado/guardar", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: orderId, estado: nuevoEstado })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) cargarPedidos();
    });
}

// =====================================================
// CERRAR MODAL DETALLES
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
// ESTADO DEL PEDIDO
// =====================================================
function abrirModal(orderId) {
    document.getElementById("modalOrderId").value = orderId;
    document.getElementById("modalEstado").classList.remove("hidden");
}

function cerrarModal() {
    document.getElementById("modalEstado").classList.add("hidden");
}

async function guardarEstado(nuevoEstado) {
    let orderId = document.getElementById("modalOrderId").value;

    let r = await fetch("/api/estado/guardar", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: orderId, estado: nuevoEstado })
    });

    let d = await r.json();

    if (d.success) {
        cerrarModal();
        cargarPedidos();
    }
}

// =====================================================
// ETIQUETAS
// =====================================================
function abrirModalEtiquetas(orderId, etiquetasTexto = "") {
    document.getElementById("modalTagOrderId").value = orderId;

    etiquetasSeleccionadas = etiquetasTexto
        ? etiquetasTexto.split(",").map(t => t.trim())
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
        body: JSON.stringify({ id, tags })
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
            <span class="px-2 py-1 rounded-full text-xs font-semibold ${colorEtiqueta(tag)} flex items-center gap-1">
                ${tag}
                <button onclick="eliminarEtiqueta(${index})" class="text-red-600 font-bold">×</button>
            </span>
        `;
    });
}

function eliminarEtiqueta(index) {
    etiquetasSeleccionadas.splice(index, 1);
    renderEtiquetasSeleccionadas();
}

function mostrarEtiquetasRapidas() {
    let cont = document.getElementById("listaEtiquetasRapidas");
    cont.innerHTML = "";

    etiquetasPredeterminadas.forEach(tag => {
        cont.innerHTML += `
            <button onclick="agregarEtiqueta('${tag}')"
                class="px-2 py-1 bg-gray-200 hover:bg-gray-300 rounded text-sm">
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

    if (tag.startsWith("d.")) return "bg-green-200 text-green-900 border border-green-300";
    if (tag.startsWith("p.")) return "bg-yellow-200 text-yellow-900 border border-yellow-300";

    return "bg-gray-200 text-gray-700 border border-gray-300";
}
