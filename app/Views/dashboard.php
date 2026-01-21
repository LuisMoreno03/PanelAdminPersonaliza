<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= csrf_hash() ?>">
  <meta name="csrf-header" content="<?= csrf_header() ?>">

  <title>Dashboard - Panel</title>

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

    /* ✅ Layout con menú */
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

    /* ✅ Fuerza que el contenedor del listado no “recorte” */
    .table-wrap {
      width: 100%;
      max-width: 100%;
    }

    /* ✅ GRID responsive real (desktop) */
    .orders-grid {
      display: grid;
      align-items: center;
      gap: .65rem;
      width: 100%;
    }

    /* ✅ Header + rows usan la misma grilla (SIN ETIQUETAS: 10 columnas) */
    .orders-grid.cols {
      grid-template-columns:
        110px                     /* Pedido */
        92px                      /* Fecha */
        minmax(170px, 1.2fr)      /* Cliente */
        90px                      /* Total */
        160px                     /* Estado */
        minmax(140px, 0.9fr)      /* Último cambio */
        44px                      /* Art */
        140px                     /* Entrega */
        minmax(190px, 1fr)        /* Método entrega */
        130px;                    /* Ver detalles */
    }

    /* ✅ Importante: permite truncar sin romper el grid */
    .orders-grid > div { min-width: 0; }

    /* ✅ Para el método de entrega: permite 2 líneas */
    .metodo-entrega {
      white-space: normal;
      line-height: 1.1;
      display: -webkit-box;
      -webkit-line-clamp: 2;       /* máximo 2 líneas */
      -webkit-box-orient: vertical;
      overflow: hidden;
    }

    /* ✅ Scroll horizontal solo en la tabla (si hace falta) */
    .table-scroll { overflow-x: auto; }
    .table-scroll::-webkit-scrollbar { height: 10px; }
    .table-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
    .table-scroll::-webkit-scrollbar-track { background: #eef2ff; border-radius: 999px; }

    /* ✅ Cuando el ancho baja demasiado, pasamos a cards */
    @media (max-width: 1180px) {
      .desktop-orders { display: none !important; }
      .mobile-orders  { display: block !important; }
    }
    @media (min-width: 1181px) {
      .desktop-orders { display: block !important; }
      .mobile-orders  { display: none !important; }
    }
  </style>
</head>

<body class="min-h-screen bg-gradient-to-b from-slate-50 to-slate-100 overflow-x-hidden">

  <!-- MENU -->
  <?= view('layouts/menu') ?>

  <main id="mainLayout" class="layout">
    <div class="p-4 sm:p-6 lg:p-8">
      <div class="mx-auto w-full max-w-[1600px]">

        <!-- HEADER -->
        <section class="mb-6">
          <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5 flex items-start justify-between gap-4">
            <div>
              <h1 class="text-3xl font-extrabold text-slate-900">Pedidos</h1>
              <p class="text-slate-500 mt-1">Estados, últimos cambios y detalles</p>
            </div>
          </div>
        </section>

        <!-- USUARIOS -->
        <section class="mb-6 hidden">
          <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5">
            <h3 class="text-lg font-extrabold text-slate-900 mb-3">Estado de usuarios</h3>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
              <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                <div class="flex justify-between items-center mb-2">
                  <span class="font-bold text-emerald-900">Conectados</span>
                  <span id="onlineCount" class="font-extrabold">0</span>
                </div>
                <ul id="onlineUsers" class="text-sm space-y-1"></ul>
              </div>

              <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4">
                <div class="flex justify-between items-center mb-2">
                  <span class="font-bold text-rose-900">Desconectados</span>
                  <span id="offlineCount" class="font-extrabold">0</span>
                </div>
                <ul id="offlineUsers" class="text-sm space-y-1"></ul>
              </div>
            </div>
          </div>
        </section>
        <!-- FILTROS -->
       <!-- FILTROS -->
        <section class="mb-6">
          <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">

              <!-- IZQUIERDA: TITULO + BUSCADOR TOP -->
              <div class="flex items-center gap-3 w-full">
                <div class="font-extrabold text-slate-900 text-lg whitespace-nowrap">Filtros</div>

                <!-- ✅ Buscador siempre visible -->
                <div class="flex items-center gap-2 w-full sm:max-w-[520px]">
                  <div class="relative flex-1">
                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">🔎</span>
                    <input id="f_q_top" type="text" placeholder="Buscar pedido, cliente, ID…"
                      class="w-full rounded-2xl border border-slate-200 pl-11 pr-4 py-3 text-sm outline-none focus:ring-2 focus:ring-blue-200">
                  </div>

                  <button id="btnBuscarTop"
                    type="button"
                    class="px-4 py-3 rounded-2xl bg-blue-600 text-white text-xs font-extrabold uppercase tracking-wide hover:bg-blue-700">
                    Buscar
                  </button>
                </div>
              </div>

              <!-- DERECHA: BOTON FILTRO -->
              <button id="btnToggleFiltros"
                type="button"
                class="px-4 py-3 rounded-2xl border border-slate-200 bg-slate-50 text-slate-700 text-xs font-extrabold uppercase tracking-wide hover:bg-slate-100 whitespace-nowrap">
                Filtro
              </button>
            </div>

            <!-- ✅ Panel completo oculto por defecto -->
            <div id="boxFiltros" class="mt-4 hidden">
              <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">

                <!-- ❌ Quitamos el buscador viejo (f_q) porque ya está arriba -->

                <div>
                  <label class="text-xs font-extrabold text-slate-600 uppercase">Estado interno</label>
                  <select id="f_estado"
                    class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm bg-white outline-none focus:ring-2 focus:ring-blue-200">
                    <option value="">Cualquiera</option>
                    <option value="Por preparar">Por preparar</option>
                    <option value="Faltan archivos">Faltan archivos</option>
                    <option value="Confirmado">Confirmado</option>
                    <option value="Diseñado">Diseñado</option>
                    <option value="Por producir">Por producir</option>
                    <option value="Enviado">Enviado</option>
                    <option value="Repetir">Repetir</option>
                  </select>
                </div>

                <div>
                  <label class="text-xs font-extrabold text-slate-600 uppercase">Estado envío</label>
                  <select id="f_envio"
                    class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm bg-white outline-none focus:ring-2 focus:ring-blue-200">
                    <option value="">Cualquiera</option>
                    <option value="__none__">Sin enviar</option>
                    <option value="fulfilled">Enviado</option>
                    <option value="partial">Parcial</option>
                    <option value="unfulfilled">Pendiente</option>
                  </select>
                </div>

                <div>
                  <label class="text-xs font-extrabold text-slate-600 uppercase">Método entrega</label>
                  <select id="f_forma"
                    class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm bg-white outline-none focus:ring-2 focus:ring-blue-200">
                    <option value="">Cualquiera</option>
                    <!-- se llena dinámico desde JS -->
                  </select>
                </div>

                <div>
                  <label class="text-xs font-extrabold text-slate-600 uppercase">Desde</label>
                  <input id="f_desde" type="date"
                    class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-blue-200">
                </div>

                <div>
                  <label class="text-xs font-extrabold text-slate-600 uppercase">Hasta</label>
                  <input id="f_hasta" type="date"
                    class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-blue-200">
                </div>

                <div>
                  <label class="text-xs font-extrabold text-slate-600 uppercase">Total min</label>
                  <input id="f_total_min" type="number" step="0.01" placeholder="0"
                    class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-blue-200">
                </div>

                <div>
                  <label class="text-xs font-extrabold text-slate-600 uppercase">Total max</label>
                  <input id="f_total_max" type="number" step="0.01" placeholder="9999"
                    class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-blue-200">
                </div>

                <div>
                  <label class="text-xs font-extrabold text-slate-600 uppercase">Artículos min</label>
                  <input id="f_art_min" type="number" step="1" placeholder="0"
                    class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-blue-200">
                </div>

                <div>
                  <label class="text-xs font-extrabold text-slate-600 uppercase">Artículos max</label>
                  <input id="f_art_max" type="number" step="1" placeholder="99"
                    class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-blue-200">
                </div>

              </div>

              <div class="mt-4 flex flex-wrap items-center gap-2">
                <button id="btnAplicarFiltros"
                  type="button"
                  class="px-5 py-3 rounded-2xl bg-blue-600 text-white font-extrabold text-xs uppercase tracking-wide hover:bg-blue-700">
                  Aplicar
                </button>

                <button id="btnLimpiarFiltros"
                  type="button"
                  class="px-5 py-3 rounded-2xl bg-slate-100 border border-slate-200 text-slate-800 font-extrabold text-xs uppercase tracking-wide hover:bg-slate-200">
                  Limpiar
                </button>

                <div id="filtersInfo" class="ml-auto text-xs font-bold text-slate-500"></div>
              </div>
            </div>
          </div>
        </section>


        <!-- PEDIDOS -->
        <section class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
          <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between">
            <div class="font-semibold text-slate-900">Listado de pedidos</div>
            <div class="text-xs text-slate-500 hidden sm:block">Todo visible · responsive</div>
          </div>

          <!-- ✅ Desktop table (oculta en mobile) -->
          <div class="table-wrap table-scroll desktop-orders">
            <!-- HEADER -->
            <div class="orders-grid cols px-4 py-3 text-[11px] uppercase tracking-wider text-slate-600 bg-slate-50 border-b">
              <div>Pedido</div>
              <div>Fecha</div>
              <div>Cliente</div>
              <div>Total</div>
              <div>Estado</div>
              <div>Último cambio</div>
              <div class="text-center">Art</div>
              <div>Entrega</div>
              <div>Método de entrega</div>
              <div class="text-right">Ver</div>
            </div>

            <!-- ROWS -->
            <div id="tablaPedidos" class="divide-y"></div>
          </div>

          <!-- MOBILE/TABLET CARDS -->
          <div id="cardsPedidos" class="mobile-orders p-4"></div>
        </section>

        <!-- PAGINACIÓN -->
        <section class="mt-6 flex flex-col sm:flex-row items-center justify-between gap-4">
          <button id="btnAnterior"
                  disabled
                  class="w-full sm:w-auto px-5 py-3 rounded-2xl bg-slate-200 text-slate-700 font-bold opacity-50 cursor-not-allowed">
            ← Anterior
          </button>

          <div class="flex items-center gap-2">
            <span id="pillPagina" class="px-4 py-2 rounded-2xl bg-white border border-slate-200 font-extrabold text-sm">
              Página 1
            </span>
            <span id="pillPaginaTotal" class="px-4 py-2 rounded-2xl bg-slate-50 border border-slate-200 font-bold text-sm">
              Página 1
            </span>
          </div>

          <button id="btnSiguiente"
                  class="w-full sm:w-auto px-5 py-3 rounded-2xl bg-blue-600 text-white font-bold hover:bg-blue-700">
            Siguiente →
          </button>
        </section>

      </div>
    </div>
  </main>

  <!-- =============================================================== -->
  <!-- MODAL DETALLES PEDIDO -->
  <!-- =============================================================== -->
  <?= view('layouts/modal_detalles') ?>
  <!-- MODALES (SOLO ESTADO) -->
  <?= view('layouts/modales_estados') ?>

  <!-- LOADER -->
  <div id="globalLoader" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-50">
    <div class="bg-white p-6 rounded-3xl shadow-xl text-center animate-fadeIn">
      <div class="w-10 h-10 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto"></div>
      <p class="mt-3 font-semibold">Cargando...</p>
    </div>
  </div>

  <!-- ✅ Variables globales (UNA sola vez) -->
  <script>
    window.CURRENT_USER = <?= json_encode(session()->get('nombre') ?? 'Sistema') ?>;

    // ✅ Endpoints correctos
    window.API = {
      pedidos: "<?= site_url('dashboard/pedidos') ?>",
      filter: "<?= site_url('dashboard/filter') ?>",
      ping: "<?= site_url('dashboard/ping') ?>",
      usuariosEstado: "<?= site_url('dashboard/usuarios-estado') ?>",
      guardarEstado: "<?= site_url('dashboard/guardar-estado') ?>"
    };
  </script>

  <!-- ✅ romper caché -->
  <script src="<?= base_url('js/dashboard.js?v=' . time()) ?>"></script>

  <!-- ✅ aplicar colapso si está guardado -->
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
