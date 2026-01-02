<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Placas - Panel</title>

  <!-- Estilos -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/alpinejs" defer></script>

  <style>
    body { background: #f3f4f6; }
    @keyframes fadeIn {
      from { opacity: 0; transform: scale(0.95); }
      to   { opacity: 1; transform: scale(1); }
    }
    .animate-fadeIn { animation: fadeIn .2s ease-out; }
  </style>
</head>

<body class="flex">

  <!-- Sidebar -->
  <?= view('layouts/menu') ?>

  <!-- Contenido principal -->
  <div class="flex-1 md:ml-64 p-8">

    <!-- Encabezado -->
    <h2 class="text-xl font-semibold text-gray-700 mb-4">
      Pedidos cargados: <span id="total-pedidos">0</span>
    </h2>

    <!-- ✅ CONTENEDOR TABLA MEJORADO -->
<div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">

  <!-- Header de la tabla -->
  <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 p-5 border-b border-gray-200">
    <div>
      <h1 class="text-2xl md:text-3xl font-bold text-gray-900">Placas</h1>
      <p class="text-sm text-gray-500 mt-1">
        Pedidos cargados: <span id="total-pedidos" class="font-semibold text-gray-800">0</span>
      </p>
    </div>

    


     <!-- Buscador -->
    <div class="flex flex-col sm:flex-row gap-2 sm:items-center">
      <div class="relative">
        <input
          id="inputBuscar"
          type="text"
          placeholder="Buscar pedido, cliente, etiqueta..."
          class="w-[320px] max-w-full pl-10 pr-3 py-2 rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-200"
        />
        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">🔎</span>
      </div>

      <button
        id="btnLimpiarBusqueda"
        class="px-4 py-2 rounded-xl border border-gray-200 bg-white text-gray-700 hover:bg-gray-50 transition"
      >
        Limpiar
      </button>
    </div>

<!-- Sistema de cargado de archivos JPG/PNG -->
     <div style="display:flex; gap:10px; align-items:center; margin:12px 0;">
  <input id="archivoNombre" placeholder="Nombre (opcional)" style="padding:10px; width:240px;">
  <input id="archivoInput" type="file" style="padding:10px;">
  <button onclick="subirArchivo()" style="padding:10px 14px;">Subir</button>
</div>

<div id="archivosMsg" style="margin:8px 0; font-size:14px;"></div>

<table style="width:100%; border-collapse:collapse; margin-top:10px;">
  <thead>
    <tr>
      <th style="border:1px solid #eee; padding:8px;">Nombre</th>
      <th style="border:1px solid #eee; padding:8px;">Original</th>
      <th style="border:1px solid #eee; padding:8px;">Tipo</th>
      <th style="border:1px solid #eee; padding:8px;">Tamaño</th>
      <th style="border:1px solid #eee; padding:8px;">Acciones</th>
    </tr>
  </thead>
  <tbody id="archivosBody">
    <tr><td colspan="5" style="padding:10px;">Cargando...</td></tr>
  </tbody>
</table>

<script>
async function listarArchivos() {
  const body = document.getElementById('archivosBody');
  body.innerHTML = `<tr><td colspan="5" style="padding:10px;">Cargando...</td></tr>`;

  const res = await fetch('/placas/archivos/listar');
  const data = await res.json();

  if (!data.success) {
    body.innerHTML = `<tr><td colspan="5" style="padding:10px;">Error</td></tr>`;
    return;
  }

  if (!data.items.length) {
    body.innerHTML = `<tr><td colspan="5" style="padding:10px;">No hay archivos</td></tr>`;
    return;
  }

  body.innerHTML = '';
  data.items.forEach(it => {
    const kb = Math.round((it.size || 0) / 1024);
    body.innerHTML += `
      <tr>
        <td style="border:1px solid #eee; padding:8px;">
          <input value="${escapeHtml(it.nombre)}" data-id="${it.id}" style="padding:8px; width:100%;">
        </td>
        <td style="border:1px solid #eee; padding:8px;">${escapeHtml(it.original || '')}</td>
        <td style="border:1px solid #eee; padding:8px;">${escapeHtml(it.mime || '')}</td>
        <td style="border:1px solid #eee; padding:8px;">${kb} KB</td>
        <td style="border:1px solid #eee; padding:8px; display:flex; gap:8px;">
          <button onclick="renombrar(${it.id})">Guardar</button>
          <button onclick="eliminarArchivo(${it.id})">Eliminar</button>
        </td>
      </tr>
    `;
  });
}

async function subirArchivo() {
  const input = document.getElementById('archivoInput');
  const nombre = document.getElementById('archivoNombre').value.trim();
  const msg = document.getElementById('archivosMsg');

  if (!input.files || !input.files[0]) {
    msg.textContent = 'Selecciona un archivo.';
    return;
  }

  msg.textContent = 'Subiendo...';

  const fd = new FormData();
  fd.append('archivo', input.files[0]);
  fd.append('nombre', nombre);

  const res = await fetch('/placas/archivos/subir', { method: 'POST', body: fd });
  const data = await res.json();

  msg.textContent = data.message || (data.success ? 'OK' : 'Error');

  if (data.success) {
    input.value = '';
    document.getElementById('archivoNombre').value = '';
    listarArchivos();
  }
}

async function renombrar(id) {
  const msg = document.getElementById('archivosMsg');
  const input = document.querySelector(`input[data-id="${id}"]`);
  const nombre = input ? input.value.trim() : '';

  if (!nombre) {
    msg.textContent = 'El nombre no puede estar vacío.';
    return;
  }

  const fd = new FormData();
  fd.append('id', id);
  fd.append('nombre', nombre);

  const res = await fetch('/placas/archivos/renombrar', { method: 'POST', body: fd });
  const data = await res.json();

  msg.textContent = data.message || (data.success ? 'Guardado' : 'Error');
}

async function eliminarArchivo(id) {
  if (!confirm('¿Eliminar este archivo?')) return;

  const msg = document.getElementById('archivosMsg');
  const fd = new FormData();
  fd.append('id', id);

  const res = await fetch('/placas/archivos/eliminar', { method: 'POST', body: fd });
  const data = await res.json();

  msg.textContent = data.message || (data.success ? 'Eliminado' : 'Error');

  if (data.success) listarArchivos();
}

function escapeHtml(str) {
  return (str || '').replace(/[&<>"']/g, s => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[s]));
}

listarArchivos();
</script>




    <!-- Paginación arriba -->
    <div class="flex items-center gap-2">
      <button id="btnAnterior"
        disabled
        class="px-4 py-2 rounded-xl border border-gray-200 bg-gray-50 text-gray-700 hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed transition">
        Anterior
      </button>

      <button id="btnSiguiente"
        onclick="paginaSiguiente()"
        class="px-4 py-2 rounded-xl bg-blue-600 text-white hover:bg-blue-700 active:scale-[0.99] transition">
        Siguiente
      </button>
    </div>
  </div>

  <!-- Tabla responsive -->
  <div class="w-full overflow-x-auto">
    <table class="min-w-[1400px] w-full text-sm">
      <thead class="bg-gray-50 sticky top-0 z-10">
        <tr class="text-left text-xs uppercase tracking-wider text-gray-500">
          <th class="px-5 py-4">Pedido</th>
          <th class="px-5 py-4">Fecha</th>
          <th class="px-5 py-4">Cliente</th>
          <th class="px-5 py-4">Total</th>
          <th class="px-5 py-4">Estado</th>
          <th class="px-5 py-4">Etiquetas</th>
          <th class="px-5 py-4">Artículos</th>
          <th class="px-5 py-4">Estado entrega</th>
          <th class="px-5 py-4">Forma entrega</th>
          <th class="px-5 py-4 text-right">Detalles</th>
        </tr>
      </thead>
        <tbody id="tablaPedidos" class="text-gray-800"></tbody>
      </table>
    </div>

    <!-- Footer de la tabla (paginación abajo) -->
  <div class="flex items-center justify-between gap-2 p-4 border-t border-gray-200 bg-white">
    <span class="text-xs text-gray-500">
     ====== Consejo: puedes desplazarte horizontalmente si hay muchas columnas. ======
    </span>

    <div class="flex items-center gap-2">
      <button id="btnAnteriorBottom"
        disabled
        class="px-4 py-2 rounded-xl border border-gray-200 bg-gray-50 text-gray-700 hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed transition"
        onclick="paginaAnterior?.()">
        Anterior
      </button>

      <button id="btnSiguienteBottom"
        onclick="paginaSiguiente()"
        class="px-4 py-2 rounded-xl bg-blue-600 text-white hover:bg-blue-700 active:scale-[0.99] transition">
        Siguiente
      </button>
    </div>
  </div>
</div>

  <!-- MODAL DETALLES -->
  <div id="modalDetalles"
    class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50">

    <div class="bg-white w-[90%] h-[92%] rounded-2xl shadow-2xl p-6 overflow-hidden flex flex-col animate-fadeIn">

      <div class="flex justify-between items-center border-b pb-4">
        <h2 id="tituloPedido" class="text-2xl font-bold text-gray-800">Detalles del pedido</h2>

        <div class="flex gap-3">
          <button onclick="abrirPanelCliente()"
            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            Información del cliente
          </button>

          <button onclick="cerrarModalDetalles()"
            class="px-4 py-2 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400">
            Cerrar
          </button>
        </div>
      </div>

      <div id="detalleProductos"
        class="flex-1 overflow-auto grid grid-cols-1 md:grid-cols-2 gap-4 p-4"></div>

      <div id="detalleTotales" class="border-t pt-4 text-lg font-semibold text-gray-800"></div>

      <div class="flex gap-2 mb-4">
        <button onclick="mostrarTodos()" class="px-4 py-2 bg-gray-300 rounded-lg hover:bg-gray-400">
          Todos
        </button>

        <button onclick="filtrarPreparados()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
          Preparados
        </button>
      </div>

    </div>
  </div>

  <!-- PANEL CLIENTE -->
  <div id="panelCliente"
    class="hidden fixed inset-0 flex justify-end bg-black/30 backdrop-blur-sm z-50">
    <div class="w-[380px] h-full bg-white shadow-xl p-6 overflow-y-auto animate-fadeIn">
      <div class="flex justify-between items-center mb-4">
        <h3 class="text-xl font-bold text-gray-800">Información del cliente</h3>
        <button onclick="cerrarPanelCliente()" class="text-gray-600 hover:text-gray-900 text-2xl font-bold">×</button>
      </div>

      <div id="detalleCliente" class="space-y-2 mb-6"></div>

      <h3 class="text-lg font-bold mt-6">Dirección de envío</h3>
      <div id="detalleEnvio" class="space-y-1 mb-6"></div>

      <h3 class="text-lg font-bold mt-6">Resumen del pedido</h3>
      <div id="detalleResumen" class="space-y-1 mb-6"></div>
    </div>
  </div>

  <!-- LOADER GLOBAL -->
  <div id="globalLoader"
    class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-[9999]">
    <div class="bg-white p-6 rounded-xl shadow-xl text-center animate-fadeIn">
      <div class="w-10 h-10 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto"></div>
      <p class="mt-3 font-semibold text-gray-700">Cargando...</p>
    </div>
  </div>

  <!-- Variables globales para JS -->
  <script>
    window.etiquetasPredeterminadas = <?= json_encode($etiquetasPredeterminadas ?? []) ?>;
    window.estadoFiltro = "Preparado";
  </script>

  <!-- JS principal -->
  <script src="<?= base_url('js/placas.js') ?>" defer></script>

</body>
</html>
     