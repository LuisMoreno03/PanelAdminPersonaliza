<!-- =============================================================== -->
<!-- MODAL CAMBIAR ESTADO DEL PEDIDO (COLORES FUERTES / LLAMATIVOS) -->
<!-- =============================================================== -->
<div id="modalEstado"
     class="hidden fixed inset-0 bg-black/70 backdrop-blur-md flex items-center justify-center z-[9999] p-4">

  <!-- Card -->
  <div class="w-full max-w-md rounded-[28px] overflow-hidden shadow-2xl border border-white/10 animate-fadeIn">

    <!-- Header (gradiente fuerte) -->
    <div class="px-6 py-5 bg-gradient-to-r from-slate-950 via-slate-900 to-slate-950">
      <div class="flex items-start justify-between gap-4">
        <div>
          <h2 class="text-xl font-extrabold text-white tracking-tight">Cambiar estado</h2>
          <p class="text-sm text-white/70 mt-1">Selecciona el nuevo estado del pedido</p>
        </div>

        <button type="button" onclick="cerrarModal()"
          class="h-10 w-10 rounded-2xl bg-white/10 border border-white/10 text-white
                 hover:bg-white/20 hover:border-white/20 transition font-extrabold text-xl leading-none">
          ×
        </button>
      </div>

      <!-- ID usado por dashboard.js -->
      <input type="hidden" id="modalOrderId">
    </div>

    <!-- Body -->
    <div class="p-6 bg-white">
      <div class="grid gap-3">

        <!-- Por preparar -->
        <button type="button"
          onclick="guardarEstado('Por preparar')"
          class="group w-full flex items-center justify-between gap-3 px-4 py-3 rounded-2xl
                 bg-slate-950 text-white font-extrabold tracking-wide
                 border border-slate-800 shadow-md
                 hover:scale-[1.01] hover:bg-slate-900 active:scale-[0.99] transition">
          <span class="flex items-center gap-3">
            <span class="h-9 w-9 rounded-2xl bg-white/10 grid place-items-center text-lg">⏳</span>
            <span>Por preparar</span>
          </span>
          <span class="h-2.5 w-2.5 rounded-full bg-slate-200 ring-2 ring-white/30"></span>
        </button>

        <!-- A medias (amarillo neón) -->
        <button type="button"
          onclick="guardarEstado('A medias')"
          class="group w-full flex items-center justify-between gap-3 px-4 py-3 rounded-2xl
                 bg-yellow-400 text-black font-extrabold tracking-wide
                 border border-yellow-500 shadow-md
                 hover:scale-[1.01] hover:bg-yellow-300 active:scale-[0.99] transition">
          <span class="flex items-center gap-3">
            <span class="h-9 w-9 rounded-2xl bg-black/10 grid place-items-center text-lg">🟡</span>
            <span>A medias</span>
          </span>
          <span class="h-2.5 w-2.5 rounded-full bg-black/80 ring-2 ring-black/20"></span>
        </button>

        <!-- Producción (fucsia eléctrico) -->
        <button type="button"
          onclick="guardarEstado('Producción')"
          class="group w-full flex items-center justify-between gap-3 px-4 py-3 rounded-2xl
                 bg-fuchsia-600 text-white font-extrabold tracking-wide
                 border border-fuchsia-700 shadow-md
                 hover:scale-[1.01] hover:bg-fuchsia-500 active:scale-[0.99] transition">
          <span class="flex items-center gap-3">
            <span class="h-9 w-9 rounded-2xl bg-white/15 grid place-items-center text-lg">🏭</span>
            <span>Producción</span>
          </span>
          <span class="h-2.5 w-2.5 rounded-full bg-white ring-2 ring-white/30"></span>
        </button>

        <!-- Fabricando (azul intenso) -->
        <button type="button"
          onclick="guardarEstado('Fabricando')"
          class="group w-full flex items-center justify-between gap-3 px-4 py-3 rounded-2xl
                 bg-blue-600 text-white font-extrabold tracking-wide
                 border border-blue-700 shadow-md
                 hover:scale-[1.01] hover:bg-blue-500 active:scale-[0.99] transition">
          <span class="flex items-center gap-3">
            <span class="h-9 w-9 rounded-2xl bg-white/15 grid place-items-center text-lg">🛠️</span>
            <span>Fabricando</span>
          </span>
          <span class="h-2.5 w-2.5 rounded-full bg-sky-200 ring-2 ring-white/30"></span>
        </button>

        <!-- Enviado (verde fuerte) -->
        <button type="button"
          onclick="guardarEstado('Enviado')"
          class="group w-full flex items-center justify-between gap-3 px-4 py-3 rounded-2xl
                 bg-emerald-600 text-white font-extrabold tracking-wide
                 border border-emerald-700 shadow-md
                 hover:scale-[1.01] hover:bg-emerald-500 active:scale-[0.99] transition">
          <span class="flex items-center gap-3">
            <span class="h-9 w-9 rounded-2xl bg-white/15 grid place-items-center text-lg">🚚</span>
            <span>Enviado</span>
          </span>
          <span class="h-2.5 w-2.5 rounded-full bg-lime-200 ring-2 ring-white/30"></span>
        </button>

      </div>

      <button type="button"
        onclick="cerrarModal()"
        class="mt-5 w-full px-4 py-3 rounded-2xl bg-slate-200 hover:bg-slate-300
               text-slate-900 font-extrabold transition">
        Cerrar
      </button>
    </div>

  </div>
</div>



<!-- =============================================================== -->
<!-- MODAL EDITAR ETIQUETAS (COLORES FUERTES / CHIPS PRO) -->
<!-- =============================================================== -->
<div id="modalEtiquetas"
     class="hidden fixed inset-0 bg-black/70 backdrop-blur-md flex items-center justify-center z-[9999] p-4">

  <div class="w-full max-w-3xl rounded-[28px] bg-white shadow-2xl border border-slate-200 overflow-hidden animate-fadeIn">

    <!-- Header fuerte -->
    <div class="p-6 bg-gradient-to-r from-slate-950 via-slate-900 to-slate-950 flex items-start justify-between gap-4">
      <div>
        <h2 class="text-xl sm:text-2xl font-extrabold text-white tracking-tight">Editar etiquetas</h2>
        <p class="text-sm text-white/70 mt-1">Toca para agregar / quitar chips. Visual fuerte y claro.</p>
      </div>

      <button type="button" onclick="cerrarModalEtiquetas()"
        class="h-10 w-10 rounded-2xl bg-white/10 border border-white/10 text-white
               hover:bg-white/20 hover:border-white/20 transition font-extrabold text-xl leading-none">
        ×
      </button>
    </div>

    <div class="p-6 space-y-5">
      <input type="hidden" id="modalEtiquetasOrderId">

      <!-- Seleccionadas -->
      <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
        <div class="flex items-center justify-between gap-3">
          <div class="font-extrabold text-slate-900">Etiquetas del pedido</div>
          <button type="button" onclick="limpiarEtiquetas?.()"
                  class="text-xs font-extrabold text-slate-700 hover:text-slate-900 underline">
            Limpiar
          </button>
        </div>

        <div id="etiquetasSeleccionadas"
             class="mt-3 min-h-[54px] p-3 rounded-2xl border border-slate-200 bg-white flex flex-wrap gap-2">
        </div>

        <p class="mt-3 text-xs text-slate-500">
          Consejo: usa <b>P.*</b> (producción) o <b>D.*</b> (diseño) + una etiqueta de acción.
        </p>
      </div>

      <!-- Rápidas -->
      <div class="rounded-3xl border border-slate-200 bg-white p-5">
        <div class="font-extrabold text-slate-900">Etiquetas rápidas</div>
        <div id="listaEtiquetasRapidas" class="mt-3 flex flex-wrap gap-2"></div>
      </div>

      <!-- Botones -->
      <div class="flex flex-col sm:flex-row gap-3">
        <button type="button" onclick="guardarEtiquetas()"
                class="w-full px-4 py-3 rounded-2xl bg-blue-600 hover:bg-blue-700 text-white font-extrabold transition shadow-md">
          Guardar cambios
        </button>

        <button type="button" onclick="cerrarModalEtiquetas()"
                class="w-full px-4 py-3 rounded-2xl bg-slate-200 hover:bg-slate-300 text-slate-900 font-extrabold transition">
          Cerrar
        </button>
      </div>
    </div>

  </div>
</div>



<script>
/* ============================================================
   ETIQUETAS PREDETERMINADAS DESDE PHP
============================================================ */
window.etiquetasPredeterminadas = <?= json_encode($etiquetasPredeterminadas) ?>;

/* ============================================================
   MOSTRAR ETIQUETAS RÁPIDAS (chips fuertes + hover)
============================================================ */
function mostrarEtiquetasRapidas() {
  const cont = document.getElementById("listaEtiquetasRapidas");
  cont.innerHTML = "";

  (window.etiquetasPredeterminadas || []).forEach(tag => {
    cont.innerHTML += `
      <button type="button"
        onclick="agregarEtiqueta('${String(tag).replace(/'/g, "\\'")}')"
        class="px-4 py-2 rounded-2xl text-xs sm:text-sm font-extrabold
               border shadow-sm hover:scale-[1.03] active:scale-[0.99] transition
               ${colorEtiqueta(tag)}">
        ${tag}
      </button>
    `;
  });
}

/* ============================================================
   COLORES DE ETIQUETA (FUERTES)
   - D.*  => verde fuerte
   - P.*  => amarillo neón
   - default => slate fuerte
============================================================ */
function colorEtiqueta(tag) {
  tag = String(tag || "").toLowerCase();

  if (tag.startsWith("d.")) return "bg-emerald-600 text-white border-emerald-700 hover:bg-emerald-500";
  if (tag.startsWith("p.")) return "bg-yellow-400 text-black border-yellow-500 hover:bg-yellow-300";

  return "bg-slate-900 text-white border-slate-800 hover:bg-slate-800";
}

/* ============================================================
   CERRAR MODAL ETIQUETAS
============================================================ */
function cerrarModalEtiquetas() {
  document.getElementById("modalEtiquetas").classList.add("hidden");
}
</script>
