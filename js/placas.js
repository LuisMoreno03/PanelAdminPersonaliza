(() => {
  const q = (id) => document.getElementById(id);

  // ✅ Safe setters (NO crashea si falta un elemento)
  const setText = (id, val) => { const el = q(id); if (el) el.textContent = val ?? ''; };
  const setHTML = (id, val) => { const el = q(id); if (el) el.innerHTML = val ?? ''; };
  const setVal  = (id, val) => { const el = q(id); if (el) el.value = val ?? ''; };
  const show    = (id) => { const el = q(id); if (el) el.classList.remove('hidden'); };
  const hide    = (id) => { const el = q(id); if (el) el.classList.add('hidden'); };

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

  // ✅ API debe venir desde tu HTML:
  // window.PLACAS_API = { subir: "...", pedidosPorProducir:"..." }
  const API = window.PLACAS_API || {};
  if (!API.subir) console.warn('Falta window.PLACAS_API.subir');
  if (!API.pedidosPorProducir) console.warn('Falta window.PLACAS_API.pedidosPorProducir');

  // Estado
  let filesSeleccionados = [];
  let allOrders = [];
  let selectedMap = new Map(); // id -> order
  let searchOrderTerm = '';

  function normalize(s) {
    return String(s || '')
      .toLowerCase()
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .trim();
  }

  function openCargaModal() {
    const back = q('modalCargaBackdrop');
    if (!back) return;
    back.classList.remove('hidden');
    cargarPedidosPorProducir().catch(() => {});
  }

  function closeCargaModal() {
    const back = q('modalCargaBackdrop');
    if (!back) return;
    back.classList.add('hidden');
    resetCargaModal();
  }

  function resetCargaModal() {
    // ✅ No fallará aunque falten elementos
    setVal('cargaLoteNombre', '');
    setVal('cargaNumero', '');
    setVal('cargaBuscarPedido', '');
    setText('cargaMsg', '');
    hide('uploadProgressWrap');
    setText('uploadProgressText', '0%');
    const bar = q('uploadProgressBar');
    if (bar) bar.style.width = '0%';

    // reset orders
    allOrders = [];
    selectedMap.clear();
    searchOrderTerm = '';
    setHTML('cargaPedidosLista', `<div class="p-3 text-xs text-gray-500">Cargando pedidos…</div>`);
    setHTML('cargaPedidosSeleccionados', `<div class="p-3 text-xs text-gray-500">Selecciona pedidos de “Por producir”.</div>`);
    setHTML('cargaPedidosVinculados', `<div class="p-3 text-xs text-gray-500">Al seleccionar pedidos, aquí aparecen vinculados.</div>`);
    setText('cargaPedidosFooter', '');

    // reset files
    const input = q('cargaArchivo');
    if (input) input.value = '';
    filesSeleccionados = [];
    renderFilePreview();
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
          <button type="button"
            data-remove-file="${i}"
            class="absolute right-1 top-1 grid h-6 w-6 place-items-center rounded-full bg-black/60 text-white hover:bg-black">
            ×
          </button>
        </div>
      `;
    }).join('');

    setHTML('cargaPreview', `
      <div class="grid grid-cols-4 gap-2 p-3">
        ${items}
      </div>
      <div class="px-3 pb-3 text-xs text-gray-500">${count} archivo(s) seleccionado(s)</div>
    `);

    // bind remove buttons (delegación)
    const preview = q('cargaPreview');
    if (preview) {
      preview.onclick = (ev) => {
        const btn = ev.target.closest('[data-remove-file]');
        if (!btn) return;
        const idx = Number(btn.getAttribute('data-remove-file'));
        if (Number.isNaN(idx)) return;
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

  function escapeHtml(str) {
    return (str || '').replace(/[&<>"']/g, s => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[s]));
  }

  async function cargarPedidosPorProducir() {
    // si no tienes endpoint, no revienta
    if (!API.pedidosPorProducir) {
      setHTML('cargaPedidosLista', `<div class="p-3 text-xs text-red-600">Falta API.pedidosPorProducir</div>`);
      return;
    }

    setHTML('cargaPedidosLista', `<div class="p-3 text-xs text-gray-500">Cargando pedidos…</div>`);

    const res = await fetch(API.pedidosPorProducir, { cache: 'no-store' });
    const data = await res.json().catch(() => null);

    if (!data || !data.success) {
      setHTML('cargaPedidosLista', `<div class="p-3 text-xs text-red-600">No se pudieron cargar pedidos.</div>`);
      setText('cargaPedidosFooter', '');
      return;
    }

    // Ajusta según tu backend: data.orders
    allOrders = Array.isArray(data.orders) ? data.orders : [];

    renderOrders();
  }

  function orderLabel(o) {
    // ✅ el número real tipo #PEDIDO001253
    const numero = o.numero || o.number || o.pedido || o.id || '';
    const cliente = o.cliente || o.customer || '';
    return { numero, cliente };
  }

  function renderOrders() {
    const term = normalize(searchOrderTerm);

    let list = allOrders.slice();
    if (term) {
      list = list.filter(o => {
        const { numero, cliente } = orderLabel(o);
        const hay = normalize(`${numero} ${cliente}`);
        return hay.includes(term);
      });
    }

    setText('cargaPedidosFooter', `${list.length} pedido(s) encontrado(s).`);

    if (!list.length) {
      setHTML('cargaPedidosLista', `<div class="p-3 text-xs text-gray-500">No hay pedidos para mostrar.</div>`);
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

    // bind checkboxes (delegación)
    const box = q('cargaPedidosLista');
    if (box) {
      box.onchange = (ev) => {
        const cb = ev.target.closest('input[type="checkbox"][data-order-id]');
        if (!cb) return;

        const oid = cb.getAttribute('data-order-id');
        const order = allOrders.find(x => String(x.id ?? (x.numero || x.number || x.pedido || '')) === String(oid))
                  || allOrders.find(x => String(x.id) === String(oid));

        if (cb.checked) {
          selectedMap.set(String(oid), order || { id: oid, numero: oid });
        } else {
          selectedMap.delete(String(oid));
        }
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

    // vinculados (por ahora = mismos pedidos; aquí puedes ampliar reglas)
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

    // bind remove buttons
    const selBox = q('cargaPedidosSeleccionados');
    if (selBox) {
      selBox.onclick = (ev) => {
        const btn = ev.target.closest('[data-remove-order]');
        if (!btn) return;
        const oid = btn.getAttribute('data-remove-order');
        selectedMap.delete(String(oid));
        renderOrders(); // re-pinta lista y checks
      };
    }
  }

  function uploadLote() {
    const loteNombre = (q('cargaLoteNombre')?.value || '').trim();
    const numeroPlaca = (q('cargaNumero')?.value || '').trim();

    if (!loteNombre) {
      setText('cargaMsg', 'El nombre del lote es obligatorio.');
      return;
    }
    if (!filesSeleccionados.length) {
      setText('cargaMsg', 'Selecciona uno o más archivos.');
      return;
    }
    if (!API.subir) {
      setText('cargaMsg', 'Falta API.subir (endpoint subir-lote).');
      return;
    }

    // ✅ pedidos seleccionados
    const selected = Array.from(selectedMap.values()).map(o => ({
      id: o.id ?? null,
      numero: o.numero || o.number || o.pedido || null,
      cliente: o.cliente || o.customer || null,
      fecha: o.fecha || null
    }));

    const wrap = q('uploadProgressWrap');
    const bar  = q('uploadProgressBar');
    const txt  = q('uploadProgressText');

    if (wrap) wrap.classList.remove('hidden');
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
    xhr.open('POST', API.subir, true);

    xhr.upload.onprogress = (e) => {
      if (!e.lengthComputable) return;
      const percent = Math.round((e.loaded / e.total) * 100);
      if (bar) bar.style.width = percent + '%';
      if (txt) txt.textContent = percent + '%';
    };

    xhr.onload = () => {
      if (btn) btn.disabled = false;

      let data = null;
      try { data = JSON.parse(xhr.responseText); } catch (e) {}

      if (xhr.status !== 200 || !data || !data.success) {
        setText('cargaMsg', (data && data.message) ? data.message : 'Error al subir');
        return;
      }

      if (bar) bar.style.width = '100%';
      if (txt) txt.textContent = '100%';
      setText('cargaMsg', data.message || '✅ Subidos correctamente');

      setTimeout(() => {
        closeCargaModal();
        // aquí puedes llamar refresh de tu listado:
        if (typeof window.refrescarTodo === 'function') window.refrescarTodo();
      }, 450);
    };

    xhr.onerror = () => {
      if (btn) btn.disabled = false;
      setText('cargaMsg', 'Error de red al subir.');
    };

    xhr.send(fd);
  }

  // INIT
  document.addEventListener('DOMContentLoaded', () => {
    // Botón abrir modal (si existe)
    q('btnAbrirModalCarga')?.addEventListener('click', openCargaModal);

    // cerrar/cancelar
    q('btnCerrarCarga')?.addEventListener('click', closeCargaModal);
    q('btnCancelarCarga')?.addEventListener('click', closeCargaModal);

    // ESC para cerrar
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !q('modalCargaBackdrop')?.classList.contains('hidden')) {
        closeCargaModal();
      }
    });

    // archivo change
    q('cargaArchivo')?.addEventListener('change', (e) => {
      filesSeleccionados = Array.from(e.target.files || []);
      renderFilePreview();
    });

    // buscar pedidos
    q('cargaBuscarPedido')?.addEventListener('input', (e) => {
      searchOrderTerm = e.target.value || '';
      renderOrders();
    });

    // guardar
    q('btnGuardarCarga')?.addEventListener('click', uploadLote);

    // primera vista preview
    renderFilePreview();
  });

})();
