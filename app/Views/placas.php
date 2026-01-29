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
      font-weight:700;
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

    .grid{
      display:grid;
      gap:12px;
      grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
      margin-top:14px;
    }
    .item{
      border:1px solid #e5e7eb;
      border-radius:14px;
      padding:12px;
      background:#fff;
      cursor:pointer;
    }
    .item-title{ font-weight:800; margin-top:10px; }
    .preview{
      width:100%;
      height:160px;
      border-radius:12px;
      border:1px solid #eee;
      overflow:hidden;
      background:#f9fafb;
    }
    .preview img{ width:100%; height:100%; object-fit:cover; }
    .preview iframe{ width:100%; height:100%; border:0; }

    /* ✅ GRID DE LOTES (CARPETAS) */
    .lotes-grid{
      display:grid;
      gap:14px;
      grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
      margin-top:12px;
    }

    .lote-card{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:16px;
      padding:14px;
      transition:.15s;
    }

    .lote-card:hover{
      box-shadow:0 10px 30px rgba(0,0,0,.06);
      transform: translateY(-1px);
    }

    .lote-left{
      display:flex;
      align-items:center;
      gap:12px;
      min-width:0;
    }

    .lote-thumb{
      width:64px;
      height:64px;
      border-radius:14px;
      border:1px solid #eee;
      background:#f9fafb;
      overflow:hidden;
      display:flex;
      align-items:center;
      justify-content:center;
      flex:0 0 auto;
    }

    .lote-thumb img{
      width:100%;
      height:100%;
      object-fit:cover;
    }

    .lote-title{
      font-weight:900;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
      max-width: 220px;
    }

    .lote-meta{
      color:#6b7280;
      font-size:12px;
      margin-top:2px;
    }

    /* ✅ Botonera debajo */
    .lote-actions{
      margin-top:12px;
      display:flex;
      gap:10px;
      flex-wrap:wrap;
    }
    .lote-actions .btn-blue,
    .lote-actions a.btn-blue{
      width:100%;
      justify-content:center;
    }
  </style>

  <!-- ✅ Config que antes estaba dentro del JS -->
  <script>
    window.PLACAS_CONFIG = {
      API: {
        listar: <?= json_encode(site_url('placas/archivos/listar-por-dia')) ?>,
        stats:  <?= json_encode(site_url('placas/archivos/stats')) ?>,
        subir:  <?= json_encode(site_url('placas/archivos/subir-lote')) ?>,
        renombrar: <?= json_encode(site_url('placas/archivos/renombrar')) ?>,
        eliminar:   <?= json_encode(site_url('placas/archivos/eliminar')) ?>,
        descargarBase: <?= json_encode(site_url('placas/archivos/descargar')) ?>,
        descargarPngLote: <?= json_encode(site_url('placas/archivos/descargar-png-lote')) ?>,
        descargarJpg: <?= json_encode(site_url('placas/archivos/descargar-jpg')) ?>,
        descargarPng: <?= json_encode(site_url('placas/archivos/descargar-png')) ?>,
        descargarJpgLote: <?= json_encode(site_url('placas/archivos/descargar-jpg-lote')) ?>,
        renombrarLote: <?= json_encode(site_url('placas/archivos/lote/renombrar')) ?>,
      }
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
          <!-- ✅ Buscador -->
          <div class="relative w-full md:w-[340px]">
            <input id="searchInput" type="text" placeholder="Buscar lote o archivo..."
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

      <div id="contenedorDias" class="space-y-6"></div>
      <div id="grid" class="grid hidden"></div>
    </div>
  </div>

  <!-- MODAL EDITAR ARCHIVO -->
  <div id="modalBackdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9999;">
    <div style="max-width:720px; margin:6vh auto; background:#fff; border-radius:16px; overflow:hidden;">
      <div style="display:flex; justify-content:space-between; align-items:center; padding:14px 16px; border-bottom:1px solid #eee;">
        <div style="font-weight:900;">Editar placa</div>
        <button id="modalClose" class="btn-blue">Cerrar</button>
      </div>

      <div style="padding:16px;">
        <div id="modalPreview" style="width:100%; height:260px; border:1px solid #eee; border-radius:14px; overflow:hidden; background:#f9fafb;"></div>

        <!-- ✅ Archivos del conjunto -->
        <div style="margin-top:14px; border:1px solid #e5e7eb; border-radius:14px; padding:12px; background:#f9fafb;">
          <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
            <div style="font-weight:900;">Archivos del conjunto</div>

            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
              <div class="muted" id="modalLoteInfo"></div>

              <button id="btnRenombrarLote" type="button" class="btn-blue" style="background:#f59e0b;">
                Cambiar nombre del lote
              </button>
            </div>
          </div>

          <div id="modalArchivos" style="margin-top:10px; max-height:220px; overflow:auto; display:grid; gap:10px;"></div>
        </div>

        <div style="margin-top:12px;">
          <div class="text-sm text-gray-600">Nombre</div>
          <input id="modalNombre" style="width:100%; border:1px solid #e5e7eb; border-radius:12px; padding:10px;" />
        </div>

        <div style="margin-top:10px;" class="text-sm text-gray-600">
          Fecha de subida: <span id="modalFecha"></span>
        </div>

        <div style="display:flex; gap:10px; margin-top:14px; justify-content:flex-end;">
          <button id="btnGuardarNombre" type="button" class="btn-blue">Guardar</button>
          <button id="btnDescargarPngSel" type="button" class="btn-blue" style="background:#10b981;">PNG</button>
          <button id="btnDescargarJpgSel" type="button" class="btn-blue" style="background:#0ea5e9;">JPG</button>
          <button id="btnEliminarArchivo" type="button" class="btn-blue" style="background:#ef4444;">Eliminar</button>
        </div>

        <div id="modalMsg" class="text-sm text-gray-500 mt-2"></div>
      </div>
    </div>
  </div>

  <!-- MODAL CARGA (MULTI) -->
  <div id="modalCargaBackdrop"
       class="fixed inset-0 bg-black/50 hidden z-[10000] flex items-center justify-center p-4">

    <div class="bg-white rounded-2xl w-full max-w-[520px] shadow-xl overflow-hidden flex flex-col">
      <div class="px-6 py-4 border-b">
        <h2 class="text-xl font-black">Cargar placa</h2>
        <div class="text-xs text-gray-500 mt-1">Completa los datos y sube uno o más archivos.</div>
      </div>

      <div class="p-6 space-y-3">
        <input id="cargaLoteNombre" type="text" placeholder="Nombre del lote (ej: Pedido #9095 - Lámparas)"
               class="w-full border rounded-xl px-3 py-2">

        <input id="cargaNumero" type="text" placeholder="Productos (opcional)"
               class="w-full border rounded-xl px-3 py-2">

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
  <script src="<?= base_url('js/placas.js') ?>"></script>
</body>
</html>
