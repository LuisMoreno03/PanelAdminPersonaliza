<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="csrf-token" content="<?= csrf_hash() ?>">
  <meta name="csrf-name" content="<?= csrf_token() ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Placas - Panel</title>

  <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 flex">
  <?= view('layouts/menu') ?>

  <div class="flex-1 md:ml-64 p-6">
    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-2xl font-black text-gray-900">PLACAS</h1>
          <div class="mt-1 text-sm text-gray-500">
            Placas hoy: <span id="placasHoy" class="font-black text-gray-900">0</span>
          </div>
        </div>

        <div class="flex w-full flex-wrap items-center gap-2 md:w-auto">
          <div class="relative w-full md:w-[360px]">
            <input id="searchInput" type="text" placeholder="Buscar por lote, archivo o pedido..."
              class="w-full rounded-xl border border-gray-200 px-4 py-2 pr-10 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
            <button id="searchClear" type="button"
              class="absolute right-2 top-1/2 -translate-y-1/2 hidden rounded-lg px-2 py-1 text-gray-400 hover:text-gray-700">
              ✕
            </button>
          </div>

          <button id="btnAbrirModalCarga"
            class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-extrabold text-white hover:bg-blue-700">
            Cargar placa
          </button>
        </div>
      </div>

      <div id="msg" class="mt-2 text-sm text-gray-500"></div>

      <div id="contenedorDias" class="mt-4 space-y-6"></div>
    </div>
  </div>

  <!-- ✅ MODAL CARGA FULL -->
  <?= view('placas_modal_carga') ?>

  <script>
    window.PLACAS_API = {
      listarPorDia: <?= json_encode(site_url('placas/archivos/listar-por-dia')) ?>,
      stats: <?= json_encode(site_url('placas/archivos/stats')) ?>,
      subirLote: <?= json_encode(site_url('placas/archivos/subir-lote')) ?>,
      pedidosPorProducir: <?= json_encode(site_url('placas/pedidos/por-producir')) ?>,
    };
  </script>

  <script src="<?= base_url('js/placas.js?v=' . time()) ?>" defer></script>
</body>
</html>
