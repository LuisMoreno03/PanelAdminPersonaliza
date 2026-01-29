<!-- app/Views/seguimiento/index.php -->
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= esc($title ?? 'Seguimiento') ?></title>

  <!-- Tailwind (si ya lo cargas en tu layout, elimina esto) -->
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- Flatpickr (calendario moderno) -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/airbnb.css">
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

  <style>
    /* Grid de la lista (desktop) */
    .seg-grid-cols{
      display:grid;
      grid-template-columns: 1.6fr 1.6fr .9fr .9fr .9fr 1.2fr .8fr;
      gap: 14px;
      align-items:center;
    }
    @media (max-width: 1024px){
      .seg-grid-cols{
        grid-template-columns: 1.6fr 1.6fr 1fr 1.2fr .9fr;
      }
      .seg-col-hide-lg{ display:none; }
    }
    @media (max-width: 640px){
      .seg-grid-cols{
        grid-template-columns: 1.6fr 1fr .8fr;
      }
      .seg-col-hide-sm{ display:none; }
    }

    /* Scroll bonito para chips */
    .seg-scrollbar::-webkit-scrollbar{ height: 10px; width: 10px; }
    .seg-scrollbar::-webkit-scrollbar-thumb{ background: #CBD5E1; border-radius: 999px; }
    .seg-scrollbar::-webkit-scrollbar-track{ background: transparent; }

    /* Modal responsive */
    .seg-modal-card{
      max-height: calc(100vh - 40px);
    }
  </style>

  <script>
    // Si usas un base path (ej. /public), setéalo aquí desde PHP si quieres:
    window.API_BASE = window.API_BASE || "";
  </script>
</head>

<body class="bg-slate-50 text-slate-900">

  <!-- Loader global -->
  <div id="globalLoader" class="hidden fixed inset-0 z-[60] bg-black/20 backdrop-blur-[1px]">
    <div class="absolute inset-0 flex items-center justify-center">
      <div class="rounded-3xl bg-white shadow-lg border border-slate-200 px-5 py-4 flex items-center gap-3">
        <div class="h-5 w-5 rounded-full border-2 border-slate-300 border-t-slate-900 animate-spin"></div>
        <div class="font-extrabold text-sm">Cargando…</div>
      </div>
    </div>
  </div>

  <main class="w-full px-4 sm:px-6 lg:px-8 py-6">

    <!-- Header -->
    <div class="flex items-start justify-between gap-4 flex-wrap">
      <div class="flex items-center gap-3">
        <div class="h-10 w-10 rounded-2xl bg-slate-900 text-white flex items-center justify-center font-black">S</div>
        <div>
          <h1 class="text-xl sm:text-2xl font-black">Seguimiento</h1>
          <p class="text-sm font-bold text-slate-600">Resumen de cambios de estados internos realizados por cada usuario.</p>
        </div>
      </div>

      <div class="flex items-center gap-2">
        <div class="rounded-2xl bg-white border border-slate-200 px-3 py-2 shadow-sm">
          <div class="text-[11px] font-extrabold text-slate-500 uppercase">Usuarios</div>
          <div class="text-base font-black" id="total-usuarios">0</div>
        </div>

        <div class="rounded-2xl bg-white border border-slate-200 px-3 py-2 shadow-sm">
          <div class="text-[11px] font-extrabold text-slate-500 uppercase">Cambios</div>
          <div class="text-base font-black" id="total-cambios">0</div>
        </div>

        <!-- Opcional (si luego lo pintas desde JS con stats.pedidos_modificados) -->
        <div class="rounded-2xl bg-white border border-slate-200 px-3 py-2 shadow-sm hidden sm:block">
          <div class="text-[11px] font-extrabold text-slate-500 uppercase">Pedidos tocados (general)</div>
          <div class="text-base font-black" id="total-pedidos-modificados">0</div>
        </div>
      </div>
    </div>

    <!-- Filtros -->
    <section class="mt-5 rounded-3xl bg-white border border-slate-200 shadow-sm p-4">
      <div class="flex flex-col xl:flex-row xl:items-center gap-3">

        <div class="flex-1 min-w-[240px]">
          <label class="text-[11px] font-extrabold text-slate-500 uppercase tracking-wide">Buscar</label>
          <div class="mt-1 flex items-center gap-2">
            <div class="relative flex-1">
              <input id="inputBuscar"
                     type="text"
                     placeholder="Buscar por usuario o email…"
                     class="w-full h-11 rounded-2xl border border-slate-200 bg-slate-50 px-4 pr-10 font-bold outline-none focus:ring-2 focus:ring-slate-900/10 focus:border-slate-300" />
              <div class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400">
                🔎
              </div>
            </div>

            <button id="btnLimpiarBusqueda"
                    type="button"
                    class="h-11 px-4 rounded-2xl bg-white border border-slate-200 font-extrabold text-sm shadow-sm hover:bg-slate-50">
              Limpiar
            </button>
          </div>
        </div>

        <div class="flex flex-wrap items-end gap-2">
          <div>
            <label class="text-[11px] font-extrabold text-slate-500 uppercase tracking-wide">Desde</label>
            <input id="from"
                   type="text"
                   placeholder="YYYY-MM-DD"
                   class="mt-1 w-[150px] h-11 rounded-2xl border border-slate-200 bg-white px-4 font-extrabold outline-none focus:ring-2 focus:ring-slate-900/10 focus:border-slate-300" />
          </div>

          <div>
            <label class="text-[11px] font-extrabold text-slate-500 uppercase tracking-wide">Hasta</label>
            <input id="to"
                   type="text"
                   placeholder="YYYY-MM-DD"
                   class="mt-1 w-[150px] h-11 rounded-2xl border border-slate-200 bg-white px-4 font-extrabold outline-none focus:ring-2 focus:ring-slate-900/10 focus:border-slate-300" />
          </div>

          <button id="btnFiltrar"
                  type="button"
                  class="h-11 px-5 rounded-2xl bg-slate-900 text-white font-black shadow-sm hover:bg-slate-800">
            Filtrar
          </button>

          <button id="btnLimpiarFechas"
                  type="button"
                  class="h-11 px-5 rounded-2xl bg-white border border-slate-200 font-black shadow-sm hover:bg-slate-50">
            Quitar filtros
          </button>

          <button id="btnActualizar"
                  type="button"
                  class="h-11 px-5 rounded-2xl bg-white border border-slate-200 font-black shadow-sm hover:bg-slate-50"
                  onclick="window.dispatchEvent(new Event('seguimiento:refresh'))">
            Actualizar
          </button>
        </div>
      </div>

      <!-- Error -->
      <div id="errorBox" class="hidden mt-4 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-extrabold text-red-700"></div>
    </section>

    <!-- Tabla / Listado -->
    <section class="mt-5 rounded-3xl bg-white border border-slate-200 shadow-sm overflow-hidden">
      <!-- Header row -->
      <div class="px-4 py-3 bg-slate-50 border-b border-slate-200">
        <div class="seg-grid-cols text-[11px] font-black text-slate-600 uppercase tracking-wide">
          <div>Usuario</div>
          <div>Email</div>
          <div class="seg-col-hide-sm">Pedidos tocados</div>
          <div>Cambios</div>
          <div class="seg-col-hide-lg">Confirmados</div>
          <div class="seg-col-hide-lg">Diseños</div>
          <div class="seg-col-hide-sm">Último cambio</div>
          <div class="text-right">Acciones</div>
        </div>
      </div>

      <!-- Contenedor Desktop (div rows) -->
      <div id="tablaSeguimiento" class="hidden 2xl:block"></div>

      <!-- Contenedor XL/LG (div rows) -->
      <div id="tablaSeguimientoTable" class="hidden xl:block 2xl:hidden"></div>

      <!-- Cards móvil -->
      <div class="xl:hidden p-4" id="cardsSeguimiento"></div>
    </section>

  </main>

  <!-- MODAL DETALLE -->
  <div id="detalleModal" class="hidden fixed inset-0 z-[70]">
    <!-- overlay (click para cerrar) -->
    <div class="absolute inset-0 bg-black/40" data-close="1"></div>

    <!-- card -->
    <div class="absolute inset-0 flex items-center justify-center p-3 sm:p-6">
      <div class="seg-modal-card w-full max-w-6xl rounded-[28px] bg-white border border-slate-200 shadow-2xl overflow-hidden">
        <!-- topbar -->
        <div class="px-5 sm:px-6 py-4 border-b border-slate-200 flex items-start justify-between gap-3">
          <div class="min-w-0">
            <h2 id="detalleTitulo" class="text-base sm:text-lg font-black truncate">Detalle</h2>
            <p id="detalleSub" class="text-sm font-bold text-slate-600 truncate">Usuario</p>
          </div>

          <button id="detalleCerrar"
                  class="shrink-0 h-10 px-4 rounded-2xl bg-white border border-slate-200 font-black hover:bg-slate-50">
            Cerrar
          </button>
        </div>

        <!-- contenido -->
        <div class="p-5 sm:p-6 space-y-4">

          <!-- ERROR modal -->
          <div id="detalleError" class="hidden rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-extrabold text-red-700"></div>

          <!-- LOADING modal -->
          <div id="detalleLoading" class="hidden">
            <div class="rounded-3xl border border-slate-200 bg-slate-50 p-4 flex items-center gap-3">
              <div class="h-5 w-5 rounded-full border-2 border-slate-300 border-t-slate-900 animate-spin"></div>
              <div class="font-extrabold text-sm">Cargando detalle…</div>
            </div>
          </div>

          <!-- Resumen del usuario (chips) -->
          <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
            <div class="rounded-3xl border border-slate-200 bg-slate-50 p-4">
              <div class="text-[11px] font-extrabold text-slate-500 uppercase">Usuario</div>
              <div class="mt-1 text-sm font-black" id="detalleUserName">—</div>
              <div class="mt-1 text-xs font-bold text-slate-600" id="detalleUserEmail">—</div>
              <div class="mt-1 text-xs font-bold text-slate-500" id="detalleUserId">—</div>
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white p-4">
              <div class="text-[11px] font-extrabold text-slate-500 uppercase">KPIs</div>
              <div class="mt-2 grid grid-cols-2 sm:grid-cols-4 gap-2">
                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2">
                  <div class="text-[11px] font-extrabold text-slate-500 uppercase">Cambios</div>
                  <div class="text-base font-black" id="detalleKpiCambios">0</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2">
                  <div class="text-[11px] font-extrabold text-slate-500 uppercase">Pedidos tocados</div>
                  <div class="text-base font-black" id="detalleKpiPedidos">0</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2">
                  <div class="text-[11px] font-extrabold text-slate-500 uppercase">Confirmados</div>
                  <div class="text-base font-black" id="detalleKpiConfirmados">0</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2">
                  <div class="text-[11px] font-extrabold text-slate-500 uppercase">Diseños</div>
                  <div class="text-base font-black" id="detalleKpiDisenos">0</div>
                </div>
              </div>
            </div>

            <div class="rounded-3xl border border-slate-200 bg-slate-50 p-4">
              <div class="text-[11px] font-extrabold text-slate-500 uppercase">Rango</div>
              <div class="mt-1 text-sm font-black" id="detalleRango">Histórico</div>
              <div class="mt-2 text-xs font-bold text-slate-600">
                Tip: puedes hacer scroll horizontal si hay muchos pedidos.
              </div>
            </div>
          </div>

          <!-- Pedidos tocados (chips/cards) -->
          <div class="rounded-3xl border border-slate-200 bg-white p-4">
            <div class="flex items-center justify-between gap-2">
              <div class="font-black text-sm">Pedidos tocados</div>
              <div class="text-xs font-extrabold text-slate-500" id="detallePedidosInfo">—</div>
            </div>

            <div class="mt-3 overflow-x-auto seg-scrollbar">
              <div id="detallePedidosChips" class="flex gap-2 min-w-max">
                <!-- chips se pintan por JS -->
              </div>
            </div>
          </div>

          <!-- Tabla detalle (desktop) -->
          <div class="rounded-3xl border border-slate-200 bg-white overflow-hidden hidden lg:block">
            <div class="grid grid-cols-12 px-4 py-3 bg-slate-50 border-b border-slate-200 text-[11px] font-black text-slate-600 uppercase tracking-wide">
              <div class="col-span-3">Fecha</div>
              <div class="col-span-2">Entidad</div>
              <div class="col-span-2">ID</div>
              <div class="col-span-2">Antes</div>
              <div class="col-span-3">Después</div>
            </div>
            <div id="detalleBodyTable" class="max-h-[46vh] overflow-y-auto"></div>
          </div>

          <!-- Cards detalle (mobile) -->
          <div id="detalleBodyCards" class="lg:hidden"></div>

          <!-- Paginación -->
          <div class="flex items-center justify-between gap-3 flex-wrap">
            <div class="text-sm font-extrabold text-slate-600" id="detallePaginacionInfo">Mostrando 0</div>
            <div class="flex items-center gap-2">
              <button id="detallePrev"
                      type="button"
                      class="h-10 px-4 rounded-2xl bg-white border border-slate-200 font-black hover:bg-slate-50">
                Anterior
              </button>
              <button id="detalleNext"
                      type="button"
                      class="h-10 px-4 rounded-2xl bg-slate-900 text-white font-black hover:bg-slate-800">
                Siguiente
              </button>
            </div>
          </div>

        </div>
      </div>
    </div>
  </div>

  <!-- Init calendario moderno (solo UI; tu JS usa el value YYYY-MM-DD) -->
  <script>
    (function(){
      if (!window.flatpickr) return;

      const from = document.getElementById('from');
      const to = document.getElementById('to');

      const baseCfg = {
        dateFormat: "Y-m-d",
        allowInput: true,
        theme: "airbnb"
      };

      if (from) flatpickr(from, { ...baseCfg });
      if (to) flatpickr(to, { ...baseCfg });
    })();
  </script>

  <!-- Tu JS (el que ya tienes) -->
  <script src="<?= base_url('assets/js/seguimiento.js') ?>"></script>

</body>
</html>
