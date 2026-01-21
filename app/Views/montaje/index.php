<?php
// montaje/index.php
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- CSRF metas (para getCsrfHeaders() del JS) -->
  <meta name="csrf-token" content="<?= csrf_hash() ?>">
  <meta name="csrf-header" content="<?= function_exists('csrf_header') ? csrf_header() : 'X-CSRF-TOKEN' ?>">

  <title>Montaje</title>

  <!-- Si ya cargas Tailwind en tu layout general, puedes quitar este comentario -->
  <!-- Este HTML asume Tailwind disponible (igual que tu Producción) -->

  <style>
    /* Grid “tipo producción”: 10 columnas (numero, fecha, cliente, total, estado, last, items, entrega, metodo, acciones) */
    .prod-grid-cols{
      display:grid;
      grid-template-columns:
        110px     /* número */
        150px     /* fecha */
        minmax(220px, 1fr) /* cliente */
        140px     /* total */
        160px     /* estado */
        170px     /* último cambio */
        90px      /* artículos */
        170px     /* entrega */
        minmax(160px, 1fr) /* método */
        160px;    /* acciones */
      align-items:center;
    }
    .tags-wrap-mini{ display:flex; gap:.35rem; flex-wrap:wrap; }
    .tag-mini{
      display:inline-flex;
      align-items:center;
      padding:.15rem .55rem;
      border-radius:999px;
      border:1px solid rgb(226 232 240);
      background:rgb(248 250 252);
      font-size:11px;
      font-weight:800;
      color:rgb(51 65 85);
      white-space:nowrap;
    }
  </style>

  <script>
    // Para que el JS construya endpoints correctamente
    window.API_BASE = "<?= rtrim(base_url(), '/') ?>";
  </script>
</head>

<body class="bg-slate-50 text-slate-900">
  <!-- Loader global -->
  <div id="globalLoader" class="hidden fixed inset-0 z-[9999] bg-black/30 backdrop-blur-[1px]">
    <div class="absolute inset-0 flex items-center justify-center">
      <div class="rounded-3xl bg-white shadow-xl border border-slate-200 px-6 py-5 flex items-center gap-3">
        <div class="h-6 w-6 rounded-full border-4 border-slate-200 border-t-slate-900 animate-spin"></div>
        <div class="font-extrabold text-slate-900">Cargando…</div>
      </div>
    </div>
  </div>

  <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-7">
    <!-- Header -->
    <div class="flex flex-col gap-4">
      <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
          <div class="flex items-center gap-3">
            <div class="h-10 w-10 rounded-3xl bg-slate-900 text-white flex items-center justify-center font-black">M</div>
            <div class="min-w-0">
              <h1 class="text-2xl font-extrabold tracking-tight truncate">Montaje</h1>
              <p class="text-sm text-slate-600 mt-0.5">
                Pedidos en estado <b>Diseñado</b> listos para subir a <b>Por producir</b>.
              </p>
            </div>
          </div>
        </div>

        <!-- Contador -->
        <div class="shrink-0 flex items-center gap-2">
          <span class="px-3 py-2 rounded-2xl bg-white border border-slate-200 shadow-sm text-sm font-extrabold">
            Total: <span id="total-pedidos">0</span>
          </span>
        </div>
      </div>

      <!-- Barra de acciones -->
      <div class="flex flex-col lg:flex-row lg:items-center gap-3">
        <!-- Buscar -->
        <div class="flex-1 flex items-center gap-2">
          <div class="relative flex-1">
            <input
              id="inputBuscar"
              type="text"
              placeholder="Buscar por #, cliente, ID, etiquetas…"
              class="w-full h-11 rounded-2xl border border-slate-200 bg-white px-4 pr-10 text-sm font-semibold outline-none
                     focus:ring-4 focus:ring-slate-200 focus:border-slate-300"
            >
            <div class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400">⌕</div>
          </div>

          <button
            id="btnLimpiarBusqueda"
            type="button"
            class="h-11 px-4 rounded-2xl bg-white border border-slate-200 shadow-sm text-slate-900 font-extrabold text-sm
                   hover:bg-slate-100 transition"
            title="Limpiar"
          >
            Limpiar
          </button>
        </div>

        <!-- Acciones derecha (opcional) -->
        <div class="flex items-center gap-2 justify-end">
          <button
            type="button"
            onclick="window.__montajeRefresh && window.__montajeRefresh()"
            class="h-11 px-4 rounded-2xl bg-slate-900 text-white font-extrabold text-sm shadow-sm
                   hover:bg-slate-800 transition"
            title="Actualizar"
          >
            Actualizar
          </button>
        </div>
      </div>
    </div>

    <!-- Tabla / Grid wrapper -->
    <section class="mt-6">
      <!-- Header “tipo tabla” (visible en XL a 2XL-) -->
      <div class="hidden xl:block 2xl:hidden rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="prod-grid-cols px-4 py-3 bg-slate-50 border-b border-slate-200 text-[12px] font-extrabold uppercase tracking-wide text-slate-600">
          <div>Número</div>
          <div>Fecha</div>
          <div>Cliente</div>
          <div class="text-right">Total</div>
          <div>Estado</div>
          <div>Último cambio</div>
          <div class="text-center">Ítems</div>
          <div>Entrega</div>
          <div>Método</div>
          <div class="text-right">Acciones</div>
        </div>
        <div id="tablaPedidosTable"></div>
      </div>

      <!-- Header + GRID (>=2XL) -->
      <div class="hidden 2xl:block rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="prod-grid-cols px-4 py-3 bg-slate-50 border-b border-slate-200 text-[12px] font-extrabold uppercase tracking-wide text-slate-600">
          <div>Número</div>
          <div>Fecha</div>
          <div>Cliente</div>
          <div class="text-right">Total</div>
          <div>Estado</div>
          <div>Último cambio</div>
          <div class="text-center">Ítems</div>
          <div>Entrega</div>
          <div>Método</div>
          <div class="text-right">Acciones</div>
        </div>
        <div id="tablaPedidos"></div>
      </div>

      <!-- Cards (<XL) -->
      <div id="cardsPedidos" class="xl:hidden"></div>
    </section>
  </main>

  <!-- =========================
       MODAL DETALLES FULL
       ========================= -->
  <div id="modalDetallesFull" class="hidden fixed inset-0 z-[9998] bg-black/40 backdrop-blur-[1px]">
    <div class="absolute inset-0 overflow-auto">
      <div class="min-h-full flex items-start justify-center px-3 sm:px-6 py-8">
        <div class="w-full max-w-6xl rounded-[28px] bg-white border border-slate-200 shadow-2xl overflow-hidden">
          <!-- Header modal -->
          <div class="p-5 sm:p-6 border-b border-slate-200 bg-slate-50">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
              <div class="min-w-0">
                <div id="detTitle" class="text-xl sm:text-2xl font-extrabold text-slate-900 truncate">Detalles</div>
                <div id="detSubtitle" class="text-sm text-slate-600 mt-1 truncate">—</div>
              </div>

              <div class="flex items-center gap-2 justify-end">
                <!-- ✅ Botón dentro de detalles: Subir pedido -->
                <button
                  id="btnSubirPedidoDetalles"
                  type="button"
                  class="h-10 px-4 rounded-2xl bg-orange-600 text-white font-extrabold text-xs sm:text-sm uppercase tracking-wide
                         hover:bg-orange-700 transition shadow-sm"
                >
                  Subir pedido
                </button>

                <button
                  type="button"
                  onclick="window.cerrarDetallesFull && window.cerrarDetallesFull()"
                  class="h-10 px-4 rounded-2xl bg-white border border-slate-200 text-slate-900 font-extrabold text-xs sm:text-sm
                         hover:bg-slate-100 transition"
                >
                  Cerrar ✕
                </button>
              </div>
            </div>
          </div>

          <!-- Body modal -->
          <div class="p-5 sm:p-6">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
              <!-- Cliente -->
              <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-4">
                <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500 mb-2">Cliente</div>
                <div id="detCliente" class="text-sm text-slate-700">—</div>
              </div>

              <!-- Envío -->
              <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-4">
                <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500 mb-2">Envío</div>
                <div id="detEnvio" class="text-sm text-slate-700">—</div>
              </div>

              <!-- Resumen / Totales -->
              <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-4">
                <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500 mb-2">Resumen</div>
                <div id="detResumen" class="text-sm text-slate-700">—</div>

                <div class="mt-4 pt-4 border-t border-slate-200">
                  <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500 mb-2">Totales</div>
                  <div id="detTotales" class="text-sm text-slate-700">—</div>
                </div>
              </div>
            </div>

            <!-- Productos -->
            <div class="mt-6">
              <div class="flex items-center justify-between gap-3">
                <div class="text-sm font-extrabold text-slate-900">
                  Productos <span class="text-slate-500 font-black">·</span>
                  <span id="detItemsCount" class="text-slate-600 font-extrabold">0</span>
                </div>
              </div>

              <div id="detItems" class="mt-3 grid grid-cols-1 gap-3">
                <div class="text-slate-500">—</div>
              </div>
            </div>

            <!-- Archivos generales (si lo usas; coincide con tu JS de producción) -->
            <div class="mt-6 rounded-3xl border border-slate-200 bg-white shadow-sm p-4">
              <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                  <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500">Archivos generales</div>
                  <div class="text-sm font-extrabold text-slate-900 mt-1">Adjuntos del pedido</div>
                </div>

                <form id="formGeneralUpload" class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                  <input type="hidden" id="generalOrderId" value="">
                  <input
                    id="generalFiles"
                    type="file"
                    multiple
                    class="block w-full sm:w-[260px] text-sm font-semibold
                           file:mr-3 file:py-2 file:px-3 file:rounded-2xl file:border-0
                           file:bg-slate-900 file:text-white file:font-extrabold
                           hover:file:bg-slate-800"
                  >
                  <button
                    type="submit"
                    class="h-10 px-4 rounded-2xl bg-blue-600 text-white font-extrabold text-xs sm:text-sm uppercase tracking-wide
                           hover:bg-blue-700 transition shadow-sm"
                  >
                    Subir archivos
                  </button>
                </form>
              </div>

              <div id="generalUploadMsg" class="mt-2 text-sm"></div>

              <div id="generalFilesList" class="mt-4 space-y-2">
                <div class="text-slate-500 text-sm">—</div>
              </div>
            </div>

            <!-- JSON debug -->
            <div class="mt-6">
              <div class="flex items-center justify-between gap-2">
                <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500">JSON (debug)</div>
                <div class="flex items-center gap-2">
                  <button type="button"
                          onclick="window.toggleJsonDetalles && window.toggleJsonDetalles()"
                          class="h-9 px-3 rounded-2xl bg-white border border-slate-200 text-slate-900 font-extrabold text-xs hover:bg-slate-100">
                    Ver/ocultar
                  </button>
                  <button type="button"
                          onclick="window.copiarDetallesJson && window.copiarDetallesJson()"
                          class="h-9 px-3 rounded-2xl bg-white border border-slate-200 text-slate-900 font-extrabold text-xs hover:bg-slate-100">
                    Copiar
                  </button>
                </div>
              </div>

              <pre id="detJson" class="hidden mt-3 p-4 rounded-3xl border border-slate-200 bg-slate-50 text-[12px] overflow-auto"></pre>
            </div>

          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- JS Montaje (ubicado en public/js) -->
  <script src="<?= base_url('js/montaje.js') ?>"></script>
</body>
</html>
