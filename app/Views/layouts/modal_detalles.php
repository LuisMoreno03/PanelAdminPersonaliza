<!-- =========================
   MODAL DETALLES (FULL SCREEN)
========================= -->
<div id="modalDetallesFull" class="hidden fixed inset-0 z-[9999] bg-black/50 backdrop-blur-sm">
  <div class="h-full w-full bg-white flex flex-col">

    <!-- Header -->
    <div class="px-5 sm:px-8 py-4 border-b border-slate-200 flex items-center justify-between gap-3">
      <div class="min-w-0">
        <div class="text-xs font-extrabold uppercase tracking-wider text-slate-500">Detalles del pedido</div>
        <h2 id="detTitle" class="text-xl sm:text-2xl font-extrabold text-slate-900 truncate">—</h2>
        <p id="detSubtitle" class="text-sm text-slate-500 mt-1 truncate">—</p>
      </div>

      <div class="flex items-center gap-2">
        <button type="button" onclick="copiarDetallesJson()"
          class="px-4 py-2 rounded-2xl bg-slate-100 border border-slate-200 text-slate-900
                 font-extrabold text-xs uppercase tracking-wide hover:bg-slate-200 transition">
          Copiar JSON
        </button>

        <!-- ✅ Abre el drawer derecho (modal_info_cliente.php) -->
        <button type="button" onclick="abrirClienteDetalle()"
          class="px-4 py-2 rounded-2xl bg-slate-900 border border-slate-900 text-white
                 font-extrabold text-xs uppercase tracking-wide hover:bg-slate-800 transition">
          Cliente
        </button>

        <button type="button" onclick="cerrarDetallesFull()"
          class="h-10 w-10 rounded-2xl border border-slate-200 bg-white text-slate-600
                 hover:text-slate-900 hover:border-slate-300 transition font-extrabold text-xl leading-none">
          ×
        </button>
      </div>
    </div>

    <!-- Body -->
    <div class="flex-1 overflow-auto">
      <div class="max-w-[1500px] mx-auto px-5 sm:px-8 py-6 grid grid-cols-1 gap-4">

        <!-- Productos -->
        <div class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
          <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
            <h3 class="font-extrabold text-slate-900">Productos</h3>
            <span id="detItemsCount"
              class="text-xs font-extrabold px-3 py-1 rounded-full bg-slate-50 border border-slate-200 text-slate-700">
              0
            </span>
          </div>

          <div id="detItems" class="p-5 grid grid-cols-1 lg:grid-cols-2 gap-4"></div>
        </div>

        <!-- Resumen -->
        <div class="rounded-3xl border border-slate-200 bg-white shadow-sm">
          <div class="px-5 py-4 border-b border-slate-200">
            <h3 class="font-extrabold text-slate-900">Resumen</h3>
            <p class="text-sm text-slate-500 mt-1">Estado, etiquetas, pago, entrega, fechas.</p>
          </div>
          <div id="detResumen" class="p-5 text-sm text-slate-800"></div>
        </div>

        <!-- JSON (colapsable) -->
        <div class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
          <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
            <h3 class="font-extrabold text-slate-900">JSON (debug)</h3>
            <button type="button" onclick="toggleJsonDetalles()"
              class="text-xs font-extrabold uppercase tracking-wide text-slate-700 hover:text-slate-900 underline">
              Mostrar / ocultar
            </button>
          </div>
          <pre id="detJson" class="hidden p-5 text-xs bg-slate-50 overflow-auto"></pre>
        </div>

      </div>
    </div>

  </div>
</div>

<!-- ✅ Drawer derecho del cliente -->
<?= view('layouts/modal_info_cliente') ?>
