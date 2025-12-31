<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1"> <!-- ✅ responsive real -->
  <title>Dashboard - Panel</title>

  <!-- Tailwind -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/alpinejs" defer></script>

  <style>
    body { background: #f3f4f6; }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(6px) scale(0.99); }
      to   { opacity: 1; transform: translateY(0) scale(1); }
    }
    .animate-fadeIn { animation: fadeIn .18s ease-out; }

    /* Scrollbar suave dentro de la tabla */
    .soft-scroll::-webkit-scrollbar { height: 10px; }
    .soft-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
    .soft-scroll::-webkit-scrollbar-track { background: #eef2ff; border-radius: 999px; }

    /* Sticky header */
    thead th {
      position: sticky;
      top: 0;
      z-index: 10;
      background: #f8fafc;
    }

    /* Mejor “tap” en móvil */
    button { -webkit-tap-highlight-color: transparent; }
  </style>
</head>

<body class="flex min-h-screen">

  <!-- Sidebar -->
  <?= view('layouts/menu') ?>

  <!-- Contenido principal -->
  <main class="flex-1 md:ml-64 p-4 sm:p-6 lg:p-8">

    <!-- Header principal -->
    <section class="bg-white rounded-2xl shadow-sm border border-slate-200 p-4 sm:p-5 mb-4 sm:mb-6">
      <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div>
          <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">Pedidos</h1>
          <p class="text-slate-500 mt-1 text-sm sm:text-base">
            Vista rápida de estados, etiquetas y cambios recientes.
          </p>
        </div>

        <div class="flex items-center gap-3">
          <div class="px-4 py-3 rounded-2xl bg-slate-50 border border-slate-200 w-full sm:w-auto">
            <div class="text-[11px] uppercase tracking-wide text-slate-500">Pedidos cargados</div>
            <div class="text-2xl font-extrabold text-slate-900 leading-none">
              <span id="total-pedidos">0</span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Estado de usuarios (responsive + scroll si hay muchos) -->
    <section class="bg-white rounded-2xl shadow-sm border border-slate-200 p-4 sm:p-5 mb-4 sm:mb-6">
      <div class="flex items-center justify-between mb-3">
        <h3 class="text-base sm:text-lg font-extrabold text-slate-800">Estado de usuarios</h3>
        <div class="text-xs text-slate-500 hidden sm:block">Actualiza automáticamente</div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6">

        <!-- CONECTADOS -->
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50/40 p-4">
          <div class="flex items-center justify-between gap-2 mb-3">
            <div class="flex items-center gap-2">
              <span class="h-3 w-3 rounded-full bg-emerald-500"></span>
              <h4 class="font-bold text-emerald-800 text-sm sm:text-base">
                Conectados
              </h4>
            </div>

            <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-white border border-emerald-200 text-emerald-800">
              <span id="onlineCount">0</span>
            </span>
          </div>

          <ul id="onlineUsers" class="space-y-2 text-sm max-h-52 overflow-auto pr-1"></ul>
          <p class="text-xs text-emerald-700/80 mt-3">
            Verde = activo en los últimos 2 min
          </p>
        </div>

        <!-- DESCONECTADOS -->
        <div class="rounded-2xl border border-rose-200 bg-rose-50/40 p-4">
          <div class="flex items-center justify-between gap-2 mb-3">
            <div class="flex items-center gap-2">
              <span class="h-3 w-3 rounded-full bg-rose-500"></span>
              <h4 class="font-bold text-rose-800 text-sm sm:text-base">
                Desconectados
              </h4>
            </div>

            <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-white border border-rose-200 text-rose-800">
              <span id="offlineCount">0</span>
            </span>
          </div>

          <ul id="offlineUsers" class="space-y-2 text-sm max-h-52 overflow-auto pr-1"></ul>
          <p class="text-xs text-rose-700/80 mt-3">
            Rojo = sin actividad reciente
          </p>
        </div>

      </div>
    </section>

    <!-- Tabla -->
    <!-- Tabla / Cards (responsive sin scroll horizontal) -->
<section class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">

  <!-- Cabecera -->
  <div class="px-4 sm:px-5 py-4 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
    <div class="flex items-center gap-2">
      <span class="inline-flex h-2.5 w-2.5 rounded-full bg-blue-600"></span>
      <p class="font-semibold text-slate-800">Listado de pedidos</p>
    </div>

    <!-- Solo se muestra en móvil -->
    <p class="text-xs text-slate-500 md:hidden">
      Vista compacta (sin scroll horizontal)
    </p>
  </div>

  <!-- ✅ MOBILE: CARDS (sin scroll) -->
  <div class="md:hidden p-3">
    <div id="cardsPedidos" class="space-y-3"></div>
  </div>

  <!-- ✅ DESKTOP: TABLA -->
  <div class="hidden md:block overflow-x-auto soft-scroll">
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

      <tbody id="tablaPedidos"
             class="text-slate-800 text-sm leading-tight divide-y divide-slate-100">
      </tbody>
    </table>
  </div>

</section>


    <!-- Paginación -->
    <section class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 mt-5">
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

  <!-- =============================================================== -->
  <!-- 🟦 MODAL DETALLES DEL PEDIDO -->
  <!-- =============================================================== -->
  <div id="modalDetalles"
       class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50">

    <div class="bg-white w-[94%] sm:w-[92%] h-[92%] rounded-2xl shadow-2xl p-4 sm:p-6 overflow-hidden flex flex-col animate-fadeIn">

      <!-- HEADER -->
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

      <!-- CONTENIDO PRINCIPAL (PRODUCTOS) -->
      <div id="detalleProductos"
           class="flex-1 overflow-auto grid grid-cols-1 lg:grid-cols-2 gap-4 p-2 sm:p-4">
      </div>

      <!-- TOTALES -->
      <div id="detalleTotales" class="border-t pt-4 text-base sm:text-lg font-bold text-slate-900"></div>

    </div>
  </div>

  <!-- =============================================================== -->
  <!-- 🟩 PANEL LATERAL: INFO CLIENTE -->
  <!-- =============================================================== -->
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

      <h3 class="text-base sm:text-lg font-extrabold mt-6 text-slate-900">Resumen del pedido</h3>
      <div id="detalleResumen" class="space-y-1 mb-6"></div>

    </div>
  </div>

  <!-- MODALES -->
  <?= view('layouts/modales_estados', ['etiquetasPredeterminadas' => $etiquetasPredeterminadas]) ?>

  <!-- LOADER GLOBAL -->
  <div id="globalLoader"
       class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-[9999]">

    <div class="bg-white p-6 rounded-2xl shadow-xl text-center animate-fadeIn border border-slate-200">
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
