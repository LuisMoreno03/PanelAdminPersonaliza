<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="csrf-token" content="<?= csrf_hash() ?>">
  <meta name="csrf-name" content="<?= csrf_token() ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Placas - Panel</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/alpinejs" defer></script>

  <style>
    body { background:#f3f4f6; }
    .btn-blue{
      background:#2563eb;color:#fff;padding:10px 16px;border-radius:12px;font-weight:700;border:none;
      cursor:pointer;transition:.15s;display:inline-flex;align-items:center;gap:8px;
    }
    .btn-blue:hover{ filter:brightness(1.06); }
    .btn-blue:disabled{ opacity:.55; cursor:not-allowed; }
    .card{ background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:16px; }
    .muted{ color:#6b7280;font-size:13px; }
    .lotes-grid{ display:grid; gap:14px; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); margin-top:12px; }
    .lote-card{ background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:14px;transition:.15s; }
    .lote-card:hover{ box-shadow:0 10px 30px rgba(0,0,0,.06); transform: translateY(-1px); }
    .lote-left{ display:flex; align-items:center; gap:12px; min-width:0; }
    .lote-thumb{ width:64px;height:64px;border-radius:14px;border:1px solid #eee;background:#f9fafb;overflow:hidden;display:flex;align-items:center;justify-content:center;flex:0 0 auto; }
    .lote-thumb img{ width:100%;height:100%;object-fit:cover; }
    .lote-title{ font-weight:900; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:220px; }
    .lote-meta{ color:#6b7280;font-size:12px;margin-top:2px; }
    .lote-actions{ margin-top:12px; display:flex; gap:10px; flex-wrap:wrap; }
    .lote-actions .btn-blue, .lote-actions a.btn-blue{ width:100%; justify-content:center; text-decoration:none; }

    .modal-backdrop{ position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9999; display:none; }
    .modal-box{ max-width:860px; margin:4vh auto; background:#fff; border-radius:18px; overflow:hidden; }
  </style>
</head>

<body class="flex">
<?= view('layouts/menu') ?>

<div class="flex-1 md:ml-64 p-8">
  <div class="card">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-black">PLACAS</h1>
        <div class="muted mt-1">Placas hoy: <span id="placasHoy" class="font-black">0</span></div>
      </div>

      <div class="flex flex-wrap items-center gap-2 w-full md:w-auto">
        <div class="relative w-full md:w-[380px]">
          <input id="searchInput" type="text" placeholder="Buscar por lote, archivo o pedido..."
            class="w-full border border-gray-200 rounded-xl px-4 py-2 pr-10 outline-none focus:ring-2 focus:ring-blue-200">
          <button id="searchClear" type="button"
            class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700 hidden">✕</button>
        </div>

        <button id="btnAbrirModalCarga" class="btn-blue whitespace-nowrap">Cargar placa</button>
      </div>
    </div>

    <div id="msg" class="muted mt-2"></div>
    <div id="contenedorDias" class="space-y-6 mt-4"></div>
  </div>
</div>

<!-- MODAL EDITAR -->
<div id="modalBackdrop" class="modal-backdrop">
  <div class="modal-box">
    <div class="flex justify-between items-center px-5 py-4 border-b">
      <div class="font-black text-lg">Editar placa</div>
      <button id="modalClose" class="btn-blue" style="background:#111827;">Cerrar</button>
    </div>

    <div class="p-5 grid grid-cols-1 lg:grid-cols-2 gap-4">
      <div class="border rounded-2xl p-3 bg-gray-50">
        <div id="modalPreview" class="w-full h-[320px] border rounded-2xl overflow-hidden bg-white"></div>

        <div class="mt-3 grid grid-cols-1 gap-2">
          <div>
            <div class="text-sm text-gray-600">Nombre</div>
            <input id="modalNombre" class="w-full border rounded-xl px-3 py-2" />
          </div>
          <div class="text-sm text-gray-600">Fecha de subida: <span id="modalFecha" class="font-black"></span></div>

          <div class="flex gap-2 justify-end flex-wrap mt-1">
            <button id="btnGuardarNombre" class="btn-blue">Guardar</button>
            <button id="btnDescargarPngSel" class="btn-blue" style="background:#10b981;">PNG</button>
            <button id="btnDescargarJpgSel" class="btn-blue" style="background:#0ea5e9;">JPG</button>
            <button id="btnEliminarArchivo" class="btn-blue" style="background:#ef4444;">Eliminar</button>
          </div>

          <div id="modalMsg" class="text-sm text-gray-500 mt-1"></div>
        </div>
      </div>

      <div class="space-y-4">
        <div class="border rounded-2xl p-3">
          <div class="flex items-center justify-between gap-2 flex-wrap">
            <div class="font-black">Archivos del lote</div>
            <div class="flex items-center gap-2 flex-wrap">
              <div class="muted" id="modalLoteInfo"></div>
              <button id="btnRenombrarLote" class="btn-blue" style="background:#f59e0b;">Cambiar nombre del lote</button>
            </div>
          </div>

          <div id="modalArchivos" class="mt-3 max-h-[240px] overflow-auto grid gap-2"></div>
        </div>

        <div class="border rounded-2xl p-3 bg-gray-50">
          <div class="font-black">Pedidos asignados a esta placa</div>
          <div id="modalPedidos" class="mt-2 grid gap-2"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- MODAL CARGA -->
<!-- ✅ MODAL CARGA (TAILWIND, RESPONSIVE) -->
<div id="modalCargaBackdrop"
     class="fixed inset-0 z-[10000] hidden bg-black/50 p-2 sm:p-6">

  <div class="mx-auto flex h-[96vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
    <!-- Header -->
    <div class="flex items-start justify-between gap-4 border-b px-5 py-4 sm:px-7">
      <div>
        <h2 class="text-lg sm:text-xl font-black text-gray-900">Cargar placa</h2>
        <p class="mt-1 text-xs sm:text-sm text-gray-500">
          Selecciona pedidos en <b>“Por producir”</b> y sube archivos. Se guardan en la placa.
        </p>
      </div>

      <button id="btnCerrarCarga"
        class="rounded-xl bg-gray-100 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200">
        Cerrar
      </button>
    </div>

    <!-- Body scroll -->
    <div class="flex-1 overflow-auto px-5 py-5 sm:px-7">
      <!-- Campos -->
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div>
          <label class="mb-1 block text-xs font-semibold text-gray-700">Nombre del lote</label>
          <input id="cargaLoteNombre" type="text" placeholder="Ej: Lote-01"
            class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
        </div>
        <div>
          <label class="mb-1 block text-xs font-semibold text-gray-700">Número de placa / nota</label>
          <input id="cargaNumero" type="text" placeholder="Ej: 01 / Observación"
            class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
        </div>
      </div>

      <!-- Contenido principal -->
      <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">

        <!-- Pedidos -->
        <section class="rounded-2xl border border-gray-200 bg-white p-4">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
              <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg bg-emerald-50 text-emerald-700">✓</span>
              <h3 class="font-extrabold text-gray-900">
                Pedidos <span class="text-gray-500 font-bold">(estado interno: Por producir)</span>
              </h3>
            </div>
            <div class="w-full sm:w-72">
              <input id="cargaBuscarPedido" type="text" placeholder="Buscar #PEDIDO, cliente..."
                class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
            </div>
          </div>

          <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div>
              <div class="text-xs font-bold text-gray-700">Lista</div>
              <div id="cargaPedidosLista" class="mt-2 h-72 overflow-auto rounded-xl border border-gray-200 bg-gray-50 p-2">
                <div class="p-3 text-xs text-gray-500">Cargando pedidos…</div>
              </div>
              <div id="cargaPedidosFooter" class="mt-2 text-xs text-gray-500"></div>
            </div>

            <div>
              <div class="text-xs font-bold text-gray-700">Seleccionados</div>
              <div id="cargaPedidosSeleccionados" class="mt-2 h-72 overflow-auto rounded-xl border border-gray-200 bg-white p-2">
                <div class="p-3 text-xs text-gray-500">Selecciona pedidos de “Por producir”.</div>
              </div>
            </div>

            <div>
              <div class="text-xs font-bold text-gray-700">Vinculados (auto)</div>
              <div id="cargaPedidosVinculados" class="mt-2 h-72 overflow-auto rounded-xl border border-gray-200 bg-white p-2">
                <div class="p-3 text-xs text-gray-500">Al seleccionar pedidos, aquí aparecen vinculados.</div>
              </div>
            </div>
          </div>
        </section>

        <!-- Archivos -->
        <section class="rounded-2xl border border-gray-200 bg-white p-4">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
              <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg bg-blue-50 text-blue-700">⬆</span>
              <h3 class="font-extrabold text-gray-900">Archivos</h3>
            </div>
            <div id="cargaArchivosCount" class="text-xs font-semibold text-gray-500">0 archivo(s)</div>
          </div>

          <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <input id="cargaArchivo" type="file" multiple class="w-full text-sm" accept="*/*">
            <div class="text-xs text-gray-500">Cualquier formato.</div>
          </div>

          <div id="cargaPreview"
               class="mt-3 flex h-[360px] items-center justify-center rounded-2xl border border-gray-200 bg-gray-50 text-sm text-gray-400">
            Vista previa
          </div>

          <div id="uploadProgressWrap" class="mt-3 hidden">
            <div class="h-3 w-full overflow-hidden rounded-full border border-gray-200 bg-gray-100">
              <div id="uploadProgressBar" class="h-3 rounded-full bg-blue-600 transition-[width] duration-150" style="width:0%"></div>
            </div>
            <div class="mt-2 flex items-center justify-between text-xs text-gray-500">
              <span id="uploadProgressLabel">Subiendo…</span>
              <span id="uploadProgressText" class="font-black">0%</span>
            </div>
          </div>

          <div id="cargaMsg" class="mt-2 text-sm text-gray-600"></div>
        </section>

      </div>
    </div>

    <!-- Footer fijo -->
    <div class="flex items-center justify-end gap-2 border-t bg-white px-5 py-4 sm:px-7">
      <button id="btnCancelarCarga"
        class="rounded-xl bg-gray-100 px-4 py-2 text-sm font-bold text-gray-700 hover:bg-gray-200">
        Cancelar
      </button>

      <button id="btnGuardarCarga"
        class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-extrabold text-white hover:bg-blue-700">
        Guardar
      </button>
    </div>
  </div>
</div>



<script>
window.PLACAS_CONFIG = {
  csrf: {
    name: document.querySelector('meta[name="csrf-name"]')?.getAttribute('content'),
    hash: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
  },
  api: {
    listar: <?= json_encode(site_url('placas/archivos/listar-por-dia')) ?>,
    stats:  <?= json_encode(site_url('placas/archivos/stats')) ?>,
    pedidos: <?= json_encode(site_url('placas/archivos/productos-por-producir')) ?>,
    subir:  <?= json_encode(site_url('placas/archivos/subir-lote')) ?>,
    renombrar: <?= json_encode(site_url('placas/archivos/renombrar')) ?>,
    eliminar:   <?= json_encode(site_url('placas/archivos/eliminar')) ?>,
    descargarPngLote: <?= json_encode(site_url('placas/archivos/descargar-png-lote')) ?>,
    descargarJpgLote: <?= json_encode(site_url('placas/archivos/descargar-jpg-lote')) ?>,
    descargarJpg: <?= json_encode(site_url('placas/archivos/descargar-jpg')) ?>,
    descargarPng: <?= json_encode(site_url('placas/archivos/descargar-png')) ?>,
    renombrarLote: <?= json_encode(site_url('placas/archivos/lote/renombrar')) ?>,
  }
};
</script>
<script>
  window.PLACAS_API = {
    subir: <?= json_encode(site_url('placas/archivos/subir-lote')) ?>,
    pedidosPorProducir: <?= json_encode(site_url('pedidos?page=1&estado=Por%20producir')) ?>
  };
</script>

<script src="<?= base_url('js/placas.js?v=' . time()) ?>" defer></script>

<script src="<?= base_url('js/placas.js?v=' . time()) ?>"></script>
</body>
</html>
