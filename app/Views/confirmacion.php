<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <meta name="csrf-token" content="<?= csrf_hash() ?>">
  <meta name="csrf-header" content="<?= csrf_header() ?>">

  <title>Confirmación - Panel</title>

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
    @media (max-width: 768px) {
      .layout, .layout.menu-collapsed { padding-left: 0 !important; }
    }
  </style>
</head>

<body class="min-h-screen bg-gradient-to-b from-slate-50 to-slate-100 overflow-x-hidden">

  <!-- MENÚ LATERAL -->
  <?= view('layouts/menu') ?>

  <!-- CONTENIDO PRINCIPAL -->
  <main id="mainLayout" class="layout">
    <div class="p-4 sm:p-6 lg:p-8">
      <div class="mx-auto w-full max-w-[1600px]">

        <!-- HEADER -->
        <section class="mb-6">
          <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5 flex items-start justify-between gap-4">
            <div>
              <h1 class="text-3xl font-extrabold text-slate-900">Confirmación</h1>
              <p class="text-slate-500 mt-1">
                Cola por usuario · Pedidos en <b>Por preparar</b>
              </p>
            </div>

            <div class="hidden sm:flex items-center gap-2">
              <span class="px-4 py-2 rounded-2xl bg-white border border-slate-200 font-extrabold text-sm">
                Pedidos: <span id="total-pedidos">0</span>
              </span>
            </div>
          </div>
        </section>

        <!-- LISTADO -->
        <section class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">

          <!-- TOOLBAR -->
          <div class="px-4 py-3 border-b border-slate-200 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
            <div class="flex items-center justify-between gap-3">
              <div class="font-semibold text-slate-900">Listado de pedidos</div>
              <div class="text-xs text-slate-500 hidden sm:block">
                Solo pedidos en estado <b>Por preparar</b>
              </div>
            </div>

            <div class="flex flex-col sm:flex-row sm:items-center gap-2">
              <div class="relative">
                <input
                  id="inputBuscar"
                  type="text"
                  placeholder="Buscar pedido, cliente..."
                  class="h-11 w-[320px] max-w-full pl-10 pr-3 rounded-2xl border border-slate-200 bg-slate-50
                         focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-200"
                />
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">🔎</span>
              </div>

              <button id="btnLimpiarBusqueda"
                class="h-11 px-4 rounded-2xl bg-slate-200 text-slate-800 font-extrabold hover:bg-slate-300 transition">
                Limpiar
              </button>

              <div class="hidden sm:block w-px h-11 bg-slate-200 mx-1"></div>

              <button id="btnTraer5"
                class="h-11 px-4 rounded-2xl bg-slate-900 text-white font-extrabold hover:bg-slate-800 transition">
                Traer 5
              </button>

              <button id="btnTraer10"
                class="h-11 px-4 rounded-2xl bg-slate-900 text-white font-extrabold hover:bg-slate-800 transition">
                Traer 10
              </button>

              <button id="btnDevolver"
                class="h-11 px-4 rounded-2xl bg-white border border-rose-200 text-rose-700
                       font-extrabold hover:bg-rose-50 transition">
                Devolver
              </button>
            </div>
          </div>

          <!-- CONTENEDORES RESPONSIVE -->
          <div class="w-full">
            <div class="hidden 2xl:block w-full overflow-x-auto soft-scroll">
              <div id="tablaPedidos" class="min-w-[1500px]"></div>
            </div>

            <div class="hidden xl:block 2xl:hidden w-full overflow-x-auto soft-scroll">
              <table class="min-w-[1500px] w-full text-sm">
                <tbody id="tablaPedidosTable"
                  class="divide-y divide-slate-100 text-slate-800"></tbody>
              </table>
            </div>

            <div id="cardsPedidos" class="block xl:hidden p-3"></div>
          </div>

          <!-- FOOTER -->
          <div class="px-4 py-3 border-t border-slate-200 flex items-center justify-between">
            <div class="text-xs text-slate-500">
              Confirmación · pedidos pendientes de validar
            </div>
            <span class="px-4 py-2 rounded-2xl bg-white border border-slate-200 font-extrabold text-sm">
              Total: <span id="total-pedidos">0</span>
            </span>
          </div>

        </section>

      </div>
    </div>
  </main>

  <!-- LOADER GLOBAL -->
  <div id="globalLoader"
       class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-[9999]">
    <div class="bg-white p-6 rounded-3xl shadow-xl text-center animate-fadeIn border border-slate-200">
      <div class="w-10 h-10 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto"></div>
      <p class="mt-3 font-semibold text-slate-800">Cargando...</p>
    </div>
  </div>

  <!-- VARIABLES GLOBALES -->
  <script>
    window.etiquetasPredeterminadas = <?= json_encode($etiquetasPredeterminadas ?? []) ?>;
    window.CURRENT_USER = <?= json_encode(session()->get('nombre') ?? 'Sistema') ?>;
    window.API_BASE = "<?= rtrim(site_url(), '/') ?>";
  </script>

  <!-- JS CONFIRMACIÓN -->
  <script src="<?= base_url('js/confirmacion.js?v=' . time()) ?>" defer></script>

  <!-- CONTROL MENÚ -->
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
