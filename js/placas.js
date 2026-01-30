(() => {
  const q = (id) => document.getElementById(id);

  const setText = (id, val) => { const el = q(id); if (el) el.textContent = val ?? ''; };
  const setHTML = (id, val) => { const el = q(id); if (el) el.innerHTML = val ?? ''; };
  const setVal  = (id, val) => { const el = q(id); if (el) el.value = val ?? ''; };
  const show    = (id) => { const el = q(id); if (el) el.classList.remove('hidden'); };
  const hide    = (id) => { const el = q(id); if (el) el.classList.add('hidden'); };

  const API = window.PLACAS_API || {};

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

  function normalize(s) {
    return String(s || '')
      .toLowerCase()
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .trim();
  }

  // =========================
  // LISTADO POR DÍAS
  // =========================
  let allDiasData = null;
  let searchTerm = '';

  function itemMatches(it, term) {
    if (!term) return true;
    const hay = normalize([
      it.nombre,
      it.original,
      it.numero_placa,
      it.id,
      it.lote_id,
      it.lote_nombre
    ].join(' '));
    return hay.includes(term);
  }

  async function cargarStats() {
    if (!API.stats) return;
    const res = await fetch(API.stats, { cache: 'no-store' });
    const data = await res.json().catch(() => null);
    if (data?.success) setText('placasHoy', data.data?.total ?? 0);
  }

  async function cargarListaPorDia() {
    if (!API.listarPorDia) return;

    const res = await fetch(API.listarPorDia, { cache: 'no-store' });
    const data = await res.json().catch(() => null);

    allDiasData = data;

    if (!data?.success || !Array.isArray(data.dias)) {
      setHTML('contenedorDias', `<div class="text-sm text-gray-500">No hay datos para mostrar.</div>`);
      return;
    }

    setText('placasHoy', data.placas_hoy ?? 0);

    const term = normalize(searchTerm);

    const dias = data.dias.map(d => {
      const lotes = (d.lotes || []).map(l => {
        const items = (l.items || []).filter(it => itemMatches(it, term));
        const okLote = normalize(`${l.lote_id} ${l.lote_nombre} ${l.created_at}`).includes(term);
        return okLote ? l : { ...l, items };
      }).filter(l => (l.items || []).length > 0);

      const okDia = normalize(d.fecha).includes(term);
      const total = lotes.reduce((a, l) => a + (l.items?.length || 0), 0);
      return okDia ? d : { ...d, lotes, total_archivos: total };
    }).filter(d => (d.lotes || []).length > 0);

    if (term && !dias.length) {
      setHTML('contenedorDias', `<div class="text-sm text-gray-500">No hay resultados para "<b>${escapeHtml(searchTerm)}</b>".</div>`);
      return;
    }

    setHTML('contenedorDias', dias.map(renderDia).join(''));
  }

  function renderDia(dia) {
    const lotesHtml = (dia.lotes || []).map(renderLoteCard).join('');

    return `
      <div class="rounded-2xl border border-gray-200 bg-white p-4">
        <div class="flex items-center justify-between">
          <div>
            <div class="text-lg font-extrabold text-gray-900">${escapeHtml(dia.fecha)}</div>
            <div class="text-sm text-gray-500">Total: ${dia.total_archivos ?? 0}</div>
          </div>
        </div>

        <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
          ${lotesHtml || `<div class="text-sm text-gray-500">Sin lotes.</div>`}
        </div>
      </div>
    `;
  }

  function renderLoteCard(lote) {
    const lid = escapeHtml(String(lote.lote_id || ''));
    const name = escapeHtml(String(lote.lote_nombre || 'Sin nombre'));
    const total = (lote.items || []).length;
    const created = escapeHtml(String(lote.created_at || ''));

    // tomamos primer item para mini-preview (si es imagen)
    const first = (lote.items || [])[0];
    const thumb = first?.view_url && (first.mime || '').startsWith('image/')
      ? `<img src="${first.view_url}" class="h-full w-full object-cover" />`
      : `<div class="text-xs text-gray-400">Carpeta</div>`;

    return `
      <div class="rounded-2xl border border-gray-200 bg-white p-4 hover:shadow-sm transition">
        <div class="flex items-center gap-3">
          <div class="h-14 w-14 overflow-hidden rounded-2xl border border-gray-200 bg-gray-50 grid place-items-center">
            ${thumb}
          </div>
          <div class="min-w-0">
            <div class="truncate font-black text-gray-900">📦 ${name}</div>
            <div class="text-xs text-gray-500">${total} archivo(s) • ${created}</div>
          </div>
        </div>

        <div class="mt-3 flex gap-2">
          <button class="w-full rounded-xl bg-gray-900 px-3 py-2 text-sm font-extrabold text-white hover:bg-black"
            data-open-lote="${lid}">
            Ver
          </button>
        </div>
      </div>
    `;
  }

  // =========================
  // MODAL CARGA
  // =========================
  let filesSeleccionados = [];
  let allOrders = [];
  let selectedMap = new Map(); // key -> order
  let searchOrderTerm = '';

  function openCargaModal() {
    const back = q('modalCargaBackdrop');
    if (!back) return;
    back.classList.remove('hidden');
    resetCargaModal(false);
    cargarPedidosPorProducir().catch(() => {});
  }

  function closeCargaModal() {
    const back = q('modalCargaBackdrop');
    if (!back) return;
    back.classList.add('hidden');
    resetCargaModal(true);
  }

  function resetCargaModal(clearInputs) {
    if (clearInputs) {
      setVal('cargaLoteNombre', '');
      setVal('cargaNumero', '');
    }
    setVal('cargaBuscarPedido', '');
    setText('cargaMsg', '');
    hide('uploadProgressWrap');
    setText('uploadProgressText', '0%');
    const bar = q('uploadProgressBar');
    if (bar) bar.style.width = '0%';

    allOrders = [];
    selectedMap.clear();
    searchOrderTerm = '';

    setHTML('cargaPedidosLista', `<div class="p-3 text-xs text-gray-500">Cargando pedidos…</div>`);
    setHTML('cargaPedidosSeleccionados', `<div class="p-3 text-xs text-gray-500">Selecciona pedidos de “Por producir”.</div>`);
    setHTML('cargaPedidosVinculados', `<div class="p-3 text-xs text-gray-500">Al seleccionar pedidos, aquí aparecen vinculados.</div>`);
    setText('cargaPedidosFooter', '');

    const input = q('cargaArchivo');
    if (input) input.value = '';
    filesSeleccionados = [];
    renderFilePreview();
  }

  function orderLabel(o) {
    const numero = o.numero || o.number || o.pedido || o.id || '';
    const cliente = o.cliente || o.customer || '';
    return { numero, cliente };
  }

  async function cargarPedidosPorProducir() {
    if (!API.pedidosPorProducir) {
      setHTML('cargaPedidosLista', `<div class="p-3 text-xs text-red-600">Falta endpoint pedidosPorProducir</div>`);
      return;
    }

    const url = API.pedidosPorProducir + (searchOrderTerm ? `?q=${encodeURIComponent(searchOrderTerm)}` : '');
    const res = await fetch(url, { cache: 'no-store' });
    const data = await res.json().catch(() => null);

    if (!data || !data.success) {
      setHTML('cargaPedidosLista', `<div class="p-3 text-xs text-red-600">No se pudieron cargar pedidos.</div>`);
      setText('cargaPedidosFooter', data?.message || '');
      return;
    }

    allOrders = Array.isArray(data.orders) ? data.orders : [];
    renderOrders();
  }

  function renderOrders() {
    const term = normalize(searchOrderTerm);

    let list = allOrders.slice();
    if (term) {
      list = list.filter(o => {
        const { numero, cliente } = orderLabel(o);
        return normalize(`${numero} ${cliente}`).includes(term);
      });
    }

    setText('cargaPedidosFooter', `${list.length} pedido(s) encontrado(s).`);

    if (!list.length) {
      setHTML('cargaPedidosLista', `<div class="p-3 text-xs text-gray-500">No hay pedidos en “Por producir”.</div>`);
      return;
    }

    const rows = list.map(o => {
      const { numero, cliente } = orderLabel(o);
      const oid = String(o.id ?? numero);
      const checked = selectedMap.has(oid) ? 'checked' : '';

      return `
        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 bg-white p-3 hover:bg-gray-50">
          <input type="checkbox" class="mt-1" data-order-id="${escapeHtml(oid)}" ${checked}>
          <div class="min-w-0">
            <div class="truncate text-sm font-extrabold text-gray-900">${escapeHtml(numero || oid)}</div>
            <div class="truncate text-xs text-gray-500">${escapeHtml(cliente)}</div>
          </div>
        </label>
      `;
    }).join('');

    setHTML('cargaPedidosLista', `<div class="space-y-2">${rows}</div>`);

    const box = q('cargaPedidosLista');
    if (box) {
      box.onchange = (ev) => {
        const cb = ev.target.closest('input[type="checkbox"][data-order-id]');
        if (!cb) return;

        const oid = cb.getAttribute('data-order-id');
        const order = allOrders.find(x => String(x.id ?? (x.numero || '')) === String(oid))
                  || allOrders.find(x => String(x.id) === String(oid));

        if (cb.checked) selectedMap.set(String(oid), order || { id: oid, numero: oid });
        else selectedMap.delete(String(oid));

        renderSelected();
      };
    }

    renderSelected();
  }

  function renderSelected() {
    const selected = Array.from(selectedMap.values());

    if (!selected.length) {
      setHTML('cargaPedidosSeleccionados', `<div class="p-3 text-xs text-gray-500">Selecciona pedidos de “Por producir”.</div>`);
      setHTML('cargaPedidosVinculados', `<div class="p-3 text-xs text-gray-500">Al seleccionar pedidos, aquí aparecen vinculados.</div>`);
      return;
    }

    const chips = selected.map(o => {
      const { numero, cliente } = orderLabel(o);
      const oid = String(o.id ?? numero);

      return `
        <div class="flex items-center justify-between gap-2 rounded-xl border border-gray-200 bg-white p-3">
          <div class="min-w-0">
            <div class="truncate text-sm font-extrabold text-gray-900">${escapeHtml(numero || oid)}</div>
            <div class="truncate text-xs text-gray-500">${escapeHtml(cliente || '')}</div>
          </div>
          <button type="button" data-remove-order="${escapeHtml(oid)}"
            class="rounded-lg bg-red-50 px-2 py-1 text-xs font-bold text-red-600 hover:bg-red-100">
            Quitar
          </button>
        </div>
      `;
    }).join('');

    setHTML('cargaPedidosSeleccionados', `<div class="space-y-2">${chips}</div>`);

    const linked = selected.map(o => {
      const { numero, cliente } = orderLabel(o);
      return `
        <div class="rounded-xl border border-gray-200 bg-gray-50 p-3">
          <div class="text-xs font-black text-gray-900">${escapeHtml(numero || '')}</div>
          <div class="text-xs text-gray-600">${escapeHtml(cliente || '')}</div>
        </div>
      `;
    }).join('');

    setHTML('cargaPedidosVinculados', `<div class="space-y-2">${linked}</div>`);

    const selBox = q('cargaPedidosSeleccionados');
    if (selBox) {
      selBox.onclick = (ev) => {
        const btn = ev.target.closest('[data-remove-order]');
        if (!btn) return;
        const oid = btn.getAttribute('data-remove-order');
        selectedMap.delete(String(oid));
        renderOrders();
      };
    }
  }

  function renderFilePreview() {
    const count = filesSeleccionados.length;
    setText('cargaArchivosCount', `${count} archivo(s)`);

    if (!count) {
      setHTML('cargaPreview', 'Vista previa');
      return;
    }

    const items = filesSeleccionados.map((f, i) => {
      const isImg = (f.type || '').startsWith('image/');
      const isPdf = (f.type || '').includes('pdf');
      const url = (isImg || isPdf) ? URL.createObjectURL(f) : null;

      const name = (f.name || 'archivo').slice(0, 22);
      const ext = (f.name || '').split('.').pop()?.toUpperCase() || 'FILE';

      const thumb = isImg
        ? `<img src="${url}" class="h-full w-full object-cover" />`
        : isPdf
          ? `<div class="flex h-full w-full items-center justify-center text-xs font-black text-gray-600">PDF</div>`
          : `<div class="flex h-full w-full flex-col items-center justify-center text-[11px] font-semibold text-gray-600">
               <div class="rounded-full bg-gray-200 px-2 py-1 text-[10px] font-black">${ext}</div>
               <div class="mt-2 px-2 text-center break-words">${escapeHtml(name)}</div>
             </div>`;

      return `
        <div class="relative h-20 overflow-hidden rounded-xl border border-gray-200 bg-white">
          ${thumb}
          <button type="button" data-remove-file="${i}"
            class="absolute right-1 top-1 grid h-6 w-6 place-items-center rounded-full bg-black/60 text-white hover:bg-black">
            ×
          </button>
        </div>
      `;
    }).join('');

    setHTML('cargaPreview', `
      <div class="grid grid-cols-4 gap-2 p-3">${items}</div>
      <div class="px-3 pb-3 text-xs text-gray-500">${count} archivo(s) seleccionado(s)</div>
    `);

    const preview = q('cargaPreview');
    if (preview) {
      preview.onclick = (ev) => {
        const btn = ev.target.closest('[data-remove-file]');
        if (!btn) return;
        const idx = Number(btn.getAttribute('data-remove-file'));
        filesSeleccionados.splice(idx, 1);
        syncFileInput();
        renderFilePreview();
      };
    }
  }

  function syncFileInput() {
    const input = q('cargaArchivo');
    if (!input) return;
    const dt = new DataTransfer();
    filesSeleccionados.forEach(f => dt.items.add(f));
    input.files = dt.files;
  }

  function uploadLote() {
    const loteNombre = (q('cargaLoteNombre')?.value || '').trim();
    const numeroPlaca = (q('cargaNumero')?.value || '').trim();

    if (!loteNombre) { setText('cargaMsg', 'El nombre del lote es obligatorio.'); return; }
    if (!filesSeleccionados.length) { setText('cargaMsg', 'Selecciona uno o más archivos.'); return; }
    if (!API.subirLote) { setText('cargaMsg', 'Falta endpoint subirLote.'); return; }

    const selected = Array.from(selectedMap.values()).map(o => ({
      id: o.id ?? null,
      numero: o.numero || o.number || o.pedido || null,
      cliente: o.cliente || o.customer || null,
      fecha: o.fecha || null
    }));

    show('uploadProgressWrap');
    const bar = q('uploadProgressBar');
    const txt = q('uploadProgressText');
    if (bar) bar.style.width = '0%';
    if (txt) txt.textContent = '0%';

    const btn = q('btnGuardarCarga');
    if (btn) btn.disabled = true;

    setText('cargaMsg', `Subiendo ${filesSeleccionados.length} archivo(s)...`);

    const fd = addCsrf(new FormData());
    fd.append('lote_nombre', loteNombre);
    fd.append('numero_placa', numeroPlaca);
    fd.append('pedidos_json', JSON.stringify(selected));
    filesSeleccionados.forEach(f => fd.append('archivos[]', f));

    const xhr = new XMLHttpRequest();
    xhr.open('POST', API.subirLote, true);

    xhr.upload.onprogress = (e) => {
      if (!e.lengthComputable) return;
      const percent = Math.round((e.loaded / e.total) * 100);
      if (bar) bar.style.width = percent + '%';
      if (txt) txt.textContent = percent + '%';
    };

    xhr.onload = async () => {
      if (btn) btn.disabled = false;

      let data = null;
      try { data = JSON.parse(xhr.responseText); } catch (e) {}

      if (xhr.status !== 200 || !data || !data.success) {
        setText('cargaMsg', data?.message || 'Error al subir');
        return;
      }

      if (bar) bar.style.width = '100%';
      if (txt) txt.textContent = '100%';
      setText('cargaMsg', data.message || '✅ Subidos correctamente');

      setTimeout(async () => {
        closeCargaModal();
        await refrescarTodo();
      }, 450);
    };

    xhr.onerror = () => {
      if (btn) btn.disabled = false;
      setText('cargaMsg', 'Error de red al subir.');
    };

    xhr.send(fd);
  }

  // =========================
  // REFRESH + SEARCH + EVENTS
  // =========================
  async function refrescarTodo() {
    try {
      await cargarStats();
      await cargarListaPorDia();

      // bind botones "Ver" (delegación)
      const cont = q('contenedorDias');
      if (cont) {
        cont.onclick = (ev) => {
          const btn = ev.target.closest('[data-open-lote]');
          if (!btn) return;
          // Si luego quieres modal editar por lote, aquí lo hacemos.
          // Por ahora, solo mensaje:
          setText('msg', '✅ Lote seleccionado: ' + btn.getAttribute('data-open-lote'));
        };
      }
    } catch (e) {
      console.error(e);
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    // Abrir modal
    q('btnAbrirModalCarga')?.addEventListener('click', openCargaModal);

    // Cerrar modal
    q('btnCerrarCarga')?.addEventListener('click', closeCargaModal);
    q('btnCancelarCarga')?.addEventListener('click', closeCargaModal);

    // ESC cerrar
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !q('modalCargaBackdrop')?.classList.contains('hidden')) closeCargaModal();
    });

    // Buscar pedidos (filtra en servidor)
    q('cargaBuscarPedido')?.addEventListener('input', async (e) => {
      searchOrderTerm = e.target.value || '';
      await cargarPedidosPorProducir();
    });

    // Archivos change
    q('cargaArchivo')?.addEventListener('change', (e) => {
      filesSeleccionados = Array.from(e.target.files || []);
      renderFilePreview();
    });

    // Guardar
    q('btnGuardarCarga')?.addEventListener('click', uploadLote);

    // Buscador principal
    const searchInput = q('searchInput');
    const searchClear = q('searchClear');
    let t = null;

    function applySearch(v) {
      searchTerm = v || '';
      if (searchClear) searchClear.classList.toggle('hidden', !searchTerm.trim());
      cargarListaPorDia();
    }

    if (searchInput) {
      searchInput.addEventListener('input', (e) => {
        clearTimeout(t);
        t = setTimeout(() => applySearch(e.target.value), 120);
      });
    }

    if (searchClear) {
      searchClear.addEventListener('click', () => {
        if (searchInput) searchInput.value = '';
        applySearch('');
      });
    }

    // Primera carga
    refrescarTodo();

    // refresco cada 10 min
    setInterval(refrescarTodo, 600000);
  });

  // expone por si lo necesitas
  window.refrescarTodo = refrescarTodo;
})();
 