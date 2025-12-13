let nextCursor = null;
let cargando = false;

function cargarPedidos() {
    if (cargando) return;
    cargando = true;

    let url = DASHBOARD_FILTER_URL;
    if (nextCursor) {
        url += `?after=${encodeURIComponent(nextCursor)}`;
    }

    fetch(url)
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById("tablaPedidos");

            if (!data.orders.length && !nextCursor) {
                tbody.innerHTML = `<tr>
                    <td colspan="9" class="text-center py-12 text-gray-400">
                        No hay pedidos
                    </td>
                </tr>`;
                return;
            }

            data.orders.forEach(o => {
                tbody.insertAdjacentHTML("beforeend", `
                    <tr>
                        <td>${o.numero}</td>
                        <td>${o.fecha}</td>
                        <td>${o.cliente}</td>
                        <td>${o.total}</td>
                        <td>Por preparar</td>
                        <td>-</td>
                        <td class="text-center">${o.articulos}</td>
                        <td>${o.estado_envio}</td>
                        <td>${o.forma_envio}</td>
                    </tr>
                `);
            });

            nextCursor = data.hasNext ? data.nextCursor : null;

            document.getElementById("btn-next")
                .classList.toggle("hidden", !data.hasNext);

            cargando = false;
        });
}

document.getElementById("btn-next").addEventListener("click", cargarPedidos);
document.addEventListener("DOMContentLoaded", cargarPedidos);


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
