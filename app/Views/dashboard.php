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

    /* ✅ Grid sin scroll para filas desktop (se adapta a ancho real) */
    ./* ✅ Fuerza que el contenedor del listado no “recorte” */
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

/* ✅ Header + rows usan la misma grilla */
.orders-grid.cols {
  grid-template-columns:
    110px                     /* Pedido */
    92px                      /* Fecha */
    minmax(170px, 1.2fr)      /* Cliente */
    90px                      /* Total */
    160px                     /* Estado */
    minmax(140px, 0.9fr)      /* Último cambio */
    minmax(170px, 1fr)        /* Etiquetas */
    44px                      /* Art */
    140px                     /* Entrega */
    minmax(190px, 1fr)        /* Método entrega */
    130px;                    /* ✅ Ver detalles */
}

/* ✅ Importante: permite truncar sin romper el grid */
.orders-grid > div {
  min-width: 0;
}

/* ✅ Para el método de entrega: permite 2 líneas */
.metodo-entrega {
  white-space: normal;
  line-height: 1.1;
  display: -webkit-box;
  -webkit-line-clamp: 2;       /* máximo 2 líneas */
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* ✅ Si quieres “ver todo sí o sí” cuando el monitor sea pequeño,
   activa scroll solo en la tabla (opcional) */
.table-scroll {
  overflow-x: auto;
}
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
              <p class="text-slate-500 mt-1">Estados, etiquetas, últimos cambios y detalles</p>
            </div>
          </div>
        </section>

        <!-- USUARIOS -->
        <section class="mb-6">
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

        <!-- PEDIDOS -->
        <section class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
          <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between">
            <div class="font-semibold text-slate-900">Listado de pedidos</div>
            <div class="text-xs text-slate-500 hidden sm:block">Todo visible · responsive</div>
          </div>

          <!-- ✅ Wrap que permite scroll opcional si hace falta -->
          <div class="table-wrap table-scroll">
            <!-- HEADER -->
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

  <!-- =========================
     MODAL ETIQUETAS BONITO (ÚNICO)
  ========================= -->
  <div id="modalEtiquetas" class="hidden fixed inset-0 z-[9998] bg-black/40 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="w-full max-w-3xl rounded-3xl bg-white shadow-2xl border border-slate-200 overflow-hidden animate-fadeIn">

      <!-- Header -->
      <div class="p-5 sm:p-6 border-b border-slate-200 flex items-start justify-between gap-4">
        <div>
          <h3 class="text-lg sm:text-xl font-extrabold text-slate-900">Etiquetas del pedido</h3>
          <p class="text-sm text-slate-500 mt-1">
            Selecciona máximo <b>6</b>. Se guardan como <b>tags</b> en Shopify.
          </p>
        </div>

        <button type="button" onclick="cerrarModalEtiquetas()"
          class="h-10 w-10 rounded-2xl border border-slate-200 bg-white text-slate-600 hover:text-slate-900 hover:border-slate-300 transition font-extrabold text-xl leading-none">
          ×
        </button>
      </div> 

      <!-- Content -->
      <div class="p-5 sm:p-6 space-y-5">

        <!-- Meta -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
          <div class="flex items-center gap-2">
            <span class="text-xs font-extrabold uppercase tracking-wide text-slate-500">Pedido:</span>
            <span id="etqPedidoLabel" class="text-sm font-extrabold text-slate-900">—</span>
          </div>

          <div class="flex items-center gap-2">
            <span class="text-xs font-extrabold uppercase tracking-wide text-slate-500">Límite:</span>
            <span id="etqCounter"
                  class="inline-flex items-center px-3 py-1 rounded-full text-xs font-extrabold bg-slate-50 border border-slate-200 text-slate-800">
              0 / 6
            </span>
          </div>
        </div>

        <!-- Selected -->
        <div class="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
          <div class="flex items-center justify-between">
            <h4 class="font-extrabold text-slate-900">Seleccionadas</h4>
            <button type="button" onclick="limpiarEtiquetas()"
                    class="text-xs font-extrabold text-slate-700 hover:text-slate-900 underline">
              Limpiar
            </button>
          </div>

          <div id="etqSelectedWrap" class="mt-3 flex flex-wrap gap-2"></div>

          <div id="etqLimitHint" class="hidden mt-3 text-sm font-bold text-rose-600">
            Máximo 6 etiquetas.
          </div>

          <div id="etqError" class="hidden mt-3 text-sm font-bold text-rose-600"></div>
        </div>

        <!-- Producción -->
        <div id="etqSectionProduccion" class="rounded-2xl border border-amber-200 bg-amber-50/50 p-4">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
              <span class="h-2.5 w-2.5 rounded-full bg-amber-500"></span>
              <h4 class="font-extrabold text-amber-900">Producción</h4>
            </div>
            <span class="text-xs font-extrabold text-amber-800/80">P.*</span>
          </div>
          <div id="etqProduccionList" class="mt-3 flex flex-wrap gap-2"></div>
        </div>

        <!-- Diseño -->
        <div id="etqSectionDiseno" class="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
              <span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
              <h4 class="font-extrabold text-emerald-900">Diseño</h4>
            </div>
            <span class="text-xs font-extrabold text-emerald-800/80">D.*</span>
          </div>
          <div id="etqDisenoList" class="mt-3 flex flex-wrap gap-2"></div>
        </div>

        <!-- Generales -->
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
              <span class="h-2.5 w-2.5 rounded-full bg-slate-900"></span>
              <h4 class="font-extrabold text-slate-900">Generales</h4>
            </div>
            <span class="text-xs font-extrabold text-slate-500">Acciones</span>
          </div>
          <div id="etqGeneralesList" class="mt-3 flex flex-wrap gap-2"></div>
        </div>

      </div>

      <!-- Footer -->
      <div class="p-5 sm:p-6 border-t border-slate-200 flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between">
        <div class="text-xs text-slate-500">
          Consejo: usa 1 tag de proceso (P.* o D.*) + 1 tag de acción (Reembolso/No contesta/etc.)
        </div>

        <div class="flex gap-2">
          <button type="button" onclick="cerrarModalEtiquetas()"
            class="px-4 py-3 rounded-2xl bg-slate-200 text-slate-800 font-extrabold hover:bg-slate-300 transition">
            Cancelar
          </button>

          <button id="btnGuardarEtiquetas"
            type="button"
            onclick="guardarEtiquetasModal()"
            class="px-4 py-3 rounded-2xl bg-slate-900 text-white font-extrabold hover:bg-slate-800 transition disabled:opacity-60 disabled:cursor-not-allowed">
            Guardar
          </button>
        </div>
      </div>

    </div>
  </div>
  
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
    window.etiquetasPredeterminadas = <?= json_encode($etiquetasPredeterminadas) ?>;
    window.CURRENT_USER = <?= json_encode(session()->get('nombre') ?? 'Sistema') ?>;
    window.API_BASE = "<?= rtrim(site_url(), '/') ?>";
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
