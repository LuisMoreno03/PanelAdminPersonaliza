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
        <div class="overflow-x-auto bg-white shadow-lg rounded-xl">
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
            <!-- 📌 Links de paginación -->
<div class="pagination-container">
    <?= $pager->links() ?>
</div>
<?= $pager->links('default', 'tailwind') ?>

        </div>

        <!-- Paginación -->
        <div class="flex justify-between mt-4">
            <button id="btnAnterior"
                onclick="paginaAnterior()"
                class="px-4 py-2 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400 disabled:opacity-40">
                Anterior
            </button>

            <button id="btnSiguiente"
                onclick="paginaSiguiente()"
                class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-40">
                Siguiente
            </button>
        </div>

    </div>

    <script src="<?= base_url('js/dashboard.js') ?>"></script>
    <script>
        
    </script>

<!-- Modal Estado -->
<!-- =========================== -->
<!-- MODAL ESTADO DEL PEDIDO     -->
<!-- =========================== -->
<div id="modalEstado" 
     class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50">

    <div class="bg-white w-96 p-6 rounded-xl shadow-xl border border-gray-200">

        <h2 class="text-xl font-bold text-gray-800 mb-4 text-center">
            Cambiar estado del pedido
        </h2>

        <input type="hidden" id="modalOrderId">

        <div class="grid gap-3">

            <button onclick="guardarEstado('Por preparar')"
                class="estado-btn bg-yellow-100 hover:bg-yellow-200 text-yellow-800 font-semibold py-2 rounded-lg">
                🟡 Por preparar
            </button>

            <button onclick="guardarEstado('Preparado')"
                class="estado-btn bg-green-100 hover:bg-green-200 text-green-800 font-semibold py-2 rounded-lg">
                🟢 Preparado
            </button>

            <button onclick="guardarEstado('Enviado')"
                class="estado-btn bg-blue-100 hover:bg-blue-200 text-blue-800 font-semibold py-2 rounded-lg">
                🔵 Enviado
            </button>

            <button onclick="guardarEstado('Entregado')"
                class="estado-btn bg-emerald-100 hover:bg-emerald-200 text-emerald-800 font-semibold py-2 rounded-lg">
                💚 Entregado
            </button>

            <button onclick="guardarEstado('Cancelado')"
                class="estado-btn bg-red-100 hover:bg-red-200 text-red-800 font-semibold py-2 rounded-lg">
                🔴 Cancelado
            </button>

            <button onclick="guardarEstado('Devuelto')"
                class="estado-btn bg-purple-100 hover:bg-purple-200 text-purple-800 font-semibold py-2 rounded-lg">
                🟣 Devuelto
            </button>
        </div>

        <button onclick="cerrarModal()"
            class="mt-5 w-full py-2 rounded-lg bg-gray-300 hover:bg-gray-400 text-gray-800 font-semibold">
            Cerrar
        </button>

    </div>
</div>

<!-- =========================== -->
<!-- MODAL EDITAR ETIQUETAS      -->
<!-- =========================== -->
<div id="modalEtiquetas"
     class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50">

    <div class="bg-white w-96 p-6 rounded-xl shadow-xl border border-gray-200">

        <h2 class="text-xl font-bold text-gray-800 mb-4 text-center">
            Editar etiquetas
        </h2>

        <input type="hidden" id="modalTagOrderId">

        <label class="text-gray-700 font-semibold">Etiquetas (separadas por coma)</label>
        <textarea id="modalTagInput"
                  class="w-full border border-gray-300 rounded-lg p-2 mt-2 h-28"></textarea>

        <button onclick="guardarEtiquetas()"
            class="mt-4 w-full py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold">
            Guardar
        </button>

        <button onclick="cerrarModalEtiquetas()"
            class="mt-3 w-full py-2 rounded-lg bg-gray-300 hover:bg-gray-400 text-gray-800 font-semibold">
            Cerrar
        </button>

    </div>
</div>


</body>
</html>
