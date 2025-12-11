<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - Panel</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs" defer></script>

    <style>
        body { background: #f3f4f6; }
    </style>
</head>

<body class="flex">

    <!-- Sidebar -->
    <?= view('layouts/menu') ?>

    <!-- Contenido -->
    <div class="flex-1 md:ml-64 p-8">

        <h2 class="text-xl font-semibold text-gray-700 mb-4">
            Pedidos: <span id="total-pedidos">0</span>
        </h2>

        <h1 class="text-3xl font-bold mb-6 text-gray-800">Pedidos</h1>

        <!-- Tabla -->
        <div class="overflow-x-auto bg-white shadow-lg rounded-xl p-4">

            <table class="w-full text-left min-w-[1200px]">
                <thead class="bg-gray-100 text-gray-700 uppercase text-sm">
                    <tr>
                        <th class="py-3 px-4">Pedido</th>
                        <th class="py-3 px-4">Fecha</th>
                        <th class="py-3 px-4">Cliente</th>
                        <th class="py-3 px-4">Total</th>
                        <th class="py-3 px-4">Estado del pedido</th>
                        <th class="py-3 px-4">Etiquetas</th>
                        <th class="py-3 px-4">Artículos</th>
                        <th class="py-3 px-4">Estado de entrega</th>
                        <th class="py-3 px-4">Forma de entrega</th>
                    </tr>
                </thead>

                <tbody id="tablaPedidos" class="text-gray-800"></tbody>
            </table>

        </div>

        <!-- Paginación -->
        <div class="flex justify-between mt-4">

            <!-- Shopify no permite "anterior" sin historial, por ahora se desactiva -->
            <button id="btnAnterior"
                disabled
                class="px-4 py-2 bg-gray-300 text-gray-800 rounded-lg opacity-50 cursor-not-allowed">
                Anterior
            </button>

            <button id="btnSiguiente"
                onclick="paginaSiguiente()"
                class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-40">
                Siguiente
            </button>
        </div>

    </div>

    <script>
        // ================================
        // PAGINACIÓN SHOPIFY
        // ================================
        let nextPageInfo = null;

        function cargarPedidos(pageInfo = null) {

            let url = "/dashboard/filter";

            if (pageInfo) {
                url += "?page_info=" + pageInfo;
            }

            fetch(url)
                .then(res => res.json())
                .then(data => {

                    nextPageInfo = data.next_page_info;

                    actualizarTabla(data.orders);


                    document.getElementById("btnSiguiente").disabled = !nextPageInfo;

                    document.getElementById("total-pedidos").textContent = data.count;
                });
        }

        function paginaSiguiente() {
            if (nextPageInfo) {
                cargarPedidos(nextPageInfo);
            }
        }

        // ================================
        // LLENAR TABLA
        // ================================
        function llenarTabla(orders) {
            const tbody = document.getElementById("tablaPedidos");
            tbody.innerHTML = "";

            orders.forEach(o => {
                const row = `
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
                tbody.innerHTML += row;
            });
        }

        // ================================
        // CARGA INICIAL
        // ================================
        cargarPedidos();
    </script>


<!-- =========================== -->
<!-- MODALES (se dejan como estaban) -->
<!-- =========================== -->
<?= view('layouts/modales_estados') ?>

</body>
</html>
