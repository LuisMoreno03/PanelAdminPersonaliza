<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= csrf_hash() ?>">
  <meta name="csrf-header" content="<?= csrf_header() ?>">

  <title>Confirmación - Panel</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/alpinejs" defer></script>

  <!-- ⚠️ USAMOS LOS MISMOS ESTILOS DEL DASHBOARD -->
  <?= view('layouts/dashboard_styles') ?>
</head>

<body class="min-h-screen bg-gradient-to-b from-slate-50 to-slate-100 overflow-x-hidden">

  <!-- MENU -->
  <?= view('layouts/menu') ?>

  <main id="mainLayout" class="layout">
    <div class="p-4 sm:p-6 lg:p-8">
      <div class="mx-auto w-full max-w-[1600px]">

        <!-- HEADER -->
        <section class="mb-6">
          <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5">
            <h1 class="text-3xl font-extrabold text-slate-900">Confirmación</h1>
            <p class="text-slate-500 mt-1">
              Cola por usuario · Pedidos en <b>Por preparar</b>
            </p>
          </div>
        </section>

        <!-- ACCIONES -->
        <section class="mb-4 flex flex-wrap gap-2">
          <input id="inputBuscar"
                 class="px-4 py-3 rounded-2xl border border-slate-200 bg-white"
                 placeholder="Buscar pedido, cliente…">

          <button id="btnLimpiarBusqueda"
                  class="px-4 py-3 rounded-2xl bg-slate-200 font-bold">
            Limpiar
          </button>

          <button id="btnTraer5"
                  class="px-4 py-3 rounded-2xl bg-blue-600 text-white font-bold">
            Traer 5
          </button>

          <button id="btnTraer10"
                  class="px-4 py-3 rounded-2xl bg-blue-600 text-white font-bold">
            Traer 10
          </button>

          <button id="btnDevolver"
                  class="px-4 py-3 rounded-2xl border border-rose-300 text-rose-700 font-bold">
            Devolver
          </button>
        </section>

        <!-- TABLA (MISMO GRID QUE DASHBOARD) -->
        <section class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
          <div class="px-4 py-3 border-b border-slate-200 flex justify-between">
            <span class="font-semibold text-slate-900">Listado de pedidos</span>
            <span class="text-xs text-slate-500">Solo Por preparar</span>
          </div>

          <div class="table-wrap table-scroll">

            <!-- HEADER -->
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
              <div>Método de entrega</div>
              <div class="text-right">Ver</div>
            </div>

            <!-- ROWS -->
            <div id="tablaPedidos" class="divide-y"></div>

          </div>

          <!-- MOBILE -->
          <div id="cardsPedidos" class="mobile-orders p-4"></div>
        </section>

        <div class="mt-2 text-right text-sm text-slate-500">
          Total: <span id="total-pedidos">0</span>
        </div>

      </div>
    </div>
  </main>

  <!-- MODALES REUTILIZADOS -->
  <?= view('layouts/modal_detalles') ?>
  <?= view('layouts/modales_estados') ?>

  <!-- LOADER -->
  <div id="globalLoader" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-50">
    <div class="bg-white p-6 rounded-3xl shadow-xl text-center">
      <div class="w-10 h-10 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto"></div>
      <p class="mt-3 font-semibold">Cargando...</p>
    </div>
  </div>

  <!-- VARIABLES GLOBALES -->
  <script>
    window.API = {
      myQueue: "<?= site_url('confirmacion/my-queue') ?>",
      pull: "<?= site_url('confirmacion/pull') ?>",
      returnAll: "<?= site_url('confirmacion/return-all') ?>"
    };
  </script>

  <!-- JS -->
  <script src="<?= base_url('js/dashboard.js?v=' . time()) ?>"></script>
  <script src="<?= base_url('js/confirmacion.js?v=' . time()) ?>"></script>

</body>
</html>
