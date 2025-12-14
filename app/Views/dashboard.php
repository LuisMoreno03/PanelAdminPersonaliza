<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - Panel</title>

    <!-- Estilos -->
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

        <!-- Encabezado -->
        <h2 class="text-xl font-semibold text-gray-700 mb-4">
            Pedidos cargados: <span id="total-pedidos">0</span>
        </h2>

        <h1 class="text-3xl font-bold mb-6 text-gray-800">Pedidos</h1>

        <!-- Tabla -->
        <div class="overflow-x-auto bg-white shadow-lg rounded-xl p-4">

            <table class="w-full text-left min-w-[1400px]">
                <thead class="bg-gray-100 text-gray-700 uppercase text-sm">
                    <tr>
                        <th class="py-3 px-4">Pedido</th>
                        <th class="py-3 px-4">Fecha</th>
                        <th class="py-3 px-4">Cliente</th>
                        <th class="py-3 px-4">Total</th>
                        <th class="py-3 px-2">Estado</th>
                        <th class="py-3 px-2">Etiquetas</th>
                        <th class="py-3 px-4">Artículos</th>
                        <th class="py-3 px-4">Estado entrega</th>
                        <th class="py-3 px-4">Forma entrega</th>
                    </tr>
                </thead>

                <tbody id="tablaPedidos" class="text-gray-800"></tbody>
            </table>

        </div>

        <!-- Paginación -->
        <div class="flex justify-between mt-4">
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

    <!-- =============================================================== -->
    <!-- MODAL DETALLES SHOPIFY STYLE -->
    <!-- =============================================================== -->
    <div id="modalDetalles"
        class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50">

        <div class="bg-white w-[90%] max-w-5xl h-[90vh] rounded-xl shadow-xl overflow-hidden flex flex-col animate-fadeIn">

            <!-- HEADER -->
            <div class="p-4 border-b flex justify-between items-center bg-gray-50">
                <h2 class="text-2xl font-bold text-gray-800">Detalles del <?php ${p.numero}?></h2>

                <button onclick="cerrarDetalles()"
                        class="px-3 py-1 bg-gray-200 hover:bg-gray-300 rounded-lg">
                    ✕ Cerrar
                </button>
            </div>

            <!-- CONTENIDO SCROLLEABLE -->
            <div class="flex-1 overflow-y-auto grid grid-cols-1 md:grid-cols-2 gap-6 p-6">

                <!-- ===================================== -->
                <!-- COLUMNA IZQUIERDA → PRODUCTOS -->
                <!-- ===================================== -->
                <div>
                    <h3 class="text-xl font-semibold mb-3">Productos</h3>
                    <div id="detalleProductos" class="space-y-4"></div>
                </div>

                <!-- ===================================== -->
                <!-- COLUMNA DERECHA → CLIENTE / ENVÍO -->
                <!-- ===================================== -->
                <div class="space-y-6">

                    <div>
                        <h3 class="text-xl font-semibold mb-2">Cliente</h3>
                        <div id="detalleCliente"
                            class="p-4 border rounded-lg bg-gray-50 text-sm"></div>
                    </div>

                    <div>
                        <h3 class="text-xl font-semibold mb-2">Dirección de envío</h3>
                        <div id="detalleEnvio"
                            class="p-4 border rounded-lg bg-gray-50 text-sm"></div>
                    </div>

                    <div>
                        <h3 class="text-xl font-semibold mb-2">Totales</h3>
                        <div id="detalleTotales"
                            class="p-4 border rounded-lg bg-gray-50 text-sm"></div>
                    </div>

                </div>

            </div>
        </div>
    </div>

    <style>
    @keyframes fadeIn {
        from { opacity: 0; transform: scale(0.95); }
        to   { opacity: 1; transform: scale(1); }
    }
    .animate-fadeIn {
        animation: fadeIn .2s ease-out;
    }
    </style>





    <!-- ===================================================================================== -->
    <!-- MODALES (Estados + Etiquetas) -->
    <!-- ===================================================================================== -->
    <?= view('layouts/modales_estados', ['etiquetasPredeterminadas' => $etiquetasPredeterminadas]) ?>


    <!-- ===================================================================================== -->
    <!-- PASAR ETIQUETAS PREDETERMINADAS DESDE PHP A JAVASCRIPT -->
    <!-- ===================================================================================== -->
    <script>
        window.etiquetasPredeterminadas = <?= json_encode($etiquetasPredeterminadas) ?>;
    </script>


    <!-- ===================================================================================== -->
    <!-- SCRIPT PRINCIPAL DEL DASHBOARD -->
    <!-- ===================================================================================== -->
    <script src="<?= base_url('js/dashboard.js') ?>"></script>

</body>
</html>
