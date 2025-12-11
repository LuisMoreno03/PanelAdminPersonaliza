let paginaActual = 1;
let filtroActual = "todos";

document.addEventListener("DOMContentLoaded", () => {
    cargarPedidos("todos", 1);
});

// ===============================
// CARGAR PEDIDOS
// ===============================
async function cargarPedidos(filtro = "todos", pagina = 1) {

    filtroActual = filtro;
    paginaActual = pagina;

    try {
        const url = `/index.php/dashboard/filter/${filtro}/${pagina}`;
        console.log("Solicitando →", url);

        const response = await fetch(url);
        const data = await response.json();

        console.log("Respuesta Shopify:", data);

        actualizarTabla(data.orders);
        actualizarPaginacion(data.count);

        document.getElementById("total-pedidos").innerText = data.count;

    } catch (e) {
        console.error("⚠ Error cargando pedidos:", e);
    }
}

// ===============================
// RELLENA TABLA
// ===============================
function actualizarTabla(pedidos) {
    const tbody = document.getElementById("tablaPedidos");
    tbody.innerHTML = "";

    if (!Array.isArray(pedidos) || pedidos.length === 0) {
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
            <tr class="border-b">
                <td class="py-2 px-4">${p.numero}</td>
                <td class="py-2 px-4">${p.fecha}</td>
                <td class="py-2 px-4">${p.cliente}</td>
                <td class="py-2 px-4">${p.total}</td>
                <td>
                    <button onclick="abrirModal(${p.id})" class="w-full text-left">
                        ${p.estado}
                    </button>
                </td>
                <td>
                    <button onclick="abrirModalEtiquetas(${p.id}, '${p.etiquetas ?? ""}')"
                        class="text-blue-600 font-semibold underline">
                        ${p.etiquetas || "Agregar etiquetas"}
                    </button>
                </td>

                <td class="py-2 px-4">${p.articulos ?? "-"}</td>
                <td class="py-2 px-4">${p.estado_envio ?? "-"}</td>
                <td class="py-2 px-4">${p.forma_envio ?? "-"}</td>
            </tr>
        `;
    });
}

function getBadgeColor(estado) {
    switch (estadp.toLowerCase()) {
        case "preparado": return "bg-green-600";
        case "por preparar": return "bg-yellow-500";
        case "enviado": return "bg-blue-600";
        case "entregado": return "bg-indigo-600";
        case "cancelado": return "bg-red-600";
        case "devuelto": return "bg-purple-600";
        default: return "bg-gray-500";
    }
}
// =============================
// MODAL
// =============================
function abrirModal(orderId) {
    document.getElementById("modalOrderId").value = orderId;
    document.getElementById("modalEstado").classList.remove("hidden");
}

function cerrarModal() {
    document.getElementById("modalEstado").classList.add("hidden");
}


// =============================
// GUARDAR ESTADO EN SERVIDOR
// =============================
async function guardarEstado(nuevoEstado) {
    let orderId = document.getElementById("modalOrderId").value;

    try {
        let response = await fetch(`/index.php/api/estado/guardar`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id: orderId, estado: nuevoEstado })
        });

        let data = await response.json();

        if (data.success) {
            cerrarModal();
            cargarPedidos(1); // ← refresca la tabla sin perder estados
        } else {
            alert("Error guardando estado");
        }

    } catch (e) {
        console.error("Error:", e);
    }
}


// =============================
// MODAL DE ETIQUETAS
// =============================
function abrirModalEtiquetas(orderId, etiquetas) {
    document.getElementById("modalTagOrderId").value = orderId;
    document.getElementById("modalTagInput").value = etiquetas || "";
    document.getElementById("modalEtiquetas").classList.remove("hidden");
}

function cerrarModalEtiquetas() {
    document.getElementById("modalEtiquetas").classList.add("hidden");
}


// =============================
// GUARDAR ETIQUETAS EN SHOPIFY
// =============================
async function guardarEtiquetas() {
    let orderId = document.getElementById("modalTagOrderId").value;
    let tags = document.getElementById("modalTagInput").value;

    try {
        let response = await fetch(`/index.php/api/estado/etiquetas/guardar`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id: orderId, tags: tags })
        });

        let data = await response.json();

        if (data.success) {
            cerrarModalEtiquetas();
            cargarPedidos(1); // refresca tabla
        } else {
            alert("Error guardando etiquetas");
        }

    } catch (e) {
        console.error(e);
    }
}


// ===============================
// PAGINACIÓN
// ===============================
function actualizarPaginacion(total) {
    document.getElementById("btnAnterior").disabled = paginaActual <= 1;
    document.getElementById("btnSiguiente").disabled = total < 50;
}

function paginaAnterior() {
    if (paginaActual > 1) cargarPedidos(filtroActual, paginaActual - 1);
}

function paginaSiguiente() {
    cargarPedidos(filtroActual, paginaActual + 1);
}
