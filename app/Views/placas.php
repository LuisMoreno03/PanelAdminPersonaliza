<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Placas - Panel</title>

 
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/alpinejs" defer></script>

  <style>
    body { background: #f3f4f6; }

 
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
      cursor:pointer;
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

 
<?= view('layouts/menu') ?>


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
  <button id="btnAbrirModalCarga" class="btn-blue">
    Cargar placa
  </button>
</div>

      </div>

      <div id="msg" class="muted mt-2"></div>


      <div class="grid" id="grid"></div>
    </div>

  </div>

  <!-- MODAL -->
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

<script>
  const q = (id) => document.getElementById(id);

  const API = {
    listar: <?= json_encode(site_url('placas/archivos/listar')) ?>,
    stats:  <?= json_encode(site_url('placas/archivos/stats')) ?>,
    subir:  <?= json_encode(site_url('placas/archivos/subir')) ?>,
    renombrar: <?= json_encode(site_url('placas/archivos/renombrar')) ?>,
    eliminar:   <?= json_encode(site_url('placas/archivos/eliminar')) ?>,
  };

  let modalItem = null;
  let placas = [];

  function escapeHtml(str) {
    return (str || '').replace(/[&<>"']/g, s => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[s]));
  }

  function formatFecha(fechaISO){
    if (!fechaISO) return '';
    const d = new Date(fechaISO.replace(' ', 'T'));
    if (isNaN(d)) return fechaISO;
    return d.toLocaleString('es-ES', {
      year:'numeric', month:'2-digit', day:'2-digit',
      hour:'2-digit', minute:'2-digit'
    });
  }

  function renderCard(item){
    const mime = item.mime || '';
    const isImg = mime.startsWith('image/');
    const isPdf = mime.includes('pdf');

    const preview = isImg
      ? `<div class="preview"><img src="${item.url}"></div>`
      : isPdf
        ? `<div class="preview"><iframe src="${item.url}"></iframe></div>`
        : `<div class="preview flex items-center justify-center">Archivo</div>`;

    const kb = Math.round((item.size || 0) / 1024);

    return `
      <div class="item" onclick="openModal(${item.id})">
        ${preview}
        <div class="item-title">${escapeHtml(item.nombre)}</div>
        <div class="muted">${escapeHtml(item.original || '')} • ${kb} KB</div>
        <div class="muted"><b>Subido:</b> ${escapeHtml(formatFecha(item.created_at))}</div>
      </div>
    `;
  }

  async function cargarLista(){
  const res = await fetch(API.listar, { cache:'no-store' });
  const data = await res.json();

  if (!data.success) {
    q('grid').innerHTML = '<div class="muted">Error cargando archivos</div>';
    return;
  }

  const grupos = data.grupos || [];

  q('grid').innerHTML = grupos.map(g => {
    const titulo = g.lote_nombre ? g.lote_nombre : `Lote ${g.lote_id}`;
    const fecha = g.created_at ? formatFecha(g.created_at) : '';
    const cantidad = (g.items || []).length;

    return `
      <div style="grid-column:1/-1; background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:12px; margin-top:6px;">
        <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
          <div style="font-weight:900;">${escapeHtml(titulo)} <span class="muted">(${cantidad} archivos)</span></div>
          <div class="muted">${escapeHtml(fecha)}</div>
        </div>
      </div>
      ${(g.items || []).map(renderCard).join('')}
    `;
  }).join('') || '<div class="muted">Aún no hay placas subidas.</div>';
}


  async function cargarStats(){
    const res = await fetch(API.stats, { cache:'no-store' });
    const data = await res.json();
    if (data.success) q('placasHoy').textContent = data.totalHoy;
  }

  async function subir(){
    const file = q('placaFile').files[0];
    if (!file) { q('msg').textContent = 'Selecciona un archivo primero.'; return; }

    q('btnSubir').disabled = true;
    q('msg').textContent = 'Subiendo...';

    const fd = new FormData();
    fd.append('archivo', file);

    const res = await fetch(API.subir, { method:'POST', body: fd });
    const data = await res.json();

    q('btnSubir').disabled = false;

    if (!data.success) { q('msg').textContent = data.message || 'Error'; return; }

    q('msg').textContent = data.message || '✅ Subido';
    q('placaFile').value = '';

    await cargarStats();
    await cargarLista();
  }

  // --- MODAL
  window.openModal = function(id){
    const item = placas.find(x => Number(x.id) === Number(id));
    if (!item) return;

    modalItem = item;

    const mime = item.mime || '';
    const isImg = mime.startsWith('image/');
    const isPdf = mime.includes('pdf');

    q('modalPreview').innerHTML = isImg
      ? `<img src="${item.url}" style="width:100%;height:100%;object-fit:contain;">`
      : isPdf
        ? `<iframe src="${item.url}" style="width:100%;height:100%;border:0;"></iframe>`
        : `<div style="height:100%;display:flex;align-items:center;justify-content:center;">Archivo</div>`;

    q('modalNombre').value = item.nombre || '';
    q('modalFecha').textContent = formatFecha(item.created_at);
    q('modalMsg').textContent = '';

    q('modalBackdrop').style.display = 'block';
  }

  function closeModal(){
    q('modalBackdrop').style.display = 'none';
    modalItem = null;
  }

  q('modalClose').addEventListener('click', closeModal);
  q('modalBackdrop').addEventListener('click', (e) => { if (e.target.id === 'modalBackdrop') closeModal(); });

  q('btnGuardarNombre').addEventListener('click', async () => {
    if (!modalItem) return;
    const nuevo = q('modalNombre').value.trim();
    if (!nuevo) { q('modalMsg').textContent = 'El nombre no puede estar vacío.'; return; }

    const fd = new FormData();
    fd.append('id', modalItem.id);
    fd.append('nombre', nuevo);

    const res = await fetch(API.renombrar, { method:'POST', body: fd });
    const data = await res.json();

    q('modalMsg').textContent = data.message || (data.success ? 'Guardado' : 'Error');
    if (data.success) await cargarLista();
  });

  q('btnEliminarArchivo').addEventListener('click', async () => {
    if (!modalItem) return;
    if (!confirm('¿Eliminar esta placa?')) return;

    const fd = new FormData();
    fd.append('id', modalItem.id);

    const res = await fetch(API.eliminar, { method:'POST', body: fd });
    const data = await res.json();

    if (data.success){
      closeModal();
      await cargarLista();
      await cargarStats();
    } else {
      q('modalMsg').textContent = data.message || 'Error';
    }
  });



  // Inicial
  cargarStats();
  cargarLista();

  // “tiempo real” cada 15s
  setInterval(() => {
    cargarStats();
    cargarLista();
  }, 15000);
</script>


<!-- CARGA DE NUEVA PLACA-->

<div id="modalCargaBackdrop" class="fixed inset-0 bg-black/50 hidden z-[10000] flex items-center justify-center">
  <div class="bg-white rounded-xl w-[420px] p-6 animate-fadeIn">

    <h2 class="text-xl font-black mb-4">Cargar placa</h2>

    <div class="space-y-3">
      <input id="cargaProducto" type="text" placeholder="Producto"
        class="w-full border rounded-xl px-3 py-2">

      <input id="cargaNumero" type="text" placeholder="Número de placa"
        class="w-full border rounded-xl px-3 py-2">

      <input id="cargaArchivo" type="file" accept="image/*,application/pdf" multiple class="w-full">


      <div id="cargaPreview"
        class="h-40 border rounded-xl flex items-center justify-center text-gray-400">
        Vista previa
      </div>
    </div>

    <div class="flex justify-end gap-2 mt-5">
      <button id="btnCerrarCarga" class="btn-blue bg-gray-400">
        Cancelar
      </button>
      <button id="btnGuardarCarga" class="btn-blue">
        Guardar
      </button>
    </div>

    <div id="cargaMsg" class="muted mt-2"></div>

  </div>
</div>

<script>

// PANTALLA EMERGENTE DE PLACA //

const modalCarga = q('modalCargaBackdrop');

q('btnAbrirModalCarga').onclick = () => {
  modalCarga.classList.remove('hidden');
};

q('btnCerrarCarga').onclick = () => {
  modalCarga.classList.add('hidden');
  q('cargaArchivo').value = '';
  q('cargaPreview').innerHTML = 'Vista previa';
  q('cargaMsg').textContent = '';
};

// ===== MULTIUPLOAD (modal) =====
let filesSeleccionados = [];

q('cargaArchivo').onchange = (e) => {
  filesSeleccionados = Array.from(e.target.files || []);
  const box = q('cargaPreview');

  if (!filesSeleccionados.length) {
    box.innerHTML = '<div class="text-sm text-gray-500">Vista previa</div>';
    return;
  }

  // Render thumbnails
  box.innerHTML = `
    <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:8px; padding:8px;">
      ${filesSeleccionados.map((f, i) => {
        const isImg = f.type.startsWith('image/');
        const url = isImg ? URL.createObjectURL(f) : '';
        return `
          <div style="border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; background:#f9fafb; height:72px; display:flex; align-items:center; justify-content:center; position:relative;">
            ${isImg
              ? `<img src="${url}" style="width:100%; height:100%; object-fit:cover;">`
              : `<div style="font-size:12px; color:#6b7280; padding:6px; text-align:center;">${f.name}</div>`
            }
            <button type="button"
              onclick="window.quitarArchivoSeleccionado(${i})"
              style="position:absolute; top:6px; right:6px; background:rgba(0,0,0,.6); color:#fff; border:0; width:22px; height:22px; border-radius:999px; cursor:pointer;">
              ×
            </button>
          </div>
        `;
      }).join('')}
    </div>
    <div class="muted" style="padding:0 8px 8px;">
      ${filesSeleccionados.length} archivo(s) seleccionado(s)
    </div>
  `;
};

window.quitarArchivoSeleccionado = (idx) => {
  filesSeleccionados.splice(idx, 1);

  // reconstruir FileList
  const dt = new DataTransfer();
  filesSeleccionados.forEach(f => dt.items.add(f));
  q('cargaArchivo').files = dt.files;

  // refrescar preview
  q('cargaArchivo').dispatchEvent(new Event('change'));
};

q('btnGuardarCarga').onclick = async () => {
  const producto = q('cargaProducto').value.trim();
  const numero   = q('cargaNumero').value.trim();

  if (!numero) { q('cargaMsg').textContent = 'Número de placa es obligatorio.'; return; }
  if (!filesSeleccionados.length) { q('cargaMsg').textContent = 'Selecciona uno o más archivos.'; return; }

  q('btnGuardarCarga').disabled = true;

  for (let i = 0; i < filesSeleccionados.length; i++) {
    const file = filesSeleccionados[i];
    q('cargaMsg').textContent = `Subiendo ${i+1}/${filesSeleccionados.length}...`;

    const fd = new FormData();
    fd.append('archivo', file);            // 👈 como tu backend actual
    fd.append('producto', producto);
    fd.append('numero_placa', numero);

    const res = await fetch(API.subir, { method:'POST', body: fd });
    const data = await res.json();

    if (!data.success) {
      q('btnGuardarCarga').disabled = false;
      q('cargaMsg').textContent = data.message || 'Error al subir';
      return;
    }
  }

  q('btnGuardarCarga').disabled = false;
  q('cargaMsg').textContent = '✅ Subidos correctamente';

  // cerrar + limpiar
  q('modalCargaBackdrop').classList.add('hidden');
  q('cargaArchivo').value = '';
  filesSeleccionados = [];
  q('cargaPreview').innerHTML = '<div class="text-sm text-gray-500">Vista previa</div>';

  // refrescar
  await cargarStats();
  await cargarLista();
};





</script>

</body>
</html>
