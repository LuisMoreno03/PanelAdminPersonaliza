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
      background:#2563eb; color:#fff; padding:10px 16px; border-radius:12px;
      font-weight:800; border:none; cursor:pointer; transition:.15s;
      display:inline-flex; align-items:center; gap:8px;
    }
    .btn-blue:hover{ filter:brightness(1.06); }
    .btn-blue:disabled{ opacity:.55; cursor:not-allowed; }

    .card{
      background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:16px;
    }
    .muted{ color:#6b7280; font-size:13px; }

    .lotes-grid{
      display:grid; gap:14px;
      grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
      margin-top:12px;
    }
    .lote-card{
      background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:14px;
      transition:.15s;
    }
    .lote-card:hover{
      box-shadow:0 10px 30px rgba(0,0,0,.06);
      transform: translateY(-1px);
    }
    .lote-left{ display:flex; align-items:center; gap:12px; min-width:0; }
    .lote-thumb{
      width:64px; height:64px; border-radius:14px; border:1px solid #eee;
      background:#f9fafb; overflow:hidden; display:flex; align-items:center; justify-content:center;
      flex:0 0 auto;
    }
    .lote-thumb img{ width:100%; height:100%; object-fit:cover; }
    .lote-title{ font-weight:900; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:220px; }
    .lote-meta{ color:#6b7280; font-size:12px; margin-top:2px; }
    .lote-actions{ margin-top:12px; display:flex; gap:10px; flex-wrap:wrap; }
    .lote-actions .btn-blue, .lote-actions a.btn-blue{ width:100%; justify-content:center; }
  </style>

  <script>
    // ✅ API URLs (PHP -> JS)
    window.PLACAS_API = {
      listar: <?= json_encode(site_url('placas/archivos/listar-por-dia')) ?>,
      stats:  <?= json_encode(site_url('placas/archivos/stats')) ?>,
      subir:  <?= json_encode(site_url('placas/archivos/subir-lote')) ?>,
      renombrar: <?= json_encode(site_url('placas/archivos/renombrar')) ?>,
      eliminar:   <?= json_encode(site_url('placas/archivos/eliminar')) ?>,
      descargarBase: <?= json_encode(site_url('placas/archivos/descargar')) ?>,
      descargarPngLote: <?= json_encode(site_url('placas/archivos/descargar-png-lote')) ?>,
      descargarJpgLote: <?= json_encode(site_url('placas/archivos/descargar-jpg-lote')) ?>,
      descargarPng: <?= json_encode(site_url('placas/archivos/descargar-png')) ?>,
      descargarJpg: <?= json_encode(site_url('placas/archivos/descargar-jpg')) ?>,
      renombrarLote: <?= json_encode(site_url('placas/archivos/lote/renombrar')) ?>,

      // opcional
      productosDePedidos: <?= json_encode(site_url('placas/archivos/pedidos/productos')) ?>,
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
        <div class="relative w-full md:w-[360px]">
          <input id="searchInput" type="text" placeholder="Buscar por fecha, lote, pedido o producto..."
            class="w-full border border-gray-200 rounded-xl px-4 py-2 pr-10 outline-none focus:ring-2 focus:ring-blue-200">
          <button id="searchClear" type="button"
            class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700 hidden">
            ✕
          </button>
        </div>

        <button id="btnAbrirModalCarga" class="btn-blue whitespace-nowrap">Cargar placa</button>
      </div>
    </div>

    <div id="msg" class="muted mt-2"></div>

    <div id="contenedorDias" class="space-y-6 mt-4"></div>
  </div>
</div>

<!-- ===================== MODAL EDITAR ===================== -->
<div id="modalBackdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9999;">
  <div style="max-width:980px; margin:5vh auto; background:#fff; border-radius:18px; overflow:hidden;">
    <div style="display:flex; justify-content:space-between; align-items:center; padding:14px 16px; border-bottom:1px solid #eee;">
      <div style="font-weight:900;">Editar placa</div>
      <button id="modalClose" class="btn-blue" style="background:#111827;">Cerrar</button>
    </div>

    <div style="padding:16px;">
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        <!-- Preview -->
        <div class="border border-gray-200 rounded-2xl bg-gray-50 p-3">
          <div class="text-xs font-black text-gray-700 mb-2">Vista previa</div>
          <div id="modalPreview" style="width:100%; height:330px; border:1px solid #eee; border-radius:14px; overflow:hidden; background:#f9fafb;"></div>
        </div>

        <!-- Archivos + meta -->
        <div class="space-y-4">

          <!-- Archivos -->
          <div class="border border-gray-200 rounded-2xl bg-gray-50 p-4">
            <div class="flex items-center justify-between gap-2">
              <div>
                <div class="font-black">Archivos del conjunto</div>
                <div class="text-xs text-gray-500" id="modalLoteInfo"></div>
              </div>

              <button id="btnRenombrarLote" type="button"
                class="btn-blue" style="background:#f59e0b;">
                Cambiar nombre
              </button>
            </div>

            <div class="mt-3" id="modalArchivos"></div>

            <div class="mt-3 flex flex-wrap gap-2 justify-end">
              <button id="btnDescargarPngSel" type="button" class="btn-blue" style="background:#10b981;">PNG</button>
              <button id="btnDescargarJpgSel" type="button" class="btn-blue" style="background:#0ea5e9;">JPG</button>
              <button id="btnEliminarArchivo" type="button" class="btn-blue" style="background:#ef4444;">Eliminar</button>
            </div>
          </div>

          <!-- Pedidos -->
          <div class="border border-gray-200 rounded-2xl bg-white p-4">
            <div class="text-xs font-black text-gray-700">Pedidos vinculados</div>
            <div id="modalPedidos" class="mt-2 flex flex-wrap gap-2"></div>
            <div class="text-xs text-gray-400 mt-2" id="modalPedidosHint"></div>
          </div>

          <!-- Productos -->
          <div class="border border-gray-200 rounded-2xl bg-white p-4">
            <div class="text-xs font-black text-gray-700">Productos</div>
            <div id="modalProductos" class="mt-2 flex flex-wrap gap-2"></div>
            <div class="text-xs text-gray-400 mt-2" id="modalProductosHint"></div>
          </div>

          <!-- Nombre -->
          <div class="border border-gray-200 rounded-2xl bg-white p-4">
            <div class="text-sm text-gray-600">Nombre del archivo</div>
            <input id="modalNombre"
              class="mt-2 w-full border border-gray-200 rounded-xl px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200"/>

            <div class="text-xs text-gray-500 mt-3">
              Fecha de subida: <span id="modalFecha" class="font-black"></span>
            </div>

            <div class="mt-3 flex justify-end">
              <button id="btnGuardarNombre" type="button" class="btn-blue">Guardar</button>
            </div>

            <div id="modalMsg" class="text-sm text-gray-500 mt-2"></div>
          </div>

        </div>
      </div>
    </div>
  </div>
</div>

<!-- ===================== MODAL CARGA ===================== -->
<div id="modalCargaBackdrop"
     class="fixed inset-0 bg-black/50 hidden z-[10000] flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl w-full max-w-[560px] shadow-xl overflow-hidden flex flex-col">

    <div class="px-6 py-4 border-b">
      <h2 class="text-xl font-black">Cargar placa</h2>
      <div class="text-xs text-gray-500 mt-1">Sube archivos + vincula pedidos/productos.</div>
    </div>

    <div class="p-6 space-y-3">
      <input id="cargaLoteNombre" type="text" placeholder="Nombre del lote (ej: Pedido #9095 - Lámparas)"
             class="w-full border rounded-xl px-3 py-2">

      <input id="cargaNumero" type="text" placeholder="Número de placa (opcional)"
             class="w-full border rounded-xl px-3 py-2">

      <input id="cargaPedidos" type="text" placeholder="Pedidos (ej: 9095, 9102, 9109)"
             class="w-full border rounded-xl px-3 py-2">

      <textarea id="cargaProductos" rows="3"
        placeholder="Productos (uno por línea o separados por coma)"
        class="w-full border rounded-xl px-3 py-2"></textarea>

      <input id="cargaArchivo" type="file" multiple class="w-full" accept="*/*">

      <div id="cargaPreview"
           class="h-44 border rounded-xl flex items-center justify-center text-gray-400">
        Vista previa
      </div>

      <div id="uploadProgressWrap" class="hidden">
        <div class="w-full bg-gray-100 border border-gray-200 rounded-full h-3 overflow-hidden">
          <div id="uploadProgressBar"
               class="bg-blue-600 h-3 rounded-full transition-[width] duration-150"
               style="width:0%">
          </div>
        </div>
        <div class="text-xs text-gray-500 mt-2 flex items-center justify-between">
          <span id="uploadProgressLabel">Subiendo…</span>
          <span id="uploadProgressText" class="font-black">0%</span>
        </div>
      </div>

      <div id="cargaMsg" class="text-sm text-gray-500"></div>
    </div>

    <div class="px-6 py-4 border-t flex justify-end gap-2">
      <button id="btnCerrarCarga" class="btn-blue" style="background:#9ca3af;">Cancelar</button>
      <button id="btnGuardarCarga" class="btn-blue">Guardar</button>
    </div>

  </div>
</div>

<!-- ✅ JS externo -->
<script src="<?= base_url('js/placas.js?v=' . time()) ?>"></script>

</body>
</html>
