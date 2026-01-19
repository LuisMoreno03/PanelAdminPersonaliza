<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= csrf_hash() ?>">
  <meta name="csrf-header" content="<?= csrf_header() ?>">
  <script src="https://cdn.tailwindcss.com"></script>
  <title>Confirmación</title>
</head>

<body class="min-h-screen bg-slate-50">
  <?= view('layouts/menu') ?>

  <main id="mainLayout" class="layout">
    <div class="p-4 sm:p-6 lg:p-8">
      <div class="mx-auto w-full max-w-[1600px]">

        <section class="mb-6">
          <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5 flex items-start justify-between gap-4">
            <div>
              <h1 class="text-3xl font-extrabold text-slate-900">Confirmación</h1>
              <p class="text-slate-500 mt-1">Trae pedidos en estado <b>Por preparar</b> con entrega <b>Sin preparar</b>. Express primero.</p>
            </div>

            <div class="flex items-center gap-2">
              <select id="limitSelect" class="px-3 py-2 rounded-2xl border border-slate-200 bg-white font-extrabold">
                <option value="5">5 pedidos</option>
                <option value="10" selected>10 pedidos</option>
              </select>

              <button id="btnPull"
                class="px-4 py-2 rounded-2xl bg-slate-900 text-white font-extrabold hover:bg-slate-800">
                Pull 1 pedido
              </button>
            </div>
          </div>
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
          <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between">
            <div class="font-semibold text-slate-900">Mi cola (Por preparar)</div>
            <div class="text-xs text-slate-500">Express primero</div>
          </div>

          <!-- header simple (como prod) -->
          <div class="px-4 py-3 text-[11px] uppercase tracking-wider text-slate-600 bg-slate-50 border-b grid grid-cols-6 gap-3">
            <div>Pedido</div>
            <div>Fecha</div>
            <div>Cliente</div>
            <div>Total</div>
            <div>Estado</div>
            <div class="text-right">Ver</div>
          </div>

          <div id="confirmacionEmpty" class="hidden p-8 text-center text-slate-500">
            No hay pedidos en tu cola.
          </div>

          <div id="confirmacionList" class="divide-y"></div>
        </section>

      </div>
    </div>
  </main>

  <!-- ✅ REUSAR el MISMO modal de detalles del dashboard -->
  <?= view('layouts/modal_detalles') ?>

  <!-- LOADER -->
  <div id="globalLoader" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-50">
    <div class="bg-white p-6 rounded-3xl shadow-xl text-center">
      <div class="w-10 h-10 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto"></div>
      <p class="mt-3 font-semibold">Cargando...</p>
    </div>
  </div>

  <script>
    window.API_CONFIRMACION = {
      myQueue: "<?= site_url('confirmacion/my-queue') ?>",
      pull: "<?= site_url('confirmacion/pull') ?>",
    };
  </script>

  <!-- ✅ Carga dashboard.js para tener window.verDetalles (MISMA vista de detalles) -->
  <script src="<?= base_url('js/dashboard.js?v=' . time()) ?>"></script>

  <!-- ✅ tu confirmacion.js encapsulado -->
  <script src="<?= base_url('js/confirmacion.js?v=' . time()) ?>"></script>
</body>
</html>
