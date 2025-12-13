let cursor = null;
let cargando = false;

function cargarPedidos() {
    if (cargando) return;
    cargando = true;

    let url = DASHBOARD_FILTER_URL;
    if (cursor) url += `?cursor=${encodeURIComponent(cursor)}`;

    fetch(url, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {

        const tbody = document.getElementById('tablaPedidos');

        data.orders.forEach(o => {
            tbody.innerHTML += `
                <tr class="border-b">
                    <td>${o.numero}</td>
                    <td>${o.fecha}</td>
                    <td>${o.cliente}</td>
                    <td>${o.total}</td>
                    <td>${o.estado}</td>
                    <td>${o.etiquetas || '-'}</td>
                    <td class="text-center">${o.articulos}</td>
                    <td>${o.forma_envio}</td>
                </tr>
            `;
        });

        cursor = data.cursor;

        document.getElementById('btn-next')
            .classList.toggle('hidden', !data.hasNext);

        cargando = false;
    });
}

document.getElementById('btn-next').addEventListener('click', cargarPedidos);
document.addEventListener('DOMContentLoaded', cargarPedidos);
