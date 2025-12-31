<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Dashboard - Panel</title>

  <!-- Tailwind -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/alpinejs" defer></script>

  <style>
    body { background: #f3f4f6; }

    @keyframes fadeIn {
      from { opacity: 0; transform: scale(0.98); }
      to   { opacity: 1; transform: scale(1); }
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
      background: #f8fafc; /* slate-50 */
    }
  </style>
</head>

<body class="flex">

  <!-- Sidebar -->
  <?= view('layouts/menu') ?>

  <!-- Contenido principal -->
  <div class="flex-1 md:ml-64 p-6 md:p-8">

    <!-- Barra superior (más visible) -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-5 mb-6">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div>
          <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">Pedidos</h1>
          <p class="text-slate-500 mt-1">
            Vista rápida de estados, etiquetas y cambios recientes.
          </p>
        </div>

        <div class="flex items-center gap-3">
          <div class="px-4 py-2 rounded-xl bg-slate-50 border border-slate-200">
            <div class="text-xs uppercase tracking-wide text-slate-500">Pedidos cargados</div>
            <div class="text-2xl font-extrabold text-slate-900">
              <span id="total-pedidos">0</span>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- BLOQUE ESTADO DE USUARIOS -->
<!-- ESTADO DE USUARIOS -->
<div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-5 mb-6">

  <h3 class="text-lg font-extrabold text-slate-800 mb-4">
    Estado de usuarios
  </h3>

  <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

    <!-- CONECTADOS -->
    <div>
      <div class="flex items-center gap-2 mb-2">
        <span class="h-3 w-3 rounded-full bg-emerald-500"></span>
        <h4 class="font-bold text-emerald-700">
          Conectados (<span id="onlineCount">0</span>)
        </h4>
      </div>

      <ul id="onlineUsers" class="space-y-2 text-sm"></ul>
    </div>

    <!-- DESCONECTADOS -->
    <div>
      <div class="flex items-center gap-2 mb-2">
        <span class="h-3 w-3 rounded-full bg-rose-500"></span>
        <h4 class="font-bold text-rose-700">
          Desconectados (<span id="offlineCount">0</span>)
        </h4>
      </div>

      <ul id="offlineUsers" class="space-y-2 text-sm"></ul>
    </div>

  </div>
</div>


    <!-- TABLA (mejorada) -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
      <!-- header de tabla -->
      <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
        <div class="flex items-center gap-2">
          <span class="inline-flex h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
          <p class="font-semibold text-slate-800">Listado de pedidos</p>
        </div>
        <p class="text-sm text-slate-500">Desliza horizontalmente si hace falta</p>
      </div>

      <div class="overflow-x-auto soft-scroll">
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

          <!--
            🔥 Tip: “divide-y” hace las filas más legibles
            “text-sm” y “leading-tight” da lectura rápida
          -->
          <tbody id="tablaPedidos"
                 class="text-slate-800 text-sm leading-tight divide-y divide-slate-100">
          </tbody>
        </table>
      </div>
    </div>

    <!-- PAGINACIÓN (más clara) -->
    <div class="flex items-center justify-between mt-5">
      <button id="btnAnterior"
              disabled
              class="px-4 py-2 rounded-xl bg-slate-200 text-slate-800 font-semibold
                     opacity-50 cursor-not-allowed transition">
        ← Anterior
      </button>

      <button id="btnSiguiente"
              onclick="paginaSiguiente()"
              class="px-4 py-2 rounded-xl bg-blue-600 text-white font-semibold
                     hover:bg-blue-700 active:scale-[0.99] transition">
        Siguiente →
      </button>
    </div>

  </div>

  <!-- =============================================================== -->
  <!-- 🟦 MODAL DETALLES DEL PEDIDO (ANCHO COMPLETO) -->
  <!-- =============================================================== -->
  <div id="modalDetalles"
       class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50">

    <div class="bg-white w-[92%] h-[92%] rounded-2xl shadow-2xl p-6 overflow-hidden flex flex-col animate-fadeIn">

      <!-- HEADER -->
      <div class="flex justify-between items-center border-b pb-4">
        <h2 id="tituloPedido" class="text-2xl font-extrabold text-slate-900">
          Detalles del pedido
        </h2>

        <div class="flex gap-3">
          <button onclick="abrirPanelCliente()"
                  class="px-4 py-2 bg-blue-600 text-white rounded-xl font-semibold hover:bg-blue-700 transition">
            Información del cliente
          </button>

          <button onclick="cerrarModalDetalles()"
                  class="px-4 py-2 bg-slate-200 text-slate-800 rounded-xl font-semibold hover:bg-slate-300 transition">
            Cerrar
          </button>
        </div>
      </div>

      <!-- CONTENIDO PRINCIPAL (PRODUCTOS) -->
      <div id="detalleProductos"
           class="flex-1 overflow-auto grid grid-cols-1 md:grid-cols-2 gap-4 p-4">
      </div>

      <!-- TOTALES -->
      <div id="detalleTotales" class="border-t pt-4 text-lg font-bold text-slate-900"></div>

    </div>
  </div>

  <!-- =============================================================== -->
  <!-- 🟩 PANEL LATERAL: INFORMACIÓN DEL CLIENTE -->
  <!-- =============================================================== -->
  <div id="panelCliente"
       class="hidden fixed inset-0 flex justify-end bg-black/30 backdrop-blur-sm z-50">

    <div class="w-[400px] h-full bg-white shadow-xl p-6 overflow-y-auto animate-fadeIn">

      <div class="flex justify-between items-center mb-4">
        <h3 class="text-xl font-extrabold text-slate-900">Información del cliente</h3>

        <button onclick="cerrarPanelCliente()"
                class="text-slate-600 hover:text-slate-900 text-2xl font-bold">×</button>
      </div>

      <div id="detalleCliente" class="space-y-2 mb-6"></div>

      <h3 class="text-lg font-extrabold mt-6 text-slate-900">Dirección de envío</h3>
      <div id="detalleEnvio" class="space-y-1 mb-6"></div>

      <h3 class="text-lg font-extrabold mt-6 text-slate-900">Resumen del pedido</h3>
      <div id="detalleResumen" class="space-y-1 mb-6"></div>

    </div>
  </div>

  <!-- =============================================================== -->
  <!-- MODALES DE ESTADOS + ETIQUETAS -->
  <!-- =============================================================== -->
  <?= view('layouts/modales_estados', ['etiquetasPredeterminadas' => $etiquetasPredeterminadas]) ?>

  <!-- =============================================================== -->
  <!-- LOADER GLOBAL -->
  <!-- =============================================================== -->
  <div id="globalLoader"
       class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-[9999]">

    <div class="bg-white p-6 rounded-2xl shadow-xl text-center animate-fadeIn border border-slate-200">
      <div class="w-10 h-10 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto"></div>
      <p class="mt-3 font-semibold text-slate-700">Cargando...</p>
    </div>
  </div>

  <!-- PASAR ETIQUETAS AL JS -->
  <script>
    window.etiquetasPredeterminadas = <?= json_encode($etiquetasPredeterminadas) ?>;
  </script>

  <!-- SCRIPT PRINCIPAL -->
  <script src="<?= base_url('js/dashboard.js') ?>"></script>

</body>
</html>
