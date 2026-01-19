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

    /* Layout con menú */
    .layout { padding-left: 16rem; transition: padding-left .2s ease; }
    .layout.menu-collapsed { padding-left: 5.25rem; }
    @media (max-width: 768px) {
      .layout, .layout.menu-collapsed { padding-left: 0 !important; }
    }

    /* Grilla EXACTA dashboard */
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
          <!-- HEADER TABLA -->
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

          <!-- FILAS -->
          <div id="tablaPedidos" class="divide-y"></div>
        </div>
      </section>

    </div>
  </div>
</main>

<!-- =========================
     MODAL DETALLES PEDIDO
========================= -->
<div id="modalDetalles" class="hidden fixed inset-0 z-50 bg-black/40 backdrop-blur-sm flex items-start justify-center p-4 overflow-y-auto">
  <div class="w-full max-w-4xl bg-white rounded-3xl shadow-xl border border-slate-200 mt-10 animate-fadeIn">

    <!-- Header -->
    <div class="p-5 border-b border-slate-200 flex justify-between items-center">
      <h2 id="detTitulo" class="text-xl font-extrabold text-slate-900">Pedido</h2>
      <button onclick="cerrarModalDetalles()"
        class="h-10 w-10 rounded-2xl border border-slate-200 text-slate-600 hover:text-slate-900 font-extrabold">
        ×
      </button>
    </div>

    <!-- Content -->
    <div class="p-6 space-y-6">
      <section>
        <h3 class="font-extrabold text-slate-900 mb-3">Productos</h3>
        <div id="detProductos" class="space-y-3 text-slate-600">
          —
        </div>
      </section>

      <section>
        <h3 class="font-extrabold text-slate-900 mb-3">Resumen</h3>
        <div id="detResumen" class="space-y-1 text-slate-700">
          —
        </div>
      </section>
    </div>

  </div>
</div>

<!-- LOADER GLOBAL -->
<div id="globalLoader" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-[100]">
  <div class="bg-white p-6 rounded-3xl shadow-xl text-center">
    <div class="w-10 h-10 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto"></div>
    <p class="mt-3 font-semibold">Cargando…</p>
  </div>
</div>

<!-- API ENDPOINTS -->
<script>
window.API = {
  myQueue: "<?= site_url('confirmacion/my-queue') ?>",
  pull: "<?= site_url('confirmacion/pull') ?>",
  returnAll: "<?= site_url('confirmacion/return-all') ?>",
  detalles: "<?= site_url('confirmacion/detalles') ?>"
};
</script>

<!-- JS CONFIRMACIÓN -->
<script src="<?= base_url('js/confirmacion.js?v=' . time()) ?>"></script>

<!-- MENU COLLAPSE -->
<script>
(function () {
  const main = document.getElementById('mainLayout');
  const collapsed = localStorage.getItem('menuCollapsed') === '1';
  if (collapsed) main.classList.add('menu-collapsed');
})();
</script>

</body>
</html>
