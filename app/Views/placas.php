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
    body { background: #f3f4f6; }

    .btn-blue{
      background:#2563eb;
      color:#fff;
      padding:10px 16px;
      border-radius:12px;
      font-weight:800;
      border:none;
      cursor:pointer;
      transition:.15s;
      display:inline-flex;
      align-items:center;
      gap:8px;
    }
    .btn-blue:hover{ filter:brightness(1.06); }
    .btn-blue:disabled{ opacity:.55; cursor:not-allowed; }

    .card{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:16px;
      padding:16px;
    }
    .muted{ color:#6b7280; font-size:13px; }

    /* GRID DÍAS/LOTES */
    .lotes-grid{
      display:grid;
      gap:14px;
      grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
      margin-top:12px;
    }
    .lote-card{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:18px;
      padding:14px;
      transition:.15s;
    }
    .lote-card:hover{
      box-shadow:0 10px 30px rgba(0,0,0,.06);
      transform: translateY(-1px);
    }
    .lote-left{
      display:flex;
      align-items:flex-start;
      gap:12px;
      min-width:0;
    }
    .lote-thumb{
      width:64px;height:64px;border-radius:16px;border:1px solid #eee;
      background:#f9fafb;overflow:hidden;display:flex;align-items:center;justify-content:center;flex:0 0 auto;
    }
    .lote-thumb img{ width:100%;height:100%;object-fit:cover; }
    .lote-title{
      font-weight:900;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
      max-width: 220px;
    }
    .lote-meta{ color:#6b7280;font-size:12px;margin-top:2px; }
    .lote-actions{ margin-top:12px; display:flex; gap:10px; flex-wrap:wrap; }
    .lote-actions .btn-blue,
    .lote-actions a.btn-blue{ width:100%; justify-content:center; }

    /* MODAL */
    .modal-backdrop{
      position:fixed; inset:0; background:rgba(0,0,0,.45);
      z-index:9999; display:none; padding:18px;
    }
    .modal-box{
      max-width:920px; margin:4vh auto; background:#fff; border-radius:18px; overflow:hidden;
      box-shadow:0 18px 70px rgba(0,0,0,.22);
    }
  </style>

  <script>
    // ✅ URLs del backend (para el JS)
    window.PLACAS_API = {
      listar: <?= json_encode(site_url('placas/archivos/listar-por-dia')) ?>,
      stats:  <?= json_encode(site_url('placas/archivos/stats')) ?>,
      subir:  <?= json_encode(site_url('placas/archivos/subir-lote')) ?>,
      renombrar: <?= json_encode(site_url('placas/archivos/renombrar')) ?>,
      eliminar:   <?= json_encode(site_url('placas/archivos/eliminar')) ?>,
      descargarBase: <?= json_encode(site_url('placas/archivos/descargar')) ?>,
      descargarPngLote: <?= json_encode(site_url('placas/archivos/descargar-png-lote')) ?>,
      descargarJpgLote: <?= json_encode(site_url('placas/archivos/descargar-jpg-lote')) ?>,
      descargarJpg: <?= json_encode(site_url('placas/archivos/descargar-jpg')) ?>,
      descargarPng: <?= json_encode(site_url('placas/archivos/descargar-png')) ?>,
      renombrarLote: <?= json_encode(site_url('placas/archivos/lote/renombrar')) ?>,
      productosPorProducir: <?= json_encode(site_url('placas/archivos/productos-por-producir')) ?>,
    };
  </script>
</head>

<body class="flex">

<?= view('layouts/menu') ?>

<div class="flex-1 md:ml-64 p-8">
  <div class="card">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-black">PLACAS</h1>
        <div class="muted mt-1">
          Placas hoy: <span id="placasHoy" class="font-black">0</span>
        </div>
      </div>

      <div class="flex flex-wrap items-center gap-2 w-full md:w-auto">
        <!-- Buscador -->
        <div class="relative w-full md:w-[360px]">
          <input id="searchInput" type="text" placeholder="Buscar por lote, archivo, pedido o producto..."
            class="w-full border border-gray-200 rounded-xl px-4 py-2 pr-10 outline-none focus:ring-2 focus:ring-blue-200">
          <button id="searchClear" type="button"
            class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700 hidden">
            ✕
          </button>
        </div>

        <button id="btnAbrirModalCarga" class="btn-blue whitespace-nowrap">
          ➕ Cargar placa
        </button>
      </div>
    </div>

    <div id="msg" class="muted mt-2"></div>

    <div id="contenedorDias" class="space-y-6 mt-4"></div>
  </div>
</div>

<!-- ===================== MODAL EDITAR PLACA ===================== -->
<div id="modalBackdrop" class="modal-backdrop">
  <div class="modal-box">
    <!-- header -->
    <div class="flex items-center justify-between px-5 py-4 border-b">
      <div class="font-black text-lg">Editar placa</div>
      <button id="modalClose" class="btn-blue" style="background:#111827;">Cerrar</button>
    </div>

    <!-- content -->
    <div class="p-5 grid grid-cols-1 lg:grid-cols-2 gap-4">

      <!-- preview grande -->
      <div class="border rounded-2xl overflow-hidden bg-gray-50">
        <div id="modalPreview" class="w-full h-[320px]"></div>

        <div class="p-4 border-t">
          <div class="text-sm text-gray-600">Nombre</div>
          <input id="modalNombre"
            class="w-full border border-gray-200 rounded-xl px-3 py-2 mt-1"
            placeholder="Nombre de la placa" />

          <div class="text-sm text-gray-500 mt-2">
            Fecha de subida: <span id="modalFecha" class="font-black"></span>
          </div>

          <div class="flex flex-wrap gap-2 mt-3 justify-end">
            <button id="btnGuardarNombre" type="button" class="btn-blue">Guardar</button>
            <button id="btnDescargarPngSel" type="button" class="btn-blue" style="background:#10b981;">PNG</button>
            <button id="btnDescargarJpgSel" type="button" class="btn-blue" style="background:#0ea5e9;">JPG</button>
            <button id="btnEliminarArchivo" type="button" class="btn-blue" style="background:#ef4444;">Eliminar</button>
          </div>

          <div id="modalMsg" class="text-sm text-gray-500 mt-2"></div>
        </div>
      </div>

      <!-- panel derecho -->
      <div class="space-y-4">

        <!-- lote info + renombrar -->
        <div class="border rounded-2xl p-4 bg-white">
          <div class="flex items-center justify-between gap-2 flex-wrap">
            <div>
              <div class="font-black">Archivos del conjunto</div>
              <div class="text-xs text-gray-500 mt-1" id="modalLoteInfo"></div>
            </div>
            <button id="btnRenombrarLote" type="button"
              class="btn-blue" style="background:#f59e0b;">
              Cambiar nombre del lote
            </button>
          </div>

          <div id="modalArchivos" class="mt-3"></div>
        </div>

        <!-- pedidos/productos -->
        <div class="border rounded-2xl p-4 bg-white">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <div class="font-black text-sm mb-2">🧾 Pedidos vinculados</div>
              <div id="modalPedidos" class="flex flex-wrap gap-2"></div>
            </div>
            <div>
              <div class="font-black text-sm mb-2">🧩 Productos</div>
              <div id="modalProductos" class="flex flex-wrap gap-2"></div>
            </div>
          </div>
        </div>

        <div class="text-xs text-gray-400">
          Tip: En “Archivos del conjunto” puedes descargar rápido cada archivo, o seleccionar uno para verlo grande.
        </div>

      </div>
    </div>
  </div>
</div>

<!-- ===================== MODAL CARGA PLACA ===================== -->
<div id="modalCargaBackdrop"
     class="fixed inset-0 bg-black/50 hidden z-[10000] flex items-center justify-center p-4">

  <div class="bg-white rounded-2xl w-full max-w-[780px] shadow-xl overflow-hidden flex flex-col">
    <!-- header -->
    <div class="px-6 py-4 border-b">
      <h2 class="text-xl font-black">Cargar placa</h2>
      <div class="text-xs text-gray-500 mt-1">Selecciona productos “Por producir”, se detectan pedidos y se guardan en la placa.</div>
    </div>

    <!-- body -->
    <div class="p-6 space-y-4">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <div class="text-sm font-black text-gray-700 mb-1">Nombre del lote</div>
          <input id="cargaLoteNombre" type="text" placeholder="Ej: Pedido #9095 - Lámparas"
                 class="w-full border rounded-xl px-3 py-2">
        </div>

        <div>
          <div class="text-sm font-black text-gray-700 mb-1">Número de placa / nota (opcional)</div>
          <input id="cargaNumero" type="text" placeholder="Ej: 01 / 02 / Observación"
                 class="w-full border rounded-xl px-3 py-2">
        </div>
      </div>

      <!-- Productos por producir -->
      <div class="border rounded-2xl p-4 bg-gray-50">
        <div class="flex items-center justify-between gap-2 flex-wrap">
          <div class="font-black">🧩 Productos a colocar (Por producir)</div>
          <input id="cargaProductosBuscar"
            class="border rounded-xl px-3 py-2 text-sm w-full md:w-[360px]"
            placeholder="Buscar producto o pedido..." />
        </div>

        <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
          <div class="bg-white border rounded-2xl p-3">
            <div class="text-xs font-black text-gray-500 mb-2">Selecciona productos</div>
            <div id="cargaProductosLista" class="max-h-[260px] overflow-auto"></div>
          </div>

          <div class="space-y-3">
            <div class="bg-white border rounded-2xl p-3">
              <div class="text-xs font-black text-gray-500 mb-2">Seleccionados</div>
              <div id="cargaProductosChips" class="flex flex-wrap gap-2"></div>
            </div>

            <div class="bg-white border rounded-2xl p-3">
              <div class="text-xs font-black text-gray-500 mb-2">Pedidos vinculados (auto)</div>
              <div id="cargaPedidosChips" class="flex flex-wrap gap-2"></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Archivos -->
      <div class="border rounded-2xl p-4 bg-gray-50">
        <div class="font-black mb-2">📎 Archivos</div>
        <input id="cargaArchivo" type="file" multiple class="w-full" accept="*/*">

        <div id="cargaPreview" class="mt-3 h-44 border rounded-xl flex items-center justify-center text-gray-400 bg-white">
          Vista previa
        </div>

        <div id="uploadProgressWrap" class="hidden mt-3">
          <div class="w-full bg-gray-100 border border-gray-200 rounded-full h-3 overflow-hidden">
            <div id="uploadProgressBar"
                 class="bg-blue-600 h-3 rounded-full transition-[width] duration-150"
                 style="width:0%"></div>
          </div>
          <div class="text-xs text-gray-500 mt-2 flex items-center justify-between">
            <span id="uploadProgressLabel">Subiendo…</span>
            <span id="uploadProgressText" class="font-black">0%</span>
          </div>
        </div>

        <div id="cargaMsg" class="text-sm text-gray-500 mt-2"></div>
      </div>
    </div>

    <!-- footer -->
    <div class="px-6 py-4 border-t flex justify-end gap-2">
      <button id="btnCerrarCarga" class="btn-blue" style="background:#9ca3af;">Cancelar</button>
      <button id="btnGuardarCarga" class="btn-blue">Guardar</button>
    </div>
  </div>
</div>

<!-- ✅ JS separado -->
<script src="<?= base_url('js/placas.js') ?>?v=<?= time() ?>"></script>

</body>
</html>
