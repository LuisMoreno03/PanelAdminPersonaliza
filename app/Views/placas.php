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
     
    <div class="text-sm text-gray-500 mb-2">
  Placas hoy: <span id="placasHoy" class="font-semibold">0</span>
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

  <!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PLACAS</title>

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

<body style="background:#f3f4f6; padding:24px;">

  <div class="card">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
      <div>
        <h1 style="margin:0; font-size:28px; font-weight:900;">PLACAS</h1>
        <div class="muted" style="margin-top:6px;">
          Placas hoy: <span id="placasHoy" style="font-weight:900;">0</span>
        </div>
      </div>

      <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <input id="placaFile" type="file" accept="image/*,application/pdf" class="hidden" style="display:none;">
        <button id="btnSeleccionar" class="btn-blue">Seleccionar archivo</button>
        <button id="btnSubir" class="btn-blue">Subir placa</button>
      </div>
    </div>

    <div id="msg" class="muted" style="margin-top:10px;"></div>

    <div class="grid" id="grid"></div>
  </div>

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

<div id="modalBackdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9999;">
  <div style="max-width:720px; margin:6vh auto; background:#fff; border-radius:16px; overflow:hidden;">
    <div style="display:flex; justify-content:space-between; align-items:center; padding:14px 16px; border-bottom:1px solid #eee;">
      <div style="font-weight:900;">Editar placa</div>
      <button id="modalClose" class="btn-blue">Cerrar</button>
    </div>

    <div style="padding:16px;">
      <div id="modalPreview" style="width:100%; height:260px; border:1px solid #eee; border-radius:14px; overflow:hidden; background:#f9fafb;"></div>

      <div style="margin-top:12px;">
        <div class="text-sm text-gray-600">Nombre</div>
        <input id="modalNombre" style="width:100%; border:1px solid #e5e7eb; border-radius:12px; padding:10px;" />
      </div>

      <div style="margin-top:10px;" class="text-sm text-gray-600">
        Fecha de subida: <span id="modalFecha"></span>
      </div>

      <div style="display:flex; gap:10px; margin-top:14px; justify-content:flex-end;">
        <button id="btnGuardarNombre" class="btn-blue">Guardar</button>
        <button id="btnEliminarArchivo" class="btn-blue" style="background:#ef4444;">Eliminar</button>
      </div>

      <div id="modalMsg" class="text-sm text-gray-500 mt-2"></div>
    </div>
  </div>
</div>
</body>

<script>
const $ = (id) => document.getElementById(id);

let modalItem = null;

// Formatear fecha bonita
function formatFecha(fechaISO){
  if (!fechaISO) return '';
  const d = new Date(fechaISO.replace(' ', 'T')); // "YYYY-MM-DD HH:mm:ss"
  if (isNaN(d)) return fechaISO;
  return d.toLocaleString('es-ES', { year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit' });
}

function escapeHtml(str) {
  return (str || '').replace(/[&<>"']/g, s => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[s]));
}

function renderCard(item){
  const mime = item.mime || '';
  const isImg = mime.startsWith('image/');
  const isPdf = mime.includes('pdf');
  const fecha = formatFecha(item.created_at);

  const preview = isImg
    ? `<img src="${item.url}" style="width:100%;height:160px;object-fit:cover;">`
    : isPdf
      ? `<iframe src="${item.url}" style="width:100%;height:160px;border:0;"></iframe>`
      : `<div style="height:160px;display:flex;align-items:center;justify-content:center;">Archivo</div>`;

  return `
    <div onclick='openModal(${item.id})' style="cursor:pointer;border:1px solid #e5e7eb;border-radius:14px;padding:12px;background:#fff;">
      <div style="border:1px solid #eee;border-radius:12px;overflow:hidden;background:#f9fafb;">${preview}</div>
      <div style="font-weight:900;margin-top:10px;">${escapeHtml(item.nombre)}</div>
      <div style="font-size:12px;color:#6b7280;margin-top:4px;">
        ${escapeHtml(item.original || '')}<br>
        <b>Subido:</b> ${escapeHtml(fecha)}
      </div>
      <div style="margin-top:10px;">
        <button class="btn-blue" onclick="event.stopPropagation(); openModal(${item.id})">Modificar</button>
      </div>
    </div>
  `;
}

async function cargarPlacas(){
  try{
    const res = await fetch('/placas/archivos/listar', { cache: 'no-store' });
    const data = await res.json();

    if (!data.success) throw new Error('Respuesta inválida');

    // Guardamos para modal
    window.__placas = data.items || [];

    $('gridPlacas').innerHTML =
      (data.items && data.items.length)
        ? data.items.map(renderCard).join('')
        : `<div class="text-sm text-gray-500">Aún no hay placas subidas.</div>`;

    $('placasMsg').textContent = '';
  }catch(e){
    $('gridPlacas').innerHTML = '';
    $('placasMsg').textContent = 'Error cargando archivos';
  }
}

async function cargarStats(){
  try{
    const res = await fetch('/placas/archivos/stats', { cache: 'no-store' });
    const data = await res.json();
    if (data.success) {
      // si tienes un span de "Placas hoy" en tu UI:
      const el = document.getElementById('placasHoy');
      if (el) el.textContent = data.totalHoy;
    }
  }catch(e){}
}

// ===== Modal =====
function openModal(id){
  const item = (window.__placas || []).find(x => Number(x.id) === Number(id));
  if (!item) return;

  modalItem = item;

  // Preview
  const mime = item.mime || '';
  const isImg = mime.startsWith('image/');
  const isPdf = mime.includes('pdf');

  $('modalPreview').innerHTML = isImg
    ? `<img src="${item.url}" style="width:100%;height:100%;object-fit:contain;">`
    : isPdf
      ? `<iframe src="${item.url}" style="width:100%;height:100%;border:0;"></iframe>`
      : `<div style="height:100%;display:flex;align-items:center;justify-content:center;">Archivo</div>`;

  $('modalNombre').value = item.nombre || '';
  $('modalFecha').textContent = formatFecha(item.created_at);
  $('modalMsg').textContent = '';

  $('modalBackdrop').style.display = 'block';
}

function closeModal(){
  $('modalBackdrop').style.display = 'none';
  modalItem = null;
}

$('modalClose').addEventListener('click', closeModal);
$('modalBackdrop').addEventListener('click', (e) => { if (e.target.id === 'modalBackdrop') closeModal(); });

$('btnGuardarNombre').addEventListener('click', async () => {
  if (!modalItem) return;
  const nuevo = $('modalNombre').value.trim();
  if (!nuevo) { $('modalMsg').textContent = 'El nombre no puede estar vacío.'; return; }

  const fd = new FormData();
  fd.append('id', modalItem.id);
  fd.append('nombre', nuevo);

  const res = await fetch('/placas/archivos/renombrar', { method:'POST', body: fd });
  const data = await res.json();
  $('modalMsg').textContent = data.message || (data.success ? 'Guardado' : 'Error');

  if (data.success){
    await cargarPlacas(); // tiempo real
  }
});

$('btnEliminarArchivo').addEventListener('click', async () => {
  if (!modalItem) return;
  if (!confirm('¿Eliminar esta placa?')) return;

  const fd = new FormData();
  fd.append('id', modalItem.id);

  const res = await fetch('/placas/archivos/eliminar', { method:'POST', body: fd });
  const data = await res.json();
  $('modalMsg').textContent = data.message || (data.success ? 'Eliminado' : 'Error');

  if (data.success){
    closeModal();
    await cargarPlacas();
    await cargarStats();
  }
});

// ===== “Tiempo real” =====
// 1) carga inicial
cargarPlacas();
cargarStats();

// 2) refresco automático (si alguien más sube en otra PC)
setInterval(() => {
  cargarStats();
  cargarPlacas();
}, 15000); // cada 15s
</script>

</body>
</html>
