<!-- =============================================================== -->
<!-- MODAL CAMBIAR ESTADO DEL PEDIDO -->
<!-- =============================================================== -->
<div id="modalEstado"
     class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-[9999] p-4">
  <div class="bg-white w-full max-w-md p-6 rounded-3xl shadow-2xl border border-slate-200 animate-fadeIn">

    <div class="flex items-start justify-between gap-4 mb-4">
      <div>
        <h2 class="text-xl font-extrabold text-slate-900">Cambiar estado</h2>
        <p class="text-sm text-slate-500">Selecciona el nuevo estado del pedido</p>
      </div>

      <button type="button" onclick="cerrarModal()"
        class="h-10 w-10 rounded-2xl border border-slate-200 bg-white text-slate-600 hover:text-slate-900 hover:border-slate-300 transition font-extrabold text-xl leading-none">
        ×
      </button>
    </div>

    <!-- ✅ este ID es el que usa dashboard.js -->
    <input type="hidden" id="modalOrderId">

    <div class="grid gap-3">
      <button type="button" onclick="guardarEstado('Por preparar')"
        class="bg-amber-100 hover:bg-amber-200 text-amber-900 font-extrabold py-2 rounded-2xl transition">
        ⏳ Por preparar
      </button>

      <button type="button" onclick="guardarEstado('Preparado')"
        class="bg-emerald-100 hover:bg-emerald-200 text-emerald-900 font-extrabold py-2 rounded-2xl transition">
        ✅ Preparado
      </button>

      <button type="button" onclick="guardarEstado('Enviado')"
        class="bg-blue-100 hover:bg-blue-200 text-blue-900 font-extrabold py-2 rounded-2xl transition">
        🚚 Enviado
      </button>

      <button type="button" onclick="guardarEstado('Entregado')"
        class="bg-green-100 hover:bg-green-200 text-green-900 font-extrabold py-2 rounded-2xl transition">
        📦 Entregado
      </button>

      <button type="button" onclick="guardarEstado('Cancelado')"
        class="bg-rose-100 hover:bg-rose-200 text-rose-900 font-extrabold py-2 rounded-2xl transition">
        ⛔ Cancelado
      </button>

      <button type="button" onclick="guardarEstado('Devuelto')"
        class="bg-purple-100 hover:bg-purple-200 text-purple-900 font-extrabold py-2 rounded-2xl transition">
        🔄 Devuelto
      </button>
    </div>

    <button type="button" onclick="cerrarModal()"
      class="mt-5 w-full py-2 rounded-2xl bg-slate-200 hover:bg-slate-300 text-slate-800 font-extrabold transition">
      Cerrar
    </button>

  </div>
</div>


<!-- =============================================================== -->
<!-- MODAL DETALLES (usado por verDetalles() en dashboard.js) -->
<!-- =============================================================== -->
<div id="modalDetalles"
     class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-[9999] p-4">
  <div class="bg-white w-full max-w-3xl p-6 rounded-3xl shadow-2xl border border-slate-200 animate-fadeIn">

    <div class="flex items-start justify-between gap-4 mb-4">
      <div>
        <h2 class="text-xl font-extrabold text-slate-900">Detalles del pedido</h2>
        <p class="text-sm text-slate-500">Vista JSON (debug)</p>
      </div>

      <button type="button" onclick="document.getElementById('modalDetalles')?.classList.add('hidden')"
        class="h-10 w-10 rounded-2xl border border-slate-200 bg-white text-slate-600 hover:text-slate-900 hover:border-slate-300 transition font-extrabold text-xl leading-none">
        ×
      </button>
    </div>

    <pre id="modalDetallesJson"
         class="w-full max-h-[70vh] overflow-auto rounded-2xl bg-slate-50 border border-slate-200 p-4 text-xs text-slate-800"></pre>

    <button type="button"
      onclick="document.getElementById('modalDetalles')?.classList.add('hidden')"
      class="mt-5 w-full py-2 rounded-2xl bg-slate-200 hover:bg-slate-300 text-slate-800 font-extrabold transition">
      Cerrar
    </button>

  </div>
</div>


<!-- =============================================================== -->
<!-- MODAL EDITAR ETIQUETAS (SIN TEXTAREA - SOLO CHIPS) -->
<!-- =============================================================== -->
<div id="modalEtiquetas"
     class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50">

    <div class="bg-white w-[430px] p-6 rounded-xl shadow-xl border border-gray-200">

        <h2 class="text-xl font-bold text-gray-800 mb-4 text-center">Editar etiquetas</h2>

        <input type="hidden" id="modalEtiquetasOrderId">

        <!-- Etiquetas actuales -->
        <label class="text-gray-700 font-semibold">Etiquetas del pedido:</label>

        <div id="etiquetasSeleccionadas"
             class="flex flex-wrap gap-2 mt-2 min-h-[45px] p-3 border rounded-lg bg-gray-50">
        </div>

        <!-- Etiquetas rápidas -->
        <label class="text-gray-700 font-semibold mt-4 block">Etiquetas rápidas:</label>

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
   ETIQUETAS PREDETERMINADAS DESDE PHP
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
                class="px-3 py-1 rounded-full text-sm font-semibold ${colorEtiqueta(tag)}">
                ${tag}
            </button>
        `;
    });
}


/* ============================================================
   COLORES DE ETIQUETA
============================================================ */
function colorEtiqueta(tag) {
    tag = tag.toLowerCase();

    if (tag.startsWith("d.")) return "bg-green-200 text-green-900 border border-green-300";
    if (tag.startsWith("p.")) return "bg-yellow-200 text-yellow-900 border border-yellow-300";

    return "bg-gray-200 text-gray-700 border border-gray-300";
}


/* ============================================================
   CERRAR MODAL ETIQUETAS
============================================================ */
function cerrarModalEtiquetas() {
    document.getElementById("modalEtiquetas").classList.add("hidden");
}
</script>
