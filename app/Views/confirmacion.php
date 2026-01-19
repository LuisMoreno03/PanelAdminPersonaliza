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
  <script src="https://unpkg.com/alpinejs" defer></script>

  <style>
    .soft-scroll::-webkit-scrollbar { height: 10px; width: 10px; }
    .soft-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
    .soft-scroll::-webkit-scrollbar-track { background: #eef2ff; border-radius: 999px; }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(6px) scale(0.99); }
      to   { opacity: 1; transform: translateY(0) scale(1); }
    }
    .animate-fadeIn { animation: fadeIn .18s ease-out; }

    /* Layout menú */
    .layout { transition: padding-left .2s ease; padding-left: 16rem; }
    .layout.menu-collapsed { padding-left: 5.25rem; }
    @media (max-width: 768px) { .layout, .layout.menu-collapsed { padding-left: 0 !important; } }
  </style>
</head>

<body class="min-h-screen bg-gradient-to-b from-slate-50 to-slate-100 overflow-x-hidden">

<?= view('layouts/menu') ?>

<main id="mainLayout" class="layout">
  <div class="p-4 sm:p-6 lg:p-8">
    <div class="mx-auto w-full max-w-[1600px] space-y-6">

      <!-- ================= HEADER ================= -->
      <section>
        <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h1 class="text-3xl font-extrabold text-slate-900">Confirmación</h1>
            <p class="text-slate-500 mt-1 text-sm">
              Pedidos asignados en estado <b>Por preparar</b>
            </p>
          </div>

          <span class="px-4 py-2 rounded-2xl bg-slate-50 border border-slate-200 font-extrabold text-sm">
            Pedidos: <span id="total-pedidos">0</span>
          </span>
        </div>
      </section>

      <!-- ================= LISTADO ================= -->
      <section class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">

        <!-- Acciones -->
        <div class="px-4 py-4 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
          <div class="font-extrabold text-slate-900">
            Listado de pedidos
          </div>

          <div class="flex flex-wrap gap-2">
            <button id="btnTraer5"
              class="px-4 py-2 rounded-xl bg-slate-900 text-white font-extrabold hover:bg-slate-800 transition">
              Traer 5
            </button>

            <button id="btnTraer10"
              class="px-4 py-2 rounded-xl bg-slate-900 text-white font-extrabold hover:bg-slate-800 transition">
              Traer 10
            </button>

            <button id="btnDevolver"
              class="px-4 py-2 rounded-xl bg-rose-600 text-white font-extrabold hover:bg-rose-700 transition">
              Devolver
            </button>
          </div>
        </div>

        <!-- Tabla -->
        <div class="table-scroll">
          <!-- Header -->
          <div class="orders-grid cols px-4 py-3 text-[11px] uppercase tracking-wider text-slate-600 border-b table-header">
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

          <!-- Body -->
          <div id="tablaPedidos" class="divide-y"></div>
        </div>
      </section>

    </div>
  </div>
</main>

<!-- ================= MODALES ================= -->
<?= view('layouts/modal_detalles') ?>
<?= view('layouts/modales_estados') ?>

<!-- ================= LOADER ================= -->
<div id="globalLoader" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-[100]">
  <div class="bg-white p-6 rounded-3xl shadow-xl text-center w-[200px]">
    <div class="w-10 h-10 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto"></div>
    <p class="mt-4 font-extrabold text-slate-900">Cargando…</p>
  </div>
</div>

<!-- ================= API ================= -->
<script>
window.API = {
  myQueue: "<?= site_url('confirmacion/my-queue') ?>",
  pull: "<?= site_url('confirmacion/pull') ?>",
  returnAll: "<?= site_url('confirmacion/return-all') ?>",
  detalles: "<?= site_url('dashboard/detalles') ?>"
};
</script>

<!-- ================= JS ================= -->
<script src="<?= base_url('js/confirmacion.js?v=' . time()) ?>"></script>

<script>
    (function () {
      const main = document.getElementById('mainLayout');
      if (!main) return;

      const collapsed = localStorage.getItem('menuCollapsed') === '1';
      main.classList.toggle('menu-collapsed', collapsed);

      window.setMenuCollapsed = function (v) {
        main.classList.toggle('menu-collapsed', !!v);
        localStorage.setItem('menuCollapsed', v ? '1' : '0');
        window.dispatchEvent(new Event('resize'));
      };
    })();
  </script>



</body>
</html>
