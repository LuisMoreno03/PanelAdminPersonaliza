

// ===============================
// CARGAR PEDIDOS
// ===============================
function cargarPedidos(pageInfo = null) {

    let url = "/dashboard/filter";

    if (pageInfo) {
        url += "?page_info=" + pageInfo;
    }

    
}

cargarPedidos();


// ===============================
// RELLENA TABLA
// ===============================



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


