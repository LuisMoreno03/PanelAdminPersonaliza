<!-- =============================================================== -->
<!-- MODAL CAMBIAR ESTADO -->
<!-- =============================================================== -->
<div id="modalEstado"
     class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50">

    <div class="bg-white w-96 p-6 rounded-xl shadow-xl border border-gray-200">

        <h2 class="text-xl font-bold text-gray-800 mb-4 text-center">Cambiar estado del pedido</h2>

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
<!-- MODAL EDITAR ETIQUETAS -->
<!-- =========================== -->
<div id="modalEtiquetas"
     class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50">

    <div class="bg-white w-96 p-6 rounded-xl shadow-xl border border-gray-200">

        <h2 class="text-xl font-bold text-gray-800 mb-4 text-center">
            Editar etiquetas
        </h2>

        <input type="hidden" id="modalTagOrderId">

        <!-- Etiquetas ya agregadas -->
        <label class="text-gray-700 font-semibold">Etiquetas del pedido:</label>

        <div id="etiquetasSeleccionadas"
             class="flex flex-wrap gap-2 mt-2 min-h-[40px] p-2 border rounded-lg bg-gray-50">
        </div>

        <!-- Etiquetas rápidas -->
        <label class="text-gray-700 font-semibold mt-4">Etiquetas rápidas:</label>

        <div id="listaEtiquetasRapidas" class="flex flex-wrap gap-2 mt-2"></div>

        <!-- Botones -->
        <button onclick="guardarEtiquetas()"
                class="mt-5 w-full py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold">
            Guardar cambios
        </button>

        <button onclick="cerrarModalEtiquetas()"
                class="mt-3 w-full py-2 rounded-lg bg-gray-300 hover:bg-gray-400 text-gray-800 font-semibold">
            Cerrar
        </button>

    </div>
</div>


<script>
/* ============================================================
   ETIQUETAS PREDETERMINADAS (VIENEN DEL CONTROLLER)
   ============================================================ */
window.etiquetasPredeterminadas = <?= json_encode($etiquetasPredeterminadas) ?>;


/* ============================================================
   MOSTRAR ETIQUETAS RÁPIDAS SEGÚN ROL
   ============================================================ */
function mostrarEtiquetasRapidas() {
    let cont = document.getElementById("listaEtiquetasRapidas");
    cont.innerHTML = "";

    etiquetasPredeterminadas.forEach(tag => {
        cont.innerHTML += `
            <button onclick="agregarEtiqueta('${tag}')"
                    class="px-2 py-1 rounded-full bg-gray-200 hover:bg-gray-300 text-sm">
                ${tag}
            </button>
        `;
    });
}






/* ============================================================
   CERRAR MODAL ETIQUETAS
   ============================================================ */
function cerrarModalEtiquetas() {
    document.getElementById("modalEtiquetas").classList.add("hidden");
}


/* ============================================================
   AGREGAR ETIQUETA RÁPIDA
   ============================================================ */
function agregarEtiqueta(tag) {
    let campo = document.getElementById("modalTagInput");

    let lista = campo.value.split(",")
        .map(t => t.trim())
        .filter(t => t.length > 0);

    if (!lista.includes(tag)) lista.push(tag);

    campo.value = lista.join(", ");
}
</script>
