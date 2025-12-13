let pedidos = [];
let paginaActual = 1;
const porPagina = 50;

// ===============================
// CARGAR PEDIDOS DESDE SHOPIFY (GRAPHQL)
// ===============================
function cargarPedidos() {
    fetch(DASHBOARD_FILTER_URL, {
        headers: { "X-Requested-With": "XMLHttpRequest" }
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            alert("Error cargando pedidos");
            return;
        }

        pedidos = data.orders;
        paginaActual = 1;
        renderTabla();
        renderBotones();
    })
    .catch(err => console.error(err));
}

// ===============================
// RENDER TABLA (50 EN 50)
// ===============================
function renderTabla() {
    const tbody = document.getElementById("tablaPedidos");
    tbody.innerHTML = "";

    const inicio = (paginaActual - 1) * porPagina;
    const fin = inicio + porPagina;
    const visibles = pedidos.slice(inicio, fin);

    visibles.forEach(o => {
        tbody.innerHTML += `
            <tr class="border-b hover:bg-gray-50">
                <td class="px-3 py-2 font-semibold">${o.numero}</td>
                <td class="px-3 py-2">${o.fecha}</td>
                <td class="px-3 py-2">${o.cliente}</td>
                <td class="px-3 py-2">${o.total}</td>
                <td class="px-3 py-2">${o.estado}</td>
                <td class="px-3 py-2">${o.etiquetas}</td>
                <td class="px-3 py-2 text-center">${o.articulos}</td>
                <td class="px-3 py-2">${o.estado_envio}</td>
                <td class="px-3 py-2">${o.forma_envio}</td>
            </tr>
        `;
    });

    document.getElementById("total-pedidos").innerText = pedidos.length;
}

// ===============================
// BOTONES SIGUIENTE / ANTERIOR
// ===============================
function renderBotones() {
    const btnPrev = document.getElementById("btn-prev");
    const btnNext = document.getElementById("btn-next");

    btnPrev.onclick = () => {
        if (paginaActual > 1) {
            paginaActual--;
            renderTabla();
            renderBotones();
        }
    };

    btnNext.onclick = () => {
        if (paginaActual * porPagina < pedidos.length) {
            paginaActual++;
            renderTabla();
            renderBotones();
        }
    };

    btnPrev.classList.toggle("hidden", paginaActual === 1);
    btnNext.classList.toggle("hidden", paginaActual * porPagina >= pedidos.length);
}

// ===============================
// INIT
// ===============================
document.addEventListener("DOMContentLoaded", cargarPedidos);
