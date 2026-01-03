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

    thead th {
      position: sticky;
      top: 0;
      z-index: 10;
      background: #f8fafc;
    }
    button { -webkit-tap-highlight-color: transparent; }
  </style>
</head>

<body class="min-h-screen bg-gradient-to-b from-slate-50 to-slate-100 overflow-x-hidden">

  <!-- MENU -->
  <?= view('layouts/menu') ?>

  <main class="md:ml-64">
    <div class="p-4 sm:p-6 lg:p-8">
      <div class="mx-auto w-full max-w-[1400px]">

        <!-- HEADER -->
        <section class="mb-6">
          <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5">
            <h1 class="text-3xl font-extrabold text-slate-900">Pedidos</h1>
            <p class="text-slate-500 mt-1">Estados, etiquetas, últimos cambios y detalles</p>
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

        <!-- PEDIDOS RESPONSIVE SIN SCROLL -->
        <section class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
          <div class="px-4 py-3 border-b border-slate-200 font-semibold text-slate-900">
            Listado de pedidos
          </div>

          <!-- ✅ TABLE SOLO EN DESKTOP (lg+) -->
          <!-- En lg: tabla condensada (sin Último cambio / Forma entrega) -->
          <!-- En xl: tabla completa -->
          <div class="hidden lg:block w-full">
            <table class="w-full table-fixed">
              <thead class="text-[11px] uppercase tracking-wider text-slate-600">
                <tr class="border-b border-slate-200 bg-slate-50">
                  <th class="px-3 py-3 w-[120px]">Pedido</th>
                  <th class="px-3 py-3 w-[105px]">Fecha</th>
                  <th class="px-3 py-3 w-[200px]">Cliente</th>
                  <th class="px-3 py-3 w-[90px]">Total</th>
                  <th class="px-3 py-3 w-[150px]">Estado</th>

                  <!-- Solo XL -->
                  <th class="px-3 py-3 w-[140px] hidden xl:table-cell">Último cambio</th>

                  <th class="px-3 py-3 w-[240px]">Etiquetas</th>
                  <th class="px-3 py-3 w-[90px]">Artículos</th>
                  <th class="px-3 py-3 w-[170px]">Estado entrega</th>

                  <!-- Solo XL -->
                  <th class="px-3 py-3 w-[170px] hidden xl:table-cell">Forma entrega</th>

                  <th class="px-3 py-3 w-[110px] text-right">Detalles</th>
                </tr>
              </thead>

              <tbody id="tablaPedidos" class="text-slate-800 text-[13px] divide-y divide-slate-100"></tbody>
            </table>
          </div>

          <!-- ✅ CARDS EN MOVIL/TABLET (sin tabla) -->
          <div id="cardsPedidos" class="grid grid-cols-1 gap-4 p-4 lg:hidden"></div>
        </section>

        <!-- PAGINACIÓN -->
        <section class="mt-6 flex flex-col sm:flex-row items-center justify-between gap-4">
          <button id="btnAnterior"
                  disabled
                  class="w-full sm:w-auto px-5 py-3 rounded-2xl bg-slate-200 text-slate-700 font-bold opacity-50 cursor-not-allowed">
            ← Anterior
          </button>

          <div class="flex items-center justify-center gap-2">
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
    <div class="bg-white p-6 rounded-3xl shadow-xl text-center">
      <div class="w-10 h-10 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto"></div>
      <p class="mt-3 font-semibold">Cargando...</p>
    </div>
  </div>

  <script>
    window.etiquetasPredeterminadas = <?= json_encode($etiquetasPredeterminadas) ?>;
  </script>

  <!-- ✅ Romper caché para que SIEMPRE tome cambios -->
  <script src="<?= base_url('js/dashboard.js?v=' . time()) ?>"></script>
</body>
</html>
