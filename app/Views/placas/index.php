<!-- CONTADOR DIARIO -->
<div class="text-sm text-gray-500 mb-2">
  Placas hoy: <span id="placasHoy" class="font-semibold">0</span>
</div>

<!-- ESTILO BOTÓN TIPO "SIGUIENTE" -->
  <style>
    .btn-primary{
      background:#2563eb;
      color:#fff;
      padding:10px 16px;
      border-radius:12px;
      font-weight:600;
      border:1px solid rgba(255,255,255,.12);
      transition:.15s;
    }
    .btn-primary:hover{ filter:brightness(1.05); }
    .btn-primary:disabled{ opacity:.5; cursor:not-allowed; }
  </style>
</head>

<!-- LISTADO / PREVIEW -->
<div class="mt-4">
  <div id="placasMsg" class="text-sm text-gray-500 mb-2"></div>

  <div id="placasGrid" class="grid gap-3" style="grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));">
  </div>
</div>

<script>
const $ = (id) => document.getElementById(id);

function escapeHtml(str) {
  return (str || '').replace(/[&<>"']/g, s => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[s]));
}

function renderCard(item){
  const isImg = (item.mime || '').startsWith('image/');
  const isPdf = (item.mime || '').includes('pdf');

  const preview = isImg
    ? `<img src="${item.url}" style="width:100%; height:150px; object-fit:cover; border-radius:10px;" />`
    : isPdf
      ? `<iframe src="${item.url}" style="width:100%; height:150px; border-radius:10px; border:1px solid #eee;"></iframe>`
      : `<div style="height:150px; display:flex; align-items:center; justify-content:center; border:1px solid #eee; border-radius:10px;">Archivo</div>`;

  return `
    <div style="border:1px solid #e5e7eb; border-radius:14px; padding:12px; background:#fff;">
      ${preview}
      <div style="margin-top:10px; font-weight:700;">${escapeHtml(item.nombre)}</div>
      <div style="font-size:12px; color:#6b7280;">${escapeHtml(item.original || '')}</div>
      <div style="display:flex; gap:8px; margin-top:10px;">
        <input data-id="${item.id}" value="${escapeHtml(item.nombre)}" style="flex:1; border:1px solid #e5e7eb; border-radius:10px; padding:8px; font-size:13px;">
        <button onclick="renombrarPlaca(${item.id})" class="btn-primary" style="padding:8px 12px;">Guardar</button>
        <button onclick="eliminarPlaca(${item.id})" class="btn-primary" style="padding:8px 12px; background:#ef4444;">X</button>
      </div>
    </div>
  `;
}

async function cargarPlacas(){
  const res = await fetch('/placas/archivos/listar');
  const data = await res.json();
  if (!data.success) {
    $('placasGrid').innerHTML = '<div>Error cargando placas</div>';
    return;
  }
  $('placasGrid').innerHTML = data.items.map(renderCard).join('');
}

async function cargarStats(){
  const res = await fetch('/placas/archivos/stats');
  const data = await res.json();
  if (data.success) $('placasHoy').textContent = data.totalHoy;
}

async function subirPlaca(){
  const file = $('placaFile').files[0];
  const nombre = $('placaNombre').value.trim();
  if (!file) { $('placasMsg').textContent = 'Selecciona un archivo.'; return; }

  $('btnSubirPlaca').disabled = true;
  $('placasMsg').textContent = 'Subiendo...';

  const fd = new FormData();
  fd.append('archivo', file);
  fd.append('nombre', nombre);

  const res = await fetch('/placas/archivos/subir', { method:'POST', body: fd });
  const data = await res.json();

  $('btnSubirPlaca').disabled = false;

  if (!data.success) {
    $('placasMsg').textContent = data.message || 'Error';
    return;
  }

  $('placasMsg').textContent = '✅ Placa subida';
  $('placaFile').value = '';
  $('placaNombre').value = '';

  // Tiempo real: recargar lista + contador
  await cargarPlacas();
  await cargarStats();
}

async function renombrarPlaca(id){
  const input = document.querySelector(`input[data-id="${id}"]`);
  const nombre = input ? input.value.trim() : '';
  if (!nombre) return;

  const fd = new FormData();
  fd.append('id', id);
  fd.append('nombre', nombre);

  const res = await fetch('/placas/archivos/renombrar', { method:'POST', body: fd });
  const data = await res.json();
  $('placasMsg').textContent = data.message || (data.success ? 'Guardado' : 'Error');
}

async function eliminarPlaca(id){
  if (!confirm('¿Eliminar esta placa?')) return;

  const fd = new FormData();
  fd.append('id', id);

  const res = await fetch('/placas/archivos/eliminar', { method:'POST', body: fd });
  const data = await res.json();
  $('placasMsg').textContent = data.message || (data.success ? 'Eliminado' : 'Error');

  if (data.success){
    await cargarPlacas();
    await cargarStats();
  }
}

$('btnSeleccionarPlaca').addEventListener('click', () => $('placaFile').click());
$('btnSubirPlaca').addEventListener('click', subirPlaca);

// primer render
cargarPlacas();
cargarStats();

// (opcional) “tiempo real” por polling cada 10s
setInterval(() => { cargarStats(); }, 10000);
</script>
