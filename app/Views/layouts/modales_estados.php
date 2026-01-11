<?php
// app/Views/layouts/modales_estados.php
// ✅ MISMA LÓGICA / MISMO FUNCIONAMIENTO
// ✅ Diseño moderno + súper visual + colores fuertes
?>

<!-- =============================================================== -->
<!-- MODAL CAMBIAR ESTADO DEL PEDIDO -->
<!-- =============================================================== -->
<div id="modalEstado"
     class="hidden fixed inset-0 z-[9999] p-4 bg-black/80 backdrop-blur-md flex items-center justify-center">

  <!-- Card -->
  <div class="w-full max-w-md rounded-[32px] overflow-hidden shadow-[0_20px_80px_rgba(0,0,0,.55)] border border-white/10 animate-fadeIn">

    <!-- Header -->
    <div class="relative px-6 py-6 bg-gradient-to-r from-slate-950 via-slate-900 to-slate-950">
      <!-- glow -->
      <div class="pointer-events-none absolute -top-24 -right-24 h-56 w-56 rounded-full bg-fuchsia-600/20 blur-3xl"></div>
      <div class="pointer-events-none absolute -bottom-24 -left-24 h-56 w-56 rounded-full bg-blue-600/20 blur-3xl"></div>

      <div class="flex items-start justify-between gap-4 relative">
        <div>
          <h2 class="text-2xl font-extrabold text-white tracking-tight">Cambiar estado</h2>
          <p class="text-sm text-white/70 mt-1">Selecciona el nuevo estado del pedido</p>
        </div>

        <button type="button" onclick="cerrarModal()"
          class="h-11 w-11 rounded-2xl bg-white/10 border border-white/10 text-white
                 hover:bg-white/20 hover:border-white/20 transition font-extrabold text-2xl leading-none grid place-items-center">
          ×
        </button>
      </div>

      <!-- ✅ ESTE INPUT lo usa guardarEstado() -->
      <input type="hidden" id="modalOrderId" value="">

      <!-- microhint -->
      <div class="mt-4 inline-flex items-center gap-2 px-3 py-1.5 rounded-2xl bg-white/10 border border-white/10 text-xs font-extrabold text-white/90">
        <span class="h-2.5 w-2.5 rounded-full bg-emerald-300"></span>
        Se guarda automáticamente al tocar un estado
      </div>
    </div>

    <!-- Body -->
    <div class="p-6 bg-white">
      <div class="grid gap-3" id="estadoOptionsWrap"></div>

      <button type="button"
        onclick="cerrarModal()"
        class="mt-6 w-full px-4 py-3 rounded-2xl bg-slate-900 hover:bg-slate-800
               text-white font-extrabold transition shadow-md active:scale-[0.99]">
        Cerrar
      </button>
    </div>

  </div>
</div>



<!-- =============================================================== -->
<!-- MODAL EDITAR ETIQUETAS (CHIPS) -->
<!-- =============================================================== -->
<div id="modalEtiquetas"
     class="hidden fixed inset-0 z-[9999] p-4 bg-black/80 backdrop-blur-md flex items-center justify-center">

  <div class="w-full max-w-3xl rounded-[32px] bg-white shadow-[0_20px_80px_rgba(0,0,0,.55)] border border-slate-200 overflow-hidden animate-fadeIn">

    <!-- Header -->
    <div class="relative p-6 bg-gradient-to-r from-slate-950 via-slate-900 to-slate-950 flex items-start justify-between gap-4">
      <div class="pointer-events-none absolute -top-24 -right-24 h-56 w-56 rounded-full bg-emerald-500/20 blur-3xl"></div>
      <div class="pointer-events-none absolute -bottom-24 -left-24 h-56 w-56 rounded-full bg-yellow-400/20 blur-3xl"></div>

      <div class="relative">
        <h2 class="text-2xl font-extrabold text-white tracking-tight">Editar etiquetas</h2>
        <p class="text-sm text-white/70 mt-1">Toca para agregar / quitar chips</p>

        <div class="mt-4 flex flex-wrap gap-2">
          <span class="px-3 py-1.5 rounded-2xl bg-white/10 border border-white/10 text-xs font-extrabold text-white/90">Máximo recomendado: 6</span>
          <span class="px-3 py-1.5 rounded-2xl bg-white/10 border border-white/10 text-xs font-extrabold text-white/90">P.* Producción</span>
          <span class="px-3 py-1.5 rounded-2xl bg-white/10 border border-white/10 text-xs font-extrabold text-white/90">D.* Diseño</span>
        </div>
      </div>

      <button type="button" onclick="cerrarModalEtiquetas()"
        class="relative h-11 w-11 rounded-2xl bg-white/10 border border-white/10 text-white
               hover:bg-white/20 hover:border-white/20 transition font-extrabold text-2xl leading-none grid place-items-center">
        ×
      </button>
    </div>

    <div class="p-6 space-y-6">
      <input type="hidden" id="modalEtiquetasOrderId" value="">

      <!-- Seleccionadas -->
      <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
        <div class="flex items-center justify-between gap-3">
          <div class="text-lg font-extrabold text-slate-900">Etiquetas del pedido</div>

          <button type="button" onclick="limpiarEtiquetas?.()"
                  class="text-xs font-extrabold text-rose-600 hover:text-rose-700 underline">
            Limpiar
          </button>
        </div>

        <div id="etiquetasSeleccionadas"
             class="mt-4 min-h-[58px] p-3 rounded-2xl border border-slate-200 bg-white flex flex-wrap gap-2 soft-scroll">
        </div>

        <p class="mt-3 text-xs text-slate-600">
          Consejo: usa <b>P.*</b> (producción) o <b>D.*</b> (diseño) + una etiqueta de acción.
        </p>
      </div>

      <!-- Rápidas -->
      <div class="rounded-3xl border border-slate-200 bg-white p-5">
        <div class="flex items-center justify-between">
          <div class="text-lg font-extrabold text-slate-900">Etiquetas rápidas</div>
          <button type="button" onclick="mostrarEtiquetasRapidas()"
                  class="text-xs font-extrabold text-slate-700 hover:text-slate-900 underline">
            Recargar
          </button>
        </div>
        <div id="listaEtiquetasRapidas" class="mt-4 flex flex-wrap gap-2"></div>
      </div>

      <!-- Botones -->
      <div class="flex flex-col sm:flex-row gap-3">
        <button type="button" onclick="guardarEtiquetas()"
                class="w-full px-4 py-3 rounded-2xl bg-blue-600 hover:bg-blue-700 text-white font-extrabold transition shadow-md active:scale-[0.99]">
          Guardar cambios
        </button>

        <button type="button" onclick="cerrarModalEtiquetas()"
                class="w-full px-4 py-3 rounded-2xl bg-slate-900 hover:bg-slate-800 text-white font-extrabold transition shadow-md active:scale-[0.99]">
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
  ESTADO STYLE (FUERTE) - SOLO PARA ESTOS ESTADOS
  ✅ FIX: ahora detecta "Faltan archivos" correctamente
===================================================== */
function estadoStyle(estado) {
  const label = normalizeEstado(estado);
  const s = String(label || "").toLowerCase().trim();

  // base pill (pero en modal usamos un card-boton más potente)
  const base =
    "w-full flex items-center justify-between gap-3 px-4 py-3 rounded-2xl " +
    "border font-extrabold tracking-wide shadow-md transition " +
    "hover:scale-[1.01] active:scale-[0.99]";

  // icon capsule
  const iconWrap =
    "h-10 w-10 rounded-2xl grid place-items-center text-lg " +
    "shadow-sm border border-white/15";

  // dot
  const dotBase = "h-3 w-3 rounded-full ring-2";

  if (s.includes("por preparar")) {
    return {
      label,
      icon: "⏳",
      wrap: `${base} bg-slate-950 text-white border-slate-800 hover:bg-slate-900`,
      iconWrap: `${iconWrap} bg-white/10`,
      dot: `${dotBase} bg-slate-200 ring-white/30`,
    };
  }

  if (s.includes("faltan archivos")) {
    return {
      label,
      icon: "⚠️",
      wrap: `${base} bg-yellow-400 text-black border-yellow-500 hover:bg-yellow-300`,
      iconWrap: `${iconWrap} bg-black/10 border-black/10`,
      dot: `${dotBase} bg-black/80 ring-black/20`,
    };
  }

  if (s.includes("confirmado")) {
    return {
      label,
      icon: "✅",
      wrap: `${base} bg-fuchsia-600 text-white border-fuchsia-700 hover:bg-fuchsia-500`,
      iconWrap: `${iconWrap} bg-white/15`,
      dot: `${dotBase} bg-white ring-white/30`,
    };
  }

  if (s.includes("diseñado")) {
    return {
      label,
      icon: "🎨",
      wrap: `${base} bg-blue-600 text-white border-blue-700 hover:bg-blue-500`,
      iconWrap: `${iconWrap} bg-white/15`,
      dot: `${dotBase} bg-sky-200 ring-white/30`,
    };
  }

  if (s.includes("por producir")) {
    return {
      label,
      icon: "🏗️",
      wrap: `${base} bg-orange-600 text-white border-orange-700 hover:bg-orange-500`,
      iconWrap: `${iconWrap} bg-white/15`,
      dot: `${dotBase} bg-amber-200 ring-white/30`,
    };
  }

  if (s.includes("enviado")) {
    return {
      label,
      icon: "🚚",
      wrap: `${base} bg-emerald-600 text-white border-emerald-700 hover:bg-emerald-500`,
      iconWrap: `${iconWrap} bg-white/15`,
      dot: `${dotBase} bg-lime-200 ring-white/30`,
    };
  }

  return {
    label: label || "—",
    icon: "📍",
    wrap: `${base} bg-slate-700 text-white border-slate-600 hover:bg-slate-600`,
    iconWrap: `${iconWrap} bg-white/15`,
    dot: `${dotBase} bg-slate-200 ring-white/30`,
  };
}

/* ============================================================
   ✅ BOTÓN DEL MODAL (usa guardarEstado(valor))
   Se manda el estadoValue TAL CUAL (como lo tenías)
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
          <span class="block text-[11px] font-bold opacity-80 mt-1">
            Toca para guardar
          </span>
        </span>
      </span>
      <span class="${st.dot}"></span>
    </button>
  `;
}

/* ============================================================
   ✅ ESTADOS QUE APARECEN EN EL MODAL (los de tu normalizeEstado)
   (dejo faltan_archivos tal cual lo tenías)
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
        class="px-4 py-2 rounded-2xl text-xs sm:text-sm font-extrabold
               border shadow-md hover:scale-[1.03] active:scale-[0.99] transition
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

  return "bg-slate-950 text-white border-slate-800 hover:bg-slate-900";
}

/* ============================================================
   CERRAR MODAL ETIQUETAS
============================================================ */
function cerrarModalEtiquetas() {
  document.getElementById("modalEtiquetas")?.classList.add("hidden");
}
</script>
