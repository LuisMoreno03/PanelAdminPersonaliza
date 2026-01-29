(() => {
  const $tbody = document.getElementById('pp-tbody');
  const $pullBtn = document.getElementById('pp-pull');
  const $limit = document.getElementById('pp-limit');
  const $alert = document.getElementById('pp-alert');

  const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

  function showError(msg) {
    $alert.textContent = msg || 'Ocurrió un error';
    $alert.classList.remove('d-none');
  }
  function clearError() {
    $alert.classList.add('d-none');
    $alert.textContent = '';
  }

  function escapeHtml(str) {
    return String(str ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function metodoSelect(pedido) {
    const current = (pedido.metodo_entrega || '').toLowerCase();

    // Ajusta tus opciones reales aquí
    const opts = [
      { value: 'Recoger', label: 'Recoger' },
      { value: 'Local', label: 'Local' },
      { value: 'Enviado', label: 'Enviado' },
    ];

    const optionsHtml = opts.map(o => {
      const selected = (o.value.toLowerCase() === current) ? 'selected' : '';
      return `<option value="${escapeHtml(o.value)}" ${selected}>${escapeHtml(o.label)}</option>`;
    }).join('');

    return `
      <select class="form-select pp-metodo" data-id="${pedido.id}">
        ${optionsHtml}
      </select>
    `;
  }

  function renderRows(rows) {
    if (!rows || rows.length === 0) {
      $tbody.innerHTML = `<tr><td colspan="7" class="text-center text-muted">No hay pedidos en estado “Diseñado”.</td></tr>`;
      return;
    }

    $tbody.innerHTML = rows.map(p => `
      <tr id="pp-row-${p.id}">
        <td>${escapeHtml(p.id)}</td>
        <td>${escapeHtml(p.numero_pedido)}</td>
        <td>${escapeHtml(p.cliente)}</td>
        <td>${metodoSelect(p)}</td>
        <td class="pp-estado">${escapeHtml(p.estado)}</td>
        <td>${escapeHtml(p.total)}</td>
        <td>${escapeHtml(p.updated_at)}</td>
      </tr>
    `).join('');

    // Bind changes
    $tbody.querySelectorAll('.pp-metodo').forEach(sel => {
      sel.addEventListener('change', onMetodoChange);
    });
  }

  async function pull() {
    clearError();
    $pullBtn.disabled = true;

    try {
      const limitVal = $limit.value;
      const url = new URL(window.POR_PRODUCIR.pullUrl, window.location.origin);
      url.searchParams.set('limit', limitVal);

      const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
      const json = await res.json();

      if (!res.ok || !json.ok) throw new Error(json.message || 'Error en pull');
      renderRows(json.data);
    } catch (e) {
      showError(e.message);
    } finally {
      $pullBtn.disabled = false;
    }
  }

  async function onMetodoChange(e) {
    clearError();

    const select = e.target;
    const id = select.getAttribute('data-id');
    const metodo_entrega = select.value;

    // UX: bloquea select mientras actualiza
    select.disabled = true;

    try {
      const url = `${window.POR_PRODUCIR.updateMetodoUrlBase}/${id}/metodo-entrega`;

      const res = await fetch(url, {
        method: 'PATCH',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrf,
        },
        body: JSON.stringify({ metodo_entrega }),
      });

      const json = await res.json();
      if (!res.ok || !json.ok) throw new Error(json.message || 'No se pudo actualizar');

      // Actualiza estado en UI
      const row = document.getElementById(`pp-row-${id}`);
      if (row) {
        const estadoCell = row.querySelector('.pp-estado');
        if (estadoCell) estadoCell.textContent = json.estado;

        // Si ya no es "Diseñado", se remueve de lista automáticamente
        if (json.remove_from_list) {
          row.remove();
          // Si se queda vacía la tabla:
          if (!$tbody.querySelector('tr')) {
            $tbody.innerHTML = `<tr><td colspan="7" class="text-center text-muted">No hay pedidos en estado “Diseñado”.</td></tr>`;
          }
        }
      }
    } catch (err) {
      showError(err.message);
      // Revert opcional (si quieres guardar valor anterior habría que almacenarlo antes)
    } finally {
      select.disabled = false;
    }
  }

  $pullBtn.addEventListener('click', pull);

  // opcional: pull automático al entrar
  pull();
})();
