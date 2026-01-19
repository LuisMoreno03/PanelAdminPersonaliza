<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- CSRF -->
  <meta name="csrf-token" content="<?= csrf_hash() ?>">
  <meta name="csrf-header" content="<?= csrf_header() ?>">

  <title>Confirmación · Panel</title>

  <!-- Tailwind -->
  <script src="https://cdn.tailwindcss.com"></script>

  <style>
    body { background: #f3f4f6; }

    .layout { padding-left: 16rem; transition: padding-left .2s ease; }
    .layout.menu-collapsed { padding-left: 5.25rem; }
    @media (max-width: 768px) {
      .layout, .layout.menu-collapsed { padding-left: 0 !important; }
    }

    .orders-grid {
      display: grid;
      align-items: center;
      gap: .65rem;
      width: 100%;
    }

    .orders-grid.cols {
      grid-template-columns:
        140px
        92px
        minmax(170px, 1.2fr)
        90px
        160px
        minmax(120px, 0.9fr)
        minmax(160px, 1fr)
        44px
        140px
        minmax(190px, 1fr)
        130px;
    }

    .orders-grid > div { min-width: 0; }
    .table-scroll { overflow-x: auto; }
  </style>
</head>

<body class="min-h-screen bg-gradient-to-b from-slate-50 to-slate-100 overflow-x-hidden">

<?= view('layouts/menu') ?>

<main id="mainLayout" class="layout">
  <div class="p-4 sm:p-6 lg:p-8">
    <div class="mx-auto w-full max-w-[1600px]">

      <!-- HEADER -->
      <section class="mb-6">
        <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5 flex justify-between items-center gap-4">
          <div>
            <h1 class="text-3xl font-extrabold text-slate-900">Confirmación</h1>
            <p class="text-slate-500 mt-1">
              Pedidos asignados en estado <b>Por preparar</b>
            </p>
          </div>

          <span class="px-4 py-2 rounded-2xl bg-white border border-slate-200 font-extrabold text-sm">
            Pedidos: <span id="total-pedidos">0</span>
          </span>
        </div>
      </section>

      <!-- LISTADO -->
      <section class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between">
          <div class="font-semibold text-slate-900">Listado de pedidos</div>

          <div class="flex gap-2">
            <button id="btnTraer5"
              class="px-4 py-2 rounded-xl bg-slate-900 text-white font-bold hover:bg-slate-800">
              Traer 5
            </button>

            <button id="btnTraer10"
              class="px-4 py-2 rounded-xl bg-slate-900 text-white font-bold hover:bg-slate-800">
              Traer 10
            </button>

            <button id="btnDevolver"
              class="px-4 py-2 rounded-xl bg-rose-600 text-white font-bold hover:bg-rose-700">
              Devolver
            </button>
          </div>
        </div>

        <div class="table-scroll">
          <div class="orders-grid cols px-4 py-3 text-[11px] uppercase tracking-wider text-slate-600 bg-slate-50 border-b">
            <div>Pedido</div>
            <div>Fecha</div>
            <div>Cliente</div>
            <div>Total</div>
            <div>Estado</div>
            <div>Último cambio</div>
            <div>Etiquetas</div>
            <div class="text-center">Art</div>
            <div>Entrega</div>
            <div>Método</div>
            <div class="text-right">Ver</div>
          </div>

          <div id="tablaPedidos" class="divide-y"></div>
        </div>
      </section>

    </div>
  </div>
</main>

<!-- =========================
     MODAL ETIQUETAS BONITO (ÚNICO)
  ========================= -->
  <div id="modalEtiquetas" class="hidden fixed inset-0 z-[10050] bg-black/40 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="w-full max-w-3xl rounded-3xl bg-white shadow-2xl border border-slate-200 overflow-hidden animate-fadeIn">

      <!-- Header -->
      <div class="p-5 sm:p-6 border-b border-slate-200 flex items-start justify-between gap-4">
        <div>
          <h3 class="text-lg sm:text-xl font-extrabold text-slate-900">Etiquetas del pedido</h3>
          <p class="text-sm text-slate-500 mt-1">
            Selecciona máximo <b>6</b>. Se guardan como <b>tags</b> en Shopify.
          </p>
        </div>

        <button type="button" onclick="cerrarModalEtiquetas()"
          class="h-10 w-10 rounded-2xl border border-slate-200 bg-white text-slate-600 hover:text-slate-900 hover:border-slate-300 transition font-extrabold text-xl leading-none">
          ×
        </button>
      </div> 

      <!-- Content -->
      <div class="p-5 sm:p-6 space-y-5">

        <!-- Meta -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
          <div class="flex items-center gap-2">
            <span class="text-xs font-extrabold uppercase tracking-wide text-slate-500">Pedido:</span>
            <span id="etqPedidoLabel" class="text-sm font-extrabold text-slate-900">—</span>
          </div>

          <div class="flex items-center gap-2">
            <span class="text-xs font-extrabold uppercase tracking-wide text-slate-500">Límite:</span>
            <span id="etqCounter"
                  class="inline-flex items-center px-3 py-1 rounded-full text-xs font-extrabold bg-slate-50 border border-slate-200 text-slate-800">
              0 / 6
            </span>
          </div>
        </div>

        <!-- Selected -->
        <div class="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
          <div class="flex items-center justify-between">
            <h4 class="font-extrabold text-slate-900">Seleccionadas</h4>
            <button type="button" onclick="limpiarEtiquetas()"
                    class="text-xs font-extrabold text-slate-700 hover:text-slate-900 underline">
              Limpiar
            </button>
          </div>

          <div id="etqSelectedWrap" class="mt-3 flex flex-wrap gap-2"></div>

          <div id="etqLimitHint" class="hidden mt-3 text-sm font-bold text-rose-600">
            Máximo 6 etiquetas.
          </div>

          <div id="etqError" class="hidden mt-3 text-sm font-bold text-rose-600"></div>
        </div>

        <!-- Producción -->
        <div id="etqSectionProduccion" class="rounded-2xl border border-amber-200 bg-amber-50/50 p-4">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
              <span class="h-2.5 w-2.5 rounded-full bg-amber-500"></span>
              <h4 class="font-extrabold text-amber-900">Producción</h4>
            </div>
            <span class="text-xs font-extrabold text-amber-800/80">P.*</span>
          </div>
          <div id="etqProduccionList" class="mt-3 flex flex-wrap gap-2"></div>
        </div>

        <!-- Diseño -->
        <div id="etqSectionDiseno" class="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
              <span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
              <h4 class="font-extrabold text-emerald-900">Diseño</h4>
            </div>
            <span class="text-xs font-extrabold text-emerald-800/80">D.*</span>
          </div>
          <div id="etqDisenoList" class="mt-3 flex flex-wrap gap-2"></div>
        </div>

        <!-- Generales -->
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
              <span class="h-2.5 w-2.5 rounded-full bg-slate-900"></span>
              <h4 class="font-extrabold text-slate-900">Generales</h4>
            </div>
            <span class="text-xs font-extrabold text-slate-500">Acciones</span>
          </div>
          <div id="etqGeneralesList" class="mt-3 flex flex-wrap gap-2"></div>
        </div>

      </div>

      <!-- Footer -->
      <div class="p-5 sm:p-6 border-t border-slate-200 flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between">
        <div class="text-xs text-slate-500">
          Consejo: usa 1 tag de proceso (P.* o D.*) + 1 tag de acción (Reembolso/No contesta/etc.)
        </div>

        <div class="flex gap-2">
          <button type="button" onclick="cerrarModalEtiquetas()"
            class="px-4 py-3 rounded-2xl bg-slate-200 text-slate-800 font-extrabold hover:bg-slate-300 transition">
            Cancelar
          </button>

          <button id="btnGuardarEtiquetas"
            type="button"
            onclick="guardarEtiquetasModal()"
            class="px-4 py-3 rounded-2xl bg-slate-900 text-white font-extrabold hover:bg-slate-800 transition disabled:opacity-60 disabled:cursor-not-allowed">
            Guardar
          </button>
        </div>
      </div>

    </div>
  </div>
  
  <!-- =============================================================== -->
   <!-- MODAL DETALLES PEDIDO -->
  <!-- =============================================================== -->
  <?= view('layouts/modal_detalles') ?>
  <!-- MODALES (SOLO ESTADO) -->
  <?= view('layouts/modales_estados') ?>

<!-- LOADER -->
<div id="globalLoader" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-[100]">
  <div class="bg-white p-6 rounded-3xl shadow-xl text-center">
    <div class="w-10 h-10 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto"></div>
    <p class="mt-3 font-semibold">Cargando…</p>
  </div>
</div>

<!-- API -->
<script>
window.API = {
  myQueue: "<?= site_url('confirmacion/my-queue') ?>",
  pull: "<?= site_url('confirmacion/pull') ?>",
  returnAll: "<?= site_url('confirmacion/return-all') ?>",
  detalles: "<?= site_url('dashboard/detalles') ?>"
};
</script>

<script src="<?= base_url('js/confirmacion.js?v=' . time()) ?>"></script>

<script>
(function () {
  const main = document.getElementById('mainLayout');
  if (localStorage.getItem('menuCollapsed') === '1') {
    main.classList.add('menu-collapsed');
  }
})();
</script>

</body>
</html>
