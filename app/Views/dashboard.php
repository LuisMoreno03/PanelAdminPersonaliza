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

        <!-- Título superior -->
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

    <!-- MODALES -->
    <?= view('layouts/modales_estados', ['etiquetasPredeterminadas' => $etiquetasPredeterminadas]) ?>
    
    <!-- Scripts de etiquetas rápidas -->
    <script>
        window.etiquetasPredeterminadas = <?= json_encode($etiquetasPredeterminadas) ?>;

        function mostrarEtiquetasRapidas() {
            let cont = document.getElementById("listaEtiquetasRapidas");
            cont.innerHTML = "";

            etiquetasPredeterminadas.forEach(tag => {
                cont.innerHTML += `
                    <button onclick="agregarEtiqueta('${tag}')"
                            class="px-2 py-1 rounded-lg text-sm ${colorEtiqueta(tag)}">
                        ${tag}
                    </button>
                `;
            });
        }

       

    </script>

    <!-- Script principal -->
    <script src="<?= base_url('js/dashboard.js') ?>"></script>

</body>
</html>
