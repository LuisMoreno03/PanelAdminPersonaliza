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

                <tbody id="tablaPedidos" class="text-gray-800">
                    <tr>
                        <td colspan="9" class="text-center py-10 text-gray-400">
                            Cargando pedidos...
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- PAGINACIÓN -->
            <button id="btn-prev"
              class="px-5 py-2 rounded-lg bg-gray-200 hidden">
                ⬅ Anterior
                </button>

                <button id="btn-next"
                class="px-5 py-2 rounded-lg bg-indigo-600 text-white hidden">
                Siguiente ➡
                </button>

        </div>

    </div>

   <!-- MODALES -->
<?= view('layouts/modales_estados') ?>

<script>
    const DASHBOARD_FILTER_URL = "<?= base_url('dashboard/filter') ?>";
</script>
<script>
let nextPage = null;
let prevPage = null;

function cargarPedidos(pageInfo = null) {
    let url = "/index.php/dashboard/filter";
    if (pageInfo) {
        url += "?page_info=" + pageInfo;
    }

    fetch(url)
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById("tablaPedidos");
            tbody.innerHTML = "";

            data.orders.forEach(o => {
                tbody.innerHTML += `
                    <tr>
                        <td>${o.name}</td>
                        <td>${o.created_at.substring(0,10)}</td>
                        <td>${o.customer?.first_name ?? '-'}</td>
                        <td>${o.total_price}</td>
                    </tr>
                `;
            });

            nextPage = data.next;
            prevPage = data.prev;

            document.getElementById("btnNext").classList.toggle("hidden", !nextPage);
            document.getElementById("btnPrev").classList.toggle("hidden", !prevPage);
        });
}

document.getElementById("btnNext").onclick = () => cargarPedidos(nextPage);
document.getElementById("btnPrev").onclick = () => cargarPedidos(prevPage);

cargarPedidos();
</script>
<script src="<?= base_url('js/dashboard.js') ?>"></script>

</body>
</html>
