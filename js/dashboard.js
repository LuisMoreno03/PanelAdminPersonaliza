let cursor = null;
let cargando = false;
let terminado = false;

function cargarPedidos() {
    if (cargando || terminado) return;

    cargando = true;

    let url = DASHBOARD_FILTER_URL;
    if (cursor) url += `?cursor=${encodeURIComponent(cursor)}`;

    fetch(url)
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('tablaPedidos');

            if (cursor === null) tbody.innerHTML = '';

            data.orders.forEach(o => {
                tbody.insertAdjacentHTML('beforeend', `
                    <tr class="border-b">
                        <td class="p-3">${o.numero}</td>
                        <td class="p-3">${o.fecha}</td>
                        <td class="p-3">${o.cliente}</td>
                        <td class="p-3">${o.total}</td>
                        <td class="p-3">${o.estado}</td>
                        <td class="p-3">${o.etiquetas}</td>
                        <td class="p-3 text-center">${o.articulos}</td>
                        <td class="p-3">${o.estado_envio}</td>
                        <td class="p-3">${o.forma_envio}</td>
                    </tr>
                `);
            });

            cursor = data.next_cursor;
            if (!data.has_next) terminado = true;

            cargando = false;
        });
}

window.addEventListener('scroll', () => {
    if (window.innerHeight + window.scrollY >= document.body.offsetHeight - 300) {
        cargarPedidos();
    }
});

document.addEventListener('DOMContentLoaded', cargarPedidos);
