<?php ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <meta name="csrf-token" content="<?= csrf_hash() ?>">
  <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs" defer></script>
  <!-- ✅ Flatpickr (calendario moderno) -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/material_blue.css">

  <title><?= esc($title ?? 'Seguimiento') ?></title>

  <style>
    .seg-grid-cols{
      display:grid;
      grid-template-columns: 260px minmax(220px,1fr) 150px 150px 200px 120px;
      align-items:center;
      gap: .75rem;
    }
  </style>

  <script>
    window.API_BASE = "<?= rtrim(base_url(), '/') ?>";
  </script>
</head>

<body class="bg-slate-50 text-slate-900">

  <?= view('layouts/menu') ?>

  <!-- Loader -->
  <div id="globalLoader" class="hidden fixed inset-0 z-[9999] bg-black/30 backdrop-blur-[1px]">
    <div class="absolute inset-0 flex items-center justify-center">
      <div class="rounded-3xl bg-white shadow-xl border border-slate-200 px-6 py-5 flex items-center gap-3">
        <div class="h-6 w-6 rounded-full border-4 border-slate-200 border-t-slate-900 animate-spin"></div>
        <div class="font-extrabold text-slate-900">Cargando…</div>
      </div>
    </div>
  </div>

  <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-7">
    <div class="flex flex-col gap-4">

      <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
          <div class="flex items-center gap-3">
            <div class="h-10 w-10 rounded-3xl bg-slate-900 text-white flex items-center justify-center font-black">S</div>
            <div class="min-w-0">
              <h1 class="text-2xl font-extrabold tracking-tight truncate">Seguimiento</h1>
              <p class="text-sm text-slate-600 mt-0.5">
                Cambios de estados internos realizados por cada usuario.
              </p>
            </div>
          </div>
        </div>

        <div class="shrink-0 flex items-center gap-2 flex-wrap justify-end">
          <span class="px-3 py-2 rounded-2xl bg-white border border-slate-200 shadow-sm text-sm font-extrabold">
            Usuarios: <span id="total-usuarios">0</span>
          </span>
          <span class="px-3 py-2 rounded-2xl bg-white border border-slate-200 shadow-sm text-sm font-extrabold">
            Cambios: <span id="total-cambios">0</span>
          </span>
          <span class="px-3 py-2 rounded-2xl bg-white border border-slate-200 shadow-sm text-sm font-extrabold">
            Pedidos tocados: <span id="total-pedidos-tocados">0</span>
          </span>
        </div>
      </div>

      <!-- Buscador + Filtro -->
      <div class="flex flex-col lg:flex-row lg:items-center gap-3">
        <div class="flex-1 flex items-center gap-2">
          <div class="relative flex-1">
            <input id="inputBuscar" type="text" placeholder="Buscar por nombre o email…"
              class="w-full h-11 rounded-2xl border border-slate-200 bg-white px-4 pr-10 text-sm font-semibold outline-none
                     focus:ring-4 focus:ring-slate-200 focus:border-slate-300">
            <div class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400">⌕</div>
          </div>

          <button id="btnLimpiarBusqueda" type="button"
            class="h-11 px-4 rounded-2xl bg-white border border-slate-200 shadow-sm text-slate-900 font-extrabold text-sm hover:bg-slate-100 transition">
            Limpiar
          </button>
        </div>

        <div class="flex items-center gap-2 justify-end flex-wrap">
          <!-- ✅ Rango moderno -->
          <input id="range" type="text" placeholder="📅 Rango de fechas"
            class="h-11 w-64 rounded-2xl border border-slate-200 bg-white px-3 text-sm font-extrabold outline-none
                   focus:ring-4 focus:ring-slate-200 focus:border-slate-300">

          <button id="btnFiltrar" type="button"
            class="h-11 px-4 rounded-2xl bg-slate-900 text-white font-extrabold text-sm shadow-sm hover:bg-slate-800 transition">
            Filtrar
          </button>

          <button id="btnLimpiarFechas" type="button"
            class="h-11 px-4 rounded-2xl bg-white border border-slate-200 text-slate-900 font-extrabold text-sm shadow-sm hover:bg-slate-100 transition">
            Quitar filtros
          </button>

          <button id="btnActualizar" type="button"
            class="h-11 px-4 rounded-2xl bg-white border border-slate-200 text-slate-900 font-extrabold text-sm shadow-sm hover:bg-slate-100 transition">
            Actualizar
          </button>
        </div>
      </div>

      <div id="errorBox" class="hidden rounded-3xl border border-red-200 bg-red-50 text-red-700 px-4 py-3 font-extrabold text-sm"></div>
    </div>

    <!-- Listado -->
    <section class="mt-6">
      <!-- Desktop -->
      <div class="hidden xl:block rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="seg-grid-cols px-4 py-3 bg-slate-50 border-b border-slate-200 text-[12px] font-extrabold uppercase tracking-wide text-slate-600">
          <div>Usuario</div>
          <div>Email</div>
          <div class="text-right">Pedidos tocados</div>
          <div class="text-right">Total cambios</div>
          <div>Último cambio</div>
          <div class="text-right">Acciones</div>
        </div>
        <div id="tablaSeguimiento"></div>
      </div>

      <!-- Mobile -->
      <div id="cardsSeguimiento" class="xl:hidden"></div>
    </section>
  </main>

  <!-- Modal detalle -->
  <div id="detalleModal" class="hidden fixed inset-0 z-[9998] bg-black/40 backdrop-blur-[1px]">
    <div class="absolute inset-0 flex items-center justify-center p-4" data-close="1">
      <div class="w-full max-w-5xl rounded-3xl bg-white border border-slate-200 shadow-2xl overflow-hidden" data-close="0">
        <div class="px-5 py-4 border-b border-slate-200 flex items-start justify-between gap-3">
          <div class="min-w-0">
            <div id="detalleTitulo" class="text-lg font-extrabold text-slate-900 truncate">Detalle</div>

            <!-- ✅ Descripción acomodada -->
            <div id="detalleDescripcion" class="mt-2 flex flex-wrap gap-2"></div>

            <!-- ✅ Pedidos tocados -->
            <div id="detallePedidosBox" class="mt-3"></div>
          </div>

          <div class="shrink-0 flex items-center gap-2">
            <button id="detalleCerrar"
              class="h-10 px-4 rounded-2xl bg-white border border-slate-200 font-extrabold text-sm hover:bg-slate-100">
              Cerrar
            </button>
          </div>
        </div>

        <div class="px-5 py-4">
          <div id="detalleLoading" class="hidden mb-3 text-sm font-extrabold text-slate-600">Cargando detalle…</div>
          <div id="detalleError" class="hidden mb-3 rounded-2xl border border-red-200 bg-red-50 text-red-700 px-3 py-2 font-extrabold text-sm"></div>

          <!-- Desktop -->
          <div class="hidden sm:block rounded-3xl border border-slate-200 overflow-hidden">
            <div class="grid grid-cols-10 px-4 py-3 bg-slate-50 border-b border-slate-200 text-[12px] font-extrabold uppercase tracking-wide text-slate-600">
              <div class="col-span-2">Fecha</div>
              <div class="col-span-2">Entidad</div>
              <div class="col-span-2">ID</div>
              <div class="col-span-2">Antes</div>
              <div class="col-span-2">Después</div>
            </div>
            <div id="detalleBodyTable" class="max-h-[65vh] overflow-auto"></div>
          </div>

          <!-- Mobile -->
          <div id="detalleBodyCards" class="sm:hidden"></div>

          <div class="mt-4 flex items-center justify-between gap-2">
            <div id="detallePaginacionInfo" class="text-sm font-extrabold text-slate-600">—</div>
            <div class="flex items-center gap-2">
              <button id="detallePrev" class="h-10 px-4 rounded-2xl bg-white border border-slate-200 font-extrabold text-sm hover:bg-slate-100">Anterior</button>
              <button id="detalleNext" class="h-10 px-4 rounded-2xl bg-white border border-slate-200 font-extrabold text-sm hover:bg-slate-100">Siguiente</button>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- ✅ Flatpickr JS -->
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>

  <script src="<?= base_url('js/seguimiento.js') ?>"></script>
</body>
</html>
