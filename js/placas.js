(() => {
  const CFG = window.PLACAS_CONFIG;
  const q = (id) => document.getElementById(id);

  // ---------------- CSRF ----------------
  function csrfPair() {
    const name = document.querySelector('meta[name="csrf-name"]')?.getAttribute('content') || CFG?.csrf?.name;
    const hash = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || CFG?.csrf?.hash;
    return { name, hash };
  }
  function addCsrf(fd) {
    const { name, hash } = csrfPair();
    if (name && hash) fd.append(name, hash);
    return fd;
  }

  // --------------- Helpers ---------------
  function escapeHtml(str) {
    return String(str || '').replace(/[&<>"']/g, s => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[s]));
  }
  function normalizeText(s) {
    return String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').trim();
  }
  function formatFecha(fechaISO){
    if (!fechaISO) return '';
    const d = new Date(String(fechaISO).replace(' ', 'T'));
    if (isNaN(d)) return String(fechaISO);
    return d.toLocaleString('es-ES', {year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit'});
  }

  // ----------------- State -----------------
  let searchTerm = '';
  let placasMap = {};
  let loteIndex = {};
  let modalItem = null;
  let modalSelectedId = null;

  // pedidos
  let pedidosData = [];
  const selectedPedidos = new Set();

  // ----------------- API -----------------
  const API = CFG.api;

  async function cargarStats(){
    try{
      const res = await fetch(API.stats, { cache:'no-store' });
      const data = await res.json();
      if (data?.success) q('placasHoy').textContent = data.data?.total ?? 0;
    }catch(e){}
  }

  // ----------------- Render: Vista por días -----------------
  async function cargarVistaAgrupada() {
    placasMap = {};
    loteIndex = {};

    const res = await fetch(API.listar, { cache: "no-store" });
    const data = await res.json();

    if (data?.success) q("placasHoy").textContent = data.placas_hoy ?? 0;

    const cont = q("contenedorDias");
    cont.innerHTML = "";

    if (!data.success || !Array.isArray(data.dias)) {
      cont.innerHTML = `<div class="muted">No hay datos para mostrar.</div>`;
      return;
    }

    const term = normalizeText(searchTerm);

    // filtra por buscador (fecha / lote / archivos / pedidos)
    const dias = data.dias
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

    if (term && !dias.length) {
      cont.innerHTML = `<div class="muted">No hay resultados para "<b>${escapeHtml(searchTerm)}</b>".</div>`;
      return;
    }

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

        // index para modal
        loteIndex[lid] = lote.items || [];
        (lote.items || []).forEach(it => {
          it.lote_id = it.lote_id ?? lid;
          it.lote_nombre = it.lote_nombre ?? lnombre;
          placasMap[it.id] = it;
        });

        const principal = (lote.items || []).find(x => Number(x.is_primary) === 1) || (lote.items || [])[0];
        const thumb = principal?.thumb_url || (principal?.url && (principal.mime || "").startsWith("image/") ? principal.url : null);

        // contar pedidos asignados (si existen)
        const pedidosCount = (principal?.pedidos && Array.isArray(principal.pedidos)) ? principal.pedidos.length : 0;

        const loteBox = document.createElement("div");
        loteBox.className = "lote-card";

        loteBox.innerHTML = `
          <div class="lote-left cursor-pointer">
            <div class="lote-thumb">
              ${thumb ? `<img src="${thumb}">` : `<div class="text-gray-400 text-xs">Carpeta</div>`}
            </div>

            <div class="min-w-0">
              <div class="lote-title">📦 ${escapeHtml(lnombre)}</div>
              <div class="lote-meta">
                ${total} archivo(s)
                ${pedidosCount ? `• <b>${pedidosCount}</b> pedido(s)` : ''}
                • ${escapeHtml(lote.created_at ?? "")}
              </div>
            </div>
          </div>

          <div class="lote-actions">
            <button class="btn-blue" style="background:#111827; padding:8px 12px;">Ver</button>

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

        loteBox.addEventListener('click', () => openLote(lid));
        loteBox.querySelector('button')?.addEventListener('click', (e)=>{ e.stopPropagation(); openLote(lid); });

        lotesCont.appendChild(loteBox);
      }
    }
  }

  function itemMatches(it, term) {
    if (!term) return true;
    const pedidosTxt = Array.isArray(it.pedidos) ? it.pedidos.map(p => p.numero || p.pedido_display || p.label || '').join(' ') : '';
    const hay = normalizeText([it.nombre, it.original, it.id, it.mime, it.url, it.lote_id, it.lote_nombre, pedidosTxt].join(' '));
    return hay.includes(term);
  }

  // ----------------- Modal Editar -----------------
  function getLoteItemsFor(item) {
    const lid = item?.lote_id ?? '';
    if (!lid) return [item];
    return loteIndex[lid] || [item];
  }

  function getSelectedItem() {
    if (!modalSelectedId) return modalItem;
    return placasMap[modalSelectedId] || modalItem;
  }

  function setSelectedItem(id) {
    modalSelectedId = Number(id);
    const it = placasMap[modalSelectedId];
    if (!it) return;

    // preview
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

    // marcar activo
    document.querySelectorAll('[data-modal-file]').forEach(el => {
      const ok = Number(el.dataset.modalFile) === modalSelectedId;
      el.classList.toggle('ring-2', ok);
      el.classList.toggle('ring-blue-300', ok);
    });

    // render pedidos (desde este item)
    renderModalPedidos(it.pedidos || []);
  }

  function renderModalPedidos(pedidos) {
    const box = q('modalPedidos');
    if (!box) return;

    if (!Array.isArray(pedidos) || !pedidos.length) {
      box.innerHTML = `<div class="muted">No hay pedidos asignados.</div>`;
      return;
    }

    box.innerHTML = pedidos.map(p => {
      const n = p.numero || p.pedido_display || p.label || '';
      const c = p.cliente || '';
      const f = p.fecha || '';
      return `
        <div class="bg-white border rounded-xl p-3 flex items-center justify-between gap-2">
          <div class="min-w-0">
            <div class="font-black truncate">${escapeHtml(n)}</div>
            <div class="text-xs text-gray-500 mt-1 truncate">
              ${escapeHtml(c)} ${f ? '• ' + escapeHtml(f) : ''}
            </div>
          </div>
          <span class="text-xs px-2 py-1 rounded-full bg-emerald-50 text-emerald-700 font-black">Por producir</span>
        </div>
      `;
    }).join('');
  }

  function renderModalArchivos(list, activeId) {
    const box = q('modalArchivos');
    if (!box) return;

    if (!Array.isArray(list) || !list.length) {
      box.innerHTML = `<div class="muted">No hay archivos en este lote.</div>`;
      return;
    }

    if (!modalSelectedId) modalSelectedId = Number(activeId);

    box.innerHTML = list.map(it => {
      const kb = Math.round((it.size || 0) / 1024);
      const isActive = Number(it.id) === Number(modalSelectedId);
      const title = it.nombre || it.original || ('Archivo #' + it.id);

      return `
        <div class="bg-white border rounded-xl p-3 flex items-center justify-between gap-3 ${isActive ? 'ring-2 ring-blue-300' : ''}"
             data-modal-file="${it.id}">
          <button type="button" class="text-left flex-1 min-w-0"
              onclick="window.__setSelectedModalFile(${it.id})">
            <div class="font-extrabold truncate">${escapeHtml(title)}</div>
            <div class="text-xs text-gray-500 mt-1">${escapeHtml(it.mime || '')} • ${kb} KB • #${it.id}</div>
          </button>

          <div class="flex items-center gap-2 shrink-0">
            <a class="btn-blue" style="background:#0ea5e9;padding:8px 10px;"
               href="${API.descargarJpg}/${it.id}" target="_blank" onclick="event.stopPropagation()">
              JPG
            </a>
            <a class="btn-blue" style="background:#10b981;padding:8px 10px;"
               href="${API.descargarPng}/${it.id}" target="_blank" onclick="event.stopPropagation()">
              PNG
            </a>
          </div>
        </div>
      `;
    }).join('');
  }

  window.__setSelectedModalFile = (id) => setSelectedItem(id);

  function openModal(id){
    const item = placasMap[id];
    if (!item) return;

    modalItem = item;
    modalSelectedId = Number(item.id);

    const list = getLoteItemsFor(item);
    renderModalArchivos(list, item.id);
    setSelectedItem(item.id);

    const loteNombre = (item.lote_nombre || '').trim();
    q('modalLoteInfo').textContent = loteNombre ? `Lote: ${loteNombre}` : '';

    q('modalBackdrop').style.display = 'block';
  }

  function closeModal(){
    q('modalBackdrop').style.display = 'none';
    modalItem = null;
    modalSelectedId = null;
  }

  function openLote(loteId){
    const list = loteIndex[String(loteId)] || [];
    if (!list.length) return;

    const principal = list.find(x => Number(x.is_primary) === 1) || list[0];
    openModal(principal.id);
  }

  // ----------------- Modal Carga: Pedidos -----------------
  async function cargarPedidosPorProducir() {
    q('ppMsg').textContent = 'Cargando pedidos...';
    try {
      const res = await fetch(API.pedidos, { cache:'no-store' });
      const data = await res.json();

      if (!data?.success) {
        q('ppMsg').textContent = data?.message || 'Error cargando pedidos';
        pedidosData = [];
        renderPedidos();
        return;
      }

      pedidosData = Array.isArray(data.items) ? data.items : [];
      q('ppMsg').textContent = pedidosData.length ? `${pedidosData.length} pedido(s) encontrados.` : 'No hay pedidos en Por producir.';
      renderPedidos();
    } catch(e) {
      q('ppMsg').textContent = 'Error de red cargando pedidos.';
      pedidosData = [];
      renderPedidos();
    }
  }

  function renderPedidos() {
    const listBox = q('ppList');
    const selBox  = q('ppSelected');
    const linkBox = q('ppLinked');
    const term = normalizeText(q('ppSearch')?.value || '');

    const filtered = pedidosData.filter(p => {
      if (!term) return true;
      const hay = normalizeText([p.pedido_display, p.numero, p.cliente, p.fecha, p.label].join(' '));
      return hay.includes(term);
    });

    if (!filtered.length) {
      listBox.innerHTML = `<div class="muted">No hay resultados.</div>`;
    } else {
      listBox.innerHTML = filtered.map(p => {
        const id = String(p.id);
        const checked = selectedPedidos.has(id);
        const num = p.pedido_display || p.numero || p.label || ('Pedido ' + id);
        const cliente = p.cliente || '';
        const fecha = p.fecha || '';
        const arts = (p.articulos != null) ? `• ${escapeHtml(p.articulos)} art.` : '';

        return `
          <label class="bg-white border rounded-xl p-3 flex items-start gap-3 cursor-pointer hover:bg-gray-50">
            <input type="checkbox" ${checked ? 'checked' : ''} data-pedido-id="${escapeHtml(id)}" style="margin-top:4px;width:16px;height:16px;">
            <div class="min-w-0 flex-1">
              <div class="flex items-center justify-between gap-2">
                <div class="font-black truncate">${escapeHtml(num)}</div>
                <span class="text-xs px-2 py-1 rounded-full bg-emerald-50 text-emerald-700 font-black">Por producir</span>
              </div>
              <div class="text-xs text-gray-500 mt-1 truncate">${escapeHtml(cliente)} ${fecha ? '• '+escapeHtml(fecha) : ''} ${arts}</div>
            </div>
          </label>
        `;
      }).join('');

      listBox.querySelectorAll('input[type="checkbox"][data-pedido-id]').forEach(chk => {
        chk.addEventListener('change', () => {
          const id = String(chk.dataset.pedidoId);
          if (chk.checked) selectedPedidos.add(id);
          else selectedPedidos.delete(id);
          renderSeleccionados();
        });
      });
    }

    renderSeleccionados();

    function renderSeleccionados(){
      const selectedArr = Array.from(selectedPedidos);
      if (!selectedArr.length) {
        selBox.innerHTML = `<div class="muted">Selecciona pedidos “Por producir”.</div>`;
        linkBox.innerHTML = `<div class="muted">Al seleccionar, aquí se muestran vinculados.</div>`;
        return;
      }

      const items = selectedArr.map(id => pedidosData.find(x => String(x.id) === id)).filter(Boolean);

      selBox.innerHTML = items.map(p => {
        const num = p.pedido_display || p.numero || p.label || '';
        return `
          <div class="bg-white border rounded-xl p-3 flex items-center justify-between gap-2">
            <div class="font-black truncate">${escapeHtml(num)}</div>
            <button type="button" class="text-xs font-black text-red-600 hover:underline"
              data-remove="${escapeHtml(p.id)}">Quitar</button>
          </div>
        `;
      }).join('');

      selBox.querySelectorAll('button[data-remove]').forEach(btn => {
        btn.addEventListener('click', () => {
          selectedPedidos.delete(String(btn.dataset.remove));
          renderPedidos();
        });
      });

      linkBox.innerHTML = items.map(p => {
        const num = p.pedido_display || p.numero || p.label || '';
        const cliente = p.cliente || '';
        return `
          <div class="bg-white border rounded-xl p-3">
            <div class="font-black truncate">${escapeHtml(num)}</div>
            <div class="text-xs text-gray-500 mt-1 truncate">${escapeHtml(cliente)}</div>
          </div>
        `;
      }).join('');
    }
  }

  // ----------------- Modal Carga: Files preview -----------------
  const modalCarga = q('modalCargaBackdrop');
  let filesSeleccionados = [];

  function resetCargaModal(){
    q('cargaLoteNombre').value = '';
    q('cargaNumero').value = '';
    q('cargaArchivo').value = '';
    filesSeleccionados = [];
    q('cargaPreview').innerHTML = 'Vista previa';
    q('cargaMsg').textContent = '';
    selectedPedidos.clear();
    q('ppSearch').value = '';
    renderPedidos();
  }

  q('btnAbrirModalCarga').addEventListener('click', async () => {
    modalCarga.classList.remove('hidden');
    q('cargaMsg').textContent = '';
    await cargarPedidosPorProducir();
  });

  q('btnCerrarCarga').addEventListener('click', () => {
    modalCarga.classList.add('hidden');
    resetCargaModal();
  });

  q('ppSearch')?.addEventListener('input', () => renderPedidos());

  q('cargaArchivo').addEventListener('change', (e) => {
    filesSeleccionados = Array.from(e.target.files || []);
    const box = q('cargaPreview');

    if (!filesSeleccionados.length) {
      box.innerHTML = '<div class="text-sm text-gray-500">Vista previa</div>';
      return;
    }

    box.innerHTML = `
      <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:8px; padding:8px;">
        ${filesSeleccionados.map((f) => {
          const isImg = f.type.startsWith('image/');
          const isPdf = (f.type || '').includes('pdf');
          const url = (isImg || isPdf) ? URL.createObjectURL(f) : '';

          return `
            <div style="border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; background:#f9fafb; height:92px; display:flex; align-items:center; justify-content:center;">
              ${
                isImg ? `<img src="${url}" style="width:100%; height:100%; object-fit:cover;">`
                : isPdf ? `<div style="font-size:12px;color:#6b7280;">PDF</div>`
                : `<div style="font-size:11px;color:#6b7280;padding:6px;text-align:center;word-break:break-word;">${escapeHtml(f.name)}</div>`
              }
            </div>
          `;
        }).join('')}
      </div>
      <div class="muted" style="padding:0 8px 8px;">
        ${filesSeleccionados.length} archivo(s) seleccionado(s)
      </div>
    `;
  });

  // ----------------- Guardar carga -----------------
  q('btnGuardarCarga').addEventListener('click', () => {
    const loteNombre = q('cargaLoteNombre')?.value.trim();
    const numeroPlaca = q('cargaNumero')?.value.trim();

    if (!loteNombre) { q('cargaMsg').textContent = 'El nombre del lote es obligatorio.'; return; }
    if (!filesSeleccionados.length) { q('cargaMsg').textContent = 'Selecciona uno o más archivos.'; return; }
    if (!selectedPedidos.size) { q('cargaMsg').textContent = 'Selecciona al menos 1 pedido en Por producir.'; return; }

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

    // ✅ pedidos_json (asignación)
    const payloadPedidos = Array.from(selectedPedidos).map(id => {
      const it = pedidosData.find(x => String(x.id) === String(id));
      return {
        id: String(id),
        numero: it?.pedido_display || it?.numero || it?.label || '',
        cliente: it?.cliente || '',
        fecha: it?.fecha || '',
        articulos: it?.articulos ?? null,
        estado: it?.estado || 'Por producir'
      };
    });
    fd.append('pedidos_json', JSON.stringify(payloadPedidos));

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
        modalCarga.classList.add('hidden');
        wrap.classList.add('hidden');
        resetCargaModal();
        await cargarStats();
        await cargarVistaAgrupada();
      }, 450);
    };

    xhr.onerror = () => {
      q('btnGuardarCarga').disabled = false;
      q('cargaMsg').textContent = 'Error de red al subir.';
    };

    xhr.send(fd);
  });

  // ----------------- Edit modal listeners -----------------
  q('modalClose').addEventListener('click', closeModal);
  q('modalBackdrop').addEventListener('click', (e) => {
    if (e.target.id === 'modalBackdrop') closeModal();
  });

  async function renombrarLoteDesdeModal() {
    const sel = getSelectedItem();
    if (!sel) return;

    const loteId = sel.lote_id;
    const actual = (sel.lote_nombre || '').trim();
    const nuevo = prompt('Nuevo nombre del lote:', actual);
    if (nuevo === null) return;

    const nombre = nuevo.trim();
    if (!nombre) { q('modalMsg').textContent = 'El nombre no puede estar vacío.'; return; }

    const fd = addCsrf(new FormData());
    fd.append('lote_id', String(loteId));
    fd.append('lote_nombre', nombre);

    const res = await fetch(API.renombrarLote, { method:'POST', body: fd, credentials:'same-origin' });
    const data = await res.json().catch(()=>null);
    if (!data?.success) { q('modalMsg').textContent = data?.message || 'Error renombrando'; return; }

    q('modalMsg').textContent = '✅ Lote renombrado';
    const keepId = sel.id;
    await cargarVistaAgrupada();
    openModal(keepId);
  }

  q('btnRenombrarLote').addEventListener('click', renombrarLoteDesdeModal);

  q('btnGuardarNombre').addEventListener('click', async () => {
    const sel = getSelectedItem();
    if (!sel) return;

    const nuevo = q('modalNombre').value.trim();
    if (!nuevo) { q('modalMsg').textContent = 'El nombre no puede estar vacío.'; return; }

    const fd = addCsrf(new FormData());
    fd.append('id', sel.id);
    fd.append('nombre', nuevo);

    const res = await fetch(API.renombrar, { method:'POST', body: fd, credentials:'same-origin' });
    const data = await res.json().catch(()=>null);

    if (!data?.success) { q('modalMsg').textContent = data?.message || 'Error renombrando'; return; }
    q('modalMsg').textContent = '✅ Nombre actualizado';

    await cargarVistaAgrupada();
    openModal(sel.id);
  });

  q('btnEliminarArchivo').addEventListener('click', async () => {
    const sel = getSelectedItem();
    if (!sel) return;
    if (!confirm(`¿Eliminar el archivo #${sel.id}?`)) return;

    const fd = addCsrf(new FormData());
    fd.append('id', sel.id);

    const res = await fetch(API.eliminar, { method:'POST', body: fd, credentials:'same-origin' });
    const data = await res.json().catch(()=>null);

    if (!data?.success) { q('modalMsg').textContent = data?.message || 'Error eliminando'; return; }

    q('modalMsg').textContent = '✅ Eliminado';
    closeModal();
    await cargarStats();
    await cargarVistaAgrupada();
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

  // ----------------- Buscador principal -----------------
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

  // ----------------- Init -----------------
  async function init(){
    await cargarStats();
    await cargarVistaAgrupada();
  }
  init();

  // expose openModal/openLote for click usage if needed
  window.openModal = openModal;
  window.openLote = openLote;
})();
