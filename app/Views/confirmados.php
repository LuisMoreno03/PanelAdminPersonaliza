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

        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.95); }
            to   { opacity: 1; transform: scale(1); }
        }
        .animate-fadeIn {
            animation: fadeIn .2s ease-out;
        }
    </style>
</head>

<body class="flex">

    <!-- Sidebar -->
    <?= view('layouts/menu') ?>

    <!-- Contenido principal -->
    <div class="flex-1 md:ml-64 p-8">

        <!-- Encabezado -->
        <h2 class="text-xl font-semibold text-gray-700 mb-4">
            Pedidos cargados: <span id="total-pedidos">0</span>
        </h2>

        <h1 class="text-3xl font-bold mb-6 text-gray-800">Pedidos</h1>

        <!-- TABLA -->
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
                        <th class="py-3 px-4">Detalles</th>
                    </tr>
                </thead>

                <tbody id="tablaPedidos" class="text-gray-800"></tbody>
            </table>
        </div>

        <!-- PAGINACIÓN -->
        <div class="flex justify-between mt-4">
            <button id="btnAnterior"
                disabled
                class="px-4 py-2 bg-gray-300 text-gray-800 rounded-lg opacity-50 cursor-not-allowed">
                Anterior
            </button>

            <button id="btnSiguiente"
                onclick="paginaSiguiente()"
                class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                Siguiente
            </button>
        </div>

    </div>


    <!-- =============================================================== -->
    <!-- 🟦 MODAL DETALLES DEL PEDIDO (ANCHO COMPLETO) -->
    <!-- =============================================================== -->
    <div id="modalDetalles"
         class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50">

        <div class="bg-white w-[90%] h-[92%] rounded-2xl shadow-2xl p-6 overflow-hidden flex flex-col animate-fadeIn">

            <!-- HEADER -->
            <div class="flex justify-between items-center border-b pb-4">
                <h2 id="tituloPedido" class="text-2xl font-bold text-gray-800">
                    Detalles del pedido
                </h2>

                <div class="flex gap-3">
                    <button onclick="abrirPanelCliente()"
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        Información del cliente
                    </button>

                    <button onclick="cerrarModalDetalles()"
                            class="px-4 py-2 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400">
                        Cerrar
                    </button>
                </div>
            </div>

            <!-- CONTENIDO PRINCIPAL (PRODUCTOS) -->
            <div id="detalleProductos"
                 class="flex-1 overflow-auto grid grid-cols-1 md:grid-cols-2 gap-4 p-4">
            </div>

            <!-- TOTALES -->
            <div id="detalleTotales" class="border-t pt-4 text-lg font-semibold text-gray-800"></div> 
            <div class="flex gap-2 mb-4">
    <button onclick="mostrarTodos()"
        class="px-4 py-2 bg-gray-300 rounded-lg hover:bg-gray-400">
        Todos
    </button>

    <button onclick="filtrarPreparados()"
        class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
        Preparados
    </button>
</div>
        </div>
    </div>



    <!-- =============================================================== -->
    <!-- 🟩 PANEL LATERAL: INFORMACIÓN DEL CLIENTE -->
    <!-- =============================================================== -->
    <div id="panelCliente"
         class="hidden fixed inset-0 flex justify-end bg-black/30 backdrop-blur-sm z-50">

        <div class="w-[380px] h-full bg-white shadow-xl p-6 overflow-y-auto animate-fadeIn">

            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-gray-800">Información del cliente</h3>

                <button onclick="cerrarPanelCliente()"
                        class="text-gray-600 hover:text-gray-900 text-2xl font-bold">×</button>
            </div>

            <div id="detalleCliente" class="space-y-2 mb-6"></div>

            <h3 class="text-lg font-bold mt-6">Dirección de envío</h3>
            <div id="detalleEnvio" class="space-y-1 mb-6"></div>

            <h3 class="text-lg font-bold mt-6">Resumen del pedido</h3>
            <div id="detalleResumen" class="space-y-1 mb-6"></div>

        </div>
    </div>



    <!-- =============================================================== -->
    <!-- MODALES DE ESTADOS + ETIQUETAS -->
    <!-- =============================================================== -->
    <?= view('layouts/modales_estados', ['etiquetasPredeterminadas' => $etiquetasPredeterminadas]) ?>

        <!-- =============================================================== -->
        <!-- LOADER GLOBAL (Pantalla de carga) -->
        <!-- =============================================================== -->
        <div id="globalLoader"
            class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-[9999]">

            <div class="bg-white p-6 rounded-xl shadow-xl text-center animate-fadeIn">
                <div class="w-10 h-10 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto"></div>
                <p class="mt-3 font-semibold text-gray-700">Cargando...</p>
            </div>

        </div>

    <!-- PASAR ETIQUETAS AL JS -->
    <script>
        window.etiquetasPredeterminadas = <?= json_encode($etiquetasPredeterminadas) ?>;
    </script>

    <!-- SCRIPT PRINCIPAL -->
    <script src="<?= base_url('js/dashboard.js') ?>"></script>

</body>
</html>

public function confirmados()
{
    return view('confirmados');
}