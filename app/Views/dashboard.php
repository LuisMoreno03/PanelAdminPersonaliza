<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - Panel</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        body { background: #f3f4f6; }
    </style>
</head>

<body class="flex">

    <!-- Sidebar -->
    <?= view('layouts/menu') ?>

    <!-- Contenido -->
    <div class="flex-1 md:ml-64 p-8">

        <!-- HEADER -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <h2 class="text-xl font-semibold text-gray-700">
                    Pedidos: <span id="total-pedidos">0</span>
                </h2>
                <p class="text-sm text-gray-500">
                    Mostrando pedidos desde Shopify (GraphQL)
                </p>
            </div>
        </div>

        <h1 class="text-3xl font-bold mb-6 text-gray-800">Pedidos</h1>

        <!-- TABLA -->
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
                        <th class="py-3 px-4 text-center">Artículos</th>
                        <th class="py-3 px-4">Estado de entrega</th>
                        <th class="py-3 px-4">Forma de entrega</th>
                    </tr>
                </thead>

                <tbody id="tablaPedidos" class="text-gray-800">
                    <tr>
                        <td colspan="9" class="text-center py-12 text-gray-400">
                            Cargando pedidos desde Shopify…
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- PAGINACIÓN -->
            <div class="flex items-center justify-between mt-6">

                <button id="btn-prev"
                    class="px-5 py-2 rounded-lg bg-gray-200 text-gray-700 font-medium hidden hover:bg-gray-300 transition">
                    ⬅ Anterior
                </button>

                <button id="btn-next"
                    class="px-5 py-2 rounded-lg bg-indigo-600 text-white font-medium hidden hover:bg-indigo-700 transition">
                    Siguiente ➡
                </button>

            </div>

        </div>

    </div>

    <!-- MODALES -->
    <?= view('layouts/modales_estados') ?>

    <!-- URL AJAX -->
    <script>
        const DASHBOARD_FILTER_URL = "<?= base_url('dashboard/filter') ?>";
    </script>

    <!-- JS DASHBOARD -->
    <script src="<?= base_url('js/dashboard.js') ?>"></script>

</body>
</html>
