<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />

  <meta name="csrf-token" content="<?= csrf_hash() ?>">
  <meta name="csrf-header" content="<?= csrf_header() ?>">

  <title>Confirmación - Panel</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/alpinejs" defer></script>

  <style>
    body { background: #f3f4f6; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(6px) scale(0.99); } to { opacity: 1; transform: translateY(0) scale(1); } }
    .animate-fadeIn { animation: fadeIn .18s ease-out; }

    .layout { transition: padding-left .2s ease; padding-left: 16rem; }
    .layout.menu-collapsed { padding-left: 5.25rem; }
    @media (max-width: 768px) { .layout, .layout.menu-collapsed { padding-left: 0 !important; } }

    .orders-grid { display:grid; align-items:center; gap:.65rem; width:100%; }
    .orders-grid.cols { grid-template-columns: 110px 92px minmax(170px, 1.2fr) 90px 160px 130px; }
    .orders-grid > div { min-width:0; }
  </style>
</head>

<body class="min-h-screen bg-gradient-to-b from-slate-50 to-slate-100 overflow-x-hidden">

  <?= view('layouts/menu') ?>

  <main id="mainLayout" class="layout">
    <div class="p-4 sm:p-6 lg:p-8">
      <div class="mx-auto w-full max-w-[1200px]">

        <!-- HEADER -->
        <section class="mb-6">
          <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5 flex items-start justify-between gap-4">
            <div>
              <h1 class="text-3xl font-extrabold text-slate-900">Confirmación</h1>
              <p class="text-slate-500 mt-1">Trae pedidos en estado <b>Por preparar</b> y revísalos.</p>
            </div>

            <div class="flex items-center gap-2">
              <select id="limitSelect"
                class="px-4 py-2 rounded-2xl border border-slate-200 bg-white font-extrabold text-sm">
                <option value="5">5 pedidos</option>
                <option value="10" selected>10 pedidos</option>
              </select>

              <button id="btnPull"
                class="px-5 py-3 rounded-2xl bg-slate-900 text-white font-extrabold hover:bg-slate-800 transition">
                Pull 1 pedido
              </button>
            </div>
          </div>
        </section>

        <!-- LISTADO -->
        <section class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
          <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between">
            <div class="font-semibold text-slate-900">Mi cola (Por preparar)</div>
            <div class="text-xs text-slate-500">Express primero</div>
          </div>

          <div class="px-4 py-3 text-[11px] uppercase tracking-wider text-slate-600 bg-slate-50 border-b">
            <div class="orders-grid cols">
              <div>Pedido</div>
              <div>Fecha</div>
              <div>Cliente</div>
              <div>Total</div>
              <div>Estado</div>
              <div class="text-right">Ver</div>
            </div>
          </div>

          <div id="confirmacionList" class="divide-y"></div>

          <div id="confirmacionEmpty" class="hidden p-8 text-center text-slate-500">
            No hay pedidos en cola.
          </div>
        </section>

      </div>
    </div>
  </main>

  <!-- ✅ Reutilizas EXACTO el modal de detalles del dashboard -->
  <?= view('layouts/modal_detalles') ?>

  <!-- Loader -->
  <div id="globalLoader" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-50">
    <div class="bg-white p-6 rounded-3xl shadow-xl text-center animate-fadeIn">
      <div class="w-10 h-10 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto"></div>
      <p class="mt-3 font-semibold">Cargando...</p>
    </div>
  </div>

  <script>
    window.CURRENT_USER = <?= json_encode(session()->get('nombre') ?? 'Sistema') ?>;

    window.API_CONFIRMACION = {
      myQueue: "<?= site_url('confirmacion/my-queue') ?>",
      pull: "<?= site_url('confirmacion/pull') ?>",

      // ✅ usamos el mismo endpoint de detalles que dashboard (así el modal es idéntico)
      detallesBase: "<?= site_url('dashboard/detalles') ?>",
    };
  </script>

  <!-- ✅ Solo necesitamos verDetalles del dashboard.js (para no duplicarlo) -->
  <script src="<?= base_url('js/dashboard.js?v=' . time()) ?>"></script>

  <!-- ✅ JS propio del panel confirmación -->
  <script src="<?= base_url('js/confirmacion.js?v=' . time()) ?>"></script>

  <!-- colapso menú -->
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
