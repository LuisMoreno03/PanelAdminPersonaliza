<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <meta name="csrf-token" content="<?= csrf_hash() ?>">
  <meta name="csrf-header" content="<?= csrf_header() ?>">

  <title>Por producir - Panel</title>

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

    .prod-grid-cols {
      grid-template-columns:
        110px 115px minmax(100px, 1fr) 95px 185px 145px 180px 180px 120px 110px;
    }

    .layout { transition: padding-left .2s ease; padding-left: 16rem; }
    .layout.menu-collapsed { padding-left: 5.25rem; }
    @media (max-width: 768px) { .layout, .layout.menu-collapsed { padding-left: 0 !important; } }
  </style>
</head>

<body class="min-h-screen bg-gradient-to-b from-slate-50 to-slate-100 overflow-x-hidden">

  <?= view('layouts/menu') ?>

  <main id="mainLayout" class="layout">
    <div class="p-4 sm:p-6 lg:p-8">
      <div class="mx-auto w-full max-w-[1600px]">

        <section class="mb-6">
          <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5 flex items-start justify-between gap-4">
            <div>
              <h1 class="text-3xl font-extrabold text-slate-900">Por producir</h1>
              <p class="text-slate-500 mt-1">Cola por usuario · Traer pedidos en lotes de 50/100</p>
            </div>

            <div class="hidden sm:flex items-center gap-2">
              <span class="px-4 py-2 rounded-2xl bg-white border border-slate-200 font-extrabold text-sm">
                Pedidos: <span id="total-pedidos-top">0</span>
              </span>
            </div>
          </div>
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">

          <div class="px-4 py-3 border-b border-slate-200 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
            <div class="flex items-center justify-between gap-3">
              <div class="font-semibold text-slate-900">Listado de pedidos</div>
              <div class="text-xs text-slate-500 hidden sm:block">
                Solo tu cola · se eliminan automáticamente cuando el pedido pasa a “Enviado”
              </div>
            </div>

            <div class="flex flex-col sm:flex-row sm:items-center gap-2">
              <div class="relative">
                <input
                  id="inputBuscar"
                  type="text"
                  placeholder="Buscar pedido, cliente, etiqueta..."
                  class="h-11 w-[320px] max-w-full pl-10 pr-3 rounded-2xl border border-slate-200 bg-slate-50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-200"
                />
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">🔎</span>
              </div>

              <button id="btnLimpiarBusqueda"
                class="h-11 px-4 rounded-2xl bg-slate-200 text-slate-800 font-extrabold hover:bg-slate-300 transition">
                Limpiar
              </button>

              <div class="hidden sm:block w-px h-11 bg-slate-200 mx-1"></div>

              <button id="btnTraer50"
                class="h-11 px-4 rounded-2xl bg-slate-900 text-white font-extrabold hover:bg-slate-800 transition">
                Traer 50 pedidos
              </button>

              <button id="btnTraer100"
                class="h-11 px-4 rounded-2xl bg-slate-900 text-white font-extrabold hover:bg-slate-800 transition">
                Traer 100 pedidos
              </button>

              <button id="btnDevolver"
                class="h-11 px-4 rounded-2xl bg-white border border-rose-200 text-rose-700 font-extrabold hover:bg-rose-50 transition">
                Devolver pedidos restantes
              </button>
            </div>
          </div>

          <div class="w-full">
            <div class="hidden 2xl:block w-full overflow-x-auto soft-scroll">
              <div class="bg-slate-50 border-b border-slate-200 min-w-[1500px]">
                <div class="grid prod-grid-cols space-x-4 items-center gap-3 px-4 py-3
                            text-[11px] uppercase tracking-wider text-slate-600 font-extrabold">
                  <div>Pedido</div>
                  <div>Fecha</div>
                  <div>Cliente</div>
                  <div class="text-right">Total</div>
                  <div>Estado</div>
                  <div>Último cambio</div>
                  <div class="text-center">Artículos</div>
                  <div>Entrega</div>
                  <div>Método</div>
                  <div class="text-right">Detalles</div>
                </div>
              </div>
              <div id="tablaPedidos" class="min-w-[1500px]"></div>
            </div>

            <div class="hidden xl:block 2xl:hidden w-full overflow-x-auto soft-scroll">
              <table class="min-w-[1500px] w-full text-sm">
                <thead class="bg-slate-50 sticky space-x-4 top-0 z-10 border-b border-slate-200">
                  <tr class="text-left text-[11px] uppercase tracking-wider text-slate-600 font-extrabold">
                    <th class="px-5 py-4">Pedido</th>
                    <th class="px-5 py-4">Fecha</th>
                    <th class="px-5 py-4">Cliente</th>
                    <th class="px-5 py-4">Total</th>
                    <th class="px-5 py-4">Estado</th>
                    <th class="px-5 py-4">Último cambio</th>
                    <th class="px-5 py-4 text-center">Artículos</th>
                    <th class="px-5 py-4">Entrega</th>
                    <th class="px-5 py-4">Método</th>
                    <th class="px-5 py-4 text-right">Detalles</th>
                  </tr>
                </thead>

                <tbody id="tablaPedidosTable" class="divide-y divide-slate-100 text-slate-800"></tbody>
              </table>
            </div>

            <div id="cardsPedidos" class="block xl:hidden p-3"></div>
          </div>

          <div class="px-4 py-3 border-t border-slate-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div class="text-xs text-slate-500">
              Consejo: en desktop verás grid/tabla; en móvil verás tarjetas.
            </div>

            <div class="flex items-center gap-2">
              <span class="px-4 py-2 rounded-2xl bg-white border border-slate-200 font-extrabold text-sm">
                Total: <span id="total-pedidos-bottom">0</span>
              </span>
            </div>
          </div>

        </section>

      </div>
    </div>
  </main>

  <!-- LOADER -->
  <div id="globalLoader" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-[9999]">
    <div class="bg-white p-6 rounded-3xl shadow-xl text-center animate-fadeIn border border-slate-200">
      <div class="w-10 h-10 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto"></div>
      <p class="mt-3 font-semibold text-slate-800">Cargando...</p>
    </div>
  </div>

  <script>
    window.etiquetasPredeterminadas = <?= json_encode($etiquetasPredeterminadas ?? []) ?>;
    window.CURRENT_USER = <?= json_encode(session()->get('nombre') ?? 'Sistema') ?>;
    window.currentUserRole = <?= json_encode(session()->get('role') ?? '') ?>;
    window.API_BASE = "<?= rtrim(site_url(), '/') ?>";
  </script>

  <script src="<?= base_url('js/por_producir.js?v=' . time()) ?>" defer></script>

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
