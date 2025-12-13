<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Pedidos – Shopify</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        body { background: #f3f4f6; }
    </style>
</head>

<body class="flex">

<?= view('layouts/menu') ?>

<div class="flex-1 md:ml-64 p-8">

    <h1 class="text-3xl font-bold mb-6">Pedidos</h1>

    <div class="bg-white shadow rounded-xl p-4 overflow-x-auto">
        <table class="min-w-[1200px] w-full text-sm">
            <thead class="bg-gray-100 text-gray-700 uppercase">
                <tr>
                    <th class="p-3">Pedido</th>
                    <th class="p-3">Fecha</th>
                    <th class="p-3">Cliente</th>
                    <th class="p-3">Total</th>
                    <th class="p-3">Estado</th>
                    <th class="p-3">Etiquetas</th>
                    <th class="p-3 text-center">Artículos</th>
                    <th class="p-3">Entrega</th>
                    <th class="p-3">Forma</th>
                </tr>
            </thead>
            <tbody id="tablaPedidos">
                <tr>
                    <td colspan="9" class="p-10 text-center text-gray-400">
                        Cargando pedidos…
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

</div>

<script>
    const DASHBOARD_FILTER_URL = "<?= base_url('dashboard/filter') ?>";
</script>

<script src="<?= base_url('js/dashboard.js') ?>"></script>

</body>
</html>
