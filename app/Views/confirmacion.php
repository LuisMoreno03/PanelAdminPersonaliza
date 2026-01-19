<?php
// confirmacion.php
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Confirmación · Panel</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- CSRF (si tu backend lo usa) -->
  <?php if (isset($csrfToken, $csrfHeader)): ?>
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <meta name="csrf-header" content="<?= htmlspecialchars($csrfHeader) ?>">
  <?php endif; ?>

  <!-- Tailwind (CDN como el resto del panel) -->
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- API endpoints -->
  <script>
    window.API = {
      myQueue: "/confirmacion/cola",
      pull: "/confirmacion/pull",
      returnAll: "/confirmacion/devolver",
      detalles: "/dashboard/detalles"
    };
  </script>
</head>

<body class="bg-slate-100 text-slate-900">

<!-- LOADER GLOBAL -->
<div id="globalLoader" class="hidden fixed inset-0 z-50 bg-black/30 flex items-center justify-center">
  <div class="bg-white px-6 py-4 rounded-2xl shadow-xl font-extrabold">
    Cargando…
  </div>
</div>

<!-- CONTENEDOR -->
<div class="max-w-[1600px] mx-auto p-6">

  <!-- HEADER -->
  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-extrabold">Confirmación</h1>
      <p class="text-slate-500 text-sm">Pedidos asignados en estado <b>Por preparar</b></p>
    </div>

    <div class="flex gap-2">
      <button id="btnTraer5"
        class="px-4 py-2 rounded-xl bg-slate-900 text-white font-extrabold hover:bg-slate-800">
        Traer 5
      </button>
      <button id="btnTraer10"
        class="px-4 py-2 rounded-xl bg-slate-900 text-white font-extrabold hover:bg-slate-800">
        Traer 10
      </button>
      <button id="btnDevolver"
        class="px-4 py-2 rounded-xl bg-rose-600 text-white font-extrabold hover:bg-rose-700">
        Devolver
      </button>
    </div>
  </div>

  <!-- LISTADO -->
  <div class="bg-white rounded-3xl shadow-sm overflow-hidden">
    <div class="px-4 py-3 border-b bg-slate-50 text-xs font-extrabold uppercase grid grid-cols-11 gap-2">
      <div>Pedido</div>
      <div>Fecha</div>
      <div>Cliente</div>
      <div>Total</div>
      <div>Estado</div>
      <div>Últ. cambio</div>
      <div>Etiquetas</div>
      <div class="text-center">Art.</div>
      <div>Entrega</div>
      <div>Método</div>
      <div class="text-right">Ver</div>
    </div>

    <div id="tablaPedidos"></div>
  </div>

  <!-- FOOTER -->
  <div class="mt-4 text-sm text-slate-600">
    Total pedidos: <b id="total-pedidos">0</b>
  </div>
</div>

<!-- =====================================================
  MODAL DETALLES FULL
===================================================== -->
<div id="modalDetallesFull"
     class="hidden fixed inset-0 z-50 bg-black/40 flex items-start justify-center overflow-y-auto">

  <div class="bg-white w-full max-w-6xl my-10 rounded-3xl shadow-xl">

    <!-- HEADER -->
    <div class="flex items-center justify-between px-6 py-4 border-b">
      <div>
        <h2 id="detTitle" class="text-xl font-extrabold">—</h2>
        <p id="detSubtitle" class="text-sm text-slate-500">—</p>
      </div>

      <div class="flex gap-2">
        <button onclick="document.getElementById('detJson').classList.toggle('hidden')"
          class="px-3 py-2 rounded-xl border text-xs font-extrabold">
          JSON
        </button>
        <button onclick="document.getElementById('modalDetallesFull').classList.add('hidden');document.body.classList.remove('overflow-hidden')"
          class="px-4 py-2 rounded-xl bg-slate-900 text-white font-extrabold">
          ✕
        </button>
      </div>
    </div>

    <!-- BODY -->
    <div class="p-6 space-y-6">

      <!-- RESUMEN -->
      <div id="detResumen" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4"></div>

      <!-- CLIENTE / ENVÍO -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
          <h3 class="font-extrabold mb-2">Cliente</h3>
          <div id="detCliente"></div>
        </div>
        <div>
          <h3 class="font-extrabold mb-2">Envío</h3>
          <div id="detEnvio"></div>
        </div>
      </div>

      <!-- PRODUCTOS -->
      <div>
        <h3 class="font-extrabold mb-2">
          Productos (<span id="detItemsCount">0</span>)
        </h3>
        <div id="detItems" class="space-y-4"></div>
      </div>

      <!-- TOTALES -->
      <div>
        <h3 class="font-extrabold mb-2">Totales</h3>
        <div id="detTotales"></div>
      </div>

      <!-- JSON DEBUG -->
      <pre id="detJson"
           class="hidden bg-slate-900 text-emerald-200 text-xs p-4 rounded-2xl overflow-auto max-h-[400px]"></pre>
    </div>
  </div>
</div>

<!-- JS -->
<script src="/assets/js/confirmacion.js?v=<?= time() ?>"></script>

</body>
</html>
