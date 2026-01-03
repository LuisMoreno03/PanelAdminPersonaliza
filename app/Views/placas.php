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






</body>
</html>
