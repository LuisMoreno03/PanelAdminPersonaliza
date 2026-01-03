<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
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

    /* ✅ GRID columnas: se adapta sin scroll (clamp) */
    .orders-grid {
      display: grid;
      gap: .5rem;
      align-items: center;
      grid-template-columns:
        110px  /* Pedido */
        95px   /* Fecha */
        minmax(150px, 1.6fr) /* Cliente */
        90px   /* Total */
        138px  /* Estado */
        minmax(120px, 1fr)   /* Último cambio */
        minmax(170px, 1.2fr) /* Etiquetas */
        54px   /* Art */
        150px  /* Entrega */
        minmax(150px, 1fr)   /* Forma */
        84px;  /* Ver */
    }

    /* ✅ En pantallas "normales" (laptop) compacta más */
    @media (max-width: 1400px) {
      .orders-grid {
        grid-template-columns:
          100px
          90px
          minmax(140px, 1.3fr)
          86px
          132px
          minmax(110px, .9fr)
          minmax(160px, 1.1fr)
          50px
          140px
          minmax(130px, .9fr)
          78px;
      }
    }

    /* ✅ Si baja a < 1180px, cambiamos a cards (sin tabla) */
    @media (max-width: 1180px) {
      .desktop-orders { display: none !important; }
      .mobile-orders { display: block !important; }
    }
    @media (min-width: 1181px) {
      .desktop-orders { display: block !important; }
      .mobile-orders { display: none !important; }
    }

    /* ✅ Layout con menú colapsable */
    .layout {
      transition: padding-left .2s ease;
      padding-left: 16rem; /* 256px expanded */
    }
    .layout.menu-collapsed {
      padding-left: 5.25rem; /* 84px collapsed */
    }
    @media (max-width: 768px) {
      .layout, .layout.menu-collapsed { padding-left: 0 !important; }
    }
  </style>
</head>

<body class="min-h-screen bg-gradient-to-b from-slate-50 to-slate-100 overflow-x-hidden">

  <!-- MENU -->
  <?= view('layouts/menu') ?>

  <!-- ✅ MAIN con soporte colapso -->
  <main id="mainLayout" class="layout">
    <div class="p-4 sm:p-6 lg:p-8">
      <div class="mx-auto w-full max-w-[1400px]">

        <!-- HEADER -->
        <section class="mb-6">
          <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5 flex items-start justify-between gap-4">
            <div>
              <h1 class="text-3xl font-extrabold text-slate-900">Pedidos</h1>
              <p class="text-slate-500 mt-1">Estados, etiquetas, últimos cambios y detalles</p>
            </div>

            <!-- ✅ Botón colapsar menú (si tu menú no lo trae) -->
            <button id="btnToggleMenu"
              class="hidden md:inline-flex items-center gap-2 px-4 py-2 rounded-2xl border border-slate-200 bg-white shadow-sm
                     text-slate-800 font-bold text-sm hover:bg-slate-50 transition">
              ☰ Menú
            </button>
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
          <div class="px-4 py-3 border-b border-slate-200 font-semibold text-slate-900">
            Listado de pedidos
          </div>

          <!-- ✅ DESKTOP: una sola línea (sin scroll) -->
          <div class="desktop-orders">
            <!-- HEADER GRID -->
            <div class="orders-grid px-4 py-3 text-[11px] uppercase tracking-wider text-slate-600 bg-slate-50 border-b">
              <div>Pedido</div>
              <div>Fecha</div>
              <div>Cliente</div>
              <div>Total</div>
              <div>Estado</div>
              <div>Último cambio</div>
              <div>Etiquetas</div>
              <div class="text-center">Art</div>
              <div>Entrega</div>
              <div>Forma</div>
              <div class="text-right">Ver</div>
            </div>

            <!-- ROWS -->
            <div id="tablaPedidos" class="divide-y"></div>
          </div>

          <!-- ✅ MOBILE/TABLET: cards -->
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
            <span id="pillPagina"
                  class="px-4 py-2 rounded-2xl bg-white border border-slate-200 font-extrabold text-sm">
              Página 1
            </span>
            <span id="pillPaginaTotal"
                  class="px-4 py-2 rounded-2xl bg-slate-50 border border-slate-200 font-bold text-sm">
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

  <!-- MODALES -->
  <?= view('layouts/modales_estados', ['etiquetasPredeterminadas' => $etiquetasPredeterminadas]) ?>

  <!-- LOADER -->
  <div id="globalLoader"
       class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-50">
    <div class="bg-white p-6 rounded-3xl shadow-xl text-center animate-fadeIn">
      <div class="w-10 h-10 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto"></div>
      <p class="mt-3 font-semibold">Cargando...</p>
    </div>
  </div>

  <script>
    window.etiquetasPredeterminadas = <?= json_encode($etiquetasPredeterminadas) ?>;
  </script>

  <!-- ✅ Romper caché -->
  <script src="<?= base_url('js/dashboard.js?v=' . time()) ?>"></script>

  <!-- ✅ Toggle menú (sin depender de tu view menu) -->
  <script>
    (function () {
      const main = document.getElementById('mainLayout');
      const btn = document.getElementById('btnToggleMenu');

      function apply(state) {
        if (!main) return;
        if (state) main.classList.add('menu-collapsed');
        else main.classList.remove('menu-collapsed');
      }

      const saved = localStorage.getItem('menuCollapsed') === '1';
      apply(saved);

      if (btn) {
        btn.addEventListener('click', () => {
          const next = !main.classList.contains('menu-collapsed');
          apply(next);
          localStorage.setItem('menuCollapsed', next ? '1' : '0');
          window.dispatchEvent(new Event('resize')); // recalcula layouts
        });
      }

      // Si tu menú lateral tiene un botón propio, puedes disparar:
      // localStorage.setItem('menuCollapsed','1/0') y llamar apply(...)
      window.setMenuCollapsed = function (v) {
        apply(!!v);
        localStorage.setItem('menuCollapsed', v ? '1' : '0');
        window.dispatchEvent(new Event('resize'));
      };
    })();
  </script>
</body>
</html>
