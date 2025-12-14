// =====================================================
// VARIABLES GLOBALES
// =====================================================
let nextPageInfo = null;
let isLoading = false;

// =====================================================
// INICIALIZAR AL CARGAR LA PÁGINA (SOLO UNA VEZ)
// =====================================================
document.addEventListener("DOMContentLoaded", () => {
    cargarPedidos(); // SOLO AQUÍ, nunca más
});

// =====================================================
// CARGAR PEDIDOS (PRINCIPAL)
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
// TABLA DINÁMICA
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
                <td class="py-2 px-4">${p.fecha}</td>
                <td class="py-2 px-4">${p.cliente}</td>
                <td class="py-2 px-4">${p.total}</td>

                <td class="py-2 px-2">
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
            </tr>
        `;
    });
}
function formatearEtiquetas(etiquetas, orderId) {
    if (!etiquetas || etiquetas.trim() === "") {
        return `<button onclick="abrirModalEtiquetas(${orderId}, '')"
                    class="text-blue-600 underline">Agregar etiquetas</button>`;
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
            <button onclick="abrirModalEtiquetas(${orderId}, ${JSON.stringify(etiquetas)})"
                    class="text-blue-600 underline text-xs ml-2">
                Editar
            </button>
        </div>
    `;
}
function colorEtiqueta(tag) {
    tag = tag.toLowerCase();

    if (tag.includes("urgente")) return "bg-red-100 text-red-700 border border-red-300";
    if (tag.includes("vip")) return "bg-yellow-100 text-yellow-700 border border-yellow-300";
    if (tag.includes("nuevo")) return "bg-green-100 text-green-700 border border-green-300";
    if (tag.includes("en proceso")) return "bg-blue-100 text-blue-700 border border-blue-300";
    if (tag.includes("revisión")) return "bg-purple-100 text-purple-700 border border-purple-300";
    if (tag.includes("envío")) return "bg-indigo-100 text-indigo-700 border border-indigo-300";

    return "bg-gray-100 text-gray-700 border border-gray-300"; // Genérico
}
    
// =====================================================
// MODAL - ESTADO
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

    let response = await fetch("/api/estado/guardar", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: orderId, estado: nuevoEstado })
    });

    let data = await response.json();

    if (data.success) {
        cerrarModal();
        cargarPedidos();
    }
}

// =====================================================
// MODAL - ETIQUETAS
// =====================================================
function abrirModalEtiquetas(orderId, etiquetas) {
    document.getElementById("modalTagOrderId").value = orderId;
    document.getElementById("modalTagInput").value = etiquetas || "";
    document.getElementById("modalEtiquetas").classList.remove("hidden");
}

function cerrarModalEtiquetas() {
    document.getElementById("modalEtiquetas").classList.add("hidden");
}
async function guardarEtiquetas() {

    let orderId = document.getElementById("modalTagOrderId").value;
    let tags    = document.getElementById("modalTagInput").value;

    let response = await fetch("/api/estado/etiquetas/guardar", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: orderId, tags: tags })
    });

    let data = await response.json();

    if (data.success) {
        cerrarModalEtiquetas();
        cargarPedidos();
    }
}

function agregarEtiqueta(tag) {
    let campo = document.getElementById("modalTagInput");

    let etiquetas = campo.value
        .split(",")
        .map(e => e.trim())
        .filter(Boolean);

    if (!etiquetas.includes(tag)) {
        etiquetas.push(tag);
    }

    campo.value = etiquetas.join(", ");
}
