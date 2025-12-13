// =====================================================
// VARIABLES GLOBALES
// =====================================================
let nextPageInfo = null;
let isLoading = false;

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
            document.getElementById("btnAnterior").disabled = true; // Shopify no permite ir atrás
            document.getElementById("total-pedidos").textContent = data.count;

        })
        .catch(err => console.error(err))
        .finally(() => {
            isLoading = false;
        });
}

// Inicializar
cargarPedidos();


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
            </tr>
        `;
        return;
    }

    pedidos.forEach(p => {
        tbody.innerHTML += `
            <tr class="border-b hover:bg-gray-50 transition">
                <td class="py-2 px-4">${p.numero}</td>
                <td class="py-2 px-4">${p.fecha}</td>
                <td class="py-2 px-4">${p.cliente}</td>
                <td class="py-2 px-4">${p.total}</td>

                <td class="py-2 px-4">
                    <button onclick="abrirModal(${p.id})" class="font-semibold text-gray-800">
                        ${p.estado}
                    </button>
                </td>

                <td class="py-2 px-4">
                    <button onclick="abrirModalEtiquetas(${p.id}, '${p.etiquetas ?? ""}')"
                        class="text-blue-600 underline">
                        ${p.etiquetas || "Agregar etiquetas"}
                    </button>
                </td>

                <td class="py-2 px-4">${p.articulos}</td>
                <td class="py-2 px-4">${p.estado_envio}</td>
                <td class="py-2 px-4">${p.forma_envio}</td>
            </tr>
        `;
    });
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
    let tags = document.getElementById("modalTagInput").value;

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
