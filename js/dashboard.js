let nextPageInfo = null;
let prevPageInfo = null;
let cargando = false;

// ===============================
// CARGAR PEDIDOS
// ===============================
function cargarPedidos() {
    fetch('/dashboard/filter', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        const tbody = document.getElementById('tablaPedidos');
        tbody.innerHTML = '';

        data.orders.forEach(o => {
            tbody.innerHTML += `
                <tr>
                    <td>${o.numero}</td>
                    <td>${o.fecha}</td>
                    <td>${o.cliente}</td>
                    <td>${o.total} €</td>
                    <td>${o.etiquetas || '-'}</td>
                    <td>${o.articulos}</td>
                    <td>${o.estado_envio}</td>
                    <td>${o.forma_envio}</td>
                </tr>
            `;
        });
    });
}

document.addEventListener('DOMContentLoaded', cargarPedidos);


// ===============================
// INIT
// ===============================
document.addEventListener("DOMContentLoaded", () => {
    cargarPedidos(null);
});
