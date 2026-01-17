<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- (Opcional) CSRF -->
  <meta name="csrf-token" content="<?= csrf_hash() ?>">
  <meta name="csrf-header" content="<?= csrf_header() ?>">

  <title>Producción - Panel</title>

  <!-- Estilos -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/alpinejs" defer></script>

  <style>
    body { background: #f3f4f6; }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(6px) scale(0.99); }
      to   { opacity: 1; transform: translateY(0) scale(1); }
    }
    .animate-fadeIn { animation: fadeIn .18s ease-out; }

    .soft-scroll::-webkit-scrollbar { height: 10px; width: 10px; }
    .soft-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
    .soft-scroll::-webkit-scrollbar-track { background: #eef2ff; border-radius: 999px; }

    /* ✅ Layout con menú (igual dashboard) */
    .layout {
      transition: padding-left .2s ease;
      padding-left: 16rem; /* 256px (md:w-64) */
    }
    .layout.menu-collapsed {
      padding-left: 5.25rem; /* 84px colapsado */
    }
    @media (max-width: 768px) {
      .layout, .layout.menu-collapsed { padding-left: 0 !important; }
    }

    /* ✅ Grid columnas igual dashboard (desktop) */
    .orders-grid.cols{
      display:grid;
      grid-template-columns:
        120px 110px minmax(220px,1fr) 110px
        200px 180px 320px 90px
        170px minmax(180px,1fr) 140px;
      gap:14px;
      align-items:center;
    }
    @media (max-width: 1280px){
      .orders-grid.cols{ display:none; }
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
              <h1 class="text-3xl font-extrabold text-slate-900">Producción</h1>
              <p class="text-slate-500 mt-1">Cola por usuario · solo pedidos en estado Producción</p>
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

          <!-- Topbar -->
          <div class="px-4 py-3 border-b border-slate-200 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
            <div class="flex items-center justify-between gap-3">
              <div class="font-semibold text-slate-900">Listado de pedidos</div>
              <div class="text-xs text-slate-500 hidden sm:block">
                Solo tu cola · desaparecen al pasar a “Fabricando”
              </div>
            </div>

            <!-- Toolbar -->
            <div class="flex flex-col sm:flex-row sm:items-center gap-2">

              <!-- Buscador -->
              <div class="relative">
                <input
                  id="inputBuscar"
                  type="text"
                  placeholder="Buscar pedido, cliente, etiqueta..."
                  class="h-11 w-[320px] max-w-full pl-10 pr-3 rounded-2xl border border-slate-200 bg-slate-50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-200"
                />
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">🔎</span>
              </div>

              <button
                id="btnLimpiarBusqueda"
                class="h-11 px-4 rounded-2xl bg-slate-200 text-slate-800 font-extrabold hover:bg-slate-300 transition"
              >
                Limpiar
              </button>

              <div class="hidden sm:block w-px h-11 bg-slate-200 mx-1"></div>

              <button
                id="btnTraer5"
                class="h-11 px-4 rounded-2xl bg-slate-900 text-white font-extrabold hover:bg-slate-800 transition"
              >
                Traer 5 pedidos
              </button>

              <button
                id="btnTraer10"
                class="h-11 px-4 rounded-2xl bg-slate-900 text-white font-extrabold hover:bg-slate-800 transition"
              >
                Traer 10 pedidos
              </button>

              <button
                id="btnDevolver"
                class="h-11 px-4 rounded-2xl bg-white border border-rose-200 text-rose-700 font-extrabold hover:bg-rose-50 transition"
              >
                Devolver pedidos restantes
              </button>
            </div>
          </div>

          <!-- ✅ LISTADO estilo DASHBOARD (GRID + CARDS) -->
          <div class="w-full">

            <!-- Header grid (solo desktop) -->
            <div class="hidden xl:block bg-slate-50 border-b border-slate-200">
              <div class="orders-grid cols px-4 py-3 text-[11px] uppercase tracking-wider text-slate-600 font-extrabold">
                <div>Pedido</div>
                <div>Fecha</div>
                <div>Cliente</div>
                <div>Total</div>
                <div>Estado</div>
                <div>Último cambio</div>
                <div>Etiquetas</div>
                <div>Artículos</div>
                <div>Entrega</div>
                <div>Método</div>
                <div class="text-right">Detalles</div>
              </div>
            </div>

            <!-- GRID desktop -->
            <div id="tablaPedidos" class="hidden xl:block"></div>

            <!-- CARDS mobile/tablet -->
            <div id="cardsPedidos" class="block xl:hidden p-3"></div>

          </div>

          <!-- Footer -->
          <div class="px-4 py-3 border-t border-slate-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div class="text-xs text-slate-500">
              Consejo: en desktop verás el grid; en móvil verás tarjetas.
            </div>

            <div class="hidden sm:flex items-center gap-2">
              <span class="px-4 py-2 rounded-2xl bg-white border border-slate-200 font-extrabold text-sm">
                Total: <span id="total-pedidos">0</span>
              </span>
            </div>
          </div>

        </section>

      </div>
    </div>
  </main>

  <!-- =========================
       MODAL DETALLES (tu mismo modal)
  ========================= -->
  <div id="modalDetalles"
    class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50 p-4">

    <div class="bg-white w-[90%] h-[92%] rounded-3xl shadow-2xl border border-slate-200 p-6 overflow-hidden flex flex-col animate-fadeIn">

      <div class="flex justify-between items-center border-b border-slate-200 pb-4">
        <h2 id="tituloPedido" class="text-2xl font-extrabold text-slate-900">Detalles del pedido</h2>

        <div class="flex gap-3">
          <button onclick="abrirPanelCliente()"
            class="h-11 px-4 rounded-2xl bg-blue-600 text-white font-extrabold hover:bg-blue-700 transition">
            Información del cliente
          </button>

          <button onclick="cerrarModalDetalles()"
            class="h-11 px-4 rounded-2xl bg-slate-200 text-slate-800 font-extrabold hover:bg-slate-300 transition">
            Cerrar
          </button>
        </div>
      </div>

      <div id="detalleProductos"
        class="flex-1 overflow-auto grid grid-cols-1 md:grid-cols-2 gap-4 p-4 soft-scroll"></div>

      <div id="detalleTotales" class="border-t border-slate-200 pt-4 text-lg font-extrabold text-slate-900"></div>

      <div class="flex gap-2 mb-4">
        <button onclick="mostrarTodos()"
          class="h-11 px-4 rounded-2xl bg-slate-200 text-slate-800 font-extrabold hover:bg-slate-300 transition">
          Todos
        </button>

        <button onclick="filtrarPreparados()"
          class="h-11 px-4 rounded-2xl bg-emerald-600 text-white font-extrabold hover:bg-emerald-700 transition">
          Preparados
        </button>
      </div>

    </div>
  </div>

  <!-- PANEL CLIENTE -->
  <div id="panelCliente"
    class="hidden fixed inset-0 flex justify-end bg-black/30 backdrop-blur-sm z-50">
    <div class="w-[380px] h-full bg-white shadow-xl border-l border-slate-200 p-6 overflow-y-auto animate-fadeIn soft-scroll">
      <div class="flex justify-between items-center mb-4">
        <h3 class="text-xl font-extrabold text-slate-900">Información del cliente</h3>
        <button onclick="cerrarPanelCliente()" class="text-slate-600 hover:text-slate-900 text-2xl font-extrabold">×</button>
      </div>

      <div id="detalleCliente" class="space-y-2 mb-6"></div>

      <h3 class="text-lg font-extrabold mt-6 text-slate-900">Dirección de envío</h3>
      <div id="detalleEnvio" class="space-y-1 mb-6"></div>

      <h3 class="text-lg font-extrabold mt-6 text-slate-900">Resumen del pedido</h3>
      <div id="detalleResumen" class="space-y-1 mb-6"></div>
    </div>
  </div>

  <!-- LOADER GLOBAL -->
  <div id="globalLoader" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-[9999]">
    <div class="bg-white p-6 rounded-3xl shadow-xl text-center animate-fadeIn border border-slate-200">
      <div class="w-10 h-10 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto"></div>
      <p class="mt-3 font-semibold text-slate-800">Cargando...</p>
    </div>
  </div>

  <!-- ✅ Variables globales -->
  <script>
    window.etiquetasPredeterminadas = <?= json_encode($etiquetasPredeterminadas ?? []) ?>;
    window.estadoFiltro = "Preparado";

    window.CURRENT_USER = <?= json_encode(session()->get('nombre') ?? 'Sistema') ?>;
    window.API_BASE = "<?= rtrim(site_url(), '/') ?>";
  </script>

  <!-- JS Producción -->
  <script src="<?= base_url('js/produccion.js?v=' . time()) ?>" defer></script>

  <!-- ✅ colapso menú (igual dashboard) -->
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
