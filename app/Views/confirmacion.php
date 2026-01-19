<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <meta name="csrf-token" content="<?= csrf_hash() ?>">
  <meta name="csrf-header" content="<?= csrf_header() ?>">

  <title>Confirmación - Panel</title>

  <!-- Tailwind -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/alpinejs" defer></script>

  <style>
    .soft-scroll::-webkit-scrollbar { height: 10px; width: 10px; }
    .soft-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
    .soft-scroll::-webkit-scrollbar-track { background: #eef2ff; border-radius: 999px; }

    .layout { padding-left: 16rem; transition: padding-left .2s ease; }
    .layout.menu-collapsed { padding-left: 5.25rem; }
    @media (max-width: 768px) {
      .layout, .layout.menu-collapsed { padding-left: 0 !important; }
    }

    .orders-grid {
      display: grid;
      grid-template-columns:
        120px
        100px
        minmax(200px, 1fr)
        100px
        180px
        120px;
      gap: .75rem;
      align-items: center;
    }
  </style>
</head>

<body class="min-h-screen bg-gradient-to-b from-slate-50 to-slate-100 overflow-x-hidden">

  <!-- MENU -->
  <?= view('layouts/menu') ?>

  <!-- CONTENIDO -->
  <main id="mainLayout" class="layout">
    <div class="p-4 sm:p-6 lg:p-8">
      <div class="mx-auto w-full max-w-[1600px]">

        <!-- HEADER -->
        <section class="mb-6">
          <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5 flex items-start justify-between gap-4">
            <div>
              <h1 class="text-3xl font-extrabold text-slate-900">Confirmación</h1>
              <p class="text-slate-500 mt-1">
                Pedidos en estado <b>Por preparar</b>. Express primero.
              </p>
            </div>

            <div class="flex items-center gap-2">
              <select id="limitSelect"
                class="h-11 px-4 rounded-2xl border border-slate-200 bg-white font-extrabold text-sm">
                <option value="5">5 pedidos</option>
                <option value="10" selected>10 pedidos</option>
              </select>

              <button id="btnPull"
                class="h-11 px-4 rounded-2xl bg-slate-900 text-white font-extrabold hover:bg-slate-800 transition">
                Pull pedidos
              </button>
            </div>
          </div>
        </section>

        <!-- LISTADO -->
        <section class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">

          <!-- Header tabla -->
          <div class="px-4 py-3 border-b border-slate-200 bg-slate-50">
            <div class="orders-grid text-[11px] uppercase tracking-wider font-extrabold text-slate-600">
              <div>Pedido</div>
              <div>Fecha</div>
              <div>Cliente</div>
              <div>Total</div>
              <div>Estado</div>
              <div class="text-right">Detalles</div>
            </div>
          </div>

          <!-- Rows -->
          <div id="confirmacionList" class="divide-y"></div>

          <!-- Empty -->
          <div id="confirmacionEmpty"
               class="hidden p-8 text-center text-slate-500 font-semibold">
            No hay pedidos en confirmación 🎉
          </div>
        </section>

      </div>
    </div>
  </main>

  <!-- MODAL DETALLES (MISMO DEL DASHBOARD) -->
  <?= view('layouts/modal_detalles') ?>

  <!-- LOADER -->
  <div id="globalLoader"
       class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-[9999]">
    <div class="bg-white p-6 rounded-3xl shadow-xl text-center">
      <div class="w-10 h-10 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto"></div>
      <p class="mt-3 font-semibold text-slate-800">Cargando...</p>
    </div>
  </div>

  <!-- VARIABLES -->
  <script>
    window.API_CONFIRMACION = {
      myQueue: "<?= site_url('confirmacion/my-queue') ?>",
      pull: "<?= site_url('confirmacion/pull') ?>",
    };
  </script>

  <!-- JS -->
  <script src="<?= base_url('js/confirmacion.js?v=' . time()) ?>"></script>

  <!-- Colapso menú -->
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
