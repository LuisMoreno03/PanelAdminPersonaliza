(() => {
  const base = window.API_BASE || "";

  // ------- DOM -------
  const globalLoader = document.getElementById("globalLoader");
  const errorBox = document.getElementById("errorBox");

  const inputBuscar = document.getElementById("inputBuscar");
  const btnLimpiarBusqueda = document.getElementById("btnLimpiarBusqueda");

  const fromEl = document.getElementById("from");
  const toEl = document.getElementById("to");
  const btnFiltrar = document.getElementById("btnFiltrar");
  const btnLimpiarFechas = document.getElementById("btnLimpiarFechas");
  const btnActualizar = document.getElementById("btnActualizar");

  const totalUsuariosEl = document.getElementById("total-usuarios");
  const totalCambiosEl = document.getElementById("total-cambios");

  const tabla = document.getElementById("tablaSeguimiento");
  const cards = document.getElementById("cardsSeguimiento");

  // Modal
  const detalleModal = document.getElementById("detalleModal");
  const detalleCerrar = document.getElementById("detalleCerrar");
  const detalleTitulo = document.getElementById("detalleTitulo");
  const detalleDescripcion = document.getElementById("detalleDescripcion");

  const detalleLoading = document.getElementById("detalleLoading");
  const detalleError = document.getElementById("detalleError");
  const detallePedidosBox = document.getElementById("detallePedidosBox");
  const detallePedidosCount = document.getElementById("detallePedidosCount");

  const detalleBodyTable = document.getElementById("detalleBodyTable");
  const detalleBodyCards = document.getElementById("detalleBodyCards");

  const detallePrev = document.getElementById("detallePrev");
  const detalleNext = document.getElementById("detalleNext");
  const detallePaginacionInfo = document.getElementById("detallePaginacionInfo");

  // ------- State -------
  let cacheRows = [];
  let detalleState = { userId: null, offset: 0, limit: 50, total: 0 };

  // ------- Helpers -------
  function setLoading(v) {
    globalLoader?.classList.toggle("hidden", !v);
  }

  function setError(msg) {
    if (!errorBox) return;
    if (!msg) {
      errorBox.classList.add("hidden");
      errorBox.textContent = "";
      return;
    }
    errorBox.textContent = msg;
    errorBox.classList.remove("hidden");
  }

  function escapeHtml(str) {
    return String(str ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function fmtDate(v) {
    return v ? String(v) : "-";
  }

  function getRangeParams() {
    const params = new URLSearchParams();
    if (fromEl?.dataset?.value) params.set("from", fromEl.dataset.value);
    if (toEl?.dataset?.value) params.set("to", toEl.dataset.value);
    return params;
  }

  // ------- Flatpickr (calendario moderno) -------
  function setupCalendar() {
    if (!window.flatpickr || !fromEl || !toEl) return;

    const cfg = {
      dateFormat: "d/m/Y",
      allowInput: true,
      disableMobile: true,
      onChange: (selectedDates, dateStr, instance) => {
        // Guardamos ISO en dataset.value
        const d = selectedDates?.[0];
        if (!d) {
          instance.input.dataset.value = "";
          return;
        }
        const iso = d.toISOString().slice(0, 10);
        instance.input.dataset.value = iso;
      }
    };

    flatpickr(fromEl, cfg);
    flatpickr(toEl, cfg);
    fromEl.dataset.value = "";
    toEl.dataset.value = "";
  }

  // ------- Summary fetch -------
  async function fetchResumen() {
    const params = getRangeParams();
    const url = `${base}/seguimiento/resumen?${params.toString()}`;

    const res = await fetch(url, { headers: { "Accept": "application/json" } });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const json = await res.json();
    if (!json?.ok) throw new Error(json?.message || "Respuesta inválida");
    return json;
  }

  function applySearch(rows) {
    const q = (inputBuscar?.value || "").trim().toLowerCase();
    if (!q) return rows;

    return rows.filter(r => {
      const name = (r.user_name || "").toLowerCase();
      const email = (r.user_email || "").toLowerCase();
      return name.includes(q) || email.includes(q) || String(r.user_id || "").includes(q);
    });
  }

  function updateCounters(rows) {
    const totalUsuarios = rows.length;
    const totalCambios = rows.reduce((acc, r) => acc + Number(r.total_cambios || 0), 0);
    if (totalUsuariosEl) totalUsuariosEl.textContent = String(totalUsuarios);
    if (totalCambiosEl) totalCambiosEl.textContent = String(totalCambios);
  }

  function renderTable(rows) {
    if (!tabla) return;
    tabla.innerHTML = "";

    if (!rows.length) {
      tabla.innerHTML = `<div class="px-4 py-6 text-sm font-bold text-slate-500">No hay registros para mostrar.</div>`;
      return;
    }

    const frag = document.createDocumentFragment();

    rows.forEach(r => {
      const userId = Number(r.user_id ?? 0);
      const userName = r.user_name || (userId === 0 ? "Sin usuario (no registrado)" : `Usuario #${userId}`);
      const userEmail = r.user_email || "-";
      const total = Number(r.total_cambios || 0);
      const pedidos = Number(r.pedidos_tocados || 0);
      const ultimo = fmtDate(r.ultimo_cambio);

      const row = document.createElement("div");
      row.className = "seg-grid-cols px-4 py-3 border-b border-slate-100 text-sm font-semibold text-slate-900 hover:bg-slate-50 transition";

      row.innerHTML = `
        <div class="min-w-0">
          <div class="font-extrabold truncate">${escapeHtml(userName)}</div>
          <div class="text-[12px] text-slate-500 font-bold">ID: ${escapeHtml(userId)}</div>
        </div>

        <div class="min-w-0 truncate text-slate-700 font-bold">${escapeHtml(userEmail)}</div>

        <div class="text-right">
          <span class="inline-flex items-center px-3 py-1 rounded-2xl bg-slate-100 text-slate-900 border border-slate-200 font-extrabold text-xs">
            ${escapeHtml(pedidos)}
          </span>
        </div>

        <div class="flex items-center justify-between gap-2">
          <span class="inline-flex items-center px-3 py-1 rounded-2xl bg-slate-900 text-white font-extrabold text-xs">
            ${escapeHtml(total)}
          </span>
          <span class="text-xs font-extrabold text-slate-600 truncate">${escapeHtml(ultimo)}</span>
        </div>

        <div class="text-right">
          <button type="button"
            class="inline-flex items-center justify-center h-9 px-4 rounded-2xl bg-white border border-slate-200 text-slate-900 font-extrabold text-xs shadow-sm hover:bg-slate-100 transition"
            data-user="${escapeHtml(userId)}">
            Ver
          </button>
        </div>
      `;

      row.querySelector("button[data-user]")?.addEventListener("click", () => openModalDetalle(userId));
      frag.appendChild(row);
    });

    tabla.appendChild(frag);
  }

  function renderCards(rows) {
    if (!cards) return;
    cards.innerHTML = "";

    if (!rows.length) {
      cards.innerHTML = `<div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-4 text-sm font-bold text-slate-500">No hay registros para mostrar.</div>`;
      return;
    }

    const frag = document.createDocumentFragment();

    rows.forEach(r => {
      const userId = Number(r.user_id ?? 0);
      const userName = r.user_name || (userId === 0 ? "Sin usuario (no registrado)" : `Usuario #${userId}`);
      const userEmail = r.user_email || "-";
      const total = Number(r.total_cambios || 0);
      const pedidos = Number(r.pedidos_tocados || 0);
      const ultimo = fmtDate(r.ultimo_cambio);

      const card = document.createElement("div");
      card.className = "rounded-3xl border border-slate-200 bg-white shadow-sm p-4 mb-3";

      card.innerHTML = `
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="text-base font-extrabold truncate">${escapeHtml(userName)}</div>
            <div class="text-sm font-bold text-slate-600 truncate">${escapeHtml(userEmail)}</div>
            <div class="text-xs font-bold text-slate-500 mt-1">ID: ${escapeHtml(userId)}</div>
          </div>
          <div class="shrink-0 flex flex-col gap-2 items-end">
            <span class="inline-flex items-center px-3 py-1 rounded-2xl bg-slate-900 text-white font-extrabold text-xs">${escapeHtml(total)} cambios</span>
            <span class="inline-flex items-center px-3 py-1 rounded-2xl bg-slate-100 text-slate-900 border border-slate-200 font-extrabold text-xs">${escapeHtml(pedidos)} pedidos</span>
          </div>
        </div>

        <div class="mt-3 text-sm font-bold text-slate-700">
          Último cambio: <span class="font-extrabold">${escapeHtml(ultimo)}</span>
        </div>

        <div class="mt-3 flex justify-end">
          <button type="button"
            class="h-10 px-4 rounded-2xl bg-white border border-slate-200 text-slate-900 font-extrabold text-sm shadow-sm hover:bg-slate-100 transition"
            data-user="${escapeHtml(userId)}">
            Ver
          </button>
        </div>
      `;

      card.querySelector("button[data-user]")?.addEventListener("click", () => openModalDetalle(userId));
      frag.appendChild(card);
    });

    cards.appendChild(frag);
  }

  function renderAll() {
    const rows = applySearch(cacheRows);
    updateCounters(rows);
    renderTable(rows);
    renderCards(rows);
  }

  async function cargar() {
    setError("");
    setLoading(true);
    try {
      const json = await fetchResumen();
      cacheRows = json.data || [];
      renderAll();
    } catch (e) {
      cacheRows = [];
      renderAll();
      setError("Error cargando seguimiento: " + (e?.message || e));
      console.error(e);
    } finally {
      setLoading(false);
    }
  }

  // ------- Modal -------
  function modalSetLoading(v) {
    detalleLoading?.classList.toggle("hidden", !v);
  }

  function modalSetError(msg) {
    if (!detalleError) return;
    if (!msg) {
      detalleError.classList.add("hidden");
      detalleError.textContent = "";
      return;
    }
    detalleError.textContent = msg;
    detalleError.classList.remove("hidden");
  }

  function closeModalDetalle() {
    detalleModal?.classList.add("hidden");
    detalleBodyTable && (detalleBodyTable.innerHTML = "");
    detalleBodyCards && (detalleBodyCards.innerHTML = "");
    detallePedidosBox && (detallePedidosBox.innerHTML = "");
    if (detallePedidosCount) detallePedidosCount.textContent = "0";
    if (detalleDescripcion) detalleDescripcion.innerHTML = "";
    modalSetError("");
  }

  async function fetchDetalle(userId, offset, limit) {
    const params = getRangeParams();
    params.set("offset", String(offset));
    params.set("limit", String(limit));

    const url = `${base}/seguimiento/detalle/${encodeURIComponent(userId)}?${params.toString()}`;

    const res = await fetch(url, { headers: { "Accept": "application/json" } });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const json = await res.json();
    if (!json?.ok) throw new Error(json?.message || "Respuesta inválida");
    return json;
  }

  function pill(label, value, strong = false) {
    return `
      <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-2xl border ${
        strong ? "bg-slate-900 text-white border-slate-900" : "bg-white text-slate-900 border-slate-200"
      } text-xs font-extrabold">
        <span class="text-[10px] uppercase tracking-wide ${strong ? "text-white/70" : "text-slate-500"}">${escapeHtml(label)}</span>
        <span class="${strong ? "text-white" : "text-slate-900"}">${escapeHtml(value)}</span>
      </span>
    `;
  }

  function renderDetalleDescripcion(json) {
    const name = json.user_name || "-";
    const email = json.user_email || "-";
    const uid = json.user_id ?? 0;
    const cambios = json.total ?? 0;

    const pedidosTocados = Array.isArray(json.pedidos) ? json.pedidos.length : 0;
    const confirmados = json.kpis?.confirmados ?? 0;
    const disenos = json.kpis?.disenos ?? 0;

    const range = (fromEl?.dataset?.value || toEl?.dataset?.value)
      ? `${fromEl?.dataset?.value || "…"} → ${toEl?.dataset?.value || "…"}`
      : "Histórico";

    detalleDescripcion.innerHTML = [
      pill("Usuario", name),
      pill("Email", email),
      pill("ID", uid),
      pill("Cambios", cambios, true),
      pill("Pedidos tocados", pedidosTocados, true),
      pill("Confirmados", confirmados),
      pill("Diseños", disenos),
      pill("Rango", range),
    ].join("");
  }

  function renderPedidosTocados(pedidos = []) {
    if (!detallePedidosBox) return;

    const total = Array.isArray(pedidos) ? pedidos.length : 0;
    if (detallePedidosCount) detallePedidosCount.textContent = String(total);

    detallePedidosBox.innerHTML = "";

    if (!total) {
      detallePedidosBox.innerHTML = `
        <div class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-600">
          No hay pedidos tocados en este rango.
        </div>
      `;
      return;
    }

    const frag = document.createDocumentFragment();

    pedidos.forEach(p => {
      const entidad = (p.entidad || "").toLowerCase();
      const id = p.entidad_id ? String(p.entidad_id) : "-";
      const cambios = Number(p.cambios || 0);
      const ultimo = p.ultimo ? String(p.ultimo) : "-";
      const label = entidad === "order" ? `Order #${id}` : `Pedido #${id}`;

      const chip = document.createElement("div");
      chip.className = "shrink-0 rounded-2xl border border-slate-200 bg-white px-3 py-2 shadow-sm";

      chip.innerHTML = `
        <div class="flex items-center gap-2">
          <div class="text-xs font-extrabold text-slate-900">${escapeHtml(label)}</div>
          <span class="inline-flex items-center px-2 py-0.5 rounded-xl bg-slate-900 text-white text-[10px] font-extrabold">
            ${escapeHtml(cambios)} cambios
          </span>
        </div>
        <div class="mt-1 text-[11px] font-bold text-slate-500 truncate">
          Último: ${escapeHtml(ultimo)}
        </div>
      `;

      frag.appendChild(chip);
    });

    detallePedidosBox.appendChild(frag);
  }

  function statusBadge(text, strong = false) {
    const v = String(text ?? "-").trim();
    if (!v || v === "-") return `<span class="text-slate-400 font-extrabold">-</span>`;
    return `
      <span class="inline-flex items-center px-2.5 py-1 rounded-2xl border border-slate-200 ${
        strong ? "bg-slate-900 text-white border-slate-900" : "bg-slate-50 text-slate-900"
      } text-[12px] font-extrabold">
        ${escapeHtml(v)}
      </span>
    `;
  }

  function renderDetalle(rows) {
    detalleBodyTable && (detalleBodyTable.innerHTML = "");
    detalleBodyCards && (detalleBodyCards.innerHTML = "");

    if (!rows || rows.length === 0) {
      if (detalleBodyTable) {
        detalleBodyTable.innerHTML = `<div class="px-4 py-10 text-sm font-extrabold text-slate-500">No hay cambios en este rango.</div>`;
      }
      if (detalleBodyCards) {
        detalleBodyCards.innerHTML = `<div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-4 text-sm font-extrabold text-slate-500">No hay cambios en este rango.</div>`;
      }
      return;
    }

    if (detalleBodyTable) {
      const frag = document.createDocumentFragment();
      rows.forEach(r => {
        const div = document.createElement("div");
        div.className = "grid grid-cols-12 px-4 py-3 border-b border-slate-100 text-sm font-semibold text-slate-900 hover:bg-slate-50 transition";
        div.innerHTML = `
          <div class="col-span-3 text-slate-700 font-bold">${escapeHtml(r.created_at || "-")}</div>
          <div class="col-span-2 text-slate-700 font-extrabold">${escapeHtml(r.entidad || "-")}</div>
          <div class="col-span-2 font-extrabold">${escapeHtml(r.entidad_id ? String(r.entidad_id) : "-")}</div>
          <div class="col-span-2">${statusBadge(r.estado_anterior ?? "-", false)}</div>
          <div class="col-span-3">${statusBadge(r.estado_nuevo ?? "-", true)}</div>
        `;
        frag.appendChild(div);
      });
      detalleBodyTable.appendChild(frag);
    }

    if (detalleBodyCards) {
      const frag = document.createDocumentFragment();
      rows.forEach(r => {
        const card = document.createElement("div");
        card.className = "rounded-3xl border border-slate-200 bg-white shadow-sm p-4 mb-3";
        card.innerHTML = `
          <div class="text-sm font-extrabold text-slate-900">${escapeHtml(r.entidad || "-")} #${escapeHtml(r.entidad_id ? String(r.entidad_id) : "-")}</div>
          <div class="text-xs font-bold text-slate-500 mt-1">${escapeHtml(r.created_at || "-")}</div>
          <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2">
            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2">
              <div class="text-[11px] font-extrabold text-slate-500 uppercase tracking-wide">Antes</div>
              <div class="mt-1">${statusBadge(r.estado_anterior ?? "-", false)}</div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white px-3 py-2">
              <div class="text-[11px] font-extrabold text-slate-500 uppercase tracking-wide">Después</div>
              <div class="mt-1">${statusBadge(r.estado_nuevo ?? "-", true)}</div>
            </div>
          </div>
        `;
        frag.appendChild(card);
      });
      detalleBodyCards.appendChild(frag);
    }
  }

  async function loadDetalle() {
    modalSetError("");
    modalSetLoading(true);

    try {
      const json = await fetchDetalle(detalleState.userId, detalleState.offset, detalleState.limit);

      detalleState.total = Number(json.total || 0);

      if (detalleTitulo) detalleTitulo.textContent = `Detalle - ${json.user_name || ""}`;
      renderDetalleDescripcion(json);
      renderPedidosTocados(json.pedidos || []);
      renderDetalle(json.data || []);

      const fromN = detalleState.total ? (detalleState.offset + 1) : 0;
      const toN = Math.min(detalleState.offset + detalleState.limit, detalleState.total);
      if (detallePaginacionInfo) {
        detallePaginacionInfo.textContent = detalleState.total
          ? `Mostrando ${fromN}-${toN} de ${detalleState.total}`
          : "Mostrando 0";
      }

      const hasPrev = detalleState.offset > 0;
      const hasNext = (detalleState.offset + detalleState.limit) < detalleState.total;

      detallePrev?.toggleAttribute("disabled", !hasPrev);
      detalleNext?.toggleAttribute("disabled", !hasNext);
      detallePrev?.classList.toggle("opacity-50", !hasPrev);
      detalleNext?.classList.toggle("opacity-50", !hasNext);

    } catch (e) {
      renderDetalle([]);
      renderPedidosTocados([]);
      modalSetError("Error cargando detalle: " + (e?.message || e));
      console.error(e);
    } finally {
      modalSetLoading(false);
    }
  }

  function openModalDetalle(userId) {
    detalleState.userId = Number(userId ?? 0);
    detalleState.offset = 0;
    detalleModal?.classList.remove("hidden");
    loadDetalle();
  }

  // cerrar modal
  detalleCerrar?.addEventListener("click", (e) => {
    e.preventDefault();
    e.stopPropagation();
    closeModalDetalle();
  });

  detalleModal?.addEventListener("click", (e) => {
    const isOverlay = e.target?.getAttribute && e.target.getAttribute("data-close") === "1";
    if (isOverlay) closeModalDetalle();
  });

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && detalleModal && !detalleModal.classList.contains("hidden")) closeModalDetalle();
  });

  detallePrev?.addEventListener("click", () => {
    if (detalleState.offset <= 0) return;
    detalleState.offset = Math.max(0, detalleState.offset - detalleState.limit);
    loadDetalle();
  });

  detalleNext?.addEventListener("click", () => {
    if ((detalleState.offset + detalleState.limit) >= detalleState.total) return;
    detalleState.offset += detalleState.limit;
    loadDetalle();
  });

  // UI principal
  inputBuscar?.addEventListener("input", renderAll);

  btnLimpiarBusqueda?.addEventListener("click", () => {
    if (inputBuscar) inputBuscar.value = "";
    renderAll();
  });

  btnFiltrar?.addEventListener("click", cargar);

  btnLimpiarFechas?.addEventListener("click", () => {
    if (fromEl) { fromEl.value = ""; fromEl.dataset.value = ""; }
    if (toEl) { toEl.value = ""; toEl.dataset.value = ""; }
    cargar();
  });

  btnActualizar?.addEventListener("click", cargar);

  // init
  setupCalendar();
  cargar();
})();
