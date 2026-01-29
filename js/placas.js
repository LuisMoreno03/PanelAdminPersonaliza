/* =========================================================
   PLACAS - JS
   Archivo: public/js/placas.js
   Requiere: window.PLACAS_CONFIG.API (inyectado desde la vista)
========================================================= */

/* =========================
   1) Helpers
========================= */
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

function normalizeText(s) {
  return String(s || '')
    .toLowerCase()
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
    .trim();
}

/* =========================
   2) Config / Estado
========================= */
const API = (window.PLACAS_CONFIG && window.PLACAS_CONFIG.API) ? window.PLACAS_CONFIG.API : {};

let modalItem = null;
let placasMap = {};   // { id: item }
let loteIndex = {};   // { lote_id: [items] }
let allData = null;
let searchTerm = '';
let modalSelectedId = null;

/* =========================
   3) Selección modal
========================= */
function getSelectedItem() {
  if (!modalSelectedId) return modalItem;
  return placasMap[modalSelectedId] || modalItem;
}

function setSelectedItem(id) {
  modalSelectedId = Number(id);

  const it = placasMap[modalSelectedId];
  if (!it) return;

  const mime = it.mime || '';
  const isImg = mime.startsWith('image/');
  const isPdf = mime.includes('pdf');

  q('modalPreview').innerHTML = isImg
    ? `<img src="${it.url}" style="width:100%;height:100%;object-fit:contain;">`
    : isPdf
      ? `<iframe src="${it.url}" style="width:100%;height:100%;border:0;"></iframe>`
      : `<div style="height:100%;display:flex;align-items:center;justify-content:center;">
           <div class="muted" style="padding:10px;text-align:center;">${escapeHtml(it.original || 'Archivo')}</div>
         </div>`;

  q('modalNombre').value = it.nombre || (it.original ? String(it.original).replace(/\.[^.]+$/, '') : '');
  q('modalFecha').textContent = formatFecha(it.created_at);

  document.querySelectorAll('[data-modal-file]').forEach(el => {
    const ok = Number(el.dataset.modalFile) === modalSelectedId;
    el.classList.toggle('ring-2', ok);
    el.classList.toggle('ring-blue-300', ok);
  });
}

/* =========================
   4) Render cards / modal list
========================= */
function itemMatches(it, term) {
  if (!term) return true;
  const hay = normalizeText([it.nombre, it.original, it.id, it.mime, it.url].join(' '));
  return hay.includes(term);
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

function renderModalArchivos(list, activeId) {
  const box = q('modalArchivos');
  if (!box) return;

  if (!Array.isArray(list) || !list.length) {
    box.innerHTML = `<div class="muted">No hay archivos en este conjunto.</div>`;
    return;
  }

  if (!modalSelectedId) modalSelectedId = Number(activeId);

  box.innerHTML = list.map(it => {
    const kb = Math.round((it.size || 0) / 1024);
    const isActive = Number(it.id) === Number(modalSelectedId);
    const title = it.nombre || it.original || ('Archivo #' + it.id);

    return `
      <button type="button"
        data-modal-file="${it.id}"
        onclick="setSelectedItem(${it.id})"
        class="w-full text-left bg-white border rounded-xl p-3 flex items-center justify-between gap-3 hover:bg-gray-50 ${isActive ? 'ring-2 ring-blue-300' : ''}">
        <div class="min-w-0">
          <div class="font-extrabold truncate">${escapeHtml(title)}</div>
          <div class="text-xs text-gray-500 mt-1">
            ${escapeHtml(it.mime || '')} • ${kb} KB
          </div>
        </div>
        <div class="text-xs px-2 py-1 rounded-full bg-gray-100 text-gray-600 shrink-0">
          #${it.id}
        </div>
      </button>
    `;
  }).join('');
}

function getLoteItemsFor(item) {
  const lid = item?.lote_id ?? '';
  if (!lid) return [item];
  return loteIndex[lid] || [item];
}

/* =========================
   5) Carga / Listado
========================= */
async function cargarStats(){
  try{
    const res = await fetch(API.stats, { cache:'no-store' });
    const data = await res.json();
    if (data.success) q('placasHoy').textContent = data.data?.total ?? 0;
  }catch(e){}
}

async function cargarVistaAgrupada() {
  placasMap = {};
  loteIndex = {};

  const res = await fetch(API.listar, { cache: "no-store" });
  const data = await res.json();

  allData = data;

  if (data?.success) q("placasHoy").textContent = data.placas_hoy ?? 0;

  const cont = q("contenedorDias");
  cont.innerHTML = "";

  if (!data.success || !Array.isArray(data.dias)) {
    cont.innerHTML = `<div class="muted">No hay datos para mostrar.</div>`;
    return;
  }

  const term = normalizeText(searchTerm);

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
      <div class="mt-3 lotes-grid"></div>
    `;

    const lotesCont = diaBox.querySelector(".lotes-grid");
    cont.appendChild(diaBox);

    for (const lote of (dia.lotes || [])) {
      const lid = String(lote.lote_id ?? "");
      const lnombre = (lote.lote_nombre || '').trim() || 'Sin nombre';
      const total = (lote.items || []).length;

      loteIndex[lid] = lote.items || [];
      (lote.items || []).forEach(it => {
        it.lote_id = it.lote_id ?? lid;
        it.lote_nombre = it.lote_nombre ?? lnombre;
        placasMap[it.id] = it;
      });

      const principal = (lote.items || []).find(x => Number(x.is_primary) === 1) || (lote.items || [])[0];
      const thumb = principal?.thumb_url || (principal?.url && (principal.mime || "").startsWith("image/") ? principal.url : null);

      const loteBox = document.createElement("div");
      loteBox.className = "lote-card";

      loteBox.innerHTML = `
        <div class="lote-left cursor-pointer" onclick="openLote('${escapeHtml(lid)}')">
          <div class="lote-thumb">
            ${thumb ? `<img src="${thumb}">` : `<div class="text-gray-400 text-xs">Carpeta</div>`}
          </div>

          <div class="min-w-0">
            <div class="lote-title">📦 ${escapeHtml(lnombre)}</div>
            <div class="lote-meta">${total} archivo(s) • ${escapeHtml(lote.created_at ?? "")}</div>
          </div>
        </div>

        <div class="lote-actions">
          <button class="btn-blue" style="background:#111827; padding:8px 12px;"
                  onclick="event.stopPropagation(); openLote('${escapeHtml(lid)}')">
            Ver
          </button>

          <a class="btn-blue" style="background:#10b981; padding:8px 12px;"
            href="${API.descargarPngLote}/${encodeURIComponent(lid)}"
            onclick="event.stopPropagation()">
            Descargar PNG
          </a>

          <a class="btn-blue" style="background:#2563eb; padding:8px 12px;"
            href="${API.descargarJpgLote}/${encodeURIComponent(lid)}"
            onclick="event.stopPropagation()">
            Descargar JPG
          </a>
        </div>
      `;

      loteBox.onclick = () => openLote(lid);
      lotesCont.appendChild(loteBox);
    }
  }
}

/* =========================
   6) Modal editar
========================= */
window.openLote = function(loteId){
  const list = loteIndex[String(loteId)] || [];
  if (!list.length) return;
  const principal = list.find(x => Number(x.is_primary) === 1) || list[0];
  openModal(principal.id);
};

window.openModal = function(id){
  const item = placasMap[id];
  if (!item) return;

  modalItem = item;

  q('modalNombre').value = item.nombre || '';
  q('modalFecha').textContent = formatFecha(item.created_at);
  q('modalMsg').textContent = '';

  const list = getLoteItemsFor(item);
  modalSelectedId = Number(item.id);
  renderModalArchivos(list, item.id);
  setSelectedItem(item.id);

  const loteNombre = (item.lote_nombre || '').trim();
  if (q('modalLoteInfo')) q('modalLoteInfo').textContent = loteNombre ? `Lote: ${loteNombre}` : '';

  q('modalBackdrop').style.display = 'block';
};

function closeModal(){
  q('modalBackdrop').style.display = 'none';
  modalItem = null;
  modalSelectedId = null;
}

async function renombrarLoteDesdeModal() {
  const sel = getSelectedItem();
  if (!sel) return;

  const loteId = sel.lote_id;
  if (!loteId) {
    q('modalMsg').textContent = 'Este archivo no tiene lote.';
    return;
  }

  const actual = (sel.lote_nombre || '').trim();
  const nuevo = prompt('Nuevo nombre del lote:', actual);
  if (nuevo === null) return;

  const nombre = nuevo.trim();
  if (!nombre) {
    q('modalMsg').textContent = 'El nombre del lote no puede estar vacío.';
    return;
  }

  const fd = addCsrf(new FormData());
  fd.append('lote_id', String(loteId));
  fd.append('lote_nombre', nombre);

  const res = await fetch(API.renombrarLote, { method: 'POST', body: fd, credentials: 'same-origin' });
  const data = await res.json().catch(() => null);

  if (!data?.success) {
    q('modalMsg').textContent = data?.message || 'Error renombrando el lote';
    return;
  }

  q('modalMsg').textContent = '✅ Lote renombrado';

  const keepId = sel.id;
  await cargarVistaAgrupada();
  openModal(keepId);
}

/* =========================
   7) Modal acciones (guardar / borrar / descargas)
========================= */
function wireModalEvents(){
  q('modalClose').addEventListener('click', closeModal);

  q('modalBackdrop').addEventListener('click', (e) => {
    if (e.target.id === 'modalBackdrop') closeModal();
  });

  q('btnRenombrarLote').addEventListener('click', renombrarLoteDesdeModal);

  q('btnGuardarNombre').addEventListener('click', async () => {
    const sel = getSelectedItem();
    if (!sel) return;

    const nuevo = q('modalNombre').value.trim();
    if (!nuevo) { q('modalMsg').textContent = 'El nombre no puede estar vacío.'; return; }

    const fd = addCsrf(new FormData());
    fd.append('id', sel.id);
    fd.append('nombre', nuevo);

    const res = await fetch(API.renombrar, { method: 'POST', body: fd, credentials: 'same-origin' });
    const text = await res.text();

    let data = null;
    try { data = JSON.parse(text); } catch (e) {}

    if (!res.ok) {
      q('modalMsg').textContent = `Error (${res.status}): ${text.slice(0, 140)}`;
      return;
    }

    q('modalMsg').textContent = data?.message || (data?.success ? 'Guardado' : 'Error');
    if (data?.success) {
      const keepId = sel.id;
      await cargarVistaAgrupada();
      openModal(keepId);
    }
  });

  q('btnEliminarArchivo').addEventListener('click', async () => {
    const sel = getSelectedItem();
    if (!sel) return;

    if (!confirm(`¿Eliminar el archivo #${sel.id}?`)) return;

    const fd = addCsrf(new FormData());
    fd.append('id', sel.id);

    const res = await fetch(API.eliminar, { method:'POST', body: fd, credentials:'same-origin' });
    const data = await res.json().catch(()=>null);

    if (data?.success){
      closeModal();
      await cargarVistaAgrupada();
      await cargarStats();
    } else {
      q('modalMsg').textContent = data?.message || 'Error';
    }
  });

  q('btnDescargarPngSel').addEventListener('click', () => {
    const sel = getSelectedItem();
    if (!sel?.id) return;
    window.open(`${API.descargarPng}/${sel.id}`, '_blank');
  });

  q('btnDescargarJpgSel').addEventListener('click', () => {
    const sel = getSelectedItem();
    if (!sel?.id) return;
    window.open(`${API.descargarJpg}/${sel.id}`, '_blank');
  });
}

/* =========================
   8) Modal carga (multi)
========================= */
function wireUploadModal(){
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
    q('uploadProgressWrap').classList.add('hidden');
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
              ${isImg ? `<img src="${url}" style="width:100%; height:100%; object-fit:cover;">`
                : isPdf ? `<div style="font-size:12px;color:#6b7280;padding:6px;text-align:center;">PDF</div>`
                : `<div style="font-size:11px;color:#6b7280;padding:6px;text-align:center;word-break:break-word;">${escapeHtml(f.name)}</div>`}
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
    const numero = q('cargaNumero').value.trim();
    const loteNombreManual = q('cargaLoteNombre')?.value.trim();

    if (!loteNombreManual) { q('cargaMsg').textContent = 'El nombre del lote es obligatorio.'; return; }
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
    fd.append('numero_placa', numero);
    fd.append('lote_nombre', loteNombreManual);
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

      setTimeout(async () => {
        modalCarga.classList.add('hidden');
        wrap.classList.add('hidden');

        q('cargaArchivo').value = '';
        filesSeleccionados = [];
        q('cargaPreview').innerHTML = '<div class="text-sm text-gray-500">Vista previa</div>';

        await cargarStats();
        await cargarVistaAgrupada();
      }, 600);
    };

    xhr.onerror = () => {
      q('btnGuardarCarga').disabled = false;
      q('cargaMsg').textContent = 'Error de red al subir.';
    };

    xhr.send(fd);
  });
}

/* =========================
   9) Buscador
========================= */
function wireSearch(){
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
}

/* =========================
   10) Inicialización + refresco
========================= */
async function refrescarTodo() {
  try {
    await cargarStats();
    await cargarVistaAgrupada();
  } catch (e) {
    console.log("Refresco detenido por error", e);
  }
}

document.addEventListener('DOMContentLoaded', async () => {
  wireModalEvents();
  wireUploadModal();
  wireSearch();

  await refrescarTodo();
  setInterval(refrescarTodo, 600000);
});
