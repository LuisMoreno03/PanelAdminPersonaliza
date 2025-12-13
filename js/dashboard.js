let nextPageInfo = null;
let cargando = false;
let terminado = false;

// ===============================
// CARGAR PEDIDOS (APPEND)
// ===============================
function cargarPedidos(pageInfo = null) {

    if (cargando || terminado) return;
    cargando = true;

    let url = DASHBOARD_FILTER_URL;
    if (pageInfo) {
        url += `?page_info=${pageInfo}`;
    }

    fetch(url, {
        headers: { "X-Requested-With": "XMLHttpRequest" }
    })
    .then(r => r.json())
    .then(data => {

        const tbody = document.getElementById("tablaPedidos");
        const totalSpan = document.getElementById("total-pedidos");

        // borrar loader inicial
        if (tbody.dataset.init !== "1") {
            tbody.innerHTML = "";
            tbody.dataset.init = "1";
        }

        data.orders.forEach(o => {
            tbody.insertAdjacentHTML("beforeend", `
                <tr class="border-b hover:bg-gray-50">
                    <td class="px-4 py-2">${o.numero}</td>
                    <td class="px-4 py-2">${o.fecha}</td>
                    <td class="px-4 py-2">${o.cliente}</td>
                    <td class="px-4 py-2">${o.total}</td>
                    <td class="px-4 py-2">${o.estado}</td>
                    <td class="px-4 py-2 text-blue-600">${o.etiquetas}</td>
                    <td class="px-4 py-2 text-center">${o.articulos}</td>
                    <td class="px-4 py-2">${o.estado_envio}</td>
                    <td class="px-4 py-2">${o.forma_envio}</td>
                </tr>
            `);
        });

        // contador total cargado
        const actual = tbody.querySelectorAll("tr").length;
        totalSpan.textContent = actual;

        // manejar cursor
        nextPageInfo = data.next_page_info;

        if (!nextPageInfo) {
            terminado = true;
            mostrarFin();
        }

        cargando = false;
    })
    .catch(() => cargando = false);
}

// ===============================
// DETECTAR SCROLL
// ===============================
window.addEventListener("scroll", () => {

    if (terminado || cargando) return;

    const scrollActual = window.scrollY + window.innerHeight;
    const alturaTotal = document.body.offsetHeight;

    // cuando falten ~300px
    if (scrollActual >= alturaTotal - 300) {
        cargarPedidos(nextPageInfo);
    }
});

// ===============================
// MENSAJE FINAL
// ===============================
function mostrarFin() {
    const tbody = document.getElementById("tablaPedidos");
    tbody.insertAdjacentHTML("beforeend", `
        <tr>
            <td colspan="9" class="text-center py-6 text-gray-400">
                No hay más pedidos para cargar
            </td>
        </tr>
    `);
}

// ===============================
// INIT
// ===============================
document.addEventListener("DOMContentLoaded", () => {
    cargarPedidos();
});
