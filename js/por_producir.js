(() => {
  const API = (path) => `${window.API_BASE.replace(/\/$/, '')}${path}`;

  const el = (id) => document.getElementById(id);

  const loaderOn = () => el('globalLoader')?.classList.remove('hidden');
  const loaderOff = () => el('globalLoader')?.classList.add('hidden');

  const getCsrf = () => ({
    header: document.querySelector('meta[name="csrf-header"]')?.content || 'X-CSRF-TOKEN',
    token: document.querySelector('meta[name="csrf-token"]')?.content || ''
  });

  const withCsrf = (headers = {}) => {
    const { header, token } = getCsrf();
    return { ...headers, [header]: token };
  };

  // Estado en memoria
  const state = {
    pedidos: [], // array
    pedidosMap: new Map(), // id -> pedido
    filtro: ''
  };

  const setTotal = () => {
    const n = state.pedidos.length;
    const top = el('total-pedidos-top');
    const bottom = el('total-pedidos-bottom');
    if (top) top.textContent = String(n);
    if (bottom) bottom.textContent = String(n);
  };

  const safeJson = (v, fallback) => {
    try { return JSON.parse(v); } catch { return fallback; }
  };

  const normalizePedido = (p) => {
    const items = typeof p.items_json === 'string' ? safeJson(p.items_json, []) : (p.items_json || []);
    return { ...p, items };
  };

  const upsertMany = (rows) => {
    for (const raw of rows) {
      const p = normalizePedido(raw);
      state.pedidosMap.set(p.id, p);
    }
    state.pedidos = Array.from(state.pedidosMap.values()).sort((a, b) => {
      // oldest first
      return new Date(a.created_at).getTime() - new Date(b.created_at).getTime();
    });
  };

  const removeMany = (ids) => {
    for (const id of ids) state.pedidosMap.delete(Number(id));
    state.pedidos = Array.from(state.pedidosMap.values()).sort((a, b) => {
      return new Date(a.created_at).getTime() - new Date(b.created_at).getTime();
    });
  };

  const formatMoney = (n) => {
    const num = Number(n ?? 0);
    return num.toLocaleString('es-ES', { style: 'currency', currency: 'EUR' });
  };

  const matchesFilter = (p) => {
    const q = (state.filtro || '').trim().toLowerCase();
    if (!q) return true;

    const hay = [
      p.numero_pedido,
      p.cliente_nombre,
      p.estado,
      p.estado_envio,
      String(p.total ?? ''),
    ].join(' ').toLowerCase();

    return hay.includes(q);
  };

  // Render simple (puedes copiar tu renderer actual y pegarlo aquí)
  const render = () => {
    const rows = state.pedidos.filter(matchesFilter);

    setTotal();

    // 2XL grid
    const contGrid = el('tablaPedidos');
    if (contGrid) {
      contGrid.innerHTML = rows.map(p => {
        const itemsCount = Array.isArray(p.items) ? p.items.length : 0;
        return `
          <div class="border-b border-slate-100 min-w-[1500px]">
            <div class="grid prod-grid-cols space-x-4 items-center gap-3 px-4 py-3 text-sm text-slate-800">
              <div class="font-extrabold">${p.numero_pedido ?? ('#' + p.id)}</div>
              <div class="text-slate-600">${(p.created_at ?? '').slice(0, 10)}</div>
              <div class="truncate">${p.cliente_nombre ?? '—'}</div>
              <div class="text-right font-semibold">${formatMoney(p.total)}</div>
              <div class="text-slate-700">${p.estado ?? '—'}</div>
              <div class="text-slate-600">${(p.ultimo_cambio ?? '').replace('T', ' ').slice(0, 19) || '—'}</div>
              <div class="text-center font-extrabold">${itemsCount}</div>
              <div class="text-slate-600">—</div>
              <div class="text-slate-600">—</div>
              <div class="text-right">
                <button data-id="${p.id}" class="btnDetalles px-3 py-2 rounded-xl bg-slate-900 text-white font-extrabold hover:bg-slate-800">
                  Ver
                </button>
              </div>
            </div>
          </div>
        `;
      }).join('');
    }

    // XL table
    const contTable = el('tablaPedidosTable');
    if (contTable) {
      contTable.innerHTML = rows.map(p => {
        const itemsCount = Array.isArray(p.items) ? p.items.length : 0;
        return `
          <tr>
            <td class="px-5 py-4 font-extrabold">${p.numero_pedido ?? ('#' + p.id)}</td>
            <td class="px-5 py-4 text-slate-600">${(p.created_at ?? '').slice(0, 10)}</td>
            <td class="px-5 py-4">${p.cliente_nombre ?? '—'}</td>
            <td class="px-5 py-4 font-semibold">${formatMoney(p.total)}</td>
            <td class="px-5 py-4">${p.estado ?? '—'}</td>
            <td class="px-5 py-4 text-slate-600">${(p.ultimo_cambio ?? '').replace('T',' ').slice(0, 19) || '—'}</td>
            <td class="px-5 py-4 text-center font-extrabold">${itemsCount}</td>
            <td class="px-5 py-4">—</td>
            <td class="px-5 py-4">—</td>
            <td class="px-5 py-4 text-right">
              <button data-id="${p.id}" class="btnDetalles px-3 py-2 rounded-xl bg-slate-900 text-white font-extrabold hover:bg-slate-800">
                Ver
              </button>
            </td>
          </tr>
        `;
      }).join('');
    }

    // Mobile cards
    const contCards = el('cardsPedidos');
    if (contCards) {
      contCards.innerHTML = rows.map(p => {
        const itemsCount = Array.isArray(p.items) ? p.items.length : 0;
        return `
          <div class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm mb-3">
            <div class="flex items-center justify-between gap-3">
              <div class="font-extrabold text-slate-900">${p.numero_pedido ?? ('#' + p.id)}</div>
              <div class="text-slate-500 text-sm">${(p.created_at ?? '').slice(0,10)}</div>
            </div>
            <div class="mt-2 text-slate-700 text-sm">
              <div><span class="font-semibold">Cliente:</span> ${p.cliente_nombre ?? '—'}</div>
              <div><span class="font-semibold">Total:</span> ${formatMoney(p.total)}</div>
              <div><span class="font-semibold">Estado:</span> ${p.estado ?? '—'}</div>
              <div><span class="font-semibold">Items:</span> ${itemsCount}</div>
            </div>
            <div class="mt-3 flex justify-end">
              <button data-id="${p.id}" class="btnDetalles h-10 px-4 rounded-2xl bg-slate-900 text-white font-extrabold hover:bg-slate-800">
                Ver detalles
              </button>
            </div>
          </div>
        `;
      }).join('');
    }
  };

  const apiGet = async (path) => {
    const res = await fetch(API(path), { credentials: 'same-origin' });
    return res.json();
  };

  const apiPost = async (path, body) => {
    const res = await fetch(API(path), {
      method: 'POST',
      credentials: 'same-origin',
      headers: withCsrf({ 'Content-Type': 'application/json' }),
      body: JSON.stringify(body ?? {})
    });

    // refresca token CSRF si tu backend lo rota (opcional)
    const newToken = res.headers.get('X-CSRF-TOKEN');
    if (newToken) {
      const meta = document.querySelector('meta[name="csrf-token"]');
      if (meta) meta.setAttribute('content', newToken);
    }

    return res.json();
  };

  const loadMine = async () => {
    loaderOn();
    try {
      const json = await apiGet('/api/por-producir/mine');
      if (json?.ok) {
        upsertMany(json.data || []);
        render();
      }
    } finally {
      loaderOff();
    }
  };

  const claim = async (limit) => {
    loaderOn();
    try {
      const json = await apiPost('/api/por-producir/claim', { limit });
      if (json?.ok) {
        upsertMany(json.data || []);
        render();
      }
    } finally {
      loaderOff();
    }
  };

  const devolver = async () => {
    loaderOn();
    try {
      const json = await apiPost('/api/por-producir/return', {});
      if (json?.ok) {
        // vaciamos UI directamente (porque devolviste todo)
        state.pedidos = [];
        state.pedidosMap.clear();
        render();
      }
    } finally {
      loaderOff();
    }
  };

  // Auto-remover enviados (cada 20s)
  const startAutoRemove = () => {
    setInterval(async () => {
      const ids = Array.from(state.pedidosMap.keys());
      if (!ids.length) return;

      try {
        const json = await apiPost('/api/por-producir/check', { ids });
        const removed = json?.removed || [];
        if (removed.length) {
          removeMany(removed);
          render();
        }
      } catch (e) {
        // silencioso
      }
    }, 20000);
  };

  const bind = () => {
    el('btnTraer50')?.addEventListener('click', () => claim(50));
    el('btnTraer100')?.addEventListener('click', () => claim(100));
    el('btnDevolver')?.addEventListener('click', devolver);

    el('inputBuscar')?.addEventListener('input', (e) => {
      state.filtro = e.target.value || '';
      render();
    });

    el('btnLimpiarBusqueda')?.addEventListener('click', () => {
      state.filtro = '';
      const inp = el('inputBuscar');
      if (inp) inp.value = '';
      render();
    });

    // Si luego quieres abrir tu modal de detalles, engancha aquí:
    document.body.addEventListener('click', (e) => {
      const btn = e.target.closest('.btnDetalles');
      if (!btn) return;
      const id = Number(btn.getAttribute('data-id'));
      const p = state.pedidosMap.get(id);
      if (!p) return;

      // TODO: aquí llamas tu modal actual (copias el mismo del diseño)
      alert(`Pedido: ${p.numero_pedido ?? ('#' + p.id)}`);
    });
  };

  // Init
  bind();
  loadMine();
  startAutoRemove();
})();
