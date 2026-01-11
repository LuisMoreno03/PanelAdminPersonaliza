<?php
// app/Views/layouts/modales_estados.php
// ✅ Compatible con tu guardarEstado() actual:
// - usa <input id="modalOrderId"> (OBLIGATORIO)
// - botones llaman guardarEstado('...') con estados válidos
// ✅ Diseño fuerte/llamativo
// ✅ Render de botones basado en normalizeEstado() + estadoStyle() (dashboard.js vibe)
// ✅ Incluye A medias / Producción / Fabricando (para que guarde como tu modal original)
// ✅ Corrige el bug típico: estadoStyle usa label para detectar (más robusto)
?>

<!-- =============================================================== -->
<!-- MODAL CAMBIAR ESTADO DEL PEDIDO -->
<!-- =============================================================== -->
<div id="modalEstado"
     class="hidden fixed inset-0 bg-black/70 backdrop-blur-md flex items-center justify-center z-[9999] p-4">

  <div class="w-full max-w-md rounded-[28px] overflow-hidden shadow-2xl border border-white/10 animate-fadeIn">

    <!-- Header -->
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

      <!-- ✅ ESTE INPUT ES EL QUE LEE guardarEstado() -->
      <input type="hidden" id="modalOrderId" value="">
    </div>

    <!-- Body -->
    <div class="p-6 bg-white">
      <div class="grid gap-3" id="estadoOptionsWrap"></div>

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
<!-- MODAL EDITAR ETIQUETAS (CHIPS) -->
<!-- =============================================================== -->
<div id="modalEtiquetas"
     class="hidden fixed inset-0 bg-black/70 backdrop-blur-md flex items-center justify-center z-[9999] p-4">

  <div class="w-full max-w-3xl rounded-[28px] bg-white shadow-2xl border border-slate-200 overflow-hidden animate-fadeIn">

    <!-- Header -->
    <div class="p-6 bg-gradient-to-r from-slate-950 via-slate-900 to-slate-950 flex items-start justify-between gap-4">
      <div>
        <h2 class="text-xl sm:text-2xl font-extrabold text-white tracking-tight">Editar etiquetas</h2>
        <p class="text-sm text-white/70 mt-1">Toca para agregar / quitar chips</p>
      </div>

      <button type="button" onclick="cerrarModalEtiquetas()"
        class="h-10 w-10 rounded-2xl bg-white/10 border border-white/10 text-white
               hover:bg-white/20 hover:border-white/20 transition font-extrabold text-xl leading-none">
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
   ✅ ESTADOS (LOS QUE TU API REALMENTE GUARDA)
   (estos son los del modal original que te funcionaba)
============================================================ */
const ESTADOS_MODAL = [
  "Por preparar",
  "A medias",
  "Producción",
  "Fabricando",
  "Enviado",
];

/* ============================================================
   ✅ normalizeEstado (basado en dashboard.js + soporte de producción)
============================================================ */
function normalizeEstado(estado) {
  const s = String(estado || "").trim().toLowerCase();

  // dashboard.js
  if (s.includes("por preparar")) return "Por preparar";
  if (s.includes("faltan archivos") || s.includes("faltan_archivos")) return "Faltan archivos";
  if (s.includes("confirmado")) return "Confirmado";
  if (s.includes("diseñado") || s.includes("disenado")) return "Diseñado";
  if (s.includes("por producir")) return "Por producir";
  if (s.includes("enviado")) return "Enviado";

  // producción (tu flujo real)
  if (s.includes("a medias") || s.includes("amedias")) return "A medias";
  if (s.includes("producción") || s.includes("produccion")) return "Producción";
  if (s.includes("fabricando")) return "Fabricando";

  return estado ? String(estado).trim() : "Por preparar";
}

/* =====================================================
  ✅ ESTADO STYLE (muy llamativo)
  IMPORTANTE: usamos label para detectar (robusto)
===================================================== */
function estadoStyle(estado) {
  const label = normalizeEstado(estado);
  const s = String(label || "").toLowerCase().trim(); // ✅ robusto

  const base =
    "inline-flex items-center gap-2 px-3 py-1.5 rounded-2xl border " +
    "text-xs font-extrabold shadow-sm tracking-wide uppercase";

  const dotBase = "h-2.5 w-2.5 rounded-full ring-2 ring-white/40";

  if (s.includes("por preparar")) {
    return { label, icon: "⏳", wrap: `${base} bg-slate-900 border-slate-700 text-white`, dot: `${dotBase} bg-slate-300` };
  }

  // A medias (amarillo neón)
  if (s.includes("a medias")) {
    return { label, icon: "🟡", wrap: `${base} bg-yellow-400 border-yellow-500 text-black`, dot: `${dotBase} bg-black/80` };
  }

  // Producción (fucsia)
  if (s.includes("producción") || s.includes("produccion")) {
    return { label, icon: "🏭", wrap: `${base} bg-fuchsia-600 border-fuchsia-700 text-white`, dot: `${dotBase} bg-white` };
  }

  // Fabricando (azul)
  if (s.includes("fabricando")) {
    return { label, icon: "🛠️", wrap: `${base} bg-blue-600 border-blue-700 text-white`, dot: `${dotBase} bg-sky-200` };
  }

  // Enviado (verde fuerte)
  if (s.includes("enviado")) {
    return { label, icon: "🚚", wrap: `${base} bg-emerald-600 border-emerald-700 text-white`, dot: `${dotBase} bg-lime-200` };
  }

  // Opcionales dashboard.js (por si tu sistema los muestra en pills)
  if (s.includes("faltan archivos")) {
    return { label, icon: "⚠️", wrap: `${base} bg-yellow-400 border-yellow-500 text-black`, dot: `${dotBase} bg-black/80` };
  }
  if (s.includes("confirmado")) {
    return { label, icon: "✅", wrap: `${base} bg-fuchsia-600 border-fuchsia-700 text-white`, dot: `${dotBase} bg-white` };
  }
  if (s.includes("diseñado")) {
    return { label, icon: "🎨", wrap: `${base} bg-blue-600 border-blue-700 text-white`, dot: `${dotBase} bg-sky-200` };
  }
  if (s.includes("por producir")) {
    return { label, icon: "🏗️", wrap: `${base} bg-orange-600 border-orange-700 text-white`, dot: `${dotBase} bg-amber-200` };
  }

  return { label: label || "—", icon: "📍", wrap: `${base} bg-slate-700 border-slate-600 text-white`, dot: `${dotBase} bg-slate-200` };
}

/* ============================================================
   ✅ HTML de botón del modal usando estadoStyle()
   IMPORTANTE: el valor enviado a guardarEstado es el estadoValue ORIGINAL
============================================================ */
function renderEstadoOptionButtonHTML(estadoValue) {
  const st = estadoStyle(estadoValue);

  return `
    <button type="button"
      onclick="guardarEstado('${String(estadoValue).replace(/'/g, "\\'")}')"
      class="group w-full flex items-center justify-between gap-3 px-4 py-3 rounded-2xl
             ${st.wrap}
             hover:scale-[1.01] active:scale-[0.99] transition shadow-md">
      <span class="flex items-center gap-3">
        <span class="h-9 w-9 rounded-2xl bg-white/10 grid place-items-center text-lg">${st.icon}</span>
        <span class="leading-none">${st.label}</span>
      </span>
      <span class="${st.dot}"></span>
    </button>
  `;
}

/* ============================================================
   ✅ Renderiza opciones del modal (una sola vez)
============================================================ */
function renderEstadosModal() {
  const wrap = document.getElementById("estadoOptionsWrap");
  if (!wrap) return;
  wrap.innerHTML = ESTADOS_MODAL.map(renderEstadoOptionButtonHTML).join("");
}
document.addEventListener("DOMContentLoaded", renderEstadosModal);

/* ============================================================
   ✅ ETIQUETAS PREDETERMINADAS DESDE PHP
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
