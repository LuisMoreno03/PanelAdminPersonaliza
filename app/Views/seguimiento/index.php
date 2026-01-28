<?php ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- CSRF para CodeIgniter -->
  <meta name="csrf-token" content="<?= csrf_hash() ?>">
  <meta name="csrf-header" content="<?= function_exists('csrf_header') ? csrf_header() : 'X-CSRF-TOKEN' ?>">

  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/alpinejs" defer></script>

  <title>Seguimiento</title>

  <style>
    .seg-grid-cols{
      display:grid;
      grid-template-columns:
        260px minmax(220px, 1fr) 170px 210px 160px;
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
                Resumen de <b>cambios de estados internos</b> realizados por cada usuario.
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
        </div>
      </div>

      <!-- Buscador + filtros + acciones -->
      <div class="flex flex-col lg:flex-row lg:items-center gap-3">
        <div class="flex-1 flex items-center gap-2">
          <div class="relative flex-1">
            <input id="inputBuscar" type="text" placeholder="Buscar por usuario o email…"
              class="w-full h-11 rounded-2xl border border-slate-200 bg-white px-4 pr-10 text-sm font-semibold outline-none
                     focus:ring-4 focus:ring-slate-200 focus:border-slate-300">
            <div class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400">⌕</div>
          </div>

          <button id="btnLimpiarBusqueda" type="button"
            class="h-11 px-4 rounded-2xl bg-white border border-slate-200 shadow-sm text-slate-900 font-extrabold text-sm
                   hover:bg-slate-100 transition">
            Limpiar
          </button>
        </div>

        <div class="flex items-center gap-2 justify-end flex-wrap">
          <div class="flex items-center gap-2">
            <input id="from" type="date"
              class="h-11 rounded-2xl border border-slate-200 bg-white px-3 text-sm font-semibold outline-none
                     focus:ring-4 focus:ring-slate-200 focus:border-slate-300">
            <input id="to" type="date"
              class="h-11 rounded-2xl border border-slate-200 bg-white px-3 text-sm font-semibold outline-none
                     focus:ring-4 focus:ring-slate-200 focus:border-slate-300">
          </div>

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

      <!-- Mensaje error -->
      <div id="errorBox" class="hidden rounded-3xl border border-rose-200 bg-rose-50 px-4 py-3 text-rose-700 font-bold text-sm"></div>
    </div>

    <!-- Listado -->
    <section class="mt-6">

      <!-- Vista XL (grid tipo tabla) -->
      <div class="hidden xl:block 2xl:hidden rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="seg-grid-cols px-4 py-3 bg-slate-50 border-b border-slate-200 text-[12px] font-extrabold uppercase tracking-wide text-slate-600">
          <div>Usuario</div>
          <div>Email</div>
          <div class="text-right">Total cambios</div>
          <div>Último cambio</div>
          <div class="text-right">Acciones</div>
        </div>

        <!-- Aquí el JS pinta filas (DIVs) -->
        <div id="tablaSeguimientoTable"></div>
      </div>

      <!-- Vista 2XL (grid tipo tabla) -->
      <div class="hidden 2xl:block rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="seg-grid-cols px-4 py-3 bg-slate-50 border-b border-slate-200 text-[12px] font-extrabold uppercase tracking-wide text-slate-600">
          <div>Usuario</div>
          <div>Email</div>
          <div class="text-right">Total cambios</div>
          <div>Último cambio</div>
          <div class="text-right">Acciones</div>
        </div>

        <!-- Aquí el JS pinta filas (DIVs) -->
        <div id="tablaSeguimiento"></div>
      </div>

      <!-- Mobile cards -->
      <div id="cardsSeguimiento" class="xl:hidden"></div>
    </section>
  </main>

  <!-- Tu JS -->
  <script src="<?= base_url('js/seguimiento.js') ?>"></script>
</body>
</html>
