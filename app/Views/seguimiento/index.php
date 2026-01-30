<?php
  // seguimiento/index.php
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <meta name="csrf-token" content="<?= csrf_hash() ?>">
  <meta name="csrf-header" content="<?= function_exists('csrf_header') ? csrf_header() : 'X-CSRF-TOKEN' ?>">

  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/alpinejs" defer></script>

  <!-- Flatpickr (calendario moderno) -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

  <title><?= esc($title ?? 'Seguimiento') ?></title>

  <style>
    .seg-grid-cols{
      display:grid;
      grid-template-columns: 260px 280px 150px minmax(260px,1fr) 120px;
      align-items:center;
    }
  </style>

  <script>
    window.API_BASE = "<?= rtrim(base_url(), '/') ?>";
  </script>
</head>

<body class="bg-slate-50 text-slate-900">
  <?= view('layouts/menu') ?>

  <!-- Loader global -->
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
                Resumen de cambios de estados internos realizados por cada usuario.
              </p>
            </div>
          </div>
        </div>

        <div class="shrink-0 flex items-center gap-2">
          <span class="px-3 py-2 rounded-2xl bg-white border border-slate-200 shadow-sm text-sm font-extrabold">
            Usuarios: <span id="total-usuarios">0</span>
          </span>
          <span class="px-3 py-2 rounded-2xl bg-white border border-slate-200 shadow-sm text-sm font-extrabold">
            Cambios: <span id="total-cambios">0</span>
          </span>
          <span class="px-3 py-2 rounded-2xl bg-white border border-slate-200 shadow-sm text-sm font-extrabold">
            Pedidos (general): <span id="total-pedidos-general">0</span>
          </span>
        </div>
      </div>

      <!-- Buscador + filtros -->
      <div class="flex flex-col lg:flex-row lg:items-center gap-3">
        <div class="flex-1 flex items-center gap-2">
          <div class="relative flex-1">
            <input id="inputBuscar" type="text" placeholder="Buscar por usuario o email…"
              class="w-full h-11 rounded-2xl border border-slate-200 bg-white px-4 pr-10 text-sm font-semibold outline-none
                     focus:ring-4 focus:ring-slate-200 focus:border-slate-300">
            <div class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400">⌕</div>
          </div>

          <button id="btnLimpiarBusqueda" type="button"
            class="h-11 px-4 rounded-2xl bg-white border border-slate-200 shadow-sm text-slate-900 font-extrabold text-sm hover:bg-slate-100 transition">
            Limpiar
          </button>
        </div>

        <div class="flex flex-wrap items-center gap-2 justify-end">
          <input id="from" type="text" placeholder="Desde"
            class="h-11 w-[140px] rounded-2xl border border-slate-200 bg-white px-4 text-sm font-semibold outline-none
                   focus:ring-4 focus:ring-slate-200 focus:border-slate-300">

          <input id="to" type="text" placeholder="Hasta"
            class="h-11 w-[140px] rounded-2xl border border-slate-200 bg-white px-4 text-sm font-semibold outline-none
                   focus:ring-4 focus:ring-slate-200 focus:border-slate-300">

          <button id="btnFiltrar" type="button"
            class="h-11 px-4 rounded-2xl bg-slate-900 text-white font-extrabold text-sm shadow-sm hover:bg-slate-800 transition">
            Filtrar
          </button>

          <button id="btnLimpiarFechas" type="button"
            class="h-11 px-4 rounded-2xl bg-white border border-slate-200 text-slate-900 font-extrabold text-sm shadow-sm hover:bg-slate-100 transition">
            Quitar filtros
          </button>

          <button type="button" onclick="window.__seguimientoRefresh && window.__seguimientoRefresh()"
            class="h-11 px-4 rounded-2xl bg-white border border-slate-200 text-slate-900 font-extrabold text-sm shadow-sm hover:bg-slate-100 transition">
            Actualizar
          </button>
        </div>
      </div>

      <!-- Error box -->
      <div id="errorBox" class="hidden rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-extrabold text-red-700"></div>
    </div>

    <!-- Listado -->
    <section class="mt-6">
      <!-- Vista XL -->
      <div class="hidden xl:block 2xl:hidden rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="seg-grid-cols px-4 py-3 bg-slate-50 border-b border-slate-200 text-[12px] font-extrabold uppercase tracking-wide text-slate-600">
          <div>Usuario</div>
          <div>Email</div>
          <div class="text-right">Pedidos tocados</div>
          <div>Total / Confirmados / Diseños / Último</div>
          <div class="text-right">Acciones</div>
        </div>
        <div id="tablaSeguimientoTable"></div>
      </div>

      <!-- Vista 2XL -->
      <div class="hidden 2xl:block rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="seg-grid-cols px-4 py-3 bg-slate-50 border-b border-slate-200 text-[12px] font-extrabold uppercase tracking-wide text-slate-600">
          <div>Usuario</div>
          <div>Email</div>
          <div class="text-right">Pedidos tocados</div>
          <div>Total / Confirmados / Diseños / Último</div>
          <div class="text-right">Acciones</div>
        </div>
        <div id="tablaSeguimiento"></div>
      </div>

      <!-- Mobile cards -->
      <div id="cardsSeguimiento" class="xl:hidden"></div>
    </section>
  </main>

  <!-- Modal Detalle -->
  <div id="detalleModal" class="hidden fixed inset-0 z-[9998]" aria-hidden="true">
    <div class="absolute inset-0 bg-black/40" data-close="1"></div>

    <div class="absolute inset-0 flex items-center justify-center p-2 sm:p-6">
      <div class="w-full max-w-6xl max-h-[92vh] rounded-3xl bg-white shadow-xl border border-slate-200 overflow-hidden flex flex-col">
        <div class="p-4 sm:p-5 border-b border-slate-200 bg-white flex items-start justify-between gap-3">
          <div class="min-w-0">
            <div id="detalleTitulo" class="text-lg sm:text-xl font-extrabold truncate">Detalle</div>
            <div id="detalleSub" class="text-sm font-bold text-slate-600">—</div>

            <div id="detalleMeta" class="mt-3 flex flex-wrap gap-2">
              <!-- JS llena chips -->
            </div>
          </div>

          <div class="shrink-0 flex items-center gap-2">
            <button id="detalleCerrar" type="button"
              class="h-10 px-4 rounded-2xl bg-white border border-slate-200 text-slate-900 font-extrabold text-sm shadow-sm hover:bg-slate-100 transition">
              Cerrar
            </button>
          </div>
        </div>

        <!-- Chips pedidos tocados -->
        <div class="px-4 sm:px-5 py-3 border-b border-slate-200 bg-slate-50">
          <div class="flex items-center justify-between gap-3">
            <div class="text-sm font-extrabold text-slate-900">Pedidos tocados</div>
            <div class="text-xs font-bold text-slate-600">Tip: puedes scroll horizontal si hay muchos.</div>
          </div>

          <div id="pedidosChips" class="mt-3 flex gap-2 overflow-x-auto pb-2"></div>
        </div>

        <!-- Body -->
        <div class="p-4 sm:p-5 flex-1 overflow-hidden flex flex-col gap-3">
          <div id="detalleLoading" class="hidden text-sm font-extrabold text-slate-600">Cargando detalle…</div>
          <div id="detalleError" class="hidden rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-extrabold text-red-700"></div>

          <!-- Tabla -->
          <div class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden flex-1 min-h-0">
            <div class="hidden sm:grid grid-cols-12 px-4 py-3 bg-slate-50 border-b border-slate-200 text-[12px] font-extrabold uppercase tracking-wide text-slate-600">
              <div class="col-span-3">Fecha</div>
              <div class="col-span-2">Entidad</div>
              <div class="col-span-3">Pedido</div>
              <div class="col-span-2">Antes</div>
              <div class="col-span-2">Después</div>
            </div>

            <div id="detalleBodyTable" class="hidden sm:block overflow-auto max-h-[46vh]"></div>
            <div id="detalleBodyCards" class="sm:hidden overflow-auto max-h-[46vh]"></div>
          </div>

          <!-- Paginación -->
          <div class="flex items-center justify-between gap-2">
            <div id="detallePaginacionInfo" class="text-xs font-extrabold text-slate-600">—</div>
            <div class="flex items-center gap-2">
              <button id="detallePrev" type="button"
                class="h-10 px-4 rounded-2xl bg-white border border-slate-200 text-slate-900 font-extrabold text-sm shadow-sm hover:bg-slate-100 transition">
                Anterior
              </button>
              <button id="detalleNext" type="button"
                class="h-10 px-4 rounded-2xl bg-slate-900 text-white font-extrabold text-sm shadow-sm hover:bg-slate-800 transition">
                Siguiente
              </button>
            </div>
          </div>

        </div>
      </div>
    </div>
  </div>

  <!-- JS -->
  <script src="<?= base_url('js/seguimiento.js') ?>"></script>
</body>
</html>
