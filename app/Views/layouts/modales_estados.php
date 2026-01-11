<?php
// app/Views/layouts/modales_estados.php
// ✅ Limpio + moderno
// ✅ Botones de estado oscuros (solo dark)
// ✅ Mantiene #modalOrderId y guardarEstado('...')
// ✅ Mantiene modalEtiquetas (chips)
?>

<!-- =============================================================== -->
<!-- MODAL CAMBIAR ESTADO DEL PEDIDO -->
<!-- =============================================================== -->
<div id="modalEstado"
     class="hidden fixed inset-0 z-[9999] p-4 bg-black/70 backdrop-blur-md flex items-center justify-center">

  <div class="w-full max-w-md rounded-[28px] overflow-hidden bg-white shadow-2xl border border-slate-200 animate-fadeIn">

    <!-- Header -->
    <div class="px-6 py-5 border-b border-slate-200 bg-white">
      <div class="flex items-start justify-between gap-4">
        <div>
          <h2 class="text-xl font-extrabold text-slate-900 tracking-tight">Cambiar estado</h2>
          <p class="text-sm text-slate-500 mt-1">Selecciona el nuevo estado del pedido</p>
        </div>

        <button type="button" onclick="cerrarModal()"
          class="h-10 w-10 rounded-2xl border border-slate-200 bg-white text-slate-700
                 hover:bg-slate-50 hover:border-slate-300 transition font-extrabold text-xl leading-none grid place-items-center">
          ×
        </button>
      </div>

      <!-- ✅ ESTE INPUT lo usa guardarEstado() -->
      <input type="hidden" id="modalOrderId" value="">
    </div>

    <!-- Body -->
    <div class="p-6">
      <div class="grid gap-2.5" id="estadoOptionsWrap"></div>

      <button type="button"
        onclick="cerrarModal()"
        class="mt-5 w-full px-4 py-3 rounded-2xl bg-slate-100 hover:bg-slate-200
               text-slate-900 font-extrabold transition">
        Cerrar
      </button>
    </div>

  </div>
</div>



<!-- =============================================================== -->
<!-- MODAL EDITAR ETIQUETAS (CHIPS) -->
<!-- =============================================================== -->
<div id="modalEtiquetas"
     class="hidden fixed inset-0 z-[9999] p-4 bg-black/70 backdrop-blur-md flex items-center justify-center">

  <div class="w-full max-w-3xl rounded-[28px] bg-white shadow-2xl border border-slate-200 overflow-hidden animate-fadeIn">

    <!-- Header -->
    <div class="p-6 border-b border-slate-200 bg-white flex items-start justify-between gap-4">
      <div>
        <h2 class="text-xl sm:text-2xl font-extrabold text-slate-900 tracking-tight">Editar etiquetas</h2>
        <p class="text-sm text-slate-500 mt-1">Toca para agregar / quitar chips</p>
      </div>

      <button type="button" onclick="cerrarModalEtiquetas()"
        class="h-10 w-10 rounded-2xl border border-slate-200 bg-white text-slate-700
               hover:bg-slate-50 hover:border-slate-300 transition font-extrabold text-xl leading-none grid place-items-center">
        ×
      </button>
    </div>

    <div class="p-6 space-y-5">
      <input type="hidden" id="modalEtiquetasOrderId" value="">

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
                class="w-full px-4 py-3 rounded-2xl bg-slate-900 hover:bg-slate-800 text-white font-extrabold transition shadow-sm">
          Guardar cambios
        </button>

        <button type="button" onclick="cerrarModalEtiquetas()"
                class="w-full px-4 py-3 rounded-2xl bg-slate-100 hover:bg-slate-200 text-slate-900 font-extrabold transition">
          Cerrar
        </button>
      </div>
    </div>

  </div>
</div>



<script>
/* ============================================================
   ✅ TU normalizeEstado() (MISMO)
============================================================ */
function normalizeEstado(estado) {
  const s = String(estado || "").trim().toLowerCase();

  if (s.includes("por preparar")) return "Por preparar";
  if (s.includes("faltan archivos") || s.includes("faltan_archivos")) return "Faltan archivos";
  if (s.includes("confirmado")) return "Confirmado";
  if (s.includes("diseñado") || s.includes("disenado")) return "Diseñado";
  if (s.includes("por producir")) return "Por producir";
  if (s.includes("enviado")) return "Enviado";

  return estado ? String(estado).trim() : "Por preparar";
}

/* =====================================================
  ESTADO STYLE (DARK ONLY) - limpio + moderno
  ✅ Sin colores neón: solo dark con acentos sutiles
===================================================== */
function estadoStyle(estado) {
  const label = normalizeEstado(estado);
  const s = String(label || "").toLowerCase().trim();

  const base =
    "w-full flex items-center justify-between gap-3 px-4 py-3 rounded-2xl " +
    "border text-white font-extrabold shadow-sm transition " +
    "hover:translate-y-[-1px] active:translate-y-[0px] active:scale-[0.995]";

  const iconWrap = "h-10 w-10 rounded-2xl grid place-items-center text-lg bg-white/10 border border-white/10";
  const dotBase  = "h-2.5 w-2.5 rounded-full ring-2 ring-white/20";

  // ✅ Solo tonos oscuros, con un acento muy sutil por estado
  if (s.includes("por preparar")) {
    return { label, icon: "⏳", wrap: `${base} bg-slate-950 border-slate-700 hover:border-slate-500`, iconWrap, dot: `${dotBase} bg-slate-200` };
  }
  if (s.includes("faltan archivos")) {
    return { label, icon: "⚠️", wrap: `${base} bg-zinc-950 border-yellow-700/60 hover:border-yellow-500/80`, iconWrap, dot: `${dotBase} bg-yellow-300` };
  }
  if (s.includes("confirmado")) {
    return { label, icon: "✅", wrap: `${base} bg-slate-950 border-fuchsia-700/60 hover:border-fuchsia-500/80`, iconWrap, dot: `${dotBase} bg-fuchsia-300` };
  }
  if (s.includes("diseñado")) {
    return { label, icon: "🎨", wrap: `${base} bg-slate-950 border-sky-700/60 hover:border-sky-500/80`, iconWrap, dot: `${dotBase} bg-sky-300` };
  }
  if (s.includes("por producir")) {
    return { label, icon: "🏗️", wrap: `${base} bg-slate-950 border-orange-700/60 hover:border-orange-500/80`, iconWrap, dot: `${dotBase} bg-orange-300` };
  }
  if (s.includes("enviado")) {
    return { label, icon: "🚚", wrap: `${base} bg-slate-950 border-emerald-700/60 hover:border-emerald-500/80`, iconWrap, dot: `${dotBase} bg-emerald-300` };
  }

  return { label: label || "—", icon: "📍", wrap: `${base} bg-slate-950 border-slate-700 hover:border-slate-500`, iconWrap, dot: `${dotBase} bg-slate-200` };
}

/* ============================================================
   ✅ BOTÓN DEL MODAL (usa guardarEstado(valor))
============================================================ */
function renderEstadoOptionButtonHTML(estadoValue) {
  const st = estadoStyle(estadoValue);

  return `
    <button type="button"
      onclick="guardarEstado('${String(estadoValue).replace(/'/g, "\\'")}')"
      class="${st.wrap}">
      <span class="flex items-center gap-3 min-w-0">
        <span class="${st.iconWrap}">${st.icon}</span>
        <span class="min-w-0">
          <span class="block text-sm sm:text-base leading-none">${st.label}</span>
          <span class="block text-[11px] font-bold text-white/70 mt-1">Toca para guardar</span>
        </span>
      </span>
      <span class="${st.dot}"></span>
    </button>
  `;
}

/* ============================================================
   ✅ ESTADOS QUE APARECEN EN EL MODAL
============================================================ */
function renderEstadosModal() {
  const wrap = document.getElementById("estadoOptionsWrap");
  if (!wrap) return;

  const estados = [
    "Por preparar",
    "faltan_archivos",
    "Confirmado",
    "Diseñado",
    "Por producir",
    "Enviado"
  ];

  wrap.innerHTML = estados.map(renderEstadoOptionButtonHTML).join("");
}

document.addEventListener("DOMContentLoaded", renderEstadosModal);

/* ============================================================
   ETIQUETAS PREDETERMINADAS DESDE PHP
============================================================ */
window.etiquetasPredeterminadas = <?= json_encode($etiquetasPredeterminadas ?? []) ?>;

/* ============================================================
   MOSTRAR ETIQUETAS RÁPIDAS
============================================================ */
function mostrarEtiquetasRapidas() {
  const cont = document.getElementById("listaEtiquetasRapidas");
  if (!cont) return;
  cont.innerHTML = "";

  (window.etiquetasPredeterminadas || []).forEach(tag => {
    cont.innerHTML += `
      <button type="button"
        onclick="agregarEtiqueta('${String(tag).replace(/'/g, "\\'")}')"
        class="px-4 py-2 rounded-2xl text-xs sm:text-sm font-extrabold border shadow-sm
               hover:scale-[1.03] active:scale-[0.99] transition ${colorEtiqueta(tag)}">
        ${tag}
      </button>
    `;
  });
}

/* ============================================================
   COLORES DE ETIQUETA (limpio)
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
  document.getElementById("modalEtiquetas")?.classList.add("hidden");
}
</script>
