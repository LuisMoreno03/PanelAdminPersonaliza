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

     
    <!-- ENCABEZADO -->
<h1 style="font-size:28px;font-weight:900;">PLACAS</h1>

<div class="text-sm text-gray-500 mb-2">
  Placas hoy: <span id="placasHoy">0</span>
</div>

<!-- BOTONES -->
<div style="display:flex; gap:10px; margin-bottom:12px;">
  <button id="btnSeleccionar" class="btn-blue">Seleccionar archivo</button>
  <button id="btnSubir" class="btn-blue">Subir placa</button>
</div>

<!-- 🔽 🔽 🔽 AQUÍ VA LO QUE PREGUNTAS 🔽 🔽 🔽 -->
<div id="gridPlacas" class="grid gap-3 mt-3"></div>
<div id="placasMsg" class="text-sm text-gray-500 mt-2"></div>
<!-- 🔼 🔼 🔼 FIN 🔼 🔼 🔼 -->

    

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

    <!-- Vista Previa -->
      <div id="gridPlacas" class="grid gap-3 mt-3"></div>
    <div id="placasMsg" class="text-sm text-gray-500 mt-2"></div>
    
    
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



  <style>
    /* Botón azul estilo "Siguiente" */
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
  </style>
</head>



<script>
const $ = (id) => document.getElementById(id);

function escapeHtml(str) {
  return (str || '').replace(/[&<>"']/g, s => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[s]));
}

function card(item){
  const isImg = (item.mime || '').startsWith('image/');
  const isPdf = (item.mime || '').includes('pdf');

  const preview = isImg
    ? `<div class="preview"><img src="${item.url}"></div>`
    : isPdf
      ? `<div class="preview"><iframe src="${item.url}"></iframe></div>`
      : `<div class="preview" style="display:flex;align-items:center;justify-content:center;">Archivo</div>`;

  const kb = Math.round((item.size || 0) / 1024);

  return `
    <div class="item">
      ${preview}
      <div class="item-title">${escapeHtml(item.nombre)}</div>
      <div class="muted">${escapeHtml(item.original || '')} • ${kb} KB • ${escapeHtml(item.dia || '')}</div>
    </div>
  `;
}

async function cargarStats(){
  const res = await fetch('/placas/archivos/stats');
  const data = await res.json();
  if (data.success) $('placasHoy').textContent = data.totalHoy;
}

async function cargarLista(){
  const res = await fetch('/placas/archivos/listar');
  const data = await res.json();
  if (!data.success) { $('grid').innerHTML = '<div class="muted">Error cargando archivos</div>'; return; }
  $('grid').innerHTML = data.items.map(card).join('') || '<div class="muted">Aún no hay placas subidas.</div>';
}

async function subir(){
  const file = $('placaFile').files[0];
  if (!file) { $('msg').textContent = 'Selecciona un archivo primero.'; return; }

  $('btnSubir').disabled = true;
  $('msg').textContent = 'Subiendo...';

  const fd = new FormData();
  fd.append('archivo', file);

  const res = await fetch('/placas/archivos/subir', { method:'POST', body: fd });
  const data = await res.json();

  $('btnSubir').disabled = false;

  if (!data.success) { $('msg').textContent = data.message || 'Error'; return; }

  $('msg').textContent = data.message || '✅ Subido';
  $('placaFile').value = '';

  // tiempo real
  await cargarStats();
  await cargarLista();
}

$('btnSeleccionar').addEventListener('click', () => $('placaFile').click());
$('btnSubir').addEventListener('click', subir);

// load inicial
cargarStats();
cargarLista();

// opcional: refrescar conteo cada 10s
setInterval(cargarStats, 10000);




</script>

</body>
</html>
