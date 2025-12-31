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

    /* Scrollbar suave */
    .soft-scroll::-webkit-scrollbar { height: 10px; width: 10px; }
    .soft-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
    .soft-scroll::-webkit-scrollbar-track { background: #eef2ff; border-radius: 999px; }

    /* Sticky header de tabla */
    thead th {
      position: sticky;
      top: 0;
      z-index: 10;
      background: #f8fafc;
    }

    button { -webkit-tap-highlight-color: transparent; }
  </style>
</head>

<body class="flex min-h-screen bg-gradient-to-b from-slate-50 to-slate-100">

  <!-- Sidebar -->
  <?= view('layouts/menu') ?>

  <!-- Contenido principal -->
  <main class="flex-1 md:ml-64 p-4 sm:p-6 lg:p-8">

    <!-- Top header -->
    <section class="mb-5 sm:mb-7">
      <div class="rounded-3xl border border-slate-200 bg-white/80 backdrop-blur shadow-sm p-4 sm:p-6">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">
              Pedidos
            </h1>
            <p class="text-slate-500 mt-1 text-sm sm:text-base">
              Control rápido: estados, etiquetas, últimos cambios y detalles.
            </p>
          </div>

          <!-- Métrica -->
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 w-full lg:w-auto">
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
              <div class="text-[11px] uppercase tracking-wider text-slate-500">
                Pedidos cargados
              </div>
              <div class="mt-1 flex items-end justify-between">
                <div class="text-3xl font-extrabold text-slate-900 leading-none">
                  <span id="total-pedidos">0</span>
                </div>
                <div class="text-xs text-slate-400">actual</div>
              </div>
            </div>

            <!-- Espacio para futura métrica si quieres (ej: Pendientes) -->
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
              <div class="text-[11px] uppercase tracking-wider text-slate-500">
                Estado general
              </div>
              <div class="mt-2 flex items-center gap-2">
                <span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
                <span class="text-sm font-semibold text-slate-800">
                  Panel activo
                </span>
              </div>
            </div>
          </div>

        </div>
      </div>
    </section>

    <!-- Estado de usuarios -->
    <section class="mb-5 sm:mb-7">
      <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-4 sm:p-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-4">
          <div>
            <h3 class="text-lg font-extrabold text-slate-900">Estado de usuarios</h3>
            <p class="text-sm text-slate-500">Conectados y desconectados en tiempo real.</p>
          </div>
          <div class="text-xs text-slate-400">Actualiza automáticamente</div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

          <!-- Conectados -->
          <div class="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <span class="h-3 w-3 rounded-full bg-emerald-500"></span>
                <h4 class="font-bold text-emerald-900">Conectados</h4>
              </div>
              <span class="px-3 py-1 rounded-full text-xs font-extrabold bg-white border border-emerald-200 text-emerald-900">
                <span id="onlineCount">0</span>
              </span>
            </div>

            <div class="mt-3 max-h-56 overflow-auto soft-scroll pr-1">
              <ul id="onlineUsers" class="space-y-2 text-sm"></ul>
            </div>

            <p class="mt-3 text-xs text-emerald-800/80">
              Verde = activo en los últimos 2 min
            </p>
          </div>

          <!-- Desconectados -->
          <div class="rounded-2xl border border-rose-200 bg-rose-50/50 p-4">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <span class="h-3 w-3 rounded-full bg-rose-500"></span>
                <h4 class="font-bold text-rose-900">Desconectados</h4>
              </div>
              <span class="px-3 py-1 rounded-full text-xs font-extrabold bg-white border border-rose-200 text-rose-900">
                <span id="offlineCount">0</span>
              </span>
            </div>

            <div class="mt-3 max-h-56 overflow-auto soft-scroll pr-1">
              <ul id="offlineUsers" class="space-y-2 text-sm"></ul>
            </div>

            <p class="mt-3 text-xs text-rose-800/80">
              Rojo = sin actividad reciente
            </p>
          </div>

        </div>
      </div>
    </section>

    <!-- Pedidos: Cards (móvil/tablet) + Tabla (solo lg+) -->
    <section class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
      <div class="px-4 sm:px-6 py-4 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
        <div class="flex items-center gap-2">
          <span class="inline-flex h-2.5 w-2.5 rounded-full bg-blue-600"></span>
          <p class="font-semibold text-slate-900">Listado de pedidos</p>
        </div>

        <div class="text-xs text-slate-500">
          <span class="lg:hidden">Vista en tarjetas (sin scroll horizontal)</span>
          <span class="hidden lg:inline">Vista en tabla</span>
        </div>
      </div>

      <!-- ✅ Cards: móvil + tablet (hasta lg) -->
      <div class="lg:hidden p-3 sm:p-4 bg-slate-50/40">
        <div id="cardsPedidos" class="space-y-3"></div>
      </div>

      <!-- ✅ Tabla: solo en pantallas grandes -->
      <div class="hidden lg:block overflow-x-auto soft-scroll">
        <table class="w-full text-left min-w-[1550px]">
          <thead class="text-[11px] uppercase tracking-wider text-slate-600">
            <tr class="border-b border-slate-200">
              <th class="py-3 px-4">Pedido</th>
              <th class="py-3 px-4">Fecha</th>
              <th class="py-3 px-4">Cliente</th>
              <th class="py-3 px-4">Total</th>
              <th class="py-3 px-2 w-44">Estado</th>
              <th class="py-3 px-4">Último cambio</th>
              <th class="py-3 px-2">Etiquetas</th>
              <th class="py-3 px-4">Artículos</th>
              <th class="py-3 px-4">Estado entrega</th>
              <th class="py-3 px-4">Forma entrega</th>
              <th class="py-3 px-4">Detalles</th>
            </tr>
          </thead>

          <tbody id="tablaPedidos" class="text-slate-800 text-sm leading-tight divide-y divide-slate-100"></tbody>
        </table>
      </div>
    </section>

    <!-- Paginación -->
    <section class="mt-5 sm:mt-6 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
      <button id="btnAnterior"
              disabled
              class="w-full sm:w-auto px-4 py-3 rounded-2xl bg-slate-200 text-slate-800 font-semibold
                     opacity-50 cursor-not-allowed transition">
        ← Anterior
      </button>

      <button id="btnSiguiente"
              onclick="paginaSiguiente()"
              class="w-full sm:w-auto px-4 py-3 rounded-2xl bg-blue-600 text-white font-semibold
                     hover:bg-blue-700 active:scale-[0.99] transition">
        Siguiente →
      </button>
    </section>

  </main>

  <!-- MODAL DETALLES -->
  <div id="modalDetalles"
       class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50">

    <div class="bg-white w-[94%] sm:w-[92%] h-[92%] rounded-3xl shadow-2xl p-4 sm:p-6 overflow-hidden flex flex-col animate-fadeIn">

      <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 border-b pb-4">
        <h2 id="tituloPedido" class="text-xl sm:text-2xl font-extrabold text-slate-900">
          Detalles del pedido
        </h2>

        <div class="flex flex-col sm:flex-row gap-2 sm:gap-3">
          <button onclick="abrirPanelCliente()"
                  class="px-4 py-3 bg-blue-600 text-white rounded-2xl font-semibold hover:bg-blue-700 transition">
            Información del cliente
          </button>

          <button onclick="cerrarModalDetalles()"
                  class="px-4 py-3 bg-slate-200 text-slate-800 rounded-2xl font-semibold hover:bg-slate-300 transition">
            Cerrar
          </button>
        </div>
      </div>

      <div id="detalleProductos"
           class="flex-1 overflow-auto grid grid-cols-1 lg:grid-cols-2 gap-4 p-2 sm:p-4">
      </div>

      <div id="detalleTotales" class="border-t pt-4 text-base sm:text-lg font-bold text-slate-900"></div>

    </div>
  </div>

  <!-- PANEL CLIENTE -->
  <div id="panelCliente"
       class="hidden fixed inset-0 flex justify-end bg-black/30 backdrop-blur-sm z-50">

    <div class="w-[92%] sm:w-[420px] h-full bg-white shadow-xl p-4 sm:p-6 overflow-y-auto animate-fadeIn">

      <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg sm:text-xl font-extrabold text-slate-900">Información del cliente</h3>
        <button onclick="cerrarPanelCliente()"
                class="text-slate-600 hover:text-slate-900 text-2xl font-bold">×</button>
      </div>

      <div id="detalleCliente" class="space-y-2 mb-6"></div>

      <h3 class="text-base sm:text-lg font-extrabold mt-6 text-slate-900">Dirección de envío</h3>
      <div id="detalleEnvio" class="space-y-1 mb-6"></div>

      <h3 class="text-base sm:text-lg font-extrabold mt-6 text-slate-900 learn">Resumen del pedido</h3>
      <div id="detalleResumen" class="space-y-1 mb-6"></div>

    </div>
  </div>

  <!-- MODALES -->
  <?= view('layouts/modales_estados', ['etiquetasPredeterminadas' => $etiquetasPredeterminadas]) ?>

  <!-- LOADER GLOBAL -->
  <div id="globalLoader"
       class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-[9999]">
    <div class="bg-white p-6 rounded-3xl shadow-xl text-center animate-fadeIn border border-slate-200">
      <div class="w-10 h-10 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto"></div>
      <p class="mt-3 font-semibold text-slate-700">Cargando...</p>
    </div>
  </div>

  <script>
    window.etiquetasPredeterminadas = <?= json_encode($etiquetasPredeterminadas) ?>;
  </script>

  <script src="<?= base_url('js/dashboard.js') ?>"></script>

</body>
</html>
