<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Placas - Panel</title>

  <!-- Tailwind / Alpine -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/alpinejs" defer></script>

  <style>
    body { background: #f3f4f6; }

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

<body class="flex">

  <!-- Sidebar -->
  <?= view('layouts/menu') ?>

  <!-- Contenido principal -->
  <div class="flex-1 md:ml-64 p-8">

    <div class="card">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-2xl font-black">PLACAS</h1>
          <div class="muted mt-1">
            Placas hoy: <span id="placasHoy" class="font-black">0</span>
          </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <input id="placaFile" type="file" accept="image/*,application/pdf" class="hidden" style="display:none;">
          <button id="btnSeleccionar" class="btn-blue">Seleccionar archivo</button>
          <button id="btnSubir" class="btn-blue">Subir placa</button>
        </div>
      </div>

      <div id="msg" class="muted mt-2"></div>

      <!-- Aquí se renderizan las previews -->
      <div class="grid" id="grid"></div>
    </div>

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
      : `<div class="preview flex items-center justify-center">Archivo</div>`;

  const kb = Math.round((item.size || 0) / 1024);

  // Si tu API devuelve created_at, lo mostramos; si no, mostramos dia
  const fecha = item.created_at ? item.created_at : (item.dia || '');

  return `
    <div class="item">
      ${preview}
      <div class="item-title">${escapeHtml(item.nombre)}</div>
      <div class="muted">${escapeHtml(item.original || '')} • ${kb} KB • ${escapeHtml(fecha)}</div>
    </div>
  `;
}

async function cargarStats(){
  try{
    const res = await fetch('/placas/archivos/stats', { cache:'no-store' });
    const data = await res.json();
    if (data.success) $('placasHoy').textContent = data.totalHoy;
  }catch(e){}
}

async function cargarLista(){
  try{
    const res = await fetch('/placas/archivos/listar', { cache:'no-store' });
    const data = await res.json();

    if (!data.success) {
      $('grid').innerHTML = '<div class="muted">Error cargando archivos</div>';
      return;
    }

    $('grid').innerHTML = (data.items && data.items.length)
      ? data.items.map(card).join('')
      : '<div class="muted">Aún no hay placas subidas.</div>';
  }catch(e){
    $('grid').innerHTML = '<div class="muted">Error cargando archivos</div>';
  }
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

 
  await cargarStats();
  await cargarLista();
}

$('btnSeleccionar').addEventListener('click', () => $('placaFile').click());
$('btnSubir').addEventListener('click', subir);


cargarStats();
cargarLista();

// refresco “tiempo real” (por si otro usuario sube)
setInterval(() => {
  cargarStats();
  cargarLista();
}, 15000);
</script>

</body>
</html>
