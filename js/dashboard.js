// =====================================================
// VARIABLES GLOBALES
// =====================================================
let nextPageInfo = null;
let isLoading = false;

// Etiquetas ya seleccionadas para la orden
let etiquetasSeleccionadas = [];


// =====================================================
// INICIALIZAR AL CARGAR LA PÁGINA
// =====================================================
document.addEventListener("DOMContentLoaded", () => {
    cargarPedidos();
});


// =====================================================
// PETICIÓN PRINCIPAL → TRAE TODOS LOS PEDIDOS
// =====================================================
function cargarPedidos(pageInfo = null) {
    if (isLoading) return;
    isLoading = true;

    let url = "/dashboard/filter";

    if (pageInfo) {
        url += "?page_info=" + encodeURIComponent(pageInfo);
    }

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
            document.getElementById("btnAnterior").disabled = true;

            document.getElementById("total-pedidos").textContent = data.count;
        })
        .catch(err => console.error(err))
        .finally(() => {
            isLoading = false;
        });
}


// =====================================================
// SIGUIENTE PÁGINA
// =====================================================
function paginaSiguiente() {
    if (nextPageInfo) {
        cargarPedidos(nextPageInfo);
    }
}


// =====================================================
// TABLA PRINCIPAL
// =====================================================
function actualizarTabla(pedidos) {
    const tbody = document.getElementById("tablaPedidos");
    tbody.innerHTML = "";

    if (!pedidos || pedidos.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="9" class="text-center text-gray-500 py-4">
                    No se encontraron pedidos
                </td>
            </tr>`;
        return;
    }

    pedidos.forEach(p => {
        tbody.innerHTML += `
            <tr class="border-b hover:bg-gray-50 transition">
                <td class="py-2 px-4">${p.numero}</td>
                <td class="w-40 py-2 px-4">${p.fecha}</td>
                <td class="py-2 px-4">${p.cliente}</td>
                <td class="py-2 px-4">${p.total}</td>

                <td class="w-32 py-2 px-2">
                    <button onclick="abrirModal(${p.id})" class="font-semibold text-gray-800">
                        ${p.estado}
                    </button>
                </td>

                <td class="py-2 px-4">
                    ${formatearEtiquetas(p.etiquetas, p.id)}
                </td>

                <td class="py-2 px-4">${p.articulos}</td>
                <td class="py-2 px-4">${p.estado_envio}</td>
                <td class="py-2 px-4">${p.forma_envio}</td>
                <td class="py-2 px-4 flex gap-2">
                    <!-- Botón info -->
                    <button onclick="verDetalles(${p.id})" 
                        class="px-2 py-1 bg-indigo-600 text-white rounded-lg text-xs hover:bg-indigo-700">
                        Info
                    </button>
                </td>

            </tr>
        `;
    });
}


// =====================================================
// FORMATEAR ETIQUETAS COMO CHIPS
// =====================================================
function formatearEtiquetas(etiquetas, orderId) {
    if (!etiquetas || etiquetas.trim() === "") {
        return `<button onclick="abrirModalEtiquetas(${orderId}, '')"
                    class="text-blue-600 underline">Agregar</button>`;
    }

    let lista = etiquetas.split(",").map(e => e.trim());

    return `
        <div class="flex flex-wrap gap-2">
            ${lista
                .map(tag => `
                    <span class="px-2 py-1 rounded-full text-xs font-semibold ${colorEtiqueta(tag)}">
                        ${tag}
                    </span>
                `)
                .join("")}

            <button onclick="abrirModalEtiquetas(${orderId}, '${etiquetas}')"
                    class="text-blue-600 underline text-xs ml-2">
                Editar
            </button>
        </div>
    `;
}

function verDetalles(orderId) {
    document.getElementById("detalleContenido").innerHTML =
        `<div class="text-center py-4">Cargando detalles...</div>`;
    
    document.getElementById("modalDetalles").classList.remove("hidden");

    fetch(`/index.php/dashboard/detalles/${orderId}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                document.getElementById("detalleContenido").innerHTML = 
                    "<p class='text-red-600'>Error cargando detalles</p>";
                return;
            }

            const o = data.order;

            // ==========================
            // CABECERA DEL PEDIDO
            // ==========================
            let html = `
                <div class="border-b pb-4 mb-4">
                    <h3 class="text-xl font-semibold">#${o.name}</h3>
                    <p class="text-gray-600">${o.created_at}</p>
                </div>
            `;

            // ==========================
            // LISTA DE PRODUCTOS
            // ==========================
            html += `<h3 class="font-bold text-lg mb-2">Productos</h3>`;

            o.line_items.forEach(item => {
                html += `
                    <div class="border rounded-lg p-3 mb-3 bg-gray-50">
                        <div class="flex gap-4">
                            <img src="${item.image?.src ?? ''}" class="w-20 h-20 rounded object-cover border">

                            <div>
                                <p class="font-bold">${item.name}</p>
                                <p class="text-sm">Cantidad: ${item.quantity}</p>
                                <p class="text-sm">Precio: ${item.price} ${o.currency}</p>

                                ${item.properties?.length ? `
                                    <div class="mt-2 text-sm text-gray-700">
                                        <b>Propiedades:</b><br>
                                        ${item.properties
                                            .map(p => `${p.name}: ${p.value}`)
                                            .join("<br>")}
                                    </div>
                                ` : ""}
                            </div>
                        </div>
                    </div>
                `;
            });

            // ==========================
            // INFORMACIÓN DEL CLIENTE
            // ==========================
            const c = o.shipping_address ?? {};

            html += `
                <h3 class="font-bold text-lg mt-4 mb-2">Cliente</h3>
                <div class="p-3 bg-gray-50 rounded-lg border">
                    <p><b>${c.first_name ?? ""} ${c.last_name ?? ""}</b></p>
                    <p>${o.email}</p>
                    <p>${c.address1 ?? ""}, ${c.city ?? ""}</p>
                    <p>${c.zip ?? ""}, ${c.country ?? ""}</p>
                    <p>${c.phone ?? ""}</p>
                </div>
            `;

            // ==========================
            // TOTALES
            // ==========================
            html += `
                <h3 class="font-bold text-lg mt-4 mb-2">Totales</h3>
                <p>Subtotal: ${o.subtotal_price} €</p>
                <p>Envío: ${o.total_shipping_price_set?.shop_money?.amount ?? "0"} €</p>
                <p class="font-bold text-xl mt-2">Total: ${o.total_price} €</p>
            `;

            document.getElementById("detalleContenido").innerHTML = html;

            // ==========================
            // IMÁGENES PERSONALIZADAS
            // ==========================
            let imgDiv = document.getElementById("detalleImagenes");
            imgDiv.innerHTML = "";

            data.imagenes.forEach(url => {
                imgDiv.innerHTML += `
                    <img src="${url}" class="w-28 h-28 rounded shadow-lg border object-cover">
                `;
            });
        });
}

function cerrarDetalles() {
    document.getElementById("modalDetalles").classList.add("hidden");
}

function cerrarModalDetalles() {
    document.getElementById("modalDetalles").classList.add("hidden");
}

// =====================================================
// COLORES DE ETIQUETAS SEGÚN TIPO
// =====================================================
function colorEtiqueta(tag) {
    tag = tag.trim().toLowerCase();

    if (tag.startsWith("d.")) {
        return "bg-green-200 text-green-900 border border-green-300";
    }
    if (tag.startsWith("p.")) {
        return "bg-yellow-200 text-yellow-900 border border-yellow-300";
    }

    return "bg-gray-200 text-gray-700 border border-gray-300";
}


// =====================================================
// MODAL ESTADO DEL PEDIDO
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

    let res = await fetch("/api/estado/guardar", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: orderId, estado: nuevoEstado })
    });

    let data = await res.json();

    if (data.success) {
        cerrarModal();
        cargarPedidos();
    }
}


// =====================================================
// MODAL ETIQUETAS (UNIFICADO, SIN DUPLICADOS)
// =====================================================
function abrirModalEtiquetas(orderId, etiquetasTexto = "") {
    document.getElementById("modalTagOrderId").value = orderId;

    etiquetasSeleccionadas = etiquetasTexto
        ? etiquetasTexto.split(",").map(t => t.trim()).filter(Boolean)
        : [];

    renderEtiquetasSeleccionadas();
    mostrarEtiquetasRapidas();

    document.getElementById("modalEtiquetas").classList.remove("hidden");
}

function cerrarModalEtiquetas() {
    document.getElementById("modalEtiquetas").classList.add("hidden");
}


// =====================================================
// GUARDAR ETIQUETAS
// =====================================================
async function guardarEtiquetas() {

    let orderId = document.getElementById("modalTagOrderId").value;
    let tags = etiquetasSeleccionadas.join(", ");

    let response = await fetch("/api/estado/etiquetas/guardar", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: orderId, tags })
    });

    let data = await response.json();

    if (data.success) {
        cerrarModalEtiquetas();
        cargarPedidos();
    }
}


// =====================================================
// RENDER DE ETIQUETAS SELECCIONADAS
// =====================================================
function renderEtiquetasSeleccionadas() {
    let cont = document.getElementById("etiquetasSeleccionadas");
    cont.innerHTML = "";

    etiquetasSeleccionadas.forEach((tag, index) => {
        cont.innerHTML += `
            <span class="px-2 py-1 rounded-full text-xs font-semibold ${colorEtiqueta(tag)} flex items-center gap-1">
                ${tag}
                <button class="text-red-600 font-bold" onclick="eliminarEtiqueta(${index})">×</button>
            </span>
        `;
    });
}

function eliminarEtiqueta(index) {
    etiquetasSeleccionadas.splice(index, 1);
    renderEtiquetasSeleccionadas();
}


// =====================================================
// ETIQUETAS RÁPIDAS SEGÚN EL ROL
// =====================================================
function mostrarEtiquetasRapidas() {
    let cont = document.getElementById("listaEtiquetasRapidas");
    cont.innerHTML = "";

    etiquetasPredeterminadas.forEach(tag => {
        cont.innerHTML += `
            <button onclick="agregarEtiqueta('${tag}')"
                class="px-2 py-1 bg-gray-200 hover:bg-gray-300 rounded-lg text-sm">
                ${tag}
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
