<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Pedidos Shopify</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 p-8">

<h1 class="text-3xl font-bold mb-6">Pedidos Shopify</h1>

<div class="bg-white rounded shadow overflow-x-auto">
<table class="min-w-full text-sm">
<thead class="bg-gray-200">
<tr>
<th class="p-3">Pedido</th>
<th class="p-3">Fecha</th>
<th class="p-3">Cliente</th>
<th class="p-3">Total</th>
<th class="p-3">Items</th>
<th class="p-3">Envío</th>
</tr>
</thead>
<tbody id="tablaPedidos">
<tr>
<td colspan="6" class="p-6 text-center text-gray-400">
Cargando pedidos…
</td>
</tr>
</tbody>
</table>
</div>

<script>
const ORDERS_URL = "<?= base_url('dashboard/orders') ?>";
</script>
<script src="<?= base_url('js/dashboard.js') ?>"></script>

</body>
</html>
