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

  <!-- ESTILOS (idénticos al dashboard) -->
  <style>
    body { background: #f3f4f6; }

    .layout { transition: padding-left .2s ease; padding-left: 16rem; }
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
        110px
        92px
        minmax(170px, 1.2fr)
        90px
        160px
        minmax(140px, 0.9fr)
        minmax(170px, 1fr)
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
        <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5 flex justify-between">
          <div>
            <h1 class="text-3xl font-extrabold text-slate-900">Confirmación</h1>
            <p class="text-slate-500 mt-1">
              Pedidos en estado <b>Por preparar</b>
            </p>
          </div>
          <span class="px-4 py-2 rounded-2xl bg-white border font-extrabold text-sm">
            Pedidos: <span id="total-pedidos">0</span>
          </span>
        </div>
      </section>

      <!-- LISTADO -->
      <section class="rounded-3xl border bg-white shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b flex justify-between items-center">
          <div class="font-semibold">Listado de pedidos</div>

          <div class="flex gap-2">
            <button id="btnTraer5" class="px-4 py-2 rounded-xl bg-slate-900 text-white font-bold">
              Traer 5
            </button>
            <button id="btnTraer10" class="px-4 py-2 rounded-xl bg-slate-900 text-white font-bold">
              Traer 10
            </button>
            <button id="btnDevolver" class="px-4 py-2 rounded-xl bg-rose-600 text-white font-bold">
              Devolver
            </button>
          </div>
        </div>

        <div class="table-scroll">
          <!-- HEADER -->
          <div class="orders-grid cols px-4 py-3 text-[11px] uppercase bg-slate-50 border-b">
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

          <!-- ROWS -->
          <div id="tablaPedidos" class="divide-y"></div>
        </div>
      </section>

    </div>
  </div>
</main>

<!-- MODALES (solo reutilización visual) -->
<?= view('layouts/modal_detalles') ?>
<?= view('layouts/modales_estados') ?>

<!-- LOADER -->
<div id="globalLoader" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-50">
  <div class="bg-white p-6 rounded-3xl shadow-xl text-center">
    <div class="w-10 h-10 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto"></div>
    <p class="mt-3 font-semibold">Cargando...</p>
  </div>
</div>

<!-- VARIABLES -->
<script>
  window.CURRENT_USER = <?= json_encode(session()->get('nombre') ?? 'Sistema') ?>;
  window.API = {
    myQueue: "<?= site_url('confirmacion/my-queue') ?>",
    pull: "<?= site_url('confirmacion/pull') ?>",
    returnAll: "<?= site_url('confirmacion/return-all') ?>"
  };
</script>

<!-- JS (ORDEN CORRECTO) -->
<script src="<?= base_url('js/dashboard.js?v=' . time()) ?>"></script>
<script src="<?= base_url('js/confirmacion.js?v=' . time()) ?>"></script>

<script>
(function () {
  const main = document.getElementById('mainLayout');
  const collapsed = localStorage.getItem('menuCollapsed') === '1';
  main.classList.toggle('menu-collapsed', collapsed);
})();
</script>

</body>
</html>
