let cursor = null;
let loading = false;
let finished = false;

const DASHBOARD_FILTER_URL = "/dashboard/filter";

function cargarPedidos() {
    if (loading || finished) return;
    loading = true;

    let url = ORDERS_URL;
    if (cursor) {
        url += '?cursor=' + encodeURIComponent(cursor);
    }

    fetch(`${DASHBOARD_FILTER_URL}?page=${page}`)
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('tablaPedidos');

            if (!cursor) tbody.innerHTML = '';

            data.orders.forEach(o => {
                tbody.insertAdjacentHTML('beforeend', `
                    <tr class="border-b">
                        <td class="p-3">${o.pedido}</td>
                        <td class="p-3">${o.fecha}</td>
                        <td class="p-3">${o.cliente}</td>
                        <td class="p-3">${o.total}</td>
                        <td class="p-3 text-center">${o.articulos}</td>
                        <td class="p-3">${o.envio}</td>
                    </tr>
                `);
            });

            cursor = data.next_cursor;
            finished = !data.has_next;
            loading = false;
        });
}

window.addEventListener('scroll', () => {
    if (window.innerHeight + window.scrollY >= document.body.offsetHeight - 300) {
        cargarPedidos();
    }
});

document.addEventListener('DOMContentLoaded', cargarPedidos);
