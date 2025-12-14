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

<!-- SIDEBAR -->
<?= view('layouts/menu') ?>

<!-- CONTENIDO -->
<div class="flex-1 md:ml-64 p-8">

    <h2 class="text-xl font-semibold text-gray-700 mb-4">
        Pedidos: <span id="total-pedidos">0</span>
    </h2>

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
                    <th class="py-3 px-4">Estado</th>
                    <th class="py-3 px-4">Etiquetas</th>
                    <th class="py-3 px-4">Artículos</th>
                    <th class="py-3 px-4">Estado entrega</th>
                    <th class="py-3 px-4">Forma entrega</th>
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
            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-40">
            Siguiente
        </button>
    </div>

</div>



<!-- =============================================================== -->
<!-- MODAL ESTADO -->
<!-- =============================================================== -->
<div id="modalEstado"
     class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50">

    <div class="bg-white w-96 p-6 rounded-xl shadow-xl border border-gray-200">

        <h2 class="text-xl font-bold text-gray-800 mb-4 text-center">Cambiar estado</h2>

        <input type="hidden" id="modalOrderId">

        <div class="grid gap-3">
            <button onclick="guardarEstado('Por preparar')" class="estado-btn bg-yellow-100 hover:bg-yellow-200 text-yellow-800 font-semibold py-2 rounded-lg">🟡 Por preparar</button>
            <button onclick="guardarEstado('Preparado')" class="estado-btn bg-green-100 hover:bg-green-200 text-green-800 font-semibold py-2 rounded-lg">🟢 Preparado</button>
            <button onclick="guardarEstado('Enviado')" class="estado-btn bg-blue-100 hover:bg-blue-200 text-blue-800 font-semibold py-2 rounded-lg">🔵 Enviado</button>
            <button onclick="guardarEstado('Entregado')" class="estado-btn bg-emerald-100 hover:bg-emerald-200 text-emerald-800 font-semibold py-2 rounded-lg">💚 Entregado</button>
            <button onclick="guardarEstado('Cancelado')" class="estado-btn bg-red-100 hover:bg-red-200 text-red-800 font-semibold py-2 rounded-lg">🔴 Cancelado</button>
            <button onclick="guardarEstado('Devuelto')" class="estado-btn bg-purple-100 hover:bg-purple-200 text-purple-800 font-semibold py-2 rounded-lg">🟣 Devuelto</button>
        </div>

        <button onclick="cerrarModal()" class="mt-5 w-full py-2 rounded-lg bg-gray-300 hover:bg-gray-400 text-gray-800 font-semibold">
            Cerrar
        </button>

    </div>
</div>



<!-- =============================================================== -->
<!-- MODAL ETIQUETAS -->
<!-- =============================================================== -->
<div id="modalEtiquetas"
     class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50">

    <div class="bg-white w-96 p-6 rounded-xl shadow-xl border border-gray-200">

        <h2 class="text-xl font-bold text-gray-800 mb-4 text-center">Editar etiquetas</h2>

        <input type="hidden" id="modalTagOrderId">

        <label class="text-gray-700 font-semibold">Etiquetas:</label>

        <div id="listaEtiquetasRapidas" class="flex flex-wrap gap-2 mt-4"></div>

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



<script>
/* ============================================================
   ETIQUETAS PREDETERMINADAS SEGÚN USUARIO
   ============================================================ */
window.etiquetasPredeterminadas = <?= json_encode($etiquetasPredeterminadas) ?>;

/* ============================================================
   MOSTRAR ETIQUETAS RÁPIDAS
   ============================================================ */
function mostrarEtiquetasRapidas() {
    let cont = document.getElementById("listaEtiquetasRapidas");
    cont.innerHTML = "";

    etiquetasPredeterminadas.forEach(tag => {
        cont.innerHTML += `
            <button onclick="agregarEtiqueta('${tag}')"
                    class="px-2 py-1 bg-gray-200 hover:bg-gray-300 rounded-lg text-sm">
                ${tag}
            </button>
        `;
    });
}

/* ============================================================
   COLOR PARA CADA ETIQUETA
   ============================================================ */
function colorEtiqueta(tag) {
    tag = tag.toLowerCase();

    if (tag.includes("urgente")) return "bg-red-100 text-red-700 border border-red-300";
    if (tag.includes("vip")) return "bg-yellow-100 text-yellow-700 border border-yellow-300";
    if (tag.includes("nuevo")) return "bg-green-100 text-green-700 border border-green-300";
    if (tag.includes("proceso")) return "bg-blue-100 text-blue-700 border border-blue-300";
    if (tag.includes("envío")) return "bg-indigo-100 text-indigo-700 border border-indigo-300";
    if (tag.includes("empacado")) return "bg-orange-100 text-orange-700 border border-orange-300";
    if (tag.includes("revisión")) return "bg-purple-100 text-purple-700 border border-purple-300";

    return "bg-gray-100 text-gray-700 border border-gray-300";
}

/* ============================================================
   FORMATO VISUAL PARA ETIQUETAS
   ============================================================ */
function formatearEtiquetas(etiquetas, id) {
    if (!etiquetas) {
        return `<button onclick="abrirModalEtiquetas(${id}, '')"
                        class="text-blue-600 underline text-sm">Agregar</button>`;
    }

    let lista = etiquetas.split(",").map(t => t.trim());

    return `
        <div class="flex flex-wrap gap-2">
            ${lista.map(t => `
                <span class="px-2 py-1 rounded-full text-xs font-semibold ${colorEtiqueta(t)}">
                    ${t}
                </span>
            `).join("")}

            <button onclick="abrirModalEtiquetas(${id}, ${JSON.stringify(etiquetas)})"
                    class="text-blue-600 underline text-xs">Editar</button>
        </div>
    `;
}

/* ============================================================
   ABRIR / CERRAR MODAL ETIQUETAS
   ============================================================ */
function abrirModalEtiquetas(id, etiquetas) {
    document.getElementById("modalTagOrderId").value = id;
    document.getElementById("modalTagInput").value = etiquetas || "";
    document.getElementById("modalEtiquetas").classList.remove("hidden");
    mostrarEtiquetasRapidas();
}

function cerrarModalEtiquetas() {
    document.getElementById("modalEtiquetas").classList.add("hidden");
}

/* ============================================================
   AGREGAR ETIQUETA RÁPIDA
   ============================================================ */
function agregarEtiqueta(tag) {
    let campo = document.getElementById("modalTagInput");

    let lista = campo.value
        .split(",")
        .map(t => t.trim())
        .filter(Boolean);

    if (!lista.includes(tag)) lista.push(tag);

    campo.value = lista.join(", ");
}
</script>



<!-- ============================================================
     ARCHIVO JS PRINCIPAL DEL PANEL
============================================================ -->
<script src="<?= base_url('js/dashboard.js') ?>"></script>

</body>
</html>
