<div id="modalCargaBackdrop" class="fixed inset-0 z-[10000] hidden bg-black/50 p-2 sm:p-6">
  <div class="mx-auto flex h-[96vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">

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

    <div class="flex-1 overflow-auto px-5 py-5 sm:px-7">
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

      <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
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
 