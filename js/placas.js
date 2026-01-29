/* public/js/placas.js */

(function () {
  const q = (id) => document.getElementById(id);

  // ===================== CSRF =====================
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

  // ===================== API =====================
  const API = window.PLACAS_API || {};
  if (!API.listar) console.warn("PLACAS_API no está definido. Revisa placas.php");

  // ===================== STATE =====================
  let placasMap = {};     // id => item
  let loteIndex = {};     // loteId => items[]
  let loteMeta = {};      // loteId => { pedidos, productos, lote_nombre }
  let searchTerm = '';
  let modalItem = null;
  let modalSelectedId = null;
  let refresco = null;

  // ===================== UTILS =====================
  function escapeHtml(str) {
    return (str || '').replace(/[&<>"']/g, s => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[s]));
  }

  function normalizeText(s) {
    return String(s || '')
      .toLowerCase()
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .trim();
  }

  function formatFecha(fechaISO) {
    if (!fechaISO) return '';
    const d = new Date(String(fechaISO).replace(' ', 'T'));
    if (isNaN(d)) return String(fechaISO);
    return d.toLocaleString('es-ES', {
      year: 'numeric', month: '2-digit', day: '2-digit',
      hour: '2-digit', minute: '2-digit'
    });
  }

  function toArrayList(v) {
    if (!v) return [];
    if (Array.isArray(v)) return v.map(x => String(x).trim()).filter(Boolean);

    const raw = String(v).trim();
    if (!raw) return [];

    // JSON?
    try {
      const j = JSON.parse(raw);
      if (Array.isArray(j)) return j.map(x => String(x).trim()).filter(Boolean);
    } catch (e) {}

    return raw.split(/[\n,]+/g).map(s => s.trim()).filter(Boolean);
  }

  function renderBadges(containerId, items, emptyText) {
    const el = q(containerId);
    if (!el) return;

    if (!items.length) {
      el.innerHTML = `<span class="text-xs text-gray-400">${escapeHtml(emptyText)}</span>`;
      return;
    }

    el.innerHTML = items.map(t => `
      <span class="px-3 py-1 rounded-full bg-gray-100 border border-gray-200 text-xs font-black text-gray-700">
        ${escapeHtml(t)}
      </span>
    `).join('');
  }

  function itemMatches(it, term) {
    if (!term) return true;

    const pedidos = toArrayList(it?.pedidos || loteMeta[it?.lote_id]?.pedidos || []);
    const productos = toArrayList(it?.productos || loteMeta[it?.lote_id]?.productos || []);

    const hay = normalizeText([
      it.nombre,
      it.original,
      it.id,
      it.mime,
      it.url,
      it.lote_id,
      it.lote_nombre,
      pedidos.join(' '),
      productos.join(' ')
    ].join(' '));

    return hay.includes(term);
  }

  function getDownloadUrl(it) {
    return it.download_url || `${API.descargarBase}/${it.id}`;
  }

  // ===================== MODAL selection =====================
  function getSelectedItem() {
    if (!modalSelectedId) return modalItem;
    return placasMap[modalSelectedId] || modalItem;
  }

  function setSelectedItem(id) {
    modalSelectedId = Number(id);
    const it = placasMap[modalSelectedId];
    if (!it) return;

    // preview grande
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

    // nombre
    q('modalNombre').value = it.nombre || (it.original ? String(it.original).replace(/\.[^.]+$/, '') : '');
    q('modalFecha').textContent = formatFecha(it.created_at);

    // marcar activo
    document.querySelectorAll('[data-modal-file]').forEach(el => {
      const active = Number(el.dataset.modalFile) === modalSelectedId;
      el.classList.toggle('ring-2', active);
      el.classList.toggle('ring-blue-300', active);
    });

    // meta lote
    updateModalMetaByLote(it);
  }

  function updateModalMetaByLote(item) {
    const lid = item?.lote_id ? String(item.lote_id) : '';
    const meta = lid ? (loteMeta[lid] || {}) : {};

    const pedidos = toArrayList(meta.pedidos || item.pedidos);
    const productos = toArrayList(meta.productos || item.productos);

    renderBadges('modalPedidos', pedidos, 'Sin pedidos vinculados');
    renderBadges('modalProductos', productos, 'Sin productos');

    if (q('modalPedidosHint')) {
      q('modalPedidosHint').textContent = pedidos.length
        ? `Pedidos: ${pedidos.join(', ')}`
        : 'Puedes vincular pedidos al subir el lote.';
    }
    if (q('modalProductosHint')) {
      q('modalProductosHint').textContent = productos.length
        ? `Productos: ${productos.length}`
        : 'Puedes adjuntar productos al subir el lote.';
    }

    const loteNombre = (item.lote_nombre || meta.lote_nombre || '').trim();
    if (q('modalLoteInfo')) q('modalLoteInfo').textContent = loteNombre ? `Lote: ${loteNombre}` : `Lote: ${lid}`;
  }

  function getLoteItemsFor(item) {
    const lid = item?.lote_id ?? '';
    if (!lid) return [item];
    return loteIndex[lid] || [item];
  }

  // ===================== Render archivos lista (modal) =====================
  function renderModalArchivos(list, activeId) {
    const box = q('modalArchivos');
    if (!box) return;

    if (!Array.isArray(list) || !list.length) {
      box.innerHTML = `<div class="muted">No hay archivos en este conjunto.</div>`;
      return;
    }

    if (!modalSelectedId) modalSelectedId = Number(activeId);

    box.innerHTML = `
      <div class="mt-2 max-h-[260px] overflow-auto grid gap-2">
        ${list.map(it => {
          const kb = Math.round((it.size || 0) / 1024);
          const isActive = Number(it.id) === Number(modalSelectedId);
          const mime = it.mime || '';
          const isImg = mime.startsWith('image/');
          const originalUrl = getDownloadUrl(it);
          const pngUrl = `${API.descargarPng}/${it.id}`;
          const jpgUrl = `${API.descargarJpg}/${it.id}`;

          return `
            <button type="button"
              data-modal-file="${it.id}"
              onclick="window.__PLACAS_setSelected(${it.id})"
              class="w-full text-left bg-white border border-gray-200 rounded-xl p-3 flex items-center justify-between gap-3 hover:bg-gray-50 ${isActive ? 'ring-2 ring-blue-300' : ''}">
              
              <div class="min-w-0">
                <div class="font-extrabold truncate">${escapeHtml(it.nombre || it.original || ('Archivo #' + it.id))}</div>
                <div class="text-xs text-gray-500 mt-1">${escapeHtml(mime)} • ${kb} KB</div>
              </div>

              <div class="flex items-center gap-2 shrink-0">
                <a href="${originalUrl}" target="_blank" download
                   onclick="event.stopPropagation()"
                   class="px-3 py-2 rounded-xl bg-gray-900 text-white text-xs font-black hover:opacity-90">
                  ⬇ Descargar
                </a>

                ${isImg ? `
                  <a href="${pngUrl}" target="_blank"
                     onclick="event.stopPropagation()"
                     class="px-2 py-2 rounded-xl bg-emerald-500 text-white text-xs font-black hover:opacity-90">PNG</a>
                  <a href="${jpgUrl}" target="_blank"
                     onclick="event.stopPropagation()"
                     class="px-2 py-2 rounded-xl bg-sky-500 text-white text-xs font-black hover:opacity-90">JPG</a>
                ` : ''}
              </div>
            </button>
          `;
        }).join('')}
      </div>
    `;
  }

  // ===================== MODAL OPEN/CLOSE =====================
  function openModal(id) {
    const item = placasMap[id];
    if (!item) return;

    modalItem = item;
    modalSelectedId = Number(item.id);

    const list = getLoteItemsFor(item);
    renderModalArchivos(list, item.id);
    setSelectedItem(item.id);

    q('modalBackdrop').style.display = 'block';
  }

  function closeModal() {
    q('modalBackdrop').style.display = 'none';
    modalItem = null;
    modalSelectedId = null;
  }

  // Exponer algunas funciones al HTML dinámico
  window.__PLACAS_openModal = openModal;
  window.__PLACAS_setSelected = setSelectedItem;

  window.openLote = function (loteId) {
    const list = loteIndex[String(loteId)] || [];
    if (!list.length) return;
    const principal = list.find(x => Number(x.is_primary) === 1) || list[0];
    openModal(principal.id);
  };

  // ===================== Render lotes por día =====================
  async function cargarVistaAgrupada() {
    placasMap = {};
    loteIndex = {};
    loteMeta = {};

    const res = await fetch(API.listar, { cache: 'no-store' });
    const data = await res.json();

    if (data?.success) q('placasHoy').textContent = data.placas_hoy ?? 0;

    const cont = q('contenedorDias');
    cont.innerHTML = '';

    if (!data.success || !Array.isArray(data.dias)) {
      cont.innerHTML = `<div class="muted">No hay datos para mostrar.</div>`;
      return;
    }

    const term = normalizeText(searchTerm);

    let dias = data.dias;

    if (term) {
      dias = data.dias
        .map(dia => {
          const lotes = (dia.lotes || [])
            .map(lote => {
              const lid = String(lote.lote_id || '');
              const lnombre = (lote.lote_nombre || '').trim();

              const pedidos = toArrayList(lote.pedidos);
              const productos = toArrayList(lote.productos);

              // filtrar items por term
              const items = (lote.items || []).filter(it => itemMatches({
                ...it,
                lote_id: lid,
                lote_nombre: lnombre,
                pedidos,
                productos
              }, term));

              // match por lote/pedidos/productos
              const loteHay = normalizeText([
                lid, lnombre, lote.created_at,
                pedidos.join(' '),
                productos.join(' ')
              ].join(' ')).includes(term);

              return loteHay ? lote : { ...lote, items };
            })
            .filter(l => (l.items || []).length > 0);

          const okDia = normalizeText(dia.fecha).includes(term);
          return okDia ? dia : { ...dia, lotes };
        })
        .filter(d => (d.lotes || []).length > 0);
    }

    if (term && !dias.length) {
      cont.innerHTML = `<div class="muted">No hay resultados para "<b>${escapeHtml(searchTerm)}</b>".</div>`;
      return;
    }

    for (const dia of dias) {
      const diaBox = document.createElement('div');
      diaBox.className = 'card';

      diaBox.innerHTML = `
        <div class="flex items-center justify-between">
          <div>
            <div class="text-lg font-extrabold">${escapeHtml(dia.fecha)}</div>
            <div class="text-sm text-gray-500">Total: ${dia.total_archivos ?? 0}</div>
          </div>
        </div>
        <div class="mt-3 lotes-grid"></div>
      `;

      const lotesCont = diaBox.querySelector('.lotes-grid');
      cont.appendChild(diaBox);

      for (const lote of (dia.lotes || [])) {
        const lid = String(lote.lote_id ?? '');
        const lnombre = (lote.lote_nombre || '').trim() || 'Sin nombre';

        // index + meta
        loteIndex[lid] = lote.items || [];
        loteMeta[lid] = {
          lote_nombre: lnombre,
          pedidos: Array.isArray(lote.pedidos) ? lote.pedidos : (lote.pedidos || ''),
          productos: Array.isArray(lote.productos) ? lote.productos : (lote.productos || ''),
        };

        (lote.items || []).forEach(it => {
          it.lote_id = it.lote_id ?? lid;
          it.lote_nombre = it.lote_nombre ?? lnombre;
          placasMap[it.id] = it;
        });

        const principal = (lote.items || []).find(x => Number(x.is_primary) === 1) || (lote.items || [])[0];
        const thumb = principal?.thumb_url || (principal?.url && (principal.mime || '').startsWith('image/') ? principal.url : null);

        const pedidos = toArrayList(loteMeta[lid].pedidos);
        const productos = toArrayList(loteMeta[lid].productos);

        const chipPedidos = pedidos.length
          ? `<span class="px-2 py-1 rounded-full bg-white border text-xs font-black text-gray-700">🧾 ${pedidos.length} pedido(s)</span>`
          : `<span class="px-2 py-1 rounded-full bg-white border text-xs font-black text-gray-400">🧾 sin pedidos</span>`;

        const chipProductos = productos.length
          ? `<span class="px-2 py-1 rounded-full bg-white border text-xs font-black text-gray-700">🧩 ${productos.length} producto(s)</span>`
          : `<span class="px-2 py-1 rounded-full bg-white border text-xs font-black text-gray-400">🧩 sin productos</span>`;

        const loteBox = document.createElement('div');
        loteBox.className = 'lote-card';

        loteBox.innerHTML = `
          <div class="lote-left cursor-pointer" onclick="openLote('${escapeHtml(lid)}')">
            <div class="lote-thumb">
              ${thumb ? `<img src="${thumb}">` : `<div class="text-gray-400 text-xs">Carpeta</div>`}
            </div>

            <div class="min-w-0">
              <div class="lote-title">📦 ${escapeHtml(lnombre)}</div>
              <div class="lote-meta">${(lote.items || []).length} archivo(s) • ${escapeHtml(lote.created_at ?? '')}</div>

              <div class="mt-2 flex flex-wrap gap-2">
                ${chipPedidos}
                ${chipProductos}
              </div>
            </div>
          </div>

          <div class="lote-actions">
            <button class="btn-blue" style="background:#111827; padding:8px 12px;"
                    onclick="event.stopPropagation(); openLote('${escapeHtml(lid)}')">
              Ver archivos
            </button>

            <a class="btn-blue" style="background:#10b981; padding:8px 12px;"
               href="${API.descargarPngLote}/${encodeURIComponent(lid)}"
               onclick="event.stopPropagation()">
              Descargar PNG (ZIP)
            </a>

            <a class="btn-blue" style="background:#2563eb; padding:8px 12px;"
               href="${API.descargarJpgLote}/${encodeURIComponent(lid)}"
               onclick="event.stopPropagation()">
              Descargar JPG (ZIP)
            </a>
          </div>
        `;

        loteBox.onclick = () => openLote(lid);
        lotesCont.appendChild(loteBox);
      }
    }
  }

  // ===================== Stats =====================
  async function cargarStats() {
    try {
      const res = await fetch(API.stats, { cache: 'no-store' });
      const data = await res.json();
      if (data.success) q('placasHoy').textContent = data.data?.total ?? 0;
    } catch (e) {}
  }

  // ===================== Renombrar lote =====================
  async function renombrarLoteDesdeModal() {
    const sel = getSelectedItem();
    if (!sel) return;

    const loteId = sel.lote_id;
    if (!loteId) { q('modalMsg').textContent = 'Este archivo no tiene lote.'; return; }

    const actual = (sel.lote_nombre || loteMeta[loteId]?.lote_nombre || '').trim();
    const nuevo = prompt('Nuevo nombre del lote:', actual);

    if (nuevo === null) return;
    const nombre = nuevo.trim();
    if (!nombre) { q('modalMsg').textContent = 'El nombre no puede estar vacío.'; return; }

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

  // ===================== Guardar nombre archivo =====================
  async function guardarNombreArchivo() {
    const sel = getSelectedItem();
    if (!sel) return;

    const nuevo = q('modalNombre').value.trim();
    if (!nuevo) { q('modalMsg').textContent = 'El nombre no puede estar vacío.'; return; }

    const fd = addCsrf(new FormData());
    fd.append('id', sel.id);
    fd.append('nombre', nuevo);

    const res = await fetch(API.renombrar, { method: 'POST', body: fd, credentials: 'same-origin' });
    const data = await res.json().catch(() => null);

    if (!data?.success) {
      q('modalMsg').textContent = data?.message || 'Error renombrando';
      return;
    }

    q('modalMsg').textContent = '✅ Nombre actualizado';
    const keepId = sel.id;
    await cargarVistaAgrupada();
    openModal(keepId);
  }

  // ===================== Eliminar archivo =====================
  async function eliminarArchivo() {
    const sel = getSelectedItem();
    if (!sel) return;

    if (!confirm(`¿Eliminar el archivo #${sel.id}?`)) return;

    const fd = addCsrf(new FormData());
    fd.append('id', sel.id);

    const res = await fetch(API.eliminar, { method: 'POST', body: fd, credentials: 'same-origin' });
    const data = await res.json().catch(() => null);

    if (!data?.success) {
      q('modalMsg').textContent = data?.message || 'Error eliminando';
      return;
    }

    q('modalMsg').textContent = '✅ Eliminado';
    closeModal();
    await cargarVistaAgrupada();
    await cargarStats();
  }

  // ===================== Descargas modal (seleccionado) =====================
  function descargarSelPng() {
    const sel = getSelectedItem();
    if (!sel?.id) return;
    window.open(`${API.descargarPng}/${sel.id}`, '_blank');
  }
  function descargarSelJpg() {
    const sel = getSelectedItem();
    if (!sel?.id) return;
    window.open(`${API.descargarJpg}/${sel.id}`, '_blank');
  }

  // ===================== MODAL CARGA =====================
  const modalCarga = q('modalCargaBackdrop');
  let filesSeleccionados = [];

  function abrirModalCarga() {
    modalCarga.classList.remove('hidden');
    q('cargaMsg').textContent = '';
  }
  function cerrarModalCarga() {
    modalCarga.classList.add('hidden');
    q('cargaArchivo').value = '';
    filesSeleccionados = [];
    q('cargaPreview').innerHTML = 'Vista previa';
    q('cargaMsg').textContent = '';
    q('uploadProgressWrap').classList.add('hidden');
    q('uploadProgressBar').style.width = '0%';
    q('uploadProgressText').textContent = '0%';
  }

  function renderPreviewSeleccion() {
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
              ${isImg
                ? `<img src="${url}" style="width:100%; height:100%; object-fit:cover;">`
                : isPdf
                  ? `<div style="font-size:12px;color:#6b7280;padding:6px;text-align:center;">PDF</div>`
                  : `<div style="font-size:11px;color:#6b7280;padding:6px;text-align:center;word-break:break-word;">${escapeHtml(f.name)}</div>`
              }
              <button type="button"
                onclick="window.__PLACAS_quitarArchivo(${i})"
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
  }

  window.__PLACAS_quitarArchivo = (idx) => {
    filesSeleccionados.splice(idx, 1);
    const dt = new DataTransfer();
    filesSeleccionados.forEach(f => dt.items.add(f));
    q('cargaArchivo').files = dt.files;
    renderPreviewSeleccion();
  };

  function subirLote() {
    const loteNombre = (q('cargaLoteNombre')?.value || '').trim();
    const numeroPlaca = (q('cargaNumero')?.value || '').trim();
    const pedidosRaw = (q('cargaPedidos')?.value || '').trim();
    const productosRaw = (q('cargaProductos')?.value || '').trim();

    if (!loteNombre) { q('cargaMsg').textContent = 'El nombre del lote es obligatorio.'; return; }
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
    fd.append('lote_nombre', loteNombre);
    fd.append('numero_placa', numeroPlaca);
    fd.append('pedidos', pedidosRaw);
    fd.append('productos', productosRaw);

    filesSeleccionados.forEach(file => fd.append('archivos[]', file));

    const xhr = new XMLHttpRequest();
    xhr.open('POST', API.subir, true);

    xhr.upload.onprogress = (e) => {
      if (!e.lengthComputable) return;
      const percent = Math.round((e.loaded / e.total) * 100);
      bar.style.width = percent + '%';
      txt.textContent = percent + '%';
    };

    xhr.onload = async () => {
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
        cerrarModalCarga();
        await cargarStats();
        await cargarVistaAgrupada();
      }, 500);
    };

    xhr.onerror = () => {
      q('btnGuardarCarga').disabled = false;
      q('cargaMsg').textContent = 'Error de red al subir.';
    };

    xhr.send(fd);
  }

  // ===================== BUSCADOR =====================
  function applySearch(v) {
    searchTerm = v || '';
    if (q('searchClear')) q('searchClear').classList.toggle('hidden', !searchTerm.trim());
    cargarVistaAgrupada();
  }

  // ===================== INIT EVENTS =====================
  function bindEvents() {
    // modal
    q('modalClose')?.addEventListener('click', closeModal);
    q('modalBackdrop')?.addEventListener('click', (e) => {
      if (e.target.id === 'modalBackdrop') closeModal();
    });

    q('btnRenombrarLote')?.addEventListener('click', renombrarLoteDesdeModal);
    q('btnGuardarNombre')?.addEventListener('click', guardarNombreArchivo);
    q('btnEliminarArchivo')?.addEventListener('click', eliminarArchivo);
    q('btnDescargarPngSel')?.addEventListener('click', descargarSelPng);
    q('btnDescargarJpgSel')?.addEventListener('click', descargarSelJpg);

    // modal carga
    q('btnAbrirModalCarga')?.addEventListener('click', abrirModalCarga);
    q('btnCerrarCarga')?.addEventListener('click', cerrarModalCarga);
    q('btnGuardarCarga')?.addEventListener('click', subirLote);

    q('cargaArchivo')?.addEventListener('change', (e) => {
      filesSeleccionados = Array.from(e.target.files || []);
      renderPreviewSeleccion();
    });

    // buscador
    const searchInput = q('searchInput');
    const searchClear = q('searchClear');

    let t = null;
    searchInput?.addEventListener('input', (e) => {
      clearTimeout(t);
      t = setTimeout(() => applySearch(e.target.value), 120);
    });

    searchClear?.addEventListener('click', () => {
      if (!searchInput) return;
      searchInput.value = '';
      applySearch('');
      searchInput.focus();
    });
  }

  // ===================== REFRESH =====================
  async function refrescarTodo() {
    try {
      await cargarStats();
      await cargarVistaAgrupada();
    } catch (e) {
      console.log("Refresco detenido por error", e);
      if (refresco) clearInterval(refresco);
      refresco = null;
    }
  }

  // ===================== START =====================
  document.addEventListener('DOMContentLoaded', async () => {
    bindEvents();
    await refrescarTodo();
    refresco = setInterval(refrescarTodo, 600000); // 10 min
  });

})();
