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

  // === Productos por producir (modal carga) ===
  let productosPool = [];              // items del endpoint
  let productosSelected = new Map();   // id => item
  let productosSearch = '';

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

    try {
      const j = JSON.parse(raw);
      if (Array.isArray(j)) return j.map(x => String(x).trim()).filter(Boolean);
    } catch (e) {}

    return raw.split(/[\n,]+/g).map(s => s.trim()).filter(Boolean);
  }

  async function fetchJsonSafe(url, options = {}) {
    const res = await fetch(url, { cache: 'no-store', ...options });
    const text = await res.text();
    let data = null;
    try { data = JSON.parse(text); } catch (e) {}
    if (!res.ok || !data) {
      const snippet = (text || '').slice(0, 300);
      throw new Error(`HTTP ${res.status} en ${url}. Respuesta: ${snippet}`);
    }
    return data;
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

    q('modalNombre').value = it.nombre || (it.original ? String(it.original).replace(/\.[^.]+$/, '') : '');
    q('modalFecha').textContent = formatFecha(it.created_at);

    document.querySelectorAll('[data-modal-file]').forEach(el => {
      const active = Number(el.dataset.modalFile) === modalSelectedId;
      el.classList.toggle('ring-2', active);
      el.classList.toggle('ring-blue-300', active);
    });

    updateModalMetaByLote(it);
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

  function updateModalMetaByLote(item) {
    const lid = item?.lote_id ? String(item.lote_id) : '';
    const meta = lid ? (loteMeta[lid] || {}) : {};

    const pedidos = toArrayList(meta.pedidos || item.pedidos);
    const productos = toArrayList(meta.productos || item.productos);

    renderBadges('modalPedidos', pedidos, 'Sin pedidos vinculados');
    renderBadges('modalProductos', productos, 'Sin productos');

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

  // ===================== LISTA POR DÍA/LOTE =====================
  async function cargarVistaAgrupada() {
    placasMap = {};
    loteIndex = {};
    loteMeta = {};

    const data = await fetchJsonSafe(API.listar);

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

              const items = (lote.items || []).filter(it => itemMatches({
                ...it,
                lote_id: lid,
                lote_nombre: lnombre,
                pedidos,
                productos
              }, term));

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

        const pedidosArr = toArrayList(loteMeta[lid].pedidos);
        const productosArr = toArrayList(loteMeta[lid].productos);

        const chipPedidos = pedidosArr.length
          ? `<span class="px-2 py-1 rounded-full bg-white border text-xs font-black text-gray-700">🧾 ${pedidosArr.length} pedido(s)</span>`
          : `<span class="px-2 py-1 rounded-full bg-white border text-xs font-black text-gray-400">🧾 sin pedidos</span>`;

        const chipProductos = productosArr.length
          ? `<span class="px-2 py-1 rounded-full bg-white border text-xs font-black text-gray-700">🧩 ${productosArr.length} producto(s)</span>`
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
      const data = await fetchJsonSafe(API.stats);
      if (data.success) q('placasHoy').textContent = data.data?.total ?? 0;
    } catch (e) {
      // no romper UI
    }
  }

  // ===================== Renombrar lote (modal) =====================
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

    const data = await fetchJsonSafe(API.renombrarLote, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    });

    if (!data?.success) { q('modalMsg').textContent = data?.message || 'Error renombrando el lote'; return; }

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

    const data = await fetchJsonSafe(API.renombrar, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    });

    if (!data?.success) { q('modalMsg').textContent = data?.message || 'Error renombrando'; return; }

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

    const data = await fetchJsonSafe(API.eliminar, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    });

    if (!data?.success) { q('modalMsg').textContent = data?.message || 'Error eliminando'; return; }

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
  function formatPedidoDisplay(raw) {
    const v = String(raw || '').trim();
    if (!v) return '';

    // ya viene pedido0001 / #pedido0001
    if (/^#?pedido\d+$/i.test(v)) return v.startsWith('#') ? v : `#${v}`;

    // numérico => #pedido + 4 dígitos
    if (/^\d+$/.test(v)) return `#pedido${v.padStart(4, '0')}`;

    if (v.startsWith('#')) return v;
    return `#${v}`;
    }

    function renderProductosLista(items, selectedSet, filterTerm = '') {
    const box = q('ppList'); // ✅ tu contenedor de lista
    if (!box) return;

    const term = normalizeText(filterTerm);

    const filtered = (items || []).filter(it => {
        if (!term) return true;
        const hay = normalizeText([
        it.pedido_display, it.pedido_codigo, it.pedido_numero,
        it.producto, it.label
        ].join(' '));
        return hay.includes(term);
    });

    if (!filtered.length) {
        box.innerHTML = `<div class="muted" style="padding:10px;">No hay resultados.</div>`;
        return;
    }

    box.innerHTML = filtered.map(it => {
        const pedidoDisplay = it.pedido_display ? String(it.pedido_display) : formatPedidoDisplay(it.pedido_codigo || it.pedido_numero || it.id);
        const producto = (it.producto || '').trim();
        const cantidad = (it.cantidad || '').toString().trim();

        const isChecked = selectedSet.has(String(it.id));

        return `
        <label class="pp-card" style="
            display:flex; gap:10px; align-items:flex-start;
            border:1px solid #e5e7eb; border-radius:12px; padding:10px;
            background:#fff; cursor:pointer;
        ">
            <input type="checkbox" data-pp-id="${escapeHtml(it.id)}" ${isChecked ? 'checked' : ''}
            style="margin-top:4px; width:16px; height:16px;">

            <div style="flex:1; min-width:0;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                <div style="font-weight:900; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                ${escapeHtml(pedidoDisplay)}
                </div>
                <span style="
                font-size:12px; padding:3px 8px; border-radius:999px;
                background:#EEF2FF; color:#3730A3; font-weight:800;
                flex:0 0 auto;
                ">Por producir</span>
            </div>

            ${producto ? `
                <div style="margin-top:6px; font-weight:800; color:#111827;">
                ${escapeHtml(producto)} ${cantidad ? `<span class="muted" style="font-weight:900;">x${escapeHtml(cantidad)}</span>` : ''}
                </div>
            ` : `
                <div class="muted" style="margin-top:6px;">Selecciona para vincular este pedido.</div>
            `}

            <div style="margin-top:8px; display:flex; gap:6px; flex-wrap:wrap;">
                ${it.pedido_numero ? `<span style="font-size:11px; background:#F3F4F6; padding:2px 8px; border-radius:999px;">ID: ${escapeHtml(it.pedido_numero)}</span>` : ''}
                ${it.id ? `<span style="font-size:11px; background:#F3F4F6; padding:2px 8px; border-radius:999px;">Ref: ${escapeHtml(it.id)}</span>` : ''}
            </div>
            </div>
        </label>
        `;
    }).join('');

    // Hook change
    box.querySelectorAll('input[type="checkbox"][data-pp-id]').forEach(chk => {
        chk.addEventListener('change', () => {
        const id = String(chk.dataset.ppId);
        if (chk.checked) selectedSet.add(id);
        else selectedSet.delete(id);

        // ✅ después de seleccionar, refresca paneles
        renderSeleccionadosYVinculados();
        });
    });
    }

  // ============================================================
  // ✅ PRODUCTOS "POR PRODUCIR" (Modal carga)
  // ============================================================
  function resetProductosSelector() {
    productosPool = [];
    productosSelected = new Map();
    productosSearch = '';
    if (q('cargaProductosBuscar')) q('cargaProductosBuscar').value = '';
    renderProductosSelector();
  }

  async function loadProductosPorProducir() {
    if (!API.productosPorProducir) {
      console.warn("Falta API.productosPorProducir en PLACAS_API");
      return;
    }
    try {
      const data = await fetchJsonSafe(API.productosPorProducir);
      productosPool = Array.isArray(data.items) ? data.items : [];
      renderProductosSelector();
    } catch (e) {
      const box = q('cargaProductosLista');
      if (box) box.innerHTML = `<div class="text-sm text-red-500">Error cargando productos: ${escapeHtml(e.message)}</div>`;
    }
  }

  function toggleProducto(item) {
    const id = String(item.id);
    if (productosSelected.has(id)) productosSelected.delete(id);
    else productosSelected.set(id, item);
    renderProductosSelector();
  }

  function getSelectedLabels() {
    return Array.from(productosSelected.values()).map(x => x.label || x.producto || String(x.id));
  }

  function getSelectedPedidos() {
    const set = new Set();
    for (const it of productosSelected.values()) {
      const pn = it.pedido_numero ?? it.pedido ?? it.pedido_id;
      if (pn != null && String(pn).trim() !== '') set.add(String(pn).trim());
    }
    return Array.from(set.values());
  }

  function renderProductosSelector() {
    const listBox = q('cargaProductosLista');
    const chipsBox = q('cargaProductosChips');
    const pedidosBox = q('cargaPedidosChips');

    if (!listBox || !chipsBox || !pedidosBox) return;

    const term = normalizeText(productosSearch);

    const filtered = term
      ? productosPool.filter(it => normalizeText([it.label, it.producto, it.pedido_numero].join(' ')).includes(term))
      : productosPool;

    if (!productosPool.length) {
      listBox.innerHTML = `<div class="text-sm text-gray-400">No hay productos en “Por producir”.</div>`;
    } else if (!filtered.length) {
      listBox.innerHTML = `<div class="text-sm text-gray-400">Sin resultados para "${escapeHtml(productosSearch)}".</div>`;
    } else {
      listBox.innerHTML = `
        <div class="grid gap-2">
          ${filtered.map(it => {
            const id = String(it.id);
            const checked = productosSelected.has(id);
            const pedido = it.pedido_numero ? `Pedido #${it.pedido_numero}` : '';
            const producto = it.producto ? it.producto : '';
            const cant = it.cantidad ? `x${it.cantidad}` : '';
            const label = it.label || `${pedido}${pedido && producto ? ' — ' : ''}${producto} ${cant}`.trim();

            return `
              <label class="flex items-start gap-3 p-3 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 cursor-pointer">
                <input type="checkbox" ${checked ? 'checked' : ''} class="mt-1"
                  onchange="window.__PLACAS_toggleProducto('${escapeHtml(id)}')">
                <div class="min-w-0">
                  <div class="font-extrabold text-sm truncate">${escapeHtml(label)}</div>
                  <div class="text-xs text-gray-500 mt-1 flex flex-wrap gap-2">
                    ${pedido ? `<span class="px-2 py-1 rounded-full bg-gray-100 border">${escapeHtml(pedido)}</span>` : ''}
                    ${producto ? `<span class="px-2 py-1 rounded-full bg-gray-100 border">${escapeHtml(producto)}</span>` : ''}
                    ${cant ? `<span class="px-2 py-1 rounded-full bg-gray-100 border">${escapeHtml(cant)}</span>` : ''}
                  </div>
                </div>
              </label>
            `;
          }).join('')}
        </div>
      `;
    }

    // chips seleccionados
    const labels = getSelectedLabels();
    chipsBox.innerHTML = labels.length
      ? labels.map((t, i) => `
          <span class="px-3 py-1 rounded-full bg-blue-50 border border-blue-200 text-xs font-black text-blue-800 inline-flex items-center gap-2">
            ${escapeHtml(t)}
            <button type="button" class="text-blue-700 hover:text-blue-900"
              onclick="window.__PLACAS_removeSelectedByIndex(${i})">✕</button>
          </span>
        `).join('')
      : `<span class="text-xs text-gray-400">Selecciona productos de “Por producir”.</span>`;

    // chips pedidos auto
    const pedidos = getSelectedPedidos();
    pedidosBox.innerHTML = pedidos.length
      ? pedidos.map(p => `
          <span class="px-3 py-1 rounded-full bg-emerald-50 border border-emerald-200 text-xs font-black text-emerald-800">
            🧾 Pedido #${escapeHtml(p)}
          </span>
        `).join('')
      : `<span class="text-xs text-gray-400">Al seleccionar productos, aquí aparecen los pedidos vinculados.</span>`;
  }

  // helpers globales para UI
  window.__PLACAS_toggleProducto = function (id) {
    const it = productosPool.find(x => String(x.id) === String(id));
    if (!it) return;
    toggleProducto(it);
  };

  window.__PLACAS_removeSelectedByIndex = function (idx) {
    const arr = Array.from(productosSelected.entries());
    const kv = arr[idx];
    if (!kv) return;
    productosSelected.delete(kv[0]);
    renderProductosSelector();
  };

  // ===================== MODAL CARGA (SUBIR LOTE) =====================
  const modalCarga = q('modalCargaBackdrop');
  let filesSeleccionados = [];

  function abrirModalCarga() {
    if (!modalCarga) return;
    modalCarga.classList.remove('hidden');
    q('cargaMsg').textContent = '';

    // reset y carga productos por producir cada vez que abres
    resetProductosSelector();
    loadProductosPorProducir();
  }

  function cerrarModalCarga() {
    if (!modalCarga) return;
    modalCarga.classList.add('hidden');
    if (q('cargaArchivo')) q('cargaArchivo').value = '';
    filesSeleccionados = [];
    if (q('cargaPreview')) q('cargaPreview').innerHTML = 'Vista previa';
    if (q('cargaMsg')) q('cargaMsg').textContent = '';

    if (q('uploadProgressWrap')) q('uploadProgressWrap').classList.add('hidden');
    if (q('uploadProgressBar')) q('uploadProgressBar').style.width = '0%';
    if (q('uploadProgressText')) q('uploadProgressText').textContent = '0%';

    resetProductosSelector();
  }

  function renderPreviewSeleccion() {
    const box = q('cargaPreview');
    if (!box) return;

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
    if (q('cargaArchivo')) q('cargaArchivo').files = dt.files;
    renderPreviewSeleccion();
  };

  async function subirLote() {
    const loteNombre = (q('cargaLoteNombre')?.value || '').trim();
    const numeroPlaca = (q('cargaNumero')?.value || '').trim();

    if (!loteNombre) { q('cargaMsg').textContent = 'El nombre del lote es obligatorio.'; return; }
    if (!filesSeleccionados.length) { q('cargaMsg').textContent = 'Selecciona uno o más archivos.'; return; }

    // ✅ productos seleccionados => labels + pedidos
    const productosLabels = getSelectedLabels();
    const pedidosAuto = getSelectedPedidos();

    // progreso
    const wrap = q('uploadProgressWrap');
    const bar  = q('uploadProgressBar');
    const txt  = q('uploadProgressText');

    if (wrap) wrap.classList.remove('hidden');
    if (bar) bar.style.width = '0%';
    if (txt) txt.textContent = '0%';

    q('btnGuardarCarga').disabled = true;
    q('cargaMsg').textContent = `Subiendo ${filesSeleccionados.length} archivo(s)...`;

    const fd = addCsrf(new FormData());
    fd.append('lote_nombre', loteNombre);
    fd.append('numero_placa', numeroPlaca);

    // ✅ Guardar en el lote
    // Se guardan como JSON strings (tu backend parsea JSON arrays)
    fd.append('productos', JSON.stringify(productosLabels));
    fd.append('pedidos', JSON.stringify(pedidosAuto));

    filesSeleccionados.forEach(file => fd.append('archivos[]', file));

    // xhr con progreso
    const xhr = new XMLHttpRequest();
    xhr.open('POST', API.subir, true);

    xhr.upload.onprogress = (e) => {
      if (!e.lengthComputable) return;
      const percent = Math.round((e.loaded / e.total) * 100);
      if (bar) bar.style.width = percent + '%';
      if (txt) txt.textContent = percent + '%';
    };

    xhr.onload = async () => {
      q('btnGuardarCarga').disabled = false;

      let data = null;
      try { data = JSON.parse(xhr.responseText); } catch (e) {}

      if (xhr.status !== 200 || !data || !data.success) {
        q('cargaMsg').textContent = (data && data.message) ? data.message : 'Error al subir';
        return;
      }

      if (bar) bar.style.width = '100%';
      if (txt) txt.textContent = '100%';
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

  // ===================== BUSCADOR principal =====================
  function applySearch(v) {
    searchTerm = v || '';
    if (q('searchClear')) q('searchClear').classList.toggle('hidden', !searchTerm.trim());
    cargarVistaAgrupada();
  }

  // ===================== INIT EVENTS =====================
  function bindEvents() {
    // modal editar
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

    // buscador productos por producir (modal carga)
    q('cargaProductosBuscar')?.addEventListener('input', (e) => {
      productosSearch = e.target.value || '';
      renderProductosSelector();
    });

    // buscador principal
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
