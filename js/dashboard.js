let nextPageInfo = null;

// ===============================
// CARGAR PEDIDOS
// ===============================
function cargarPedidos(pageInfo = null) {

    let url = "/dashboard/filter";

    if (pageInfo) {
        url += "?page_info=" + pageInfo;
    }

    fetch(url)
        .then(res => res.json())
        .then(data => {

            nextPageInfo = data.next_page_info;

            actualizarTabla(data.orders);

            // Shopify no permite retroceder, así que lo desactivamos
            document.getElementById("btnAnterior").disabled = true;
            document.getElementById("btnSiguiente").disabled = !nextPageInfo;
        });
}

cargarPedidos();


// ===============================
// RELLENA TABLA
// ===============================
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


// =============================
// MODAL ESTADO
// =============================
function abrirModal(orderId) {
    document.getElementById("modalOrderId").value = orderId;
    document.getElementById("modalEstado").classList.remove("hidden");
}

function cerrarModal() {
    document.getElementById("modalEstado").classList.add("hidden");
}


// =============================
// GUARDAR ESTADO
// =============================
async function guardarEstado(nuevoEstado) {

    let orderId = document.getElementById("modalOrderId").value;

    let response = await fetch(`/index.php/api/estado/guardar`, {
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


// =============================
// MODAL ETIQUETAS
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
// GUARDAR ETIQUETAS
// =============================
async function guardarEtiquetas() {

    let orderId = document.getElementById("modalTagOrderId").value; 
    let tags = document.getElementById("modalTagInput").value;

    let response = await fetch(`/index.php/api/estado/etiquetas/guardar`, {
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


// ===============================
// PAGINACIÓN — SOLO SIGUIENTE
// ===============================
function paginaSiguiente() {
    if (nextPageInfo) {
        cargarPedidos(nextPageInfo);
    }
}
