/**
 * public/js/porproducir.js
 *
 * Reglas:
 * - Pull trae 5 o 10 pedidos en estado "Diseñado"
 * - Si el método de entrega cambia a "Enviado" => backend cambia estado a "Enviado"
 *   y el pedido se elimina automáticamente de la lista (sin recargar).
 *
 * Requiere en el view:
 * - window.API.pull
 * - window.API.updateMetodo  (endpoint para actualizar método/estado)
 * - window.API.detalles      (endpoint para modal detalles)
 * - window.CSRF.token / window.CSRF.header
 * - ids: btnTraer5, btnTraer10, tablaPedidos, total-pedidos, ppAlert, globalLoader, modalDetallesFull, detTitulo, detCliente, detProductos, detResumen
 */

(() => {
  const $ = (id) => document.getElementById(id);

  const tabla = $('tablaPedidos');
  const totalSpan = $('total-pedidos');
  const alertBox = $('ppAlert');
  const loader = $('globalLoader');

  const btn5 = $('btnTraer5');
  const btn10 = $('btnTraer10');

  // Estado local
  let pedidos = [];
  let pedidoJsonActual = null;

  // =========================
  // UI helpers
  // =========================
  function showLoader(v) {
    if (!loader) return;
    loader.classList.toggle('hidden', !v);
  }

  function showError(msg) {
    if (!alertBox) return;
    alertBox.textContent = msg || 'Ocurrió un error';
    alertBox.classList.remove('hidden');
  }

  function clearError() {
    if (!alertBox) return;
    alertBox.classList.add('hidden');
    alertBox.textContent = '';
  }

  function escapeHtml(str) {
    return String(str ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function fmtDateShort(iso) {
    if (!iso) return '—';
    // Si llega "YYYY-MM-DD ..." o ISO:
    const d = new Date(iso);
    if (isNaN(d.getTime())) return String(iso);
    const dd = String(d.getDate()).padStart(2, '0');
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const yy = d.getFullYear();
    return `${dd}/${mm}/${yy}`;
  }

  function fmtMoney(v) {
    if (v === null || v === undefined || v === '') return '—';
    const num = Number(v);
    if (Number.isNaN(num)) return String(v);
    return num.toLocaleString('es-ES', { style: 'currency', currency: 'EUR' });
  }

  function setTotal(n) {
    if (!totalSpan) return;
    totalSpan.textContent = String(n ?? 0);
  }

  // =========================
  // HTTP helpers (CSRF)
  // =========================
  async function httpJson(url, { method = 'GET', body = null } = {}) {
    const headers = { 'Accept': 'application/json' };

    if (method !== 'GET' && method !== 'HEAD') {
      headers['Content-Type'] = 'application/json';

      // CSRF (CodeIgniter / similar)
      if (window.CSRF?.header && window.CSRF?.token) {
        headers[window.CSRF.header] = window.CSRF.token;
      }
    }

    const res = await fetch(url, {
      method,
      headers,
      body: body ? JSON.stringify(body) : null
    });

    // CI a veces devuelve HTML en error; intentamos JSON y fallback:
    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch (_) {}

    if (!res.ok) {
      const msg = json?.message || json?.error || text?.slice(0, 180) || `HTTP ${res.status}`;
      throw new Error(msg);
    }

    return json ?? {};
  }

  // =========================
  // Render
  // =========================
  function etiquetasHtml(p) {
    const tags = p?.etiquetas || p?.tags || [];
    if (!Array.isArray(tags) || tags.length === 0) {
      return `<span class="text-xs text-slate-400">—</span>`;
    }
    const max = 3;
    const view = tags.slice(0, max).map(t => `
      <span class="inline-flex items-center px-2 py-1 rounded-full bg-slate-50 border border-slate-200 text-[11px] font-extrabold text-slate-700 mr-1">
        ${escapeHtml(t)}
      </span>
    `).join('');
    const extra = tags.length > max
      ? `<span class="text-[11px] font-extrabold text-slate-500">+${tags.length - max}</span>`
      : '';
    return view + extra;
  }

  function estadoBadge(estado) {
    const e = (estado || '').toLowerCase();
    let cls = 'bg-slate-50 border-slate-200 text-slate-800';
    if (e === 'diseñado' || e === 'disenado') cls = 'bg-amber-50 border-amber-200 text-amber-900';
    if (e === 'enviado') cls = 'bg-emerald-50 border-emerald-200 text-emerald-900';

    return `
      <span class="inline-flex items-center px-3 py-1 rounded-full border text-[11px] font-extrabold ${cls}">
        ${escapeHtml(estado || '—')}
      </span>
    `;
  }

  function entregaHtml(p) {
    // Si tienes un campo "entrega" o "fecha_entrega"
    const entrega = p?.entrega || p?.fecha_entrega || '—';
    return `<span class="text-sm font-extrabold text-slate-800 truncate">${escapeHtml(entrega)}</span>`;
  }

  function metodoSelectHtml(p) {
    const cur = String(p?.metodo_entrega ?? p?.metodo ?? '').toLowerCase();

    // Ajusta tus opciones reales aquí
    const opciones = [
      { value: 'Recoger', label: 'Recoger' },
      { value: 'Local', label: 'Local' },
      { value: 'Enviado', label: 'Enviado' }
    ];

    const opts = opciones.map(o => {
      const selected = o.value.toLowerCase() === cur ? 'selected' : '';
      return `<option value="${escapeHtml(o.value)}" ${selected}>${escapeHtml(o.label)}</option>`;
    }).join('');

    return `
      <select
        class="ppMetodo w-full px-3 py-2 rounded-xl border border-slate-200 bg-white font-extrabold text-sm
               focus:outline-none focus:ring-2 focus:ring-slate-900/10"
        data-id="${escapeHtml(p.id)}">
        ${opts}
      </select>
    `;
  }

  function verBtnHtml(p) {
    return `
      <button
        class="ppVer w-full px-4 py-2 rounded-xl bg-slate-900 text-white font-extrabold text-sm hover:bg-slate-800 transition text-right"
        data-id="${escapeHtml(p.id)}">
        Ver
      </button>
    `;
  }

  function rowHtml(p) {
    const pedido = p?.numero_pedido || p?.pedido || p?.name || p?.order_name || p?.id || '—';
    const fecha = fmtDateShort(p?.fecha || p?.created_at);
    const cliente = p?.cliente || p?.customer || '—';
    const total = fmtMoney(p?.total);
    const estado = p?.estado || '—';
    const updated = fmtDateShort(p?.updated_at || p?.ultimo_cambio);

    // Artículos (si lo tienes)
    const art = p?.articulos ?? p?.items_count ?? p?.art ?? '—';

    return `
      <div id="row-${escapeHtml(p.id)}" class="orders-grid cols px-4 py-3">
        <div class="truncate font-extrabold text-slate-900">${escapeHtml(pedido)}</div>
        <div class="text-sm font-extrabold text-slate-700">${escapeHtml(fecha)}</div>
        <div class="truncate">
          <div class="font-extrabold text-slate-900 truncate">${escapeHtml(cliente)}</div>
          <div class="text-xs text-slate-500 truncate">#${escapeHtml(p.id)}</div>
        </div>
        <div class="text-sm font-extrabold text-slate-900">${escapeHtml(total)}</div>
        <div class="ppEstado">${estadoBadge(estado)}</div>
        <div class="text-xs font-extrabold text-slate-600 truncate">${escapeHtml(updated)}</div>
        <div class="truncate">${etiquetasHtml(p)}</div>
        <div class="text-center font-extrabold text-slate-900">${escapeHtml(art)}</div>
        <div class="truncate">${entregaHtml(p)}</div>
        <div>${metodoSelectHtml(p)}</div>
        <div class="text-right">${verBtnHtml(p)}</div>
      </div>
    `;
  }

  function render() {
    if (!tabla) return;

    if (!Array.isArray(pedidos) || pedidos.length === 0) {
      tabla.innerHTML = `
        <div class="px-4 py-6 text-center text-slate-500 font-extrabold">
          No hay pedidos en estado <b>Diseñado</b>.
        </div>
      `;
      setTotal(0);
      return;
    }

    tabla.innerHTML = pedidos.map(rowHtml).join('');
    setTotal(pedidos.length);

    // Bind eventos
    tabla.querySelectorAll('.ppMetodo').forEach(sel => {
      sel.addEventListener('change', onMetodoChange);
    });
    tabla.querySelectorAll('.ppVer').forEach(btn => {
      btn.addEventListener('click', onVerClick);
    });
  }

  // =========================
  // Pull
  // =========================
  async function pull(limit) {
    clearError();
    showLoader(true);

    try {
      const url = new URL(window.API.pull, window.location.origin);
      url.searchParams.set('limit', String(limit));

      const json = await httpJson(url.toString(), { method: 'GET' });

      // Esperado: { ok:true, data:[...] } o directo array
      const data = Array.isArray(json) ? json : (json.data ?? []);
      pedidos = Array.isArray(data) ? data : [];

      render();
    } catch (e) {
      showError(e.message);
    } finally {
      showLoader(false);
    }
  }

  // =========================
  // Update método de entrega
  // =========================
  async function onMetodoChange(e) {
    clearError();

    const select = e.target;
    const id = select.getAttribute('data-id');
    const metodo = select.value;

    select.disabled = true;
    showLoader(true);

    try {
      // Puedes usar 2 formatos de endpoint:
      // A) window.API.updateMetodo = /porproducir/update-metodo  (POST/PATCH con {id, metodo_entrega})
      // B) window.API.updateMetodo = /por-producir/{id}/metodo-entrega (PATCH)
      //
      // Aquí usamos el formato A por defecto (más CI-friendly).
      const payload = { id, metodo_entrega: metodo };

      const json = await httpJson(window.API.updateMetodo, {
        method: 'POST',
        body: payload
      });

      // Esperado: { ok:true, estado:'Enviado', remove_from_list:true/false, csrf?:{token:'...'} }
      if (json?.csrf?.token && window.CSRF?.token !== json.csrf.token) {
        // Si el backend rota el token, lo actualizamos
        window.CSRF.token = json.csrf.token;
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) meta.setAttribute('content', json.csrf.token);
      }

      const estadoNuevo = json?.estado || (metodo.toLowerCase() === 'enviado' ? 'Enviado' : 'Diseñado');
      const remove = !!json?.remove_from_list;

      // UI update
      const row = document.getElementById(`row-${id}`);
      if (row) {
        const estadoNode = row.querySelector('.ppEstado');
        if (estadoNode) estadoNode.innerHTML = estadoBadge(estadoNuevo);

        if (remove) {
          // quitar del array y del DOM
          pedidos = pedidos.filter(p => String(p.id) !== String(id));
          row.remove();
          setTotal(pedidos.length);

          if (pedidos.length === 0) render();
        } else {
          // actualizar en array
          pedidos = pedidos.map(p => String(p.id) === String(id)
            ? { ...p, metodo_entrega: metodo, estado: estadoNuevo }
            : p
          );
        }
      }
    } catch (err) {
      showError(err.message);
    } finally {
      select.disabled = false;
      showLoader(false);
    }
  }

  // =========================
  // Modal detalles (opcional)
  // =========================
  async function onVerClick(e) {
    const id = e.currentTarget.getAttribute('data-id');
    if (!id) return;

    clearError();
    showLoader(true);

    try {
      // Endpoint esperado: window.API.detalles?id=123
      const url = new URL(window.API.detalles, window.location.origin);
      url.searchParams.set('id', String(id));

      const json = await httpJson(url.toString(), { method: 'GET' });
      pedidoJsonActual = json;

      // Si tu endpoint ya devuelve {ok:true, data:{...}}:
      const data = json?.data ?? json;

      // Pintar modal (mínimo; tu backend puede devolver html listo)
      const modal = document.getElementById('modalDetallesFull');
      const detTitulo = document.getElementById('detTitulo');
      const detCliente = document.getElementById('detCliente');
      const detProductos = document.getElementById('detProductos');
      const detResumen = document.getElementById('detResumen');

      if (detTitulo) detTitulo.textContent = data?.pedido || data?.numero_pedido || data?.order_name || `Pedido #${id}`;
      if (detCliente) detCliente.textContent = data?.cliente || data?.customer || '—';

      // Si tienes HTML ya listo:
      if (data?.htmlProductos && detProductos) detProductos.innerHTML = data.htmlProductos;
      else if (detProductos) detProductos.innerHTML = `
        <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5">
          <div class="font-extrabold text-slate-900 mb-2">JSON (preview)</div>
          <pre class="text-xs bg-slate-50 border border-slate-200 rounded-2xl p-4 overflow-auto">${escapeHtml(JSON.stringify(data, null, 2))}</pre>
        </div>
      `;

      if (data?.htmlResumen && detResumen) detResumen.innerHTML = data.htmlResumen;
      else if (detResumen) detResumen.innerHTML = `
        <div class="font-extrabold text-slate-900 mb-2">Resumen</div>
        <div class="text-sm text-slate-600">
          <div><b>Estado:</b> ${escapeHtml(data?.estado ?? '—')}</div>
          <div><b>Total:</b> ${escapeHtml(fmtMoney(data?.total))}</div>
          <div><b>Método:</b> ${escapeHtml(data?.metodo_entrega ?? data?.metodo ?? '—')}</div>
        </div>
      `;

      if (modal) modal.classList.remove('hidden');
    } catch (err) {
      showError(err.message);
    } finally {
      showLoader(false);
    }
  }

  // Copiar JSON del pedido actual (botón modal)
  window.copiarJsonPedido = async function () {
    try {
      if (!pedidoJsonActual) throw new Error('No hay JSON cargado');
      await navigator.clipboard.writeText(JSON.stringify(pedidoJsonActual, null, 2));
    } catch (e) {
      showError(e.message);
    }
  };

  // Cerrar modal (el view ya lo llama)
  window.cerrarModalDetalles = function () {
    document.getElementById('modalDetallesFull')?.classList.add('hidden');
  };

  // =========================
  // Bind acciones
  // =========================
  btn5?.addEventListener('click', () => pull(5));
  btn10?.addEventListener('click', () => pull(10));

  // Pull inicial
  pull(10);
})();
