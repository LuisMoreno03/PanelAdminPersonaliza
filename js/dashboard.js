// ===============================
// VARIABLES DE PAGINACIÓN
// ===============================
let nextPageInfo = null;
let prevPageInfo = null;
let cargando = false;


// ===============================
// CARGAR PEDIDOS (AJAX + PAGINACIÓN)
// ===============================
function cargarPedidos(pageInfo = null) {

    if (cargando) return;
    cargando = true;

    let url = "/dashboard/filter";
    if (pageInfo) {
        url += "?page_info=" + pageInfo;
    }

    const tbody = document.getElementById("tablaPedidos");
    tbody.innerHTML = `
        <tr>
            <td colspan="9" class="text-center py-10 text-gray-400">
                Cargando pedidos...
            </td>
        </tr>
    `;

    fetch(url, {
        headers: { "X-Requested-With": "XMLHttpRequest" }
    })
    .then(r => r.json())
    .then(data => {

        tbody.innerHTML = "";

        if (!data.success) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" class="text-center py-10 text-red-500">
                        Error cargando pedidos
                    </td>
                </tr>
            `;
            cargando = false;
            return;
        }

        // Contadores
        document.getElementById("total-pedidos").innerText = data.count;
        document.getElementById("contador-pedidos").innerText =
            `Mostrando ${data.count} pedidos`;

        // Render tabla
        data.orders.forEach(o => {
            tbody.innerHTML += `
                <tr class="border-b hover:bg-gray-50">
                    <td class="py-3 px-4 font-semibold">${o.numero}</td>
                    <td class="py-3 px-4">${o.fecha}</td>
                    <td class="py-3 px-4">${o.cliente}</td>
                    <td class="py-3 px-4">${o.total}</td>

                    <td class="py-3 px-4 cursor-pointer"
                        onclick="abrirModal(${o.id})">
                        ${o.estado}
                    </td>

                    <td class="py-3 px-4 cursor-pointer"
                        onclick="abrirModalEtiquetas(${o.id}, '${o.etiquetas.replace(/'/g, "\\'")}')">
                        ${o.etiquetas || '-'}
                    </td>

                    <td class="py-3 px-4 text-center">${o.articulos}</td>
                    <td class="py-3 px-4">${o.estado_envio}</td>
                    <td class="py-3 px-4">${o.forma_envio}</td>
                </tr>
            `;
        });

        // Guardar cursores
        nextPageInfo = data.next_page_info;
        prevPageInfo = data.prev_page_info;

        // Botón Anterior
        const btnPrev = document.getElementById("btn-prev");
        if (btnPrev) {
            if (prevPageInfo) {
                btnPrev.classList.remove("hidden");
                btnPrev.onclick = () => cargarPedidos(prevPageInfo);
            } else {
                btnPrev.classList.add("hidden");
            }
        }

        // Botón Siguiente
        const btnNext = document.getElementById("btn-next");
        if (btnNext) {
            if (nextPageInfo) {
                btnNext.classList.remove("hidden");
                btnNext.onclick = () => cargarPedidos(nextPageInfo);
            } else {
                btnNext.classList.add("hidden");
            }
        }

        cargando = false;
    })
    .catch(() => {
        cargando = false;
    });
}

// CARGA INICIAL
cargarPedidos();


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
        cargarPedidos(); // 🔄 refresca página actual
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
        cargarPedidos(); // 🔄 refresca
    }
}
