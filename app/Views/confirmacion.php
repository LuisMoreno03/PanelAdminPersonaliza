<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Confirmación</title>

  <meta name="csrf-token" content="<?= csrf_hash() ?>">
  <meta name="csrf-header" content="<?= csrf_header() ?>">

  <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-100">

<?= view('layouts/menu') ?>

<main class="layout">
  <div class="p-6 max-w-[1400px] mx-auto">

    <!-- HEADER -->
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-3xl font-extrabold text-slate-900">Confirmación</h1>
        <p class="text-slate-500">
          Pedidos <b>Por preparar</b> · Entrega <b>Sin preparar</b> · Express primero
        </p>
      </div>

      <div class="flex items-center gap-3">
        <select id="limitSelect"
                class="rounded-xl border border-slate-300 px-3 py-2 font-bold">
          <option value="5">5 pedidos</option>
          <option value="10" selected>10 pedidos</option>
        </select>

        <button id="btnPull"
          class="px-4 py-2 rounded-xl bg-slate-900 text-white font-extrabold hover:bg-slate-800">
          Pull 1 pedido
        </button>
      </div>
    </div>

    <!-- LISTADO -->
    <section class="rounded-3xl border border-slate-200 bg-white overflow-hidden shadow-sm">

      <div class="grid grid-cols-[140px_120px_1fr_120px_160px_120px]
                  px-4 py-3 bg-slate-50 text-xs font-extrabold uppercase text-slate-600">
        <div>Pedido</div>
        <div>Fecha</div>
        <div>Cliente</div>
        <div>Total</div>
        <div>Estado</div>
        <div class="text-right">Ver</div>
      </div>

      <div id="confirmacionList" class="divide-y"></div>

      <div id="confirmacionEmpty"
           class="hidden p-6 text-center text-slate-500 font-semibold">
        No hay pedidos por confirmar
      </div>

    </section>
  </div>
</main>

<!-- MODAL DETALLES (REUTILIZADO DEL DASHBOARD) -->
<?= view('layouts/modal_detalles') ?>

<script>
window.API_CONFIRMACION = {
  myQueue: "<?= site_url('confirmacion/my-queue') ?>",
  pull: "<?= site_url('confirmacion/pull') ?>"
};
</script>

<script src="<?= base_url('js/confirmacion.js?v=' . time()) ?>"></script>

</body>
</html>
