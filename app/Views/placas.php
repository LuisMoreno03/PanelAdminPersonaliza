<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="csrf-token" content="<?= csrf_hash() ?>">
  <meta name="csrf-name" content="<?= csrf_token() ?>">
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

    <div class="flex flex-wrap items-center gap-2 w-full md:w-auto">
  
    <!-- ✅ Buscador -->
  <div class="relative w-full md:w-[340px]">
    <input id="searchInput" type="text" placeholder="Buscar lote o archivo..."
      class="w-full border border-gray-200 rounded-xl px-4 py-2 pr-10 outline-none focus:ring-2 focus:ring-blue-200">
    <button id="searchClear" type="button"
      class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700 hidden">
      ✕
    </button>
  </div>

  <button id="btnAbrirModalCarga" class="btn-blue whitespace-nowrap">Cargar placa</button>
</div>


    </div>

    <div id="msg" class="muted mt-2"></div>

    <div id="contenedorDias" class="space-y-6"></div>
<div id="grid" class="grid hidden"></div>

  </div>
</div>

<!-- MODAL EDITAR ARCHIVO -->
<div id="modalBackdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9999;">
  <div style="max-width:720px; margin:6vh auto; background:#fff; border-radius:16px; overflow:hidden;">
    <div style="display:flex; justify-content:space-between; align-items:center; padding:14px 16px; border-bottom:1px solid #eee;">
      <div style="font-weight:900;">Editar placa</div>
      <button id="modalClose" class="btn-blue">Cerrar</button>
    </div>

    <div style="padding:16px;">
      <div id="modalPreview" style="width:100%; height:260px; border:1px solid #eee; border-radius:14px; overflow:hidden; background:#f9fafb;"></div>

      <!-- ✅ Archivos del conjunto -->
<div style="margin-top:14px; border:1px solid #e5e7eb; border-radius:14px; padding:12px; background:#f9fafb;">
  <div style="display:flex; align-items:center; justify-content:space-between; gap:10px;">
    <div style="font-weight:900;">Archivos del conjunto</div>
    <div class="muted" id="modalLoteInfo"></div>
  </div>

  <div id="modalArchivos" style="margin-top:10px; max-height:220px; overflow:auto; display:grid; gap:10px;"></div>
</div>


      <div style="margin-top:12px;">
        <div class="text-sm text-gray-600">Nombre</div>
        <input id="modalNombre" style="width:100%; border:1px solid #e5e7eb; border-radius:12px; padding:10px;" />
      </div>

      <div style="margin-top:10px;" class="text-sm text-gray-600">
        Fecha de subida: <span id="modalFecha"></span>
      </div>

      <div style="display:flex; gap:10px; margin-top:14px; justify-content:flex-end;">
      <button id="btnGuardarNombre" type="button" class="btn-blue">Guardar</button>
      <button id="btnEliminarArchivo" type="button" class="btn-blue" style="background:#ef4444;">Eliminar</button>

      </div>

      <div id="modalMsg" class="text-sm text-gray-500 mt-2"></div>
    </div>
  </div>
</div>

<!-- MODAL CARGA (MULTI) -->
<div id="modalCargaBackdrop" class="fixed inset-0 bg-black/50 hidden z-[10000] flex items-center justify-center">
  <div class="bg-white rounded-xl w-[440px] p-6">
    <h2 class="text-xl font-black mb-4">Cargar placa</h2>

    <div class="space-y-3">
      <input id="cargaProducto" type="text" placeholder="Producto" class="w-full border rounded-xl px-3 py-2">
    <input id="cargaNumero" type="text" placeholder="Número de placa" class="w-full border rounded-xl px-3 py-2">

      <!-- ✅ Acepta cualquier archivo -->
      <input id="cargaArchivo" type="file" multiple class="w-full" accept="*/*">

      <div id="cargaPreview" class="h-40 border rounded-xl flex items-center justify-center text-gray-400">
        Vista previa
      </div>
    </div>

    <div class="flex justify-end gap-2 mt-5">
      <button id="btnCerrarCarga" class="btn-blue" style="background:#9ca3af;">Cancelar</button>
      <button id="btnGuardarCarga" class="btn-blue">Guardar</button>
    </div>

<!-- ✅ Barra de progreso DENTRO del modal -->
    <div id="uploadProgressWrap" class="mt-4 hidden">
      <div class="w-full bg-gray-100 border border-gray-200 rounded-full h-3 overflow-hidden">
        <div id="uploadProgressBar"
             class="bg-blue-600 h-3 rounded-full transition-[width] duration-150"
             style="width:0%">
        </div>
      </div>
      <div class="muted mt-1 flex items-center justify-between">
        <span id="uploadProgressLabel">Subiendo…</span>
        <span id="uploadProgressText" class="font-black">0%</span>
      </div>
    </div>

    <div id="cargaMsg" class="muted mt-2"></div>
  </div>
</div>



<script>
  const q = (id) => document.getElementById(id);

function csrfPair() {
  const name = document.querySelector('meta[name="csrf-name"]')?.getAttribute('content');
  const hash = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
  return { name, hash };
}

function addCsrf(fd) {
  const { name, hash } = csrfPair();
  if (name && hash) fd.append(name, hash);
  return fd;
}

  const API = {
  listar: <?= json_encode(site_url('placas/archivos/listar-por-dia')) ?>,
  stats:  <?= json_encode(site_url('placas/archivos/stats')) ?>,
  subir:  <?= json_encode(site_url('placas/archivos/subir-lote')) ?>,
  renombrar: <?= json_encode(site_url('placas/archivos/renombrar')) ?>,
  eliminar:   <?= json_encode(site_url('placas/archivos/eliminar')) ?>,
  descargarBase: <?= json_encode(site_url('placas/archivos/descargar')) ?>,
  descargarPngLote: <?= json_encode(site_url('placas/archivos/descargar-png-lote')) ?>,
};




  let modalItem = null;

  // ✅ mapa global para que openModal funcione aunque sea listado por grupos
  let placasMap = {}; // { id: item }
let allData = null;
let searchTerm = '';
let loteIndex = {};



  function escapeHtml(str) {
    return (str || '').replace(/[&<>"']/g, s => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[s]));
  }

  function formatFecha(fechaISO){
    if (!fechaISO) return '';
    const d = new Date(String(fechaISO).replace(' ', 'T'));
    if (isNaN(d)) return String(fechaISO);
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
        : `<div class="preview flex items-center justify-center"><div class="muted" style="padding:8px;text-align:center;">${escapeHtml(item.original || 'Archivo')}</div></div>`;

    const kb = Math.round((item.size || 0) / 1024);

    return `
      <div class="item" onclick="openModal(${item.id})">
        ${preview}
        <div class="item-title">${escapeHtml(item.nombre || 'Sin nombre')}</div>
        <div class="muted">${escapeHtml(item.original || '')} • ${kb} KB</div>
        <div class="muted"><b>Subido:</b> ${escapeHtml(formatFecha(item.created_at))}</div>
      </div>
    `;
  }

  async function cargarStats(){
    try{
      const res = await fetch(API.stats, { cache:'no-store' });
      const data = await res.json();
      if (data.success) q('placasHoy').textContent = data.data?.total ?? 0;
    }catch(e){}
  }

function normalizeText(s) {
  return String(s || '')
    .toLowerCase()
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '') // quita acentos
    .trim();
}

function itemMatches(it, term) {
  if (!term) return true;
  const hay = normalizeText([
    it.nombre,
    it.original,
    it.id,
    it.mime,
    it.url
  ].join(' '));
  return hay.includes(term);
}

function groupMatches(g, term) {
  if (!term) return true;
  const hay = normalizeText([
    g.lote_nombre,
    g.lote_id,
    g.created_at
  ].join(' '));
  return hay.includes(term);
}

function renderFromData(data) {
  placasMap = {};
  loteIndex = {};

  if (!data || !data.success) {
    q('grid').innerHTML = '<div class="muted">Error cargando archivos</div>';
    return;
  }

  const term = normalizeText(searchTerm);

  // ====== AGRUPADO ======
  if (Array.isArray(data.grupos)) {
    let grupos = data.grupos || [];

    // llenar mapa por ID y el índice por lote
    grupos.forEach(g => {
      const lid = (g.lote_id ?? g.lote_nombre ?? g.id ?? '').toString();
      const items = (g.items || []);

      loteIndex[lid] = items;

      items.forEach(it => {
        if (it.lote_id == null) it.lote_id = lid; // asegurar lote_id en cada item
        placasMap[it.id] = it;                    // mapa global por id
      });
    });

    // filtro
    if (term) {
      grupos = grupos
        .map(g => {
         const items = (g.items || []).filter(it => itemMatches(it, term));
         const gm = groupMatches(g, term);
         return gm ? g : { ...g, items };
        })
        .filter(g => groupMatches(g, term) || (g.items || []).length > 0);
    }

    if (!grupos.length) {
      q('grid').innerHTML = `<div class="muted">No hay resultados para "<b>${escapeHtml(searchTerm)}</b>".</div>`;
      return;
    }

    q('grid').innerHTML = grupos.map(g => {
      const titulo = g.lote_nombre || g.lote_id || 'Lote';
      const fecha = g.created_at ? formatFecha(g.created_at) : '';
      const cards = (g.items || []).map(renderCard).join('');

      return `
        <div style="grid-column: 1 / -1; background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:12px;">
          <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap;">
            <div style="font-weight:900;">📦 ${escapeHtml(titulo)}</div>
            <div class="muted">${escapeHtml(fecha)}</div>
          </div>
          <div style="margin-top:10px; display:grid; gap:12px; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));">
            ${cards || '<div class="muted">Sin archivos en este lote.</div>'}
          </div>
        </div>
      `;
    }).join('');

    return;
  }

  // ====== SIN AGRUPAR (compat) ======
const flat = Array.isArray(data.items)
  ? data.items
  : Array.isArray(data.data)   // ✅ tu backend actual
    ? data.data
    : null;

if (Array.isArray(flat)) {
  let items = flat;
  items.forEach(it => { placasMap[it.id] = it; });

  if (term) items = items.filter(it => itemMatches(it, term));

  q('grid').innerHTML = items.length
    ? items.map(renderCard).join('')
    : `<div class="muted">No hay resultados para "<b>${escapeHtml(searchTerm)}</b>".</div>`;
  return;
}

q('grid').innerHTML = '<div class="muted">No hay datos para mostrar.</div>';


}



  // ✅ LISTA soporta: data.grupos o data.items
async function cargarLista(){
  try{
    const res = await fetch(API.listar, { cache:'no-store' });
    const data = await res.json();

    allData = data;
    renderFromData(data);

    // si tu backend devuelve total (lo podemos agregar)
    if (data.success && data.total != null) {
      q('placasHoy').textContent = data.total;
    }
    await cargarVistaAgrupada();
  } catch(e){
    q('contenedorDias').innerHTML = '<div class="muted">Error cargando archivos</div>';
  }

}


async function cargarVistaAgrupada() {
  placasMap = {};
  loteIndex = {};

  const res = await fetch(API.listar, { cache: "no-store" });
  const data = await res.json();

  // contador hoy (usa el valor del endpoint)
  if (data?.success) q("placasHoy").textContent = data.placas_hoy ?? 0;

  const cont = q("contenedorDias");
  cont.innerHTML = "";

  if (!data.success || !Array.isArray(data.dias)) {
    cont.innerHTML = `<div class="muted">No hay datos para mostrar.</div>`;
    return;
  }

  const term = normalizeText(searchTerm);

  // filtra por buscador (fecha / lote / archivos)
  const diasFiltrados = data.dias
    .map(dia => {
      const lotes = (dia.lotes || []).map(lote => {
        const items = (lote.items || []).filter(it => itemMatches(it, term));
        const okLote = normalizeText([lote.lote_id, lote.lote_nombre, lote.created_at].join(" ")).includes(term);
        return okLote ? lote : { ...lote, items };
      }).filter(l => (l.items || []).length > 0);

      const okDia = normalizeText(dia.fecha).includes(term);
      return okDia ? dia : { ...dia, lotes, total_archivos: lotes.reduce((a,l)=>a+(l.items?.length||0),0) };
    })
    .filter(d => (d.lotes || []).length > 0);

  if (term && !diasFiltrados.length) {
    cont.innerHTML = `<div class="muted">No hay resultados para "<b>${escapeHtml(searchTerm)}</b>".</div>`;
    return;
  }

  const dias = term ? diasFiltrados : data.dias;

  for (const dia of dias) {
    const diaBox = document.createElement("div");
    diaBox.className = "card";

    diaBox.innerHTML = `
      <div class="flex items-center justify-between">
        <div>
          <div class="text-lg font-extrabold">${escapeHtml(dia.fecha)}</div>
          <div class="text-sm text-gray-500">Total: ${dia.total_archivos}</div>
        </div>
      </div>
      <div class="mt-3 space-y-3"></div>
    `;

    const lotesCont = diaBox.querySelector(".space-y-3");
    cont.appendChild(diaBox);

    for (const lote of (dia.lotes || [])) {
      const lid = String(lote.lote_id ?? "");
      const lnombre = lote.lote_nombre || lid;

      // ✅ mantener índices para el modal
      loteIndex[lid] = lote.items || [];
      (lote.items || []).forEach(it => {
        it.lote_id = it.lote_id ?? lid;
        placasMap[it.id] = it;
      });

      const loteBox = document.createElement("div");
      loteBox.className = "border rounded-xl p-3 bg-gray-50";

      loteBox.innerHTML = `
        <div class="flex items-center justify-between flex-wrap gap-2">
          <div class="font-bold">📦 ${escapeHtml(lnombre)}</div>
          <div class="text-xs text-gray-500">${escapeHtml(lote.created_at ?? "")}</div>
        </div>


        <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
          ${(lote.items || []).map(it => `
            <div class="bg-white border rounded-xl p-2 cursor-pointer" onclick="openModal(${it.id})">
              ${(it.url && (it.mime || "").startsWith("image/"))
                ? `<img src="${it.url}" class="w-full h-32 object-cover rounded-lg">`
                : `<div class="h-32 flex items-center justify-center text-gray-400">Archivo</div>`
              }
              <div class="mt-2 text-sm font-semibold break-all">${escapeHtml(it.original || "")}</div>
              <div class="text-xs text-gray-500">${Math.round((it.size || 0) / 1024)} KB</div>
              <div class="text-xs text-gray-500">${escapeHtml(it.created_at || "")}</div>
            </div>
          `).join("")}
        </div>
      `;
const principal = (lote.items || []).find(x => Number(x.is_primary) === 1) || (lote.items || [])[0];

loteBox.innerHTML = `
  <div class="flex items-center justify-between flex-wrap gap-2">
    <div class="font-bold">📦 ${escapeHtml(lnombre)}</div>
    <div class="text-xs text-gray-500">${escapeHtml(lote.created_at ?? "")}</div>
  </div>

  <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
    ${
      principal
      ? `
        <div class="bg-white border rounded-xl p-2 cursor-pointer" onclick="openLote('${escapeHtml(lid)}')">
          ${
            principal.thumb_url
              ? `<img src="${principal.thumb_url}" class="w-full h-32 object-cover rounded-lg">`
              : (principal.url && (principal.mime || "").startsWith("image/"))
                ? `<img src="${principal.url}" class="w-full h-32 object-cover rounded-lg">`
                : `<div class="h-32 flex items-center justify-center text-gray-400">Carpeta</div>`
          }
          <div class="mt-2 text-sm font-semibold">Lote ${escapeHtml(lid)}</div>
          <div class="text-xs text-gray-500">${(lote.items || []).length} archivo(s)</div>

          <div class="mt-2 flex gap-2">
            <a class="btn-blue" style="background:#10b981; padding:8px 12px; border-radius:12px;"
               href="${API.descargarPngLote}/${encodeURIComponent(lid)}"
               onclick="event.stopPropagation()">
              Descargar PNG
            </a>

            <button class="btn-blue" style="background:#111827; padding:8px 12px; border-radius:12px;"
                    onclick="event.stopPropagation(); openLote('${escapeHtml(lid)}')">
              Ver
            </button>
          </div>
        </div>
      `
      : `<div class="muted">Sin archivos</div>`
    }
  </div>
`;
      lotesCont.appendChild(loteBox);
    }
  }
}





function renderModalArchivos(list, activeId) {
  const box = q('modalArchivos');
  if (!box) return;

  if (!Array.isArray(list) || !list.length) {
    box.innerHTML = `<div class="muted">No hay archivos en este conjunto.</div>`;
    return;
  }

  box.innerHTML = list.map(it => {
    const isActive = Number(it.id) === Number(activeId);
    const kb = Math.round((it.size || 0) / 1024);

    return `
      <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px; border:1px solid #e5e7eb; border-radius:12px; background:#fff;">
        <div style="min-width:0;">
          <div style="font-weight:800; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
            ${escapeHtml(it.original || it.nombre || ('Archivo #' + it.id))}
            ${isActive ? `<span style="margin-left:8px; font-size:11px; padding:2px 8px; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-weight:800;">Actual</span>` : ''}
          </div>
          <div class="muted" style="margin-top:2px;">
            ${escapeHtml(it.mime || '')} • ${kb} KB
          </div>
        </div>

        <div style="display:flex; gap:8px; flex-shrink:0;">
          <a class="btn-blue" style="background:#10b981; padding:8px 12px; border-radius:12px;"
             href="${API.descargarBase}/${it.id}">
            Descargar
          </a>

          <button class="btn-blue" style="background:#111827; padding:8px 12px; border-radius:12px;"
                  onclick="openModal(${it.id})">
            Ver
          </button>
        </div>
      </div>
    `;
  }).join('');
}

function getLoteItemsFor(item) {
  const lid = item?.lote_id ?? '';
  if (!lid) return [item];
  return loteIndex[lid] || [item];
}

// ✅ FUERA de la función (GLOBAL)
window.openLote = function(loteId){
  const list = loteIndex[String(loteId)] || [];
  if (!list.length) return;

  const principal = list.find(x => Number(x.is_primary) === 1) || list[0];
  openModal(principal.id);
};




      

  // --- MODAL EDITAR
  window.openModal = function(id){
  const item = placasMap[id];
  if (!item) return;

  modalItem = item;

  // ✅ render preview actual
  const mime = item.mime || '';
  const isImg = mime.startsWith('image/');
  const isPdf = mime.includes('pdf');

  q('modalPreview').innerHTML = isImg
    ? `<img src="${item.url}" style="width:100%;height:100%;object-fit:contain;">`
    : isPdf
      ? `<iframe src="${item.url}" style="width:100%;height:100%;border:0;"></iframe>`
      : `<div style="height:100%;display:flex;align-items:center;justify-content:center;">
           <div class="muted" style="padding:10px;text-align:center;">${escapeHtml(item.original || 'Archivo')}</div>
         </div>`;

  q('modalNombre').value = item.nombre || '';
  q('modalFecha').textContent = formatFecha(item.created_at);
  q('modalMsg').textContent = '';

  // ✅ Archivos del conjunto (mismo lote)
  const list = getLoteItemsFor(item);
  const lid = item.lote_id ?? '';
  if (q('modalLoteInfo')) q('modalLoteInfo').textContent = lid ? `Lote: ${lid}` : '';
  renderModalArchivos(list, item.id);

  q('modalBackdrop').style.display = 'block';
}



  function closeModal(){
    q('modalBackdrop').style.display = 'none';
    modalItem = null;
  }

  q('modalClose').addEventListener('click', closeModal);
  q('modalBackdrop').addEventListener('click', (e) => {
    if (e.target.id === 'modalBackdrop') closeModal();
  });

  q('btnGuardarNombre').addEventListener('click', async () => {
  if (!modalItem) return;

  const nuevo = q('modalNombre').value.trim();
  if (!nuevo) {
    q('modalMsg').textContent = 'El nombre no puede estar vacío.';
    return;
  }

  const fd = addCsrf(new FormData());
  fd.append('id', modalItem.id);
  fd.append('nombre', nuevo);

  const res = await fetch(API.renombrar, {
    method: 'POST',
    body: fd,
    credentials: 'same-origin',
  });

  const text = await res.text();
  let data = null;
  try { data = JSON.parse(text); } catch (e) {}

  if (!res.ok) {
    q('modalMsg').textContent = `Error (${res.status}): ${text.slice(0, 140)}`;
    return;
  }

  q('modalMsg').textContent = data?.message || (data?.success ? 'Guardado' : 'Error');
  if (data?.success) await cargarLista();
});

  q('btnEliminarArchivo').addEventListener('click', async () => {
    if (!modalItem) return;
    if (!confirm('¿Eliminar esta placa?')) return;

    const fd = addCsrf(new FormData());
    fd.append('id', modalItem.id);

    const res = await fetch(API.eliminar, { method:'POST', body: fd, credentials:'same-origin' });

    const data = await res.json();

    if (data.success){
      closeModal();
      await cargarLista();
      await cargarStats();
    } else {
      q('modalMsg').textContent = data.message || 'Error';
    }
  });

  // ===== MODAL CARGA MULTI (UNA SOLA REQUEST => QUEDAN AGRUPADOS) =====
  const modalCarga = q('modalCargaBackdrop');
  let filesSeleccionados = [];

  q('btnAbrirModalCarga').addEventListener('click', () => {
    modalCarga.classList.remove('hidden');
    q('cargaMsg').textContent = '';
  });

  q('btnCerrarCarga').addEventListener('click', () => {
    modalCarga.classList.add('hidden');
    q('cargaArchivo').value = '';
    filesSeleccionados = [];
    q('cargaPreview').innerHTML = 'Vista previa';
    q('cargaMsg').textContent = '';
  });

  q('cargaArchivo').addEventListener('change', (e) => {
    filesSeleccionados = Array.from(e.target.files || []);
    const box = q('cargaPreview');

    if (!filesSeleccionados.length) {
      box.innerHTML = '<div class="text-sm text-gray-500">Vista previa</div>';
      return;
    }

    box.innerHTML = `
      <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:8px; padding:8px;">
        ${filesSeleccionados.map((f, i) => {
          const isImg = f.type.startsWith('image/');
          const isPdf = (f.type || '').includes('pdf');
          const url = (isImg || isPdf) ? URL.createObjectURL(f) : '';

          return `
            <div style="border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; background:#f9fafb; height:72px; display:flex; align-items:center; justify-content:center; position:relative;">
              ${
                isImg ? `<img src="${url}" style="width:100%; height:100%; object-fit:cover;">`
                : isPdf ? `<div style="font-size:12px;color:#6b7280;padding:6px;text-align:center;">PDF</div>`
                : `<div style="font-size:11px;color:#6b7280;padding:6px;text-align:center;word-break:break-word;">${escapeHtml(f.name)}</div>`
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
  });

  window.quitarArchivoSeleccionado = (idx) => {
    filesSeleccionados.splice(idx, 1);
    const dt = new DataTransfer();
    filesSeleccionados.forEach(f => dt.items.add(f));
    q('cargaArchivo').files = dt.files;
    q('cargaArchivo').dispatchEvent(new Event('change'));
  };

 q('btnGuardarCarga').addEventListener('click', () => {
  const producto = q('cargaProducto').value.trim();
 const numero   = q('cargaNumero').value.trim();

  if (!numero) { q('cargaMsg').textContent = 'Número de placa es obligatorio.'; return; }
  if (!filesSeleccionados.length) { q('cargaMsg').textContent = 'Selecciona uno o más archivos.'; return; }

  const wrap = q('uploadProgressWrap');
  const bar  = q('uploadProgressBar');
  const txt  = q('uploadProgressText');

  wrap.classList.remove('hidden');
  bar.style.width = '0%';
  txt.textContent = '0%';

  q('btnGuardarCarga').disabled = true;
  q('cargaMsg').textContent = `Subiendo ${filesSeleccionados.length} archivo(s)...`;

  const fd = addCsrf(new FormData());
fd.append('producto', producto);
fd.append('numero_placa', numero);
filesSeleccionados.forEach(file => fd.append('archivos[]', file));


  const xhr = new XMLHttpRequest();
  xhr.open('POST', API.subir, true);

  xhr.upload.onprogress = (e) => {
    if (!e.lengthComputable) return;
    const percent = Math.round((e.loaded / e.total) * 100);
    bar.style.width = percent + '%';
    txt.textContent = percent + '%';
  };

  xhr.onload = () => {
    q('btnGuardarCarga').disabled = false;

    let data = null;
    try { data = JSON.parse(xhr.responseText); } catch (e) {}

    if (xhr.status !== 200 || !data || !data.success) {
      q('cargaMsg').textContent = (data && data.message) ? data.message : 'Error al subir';
      return;
    }

    bar.style.width = '100%';
    txt.textContent = '100%';
   q('cargaMsg').textContent = data.message || '✅ Subidos correctamente';

    setTimeout(() => {
      modalCarga.classList.add('hidden');
      wrap.classList.add('hidden');

      q('cargaArchivo').value = '';
      filesSeleccionados = [];
      q('cargaPreview').innerHTML = '<div class="text-sm text-gray-500">Vista previa</div>';

      cargarStats();
      cargarLista();
    }, 600);
  };

  xhr.onerror = () => {
    q('btnGuardarCarga').disabled = false;
    q('cargaMsg').textContent = 'Error de red al subir.';
  };

  xhr.send(fd);
});


// ✅ Buscador (filtra sin pedirle nada al backend)
const searchInput = q('searchInput');
const searchClear = q('searchClear');

let searchT = null;
function applySearch(v) {
  searchTerm = v || '';
  if (searchClear) searchClear.classList.toggle('hidden', !searchTerm.trim());
  cargarVistaAgrupada();
  
}

if (searchInput) {
  searchInput.addEventListener('input', (e) => {
    const v = e.target.value;
    clearTimeout(searchT);
    searchT = setTimeout(() => applySearch(v), 120);
  });
}

if (searchClear) {
  searchClear.addEventListener('click', () => {
    searchInput.value = '';
    applySearch('');
    searchInput.focus();
  });
}

let refresco = null;

async function refrescarTodo() {
  // Si hay errores de BD, NO sigas spameando
  try {
    await cargarStats();
    await cargarLista();
  } catch (e) {
    console.log("Refresco detenido por error", e);
    if (refresco) clearInterval(refresco);
    refresco = null;
  }
}

// Inicial (1 sola vez)
refrescarTodo();

// Refresca cada 10 minutos (menos agresivo)
refresco = setInterval(refrescarTodo, 600000);


</script>

</body>
</html>