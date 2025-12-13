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
            const tbody = document.getElementById("tablaPedidos");

            data.orders.forEach(o => {
                tbody.insertAdjacentHTML("beforeend", `
                    <tr>
                        <td>${o.numero}</td>
                        <td>${o.fecha}</td>
                        <td>${o.cliente}</td>
                        <td>${o.total}</td>
                        <td>${o.estado}</td>
                        <td>${o.etiquetas}</td>
                        <td>${o.articulos}</td>
                        <td>${o.estado_envio}</td>
                        <td>${o.forma_envio}</td>
                    </tr>
                `);
            });

            cursor = data.next_cursor;
            if (!data.has_next) terminado = true;

            cargando = false;
        });
}

window.addEventListener("scroll", () => {
    if (window.innerHeight + window.scrollY >= document.body.offsetHeight - 300) {
        cargarPedidos();
    }
});

document.addEventListener("DOMContentLoaded", cargarPedidos);
