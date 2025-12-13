let paginaActual = 1;
const porPagina = 50;

const btnPrev = document.getElementById("btn-prev");
const btnNext = document.getElementById("btn-next");

function cargarPedidos(pagina = 1) {
    fetch(`${DASHBOARD_FILTER_URL}?page=${pagina}`, {
        headers: { "X-Requested-With": "XMLHttpRequest" }
    })
    .then(r => r.json())
    .then(data => {

        const tbody = document.getElementById("tablaPedidos");
        tbody.innerHTML = "";

        if (!data.orders.length) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" class="text-center py-10 text-gray-400">
                        No hay más pedidos
                    </td>
                </tr>
            `;
        }

        data.orders.forEach(o => {
            tbody.innerHTML += `
                <tr class="border-b">
                    <td class="py-3 px-4">${o.numero}</td>
                    <td class="py-3 px-4">${o.fecha}</td>
                    <td class="py-3 px-4">${o.cliente}</td>
                    <td class="py-3 px-4">${o.total}</td>
                    <td class="py-3 px-4">${o.estado}</td>
                    <td class="py-3 px-4">${o.etiquetas}</td>
                    <td class="py-3 px-4">${o.articulos}</td>
                    <td class="py-3 px-4">${o.estado_envio}</td>
                    <td class="py-3 px-4">${o.forma_envio}</td>
                </tr>
            `;
        });

        // ---- BOTONES ----

        // Anterior
        if (pagina > 1) {
            btnPrev.classList.remove("hidden");
        } else {
            btnPrev.classList.add("hidden");
        }

        // Siguiente
        if (data.orders.length === porPagina) {
            btnNext.classList.remove("hidden");
        } else {
            btnNext.classList.add("hidden");
        }

        // total pedidos
        document.getElementById("total-pedidos").innerText = data.total;
    });
}

// EVENTOS
btnNext.addEventListener("click", () => {
    paginaActual++;
    cargarPedidos(paginaActual);
});

btnPrev.addEventListener("click", () => {
    if (paginaActual > 1) {
        paginaActual--;
        cargarPedidos(paginaActual);
    }
});

// INIT
document.addEventListener("DOMContentLoaded", () => {
    cargarPedidos(1);
});
