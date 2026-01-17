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
    .layout { transition: padding-left .2s ease; padding-left: 16rem; }
    .layout.menu-collapsed { padding-left: 5.25rem; }
    @media (max-width: 768px) { .layout, .layout.menu-collapsed { padding-left: 0 !important; } }

    /* ✅ Grid columnas (igual dashboard) */
    .orders-grid.cols{
      display:grid;
      grid-template-columns:
        150px 140px minmax(220px,1fr) 110px
        210px 180px minmax(280px,1fr) 90px
        170px minmax(220px,1fr) 150px;
      gap:14px;
      align-items:center;
    }

    /* ✅ Ajuste responsive: en 2xl el grid reduce columnas problemáticas */
    @media (max-width: 1535px) {
      .orders-grid.cols{
        grid-template-columns:
          140px 130px minmax(200px,1fr) 100px
          200px 170px minmax(220px,1fr) 80px
          160px minmax(180px,1fr) 140px;
        gap:12px;
      }
    }

    /* ===========================
       ✅ ETIQUETAS COMPACTAS
       - reduce ancho col
       - chips mini
       - no rompe layout
    ============================ */
    .col-etiquetas {
      width: 140px;
      max-width: 140px;
      overflow: hidden;
      white-space: nowrap;
      text-overflow: ellipsis;
    }

    .tags-wrap-mini {
      display:flex;
      flex-wrap:nowrap;
      gap:6px;
      overflow:hidden;
      max-width: 140px;
    }

    .tag-mini {
      display:inline-flex;
      align-items:center;
      height: 18px;
      padding: 0 6px;
      border-radius: 999px;
      font-size: 10px;
      font-weight: 800;
      line-height: 18px;
      letter-spacing: .02em;
      text-transform: uppercase;
      border: 1px solid #e2e8f0;
      background: #f8fafc;
      color: #0f172a;
      flex: 0 0 auto;
    }

    /* ✅ si quieres ultra compacto aún más: descomenta
    .col-etiquetas { width:110px; max-width:110px; }
    .tags-wrap-mini { max-width:110px; }
    .tag-mini { font-size:9px; height:16px; line-height:16px; padding:0 5px; }
    */

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

          <!-- ✅ LISTADO estilo DASHBOARD (RESPONSIVE REAL) -->
          <div class="w-full">

            <!-- Header grid (solo pantallas 2xl+) -->
            <div class="hidden 2xl:block bg-slate-50 border-b border-slate-200">
              <div class="orders-grid cols px-4 py-3 text-[11px] uppercase tracking-wider text-slate-600 font-extrabold">
                <div>Pedido</div>
                <div>Fecha</div>
                <div>Cliente</div>
                <div>Total</div>
                <div>Estado</div>
                <div>Último cambio</div>

                <!-- ✅ ETIQUETAS compact -->
                <div class="col-etiquetas">Etiquetas</div>

                <div class="text-center">Artículos</div>
                <div>Entrega</div>
                <div>Método</div>
                <div class="text-right">Detalles</div>
              </div>
            </div>

            <!-- ✅ GRID grande (2xl+) -->
            <!-- OJO: tu JS debe renderizar aquí DIVs tipo grid.
                 Para etiquetas: usa <div class="col-etiquetas"><div class="tags-wrap-mini">...</div></div>
            -->
            <div id="tablaPedidos" class="hidden 2xl:block"></div>

            <!-- ✅ TABLA con scroll para pantallas intermedias (xl y 2xl-) -->
            <div class="hidden xl:block 2xl:hidden w-full overflow-x-auto soft-scroll">
              <table class="min-w-[1400px] w-full text-sm">
                <thead class="bg-slate-50 sticky top-0 z-10 border-b border-slate-200">
                  <tr class="text-left text-[11px] uppercase tracking-wider text-slate-600 font-extrabold">
                    <th class="px-5 py-4">Pedido</th>
                    <th class="px-5 py-4">Fecha</th>
                    <th class="px-5 py-4">Cliente</th>
                    <th class="px-5 py-4">Total</th>
                    <th class="px-5 py-4">Estado</th>
                    <th class="px-5 py-4">Último cambio</th>

                    <!-- ✅ ETIQUETAS compact -->
                    <th class="px-5 py-4 col-etiquetas">Etiquetas</th>

                    <th class="px-5 py-4 text-center">Artículos</th>
                    <th class="px-5 py-4">Entrega</th>
                    <th class="px-5 py-4">Método</th>
                    <th class="px-5 py-4 text-right">Detalles</th>
                  </tr>
                </thead>

                <!-- ✅ aquí renderizas filas TR cuando estás en "modo tabla" -->
                <tbody id="tablaPedidosTable" class="divide-y divide-slate-100 text-slate-800"></tbody>
              </table>
            </div>

            <!-- ✅ CARDS móvil/mediano (<xl) -->
            <div id="cardsPedidos" class="block xl:hidden p-3"></div>

          </div>

          <!-- Footer -->
          <div class="px-4 py-3 border-t border-slate-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div class="text-xs text-slate-500">
              Consejo: en desktop verás el grid; en móvil verás tarjetas.
            </div>

            <div class="flex items-center gap-2">
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
       ✅ MODAL DETALLES FULL (estilo dashboard)
  ========================= -->
  <div id="modalDetallesFull" class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm z-50">
    <div class="absolute inset-0 p-4 sm:p-6">
      <div class="mx-auto h-full max-w-[1400px] rounded-3xl bg-white shadow-2xl border border-slate-200 overflow-hidden flex flex-col animate-fadeIn">

        <!-- Header -->
        <div class="p-5 border-b border-slate-200 flex items-start justify-between gap-4">
          <div class="min-w-0">
            <div id="detTitle" class="text-2xl sm:text-3xl font-extrabold text-slate-900 truncate">Detalles</div>
            <div id="detSubtitle" class="text-sm text-slate-500 mt-1 truncate">—</div>
          </div>

          <div class="flex items-center gap-2">
            <button type="button" onclick="toggleJsonDetalles()"
              class="h-11 px-4 rounded-2xl bg-white border border-slate-200 text-slate-900 font-extrabold hover:bg-slate-50 transition">
              JSON
            </button>
            <button type="button" onclick="copiarDetallesJson()"
              class="h-11 px-4 rounded-2xl bg-slate-900 text-white font-extrabold hover:bg-slate-800 transition">
              Copiar
            </button>
            <button type="button" onclick="cerrarDetallesFull()"
              class="h-11 px-4 rounded-2xl bg-slate-200 text-slate-800 font-extrabold hover:bg-slate-300 transition">
              Cerrar
            </button>
          </div>
        </div>

        <!-- Body -->
        <div class="flex-1 overflow-hidden">
          <div class="h-full grid grid-cols-1 lg:grid-cols-[1fr_420px] gap-0">

            <!-- Left: items -->
            <div class="h-full overflow-auto soft-scroll p-5">
              <div class="flex items-center justify-between mb-4">
                <div class="text-sm font-extrabold text-slate-900">
                  Productos <span class="text-slate-500">·</span> <span id="detItemsCount">0</span>
                </div>
              </div>

              <div id="detItems" class="space-y-3"></div>

              <pre id="detJson" class="hidden mt-6 p-4 rounded-2xl bg-slate-950 text-slate-100 text-xs overflow-auto soft-scroll"></pre>
            </div>

            <!-- Right: panels -->
            <div class="h-full overflow-auto soft-scroll p-5 border-l border-slate-200 bg-slate-50">

              <div class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500">Cliente</div>
                <div id="detCliente" class="mt-2 text-sm text-slate-700"></div>
              </div>

              <div class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm mt-4">
                <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500">Envío</div>
                <div id="detEnvio" class="mt-2 text-sm text-slate-700"></div>
              </div>

              <div class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm mt-4">
                <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500">Resumen</div>
                <div id="detResumen" class="mt-2 text-sm text-slate-700"></div>
              </div>

              <div class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm mt-4">
                <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500">Totales</div>
                <div id="detTotales" class="mt-2 text-sm text-slate-700"></div>
              </div>

            </div>
          </div>
        </div>

      </div>
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

    window.CURRENT_USER = <?= json_encode(session()->get('nombre') ?? 'Sistema') ?>;
    window.currentUserRole = <?= json_encode(session()->get('role') ?? '') ?>;

    window.API_BASE = "<?= rtrim(site_url(), '/') ?>";
  </script>

  <!-- JS principal -->
  <script src="<?= base_url('js/produccion.js?v=' . time()) ?>" defer></script>

  <!-- ✅ aplicar colapso menú (igual dashboard) -->
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
