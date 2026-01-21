<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- CSRF para tu JS (como en tu ejemplo) -->
  <meta name="csrf-token" content="<?= csrf_hash() ?>">
  <meta name="csrf-header" content="X-CSRF-TOKEN">

  <title>Montaje</title>

  <!-- Si ya tienes Tailwind en tu proyecto, perfecto. Si no, deja tu CSS propio -->
  <style>
    .prod-grid-cols{
      display:grid;
      grid-template-columns: 110px 130px 1fr 130px 160px 170px 90px 190px 1fr 240px;
      gap: 12px;
    }
    @media (max-width: 1535px){
      .prod-grid-cols{ grid-template-columns: 110px 130px 1fr 130px 160px 170px 90px 190px 1fr 220px; }
    }
    .tag-mini{
      display:inline-flex;
      padding:4px 10px;
      border-radius:999px;
      font-weight:800;
      font-size:11px;
      border:1px solid #e2e8f0;
      background:#f8fafc;
      margin-right:6px;
      margin-bottom:6px;
      white-space:nowrap;
    }
    .tags-wrap-mini{ display:flex; flex-wrap:wrap; }
  </style>

  <script>
    window.API_BASE = "<?= rtrim(base_url(), '/') ?>";
  </script>
</head>

<body class="bg-slate-50">

  <!-- Loader global -->
  <div id="globalLoader" class="hidden fixed inset-0 z-[9999] bg-black/30 flex items-center justify-center">
    <div class="bg-white rounded-2xl px-5 py-3 font-extrabold shadow">Cargando…</div>
  </div>

  <div class="max-w-7xl mx-auto p-4">

    <div class="flex items-start justify-between gap-3 mb-4">
      <div>
        <div class="text-2xl font-extrabold text-slate-900">Montaje</div>
        <div class="text-sm text-slate-600 mt-1">
          Pedidos en estado <b>Diseñado</b>
        </div>
      </div>

      <div class="text-sm font-extrabold text-slate-900 bg-white border border-slate-200 px-4 py-2 rounded-2xl">
        Total: <span id="total-pedidos">0</span>
      </div>
    </div>

    <!-- Buscador -->
    <div class="flex gap-2 items-center mb-4">
      <input id="inputBuscar" type="text"
             class="w-full bg-white border border-slate-200 rounded-2xl px-4 py-3 font-semibold"
             placeholder="Buscar por #, cliente, etiqueta, id..." />
      <button id="btnLimpiarBusqueda"
              class="px-4 py-3 rounded-2xl bg-white border border-slate-200 font-extrabold hover:bg-slate-100">
        Limpiar
      </button>
    </div>

    <!-- Contenedores responsive -->
    <div id="tablaPedidos" class="hidden rounded-3xl overflow-hidden border border-slate-200 bg-white"></div>
    <div id="tablaPedidosTable" class="hidden rounded-3xl overflow-hidden border border-slate-200 bg-white"></div>
    <div id="cardsPedidos" class="hidden"></div>
  </div>

  <!-- MODAL Detalles FULL -->
  <div id="modalDetallesFull" class="hidden fixed inset-0 z-[9998] bg-black/40 p-3 overflow-auto">
    <div class="max-w-6xl mx-auto bg-white rounded-3xl shadow-xl overflow-hidden">
      <div class="p-4 border-b border-slate-200 flex items-center justify-between gap-3">
        <div class="min-w-0">
          <div id="detTitle" class="text-lg font-extrabold text-slate-900 truncate">Detalles</div>
          <div id="detSubtitle" class="text-sm text-slate-600 truncate">—</div>
        </div>

        <div class="flex items-center gap-2">
          <!-- Botón dentro de detalles -->
          <button id="btnSubirPedidoDetalle"
                  class="h-9 px-3 rounded-2xl bg-emerald-600 text-white text-[11px] font-extrabold uppercase tracking-wide hover:bg-emerald-700 transition">
            Subir pedido ⬆️
          </button>

          <button onclick="cerrarDetallesFull()"
                  class="h-9 px-3 rounded-2xl bg-white border border-slate-200 text-slate-900 text-[11px] font-extrabold uppercase tracking-wide hover:bg-slate-100 transition">
            Cerrar ✕
          </button>
        </div>
      </div>

      <div class="p-4 grid grid-cols-1 xl:grid-cols-3 gap-4">
        <div class="xl:col-span-2 space-y-4">

          <div class="rounded-3xl border border-slate-200 bg-white p-4">
            <div class="flex items-center justify-between mb-3">
              <div class="text-sm font-extrabold text-slate-900">Productos</div>
              <div class="text-xs font-extrabold text-slate-600">Items: <span id="detItemsCount">0</span></div>
            </div>
            <div id="detItems" class="space-y-3"></div>
          </div>

          <!-- Upload general (opcional, como tu producción) -->
          <div class="rounded-3xl border border-slate-200 bg-white p-4">
            <div class="text-sm font-extrabold text-slate-900 mb-2">Archivos de montaje</div>
            <div id="generalFilesList" class="space-y-2 mb-3"></div>

            <form id="formGeneralUpload" class="flex flex-col gap-2">
              <input type="hidden" id="generalOrderId" value="">
              <input id="generalFiles" type="file" multiple
                     class="block w-full border border-slate-200 rounded-2xl px-3 py-2" />
              <div class="flex items-center gap-2">
                <button type="submit"
                        class="h-10 px-4 rounded-2xl bg-blue-600 text-white font-extrabold text-xs hover:bg-blue-700">
                  Subir archivos
                </button>
                <div id="generalUploadMsg" class="text-sm"></div>
              </div>
            </form>
          </div>

        </div>

        <div class="space-y-4">
          <div class="rounded-3xl border border-slate-200 bg-white p-4">
            <div class="text-sm font-extrabold text-slate-900 mb-2">Cliente</div>
            <div id="detCliente"></div>
          </div>

          <div class="rounded-3xl border border-slate-200 bg-white p-4">
            <div class="text-sm font-extrabold text-slate-900 mb-2">Envío</div>
            <div id="detEnvio"></div>
          </div>

          <div class="rounded-3xl border border-slate-200 bg-white p-4">
            <div class="text-sm font-extrabold text-slate-900 mb-2">Resumen</div>
            <div id="detResumen"></div>
          </div>

          <div class="rounded-3xl border border-slate-200 bg-white p-4">
            <div class="text-sm font-extrabold text-slate-900 mb-2">Totales</div>
            <div id="detTotales"></div>
          </div>

          <div class="rounded-3xl border border-slate-200 bg-white p-4">
            <div class="flex items-center justify-between">
              <div class="text-sm font-extrabold text-slate-900">JSON</div>
              <div class="flex gap-2">
                <button onclick="toggleJsonDetalles()"
                        class="px-3 py-2 rounded-2xl bg-white border border-slate-200 font-extrabold text-xs hover:bg-slate-100">
                  Ver/Ocultar
                </button>
                <button onclick="copiarDetallesJson()"
                        class="px-3 py-2 rounded-2xl bg-white border border-slate-200 font-extrabold text-xs hover:bg-slate-100">
                  Copiar
                </button>
              </div>
            </div>
            <pre id="detJson" class="hidden mt-3 text-xs bg-slate-50 border border-slate-200 rounded-2xl p-3 overflow-auto"></pre>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Tu JS -->
  <script src="<?= base_url('js/montaje.js') ?>"></script>

</body>
</html>
