(() => {
  const base = window.API_BASE || "";

  const globalLoader = document.getElementById("globalLoader");
  const errorBox = document.getElementById("errorBox");

  const inputBuscar = document.getElementById("inputBuscar");
  const btnLimpiarBusqueda = document.getElementById("btnLimpiarBusqueda");

  // ✅ rango
  const rangeEl = document.getElementById("range");
  const btnFiltrar = document.getElementById("btnFiltrar");
  const btnLimpiarFechas = document.getElementById("btnLimpiarFechas");
  const btnActualizar = document.getElementById("btnActualizar");

  const totalUsuariosEl = document.getElementById("total-usuarios");
  const totalCambiosEl = document.getElementById("total-cambios");
  const totalPedidosTocadosEl = document.getElementById("total-pedidos-tocados");

  const tabla = document.getElementById("tablaSeguimiento");
  const cards = document.getElementById("cardsSeguimiento");

  // Modal
  const detalleModal = document.getElementById("detalleModal");
  const detalleCerrar = document.getElementById("detalleCerrar");
  const detalleTitulo = document.getElementById("detalleTitulo");
  const detalleDescripcion = document.getElementById("detalleDescripcion");
  const detallePedidosBox = document.getElementById("detallePedidosBox");
  const detalleLoading = document.getElementById("detalleLoading");
  const detalleError = document.getElementById("detalleError");
  const detalleBodyTable = document.getElementById("detalleBodyTable");
  const detalleBodyCards = document.getElementById("detalleBodyCards");
  const detallePrev = document.getElementById("detallePrev");
  const detalleNext = document.getElementById("detalleNext");
  const detallePaginacionInfo = document.getElementById("detallePaginacionInfo");
  const detallePedidosCount = document.getElementById("detallePedidosCount");

  let cacheRows = [];
  let lastStats = { pedidos_tocados: 0 };
  let rangePicker = null;

  let detalleState = { userId: null, offset: 0, limit: 50, total: 0 };

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

  // --------- Calendario moderno (Flatpickr range) ----------
  if (window.flatpickr && rangeEl) {
    flatpickr.localize(flatpickr.l10ns.es);

    rangePicker = flatpickr(rangeEl, {
      mode: "range",
      dateFormat: "d/m/Y",
      allowInput: true,
      disableMobile: true,
      rangeSeparator: " a ",
      animate: true
    });
  }

  function toISODate(dmy) {
    const s = String(dmy || "").trim();
    if (!s) return "";
    if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;

    const m = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if (!m) return "";
    const dd = String(m[1]).padStart(2, "0");
    const mm = String(m[2]).padStart(2, "0");
    const yy = m[3];
    return `${yy}-${mm}-${dd}`;
  }

  function getRangeISO() {
    const raw = String(rangeEl?.value || "").trim();
    if (!raw) return { from: "", to: "", label: "" };

    const parts = raw.split(" a ").map(s => s.trim()).filter(Boolean);
    const fromISO = toISODate(parts[0] || "");
    const toISO = toISODate(parts[1] || "");

    if (!fromISO && raw) throw new Error("Rango inválido. Usa el calendario o formato dd/mm/aaaa");
    if (parts.length >= 2 && !toISO) throw new Error("Rango inválido. Selecciona fecha final.");

    // si solo seleccionó 1 fecha, filtramos ese día completo (from=to)
    const finalTo = toISO || fromISO;

    if (fromISO && finalTo && fromISO > finalTo) throw new Error("Rango inválido: desde > hasta");

    return {
      from: fromISO,
      to: finalTo,
      label: raw
    };
  }

  function sortRows(rows) {
    return [...rows].sort((a, b) => {
      const au = Number(a.user_id ?? 0);
      const bu = Number(b.user_id ?? 0);
      // ✅ primero users normales, luego user_id=0
      if ((au === 0) !== (bu === 0)) return (au === 0) ? 1 : -1;

      const at = Number(a.total_cambios || 0);
      const bt = Number(b.total_cambios || 0);
      if (bt !== at) return bt - at;

      return String(a.user_name || "").toLowerCase().localeCompare(String(b.user_name || "").toLowerCase());
    });
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

  function updateCounters() {
    const totalUsuarios = cacheRows.length;
    const totalCambios = cacheRows.reduce((acc, r) => acc + Number(r.total_cambios || 0), 0);

    totalUsuariosEl && (totalUsuariosEl.textContent = String(totalUsuarios));
    totalCambiosEl && (totalCambiosEl.textContent = String(totalCambios));
    totalPedidosTocadosEl && (totalPedidosTocadosEl.textContent = String(lastStats?.pedidos_tocados || 0));
  }

  function renderTableRows(rows) {
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
      const pedidos = Number(r.pedidos_tocados || 0);
      const total = Number(r.total_cambios || 0);
      const ultimo = r.ultimo_cambio ? String(r.ultimo_cambio) : "-";

      const row = document.createElement("div");
      row.className = "seg-grid-cols px-4 py-3 border-b border-slate-100 text-sm font-semibold text-slate-900 hover:bg-slate-50 transition";

      row.innerHTML = `
        <div class="min-w-0">
          <div class="font-extrabold truncate">${escapeHtml(userName)}</div>
          <div class="text-[12px] text-slate-500 font-bold">ID: ${escapeHtml(userId)}</div>
        </div>

        <div class="min-w-0 truncate text-slate-700 font-bold">${escapeHtml(userEmail)}</div>

        <div class="text-right">
          <span class="inline-flex items-center px-3 py-1 rounded-2xl bg-white border border-slate-200 text-slate-900 font-extrabold text-xs">
            ${escapeHtml(pedidos)}
          </span>
        </div>

        <div class="text-right">
          <span class="inline-flex items-center px-3 py-1 rounded-2xl bg-slate-900 text-white font-extrabold text-xs">
            ${escapeHtml(total)}
          </span>
        </div>

        <div class="text-slate-700 font-bold">${escapeHtml(ultimo)}</div>

        <div class="text-right">
          <button type="button"
            class="inline-flex items-center justify-center h-9 px-4 rounded-2xl bg-white border border-slate-200 text-slate-900 font-extrabold text-xs shadow-sm hover:bg-slate-100 transition"
            data-user="${escapeHtml(userId)}">
            Ver
          </button>
        </div>
      `;

      row.querySelector("button[data-user]")?.addEventListener("click", () => {
        openModalDetalle(userId);
      });

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
      const pedidos = Number(r.pedidos_tocados || 0);
      const total = Number(r.total_cambios || 0);
      const ultimo = r.ultimo_cambio ? String(r.ultimo_cambio) : "-";

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
            <span class="inline-flex items-center px-3 py-1 rounded-2xl bg-slate-900 text-white font-extrabold text-xs">
              ${escapeHtml(total)} cambios
            </span>
            <span class="inline-flex items-center px-3 py-1 rounded-2xl bg-white border border-slate-200 text-slate-900 font-extrabold text-xs">
              ${escapeHtml(pedidos)} pedidos
            </span>
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

      card.querySelector("button[data-user]")?.addEventListener("click", () => {
        openModalDetalle(userId);
      });

      frag.appendChild(card);
    });

    cards.appendChild(frag);
  }

  function renderAll() {
    updateCounters();
    const rows = applySearch(sortRows(cacheRows));
    renderTableRows(rows);
    renderCards(rows);
  }

  async function fetchResumen() {
    const params = new URLSearchParams();

    const r = getRangeISO();
    if (r.from) params.set("from", r.from);
    if (r.to) params.set("to", r.to);

    const url = `${base}/seguimiento/resumen?${params.toString()}`;
    const res = await fetch(url, { headers: { "Accept": "application/json" } });
    const json = await res.json().catch(() => null);

    if (!res.ok) throw new Error(json?.message || `HTTP ${res.status}`);
    if (!json?.ok) throw new Error(json?.message || "Respuesta inválida");

    return json;
  }

  async function cargar() {
    setError("");
    setLoading(true);
    try {
      const json = await fetchResumen();
      cacheRows = Array.isArray(json.data) ? json.data : [];
      lastStats = json.stats || { pedidos_tocados: 0 };
      renderAll();
    } catch (e) {
      cacheRows = [];
      lastStats = { pedidos_tocados: 0 };
      renderAll();
      setError("Error cargando seguimiento: " + (e?.message || e));
      console.error(e);
    } finally {
      setLoading(false);
    }
  }

  // ---------------- MODAL ----------------
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

  function pill(label, value, strong = false) {
    return `
      <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-2xl border border-slate-200 bg-white shadow-sm">
        <span class="text-[11px] font-extrabold text-slate-500 uppercase">${escapeHtml(label)}</span>
        <span class="${strong ? 'text-sm font-extrabold text-slate-900' : 'text-sm font-bold text-slate-700'}">
          ${escapeHtml(value)}
        </span>
      </div>
    `;
  }

  function closeModalDetalle() {
    detalleModal?.classList.add("hidden");
    detalleBodyTable && (detalleBodyTable.innerHTML = "");
    detalleBodyCards && (detalleBodyCards.innerHTML = "");
    detallePedidosBox && (detallePedidosBox.innerHTML = "");
    detalleDescripcion && (detalleDescripcion.innerHTML = "");
    modalSetError("");
  }

  function openModalDetalle(userId) {
    detalleState.userId = Number(userId ?? 0);
    detalleState.offset = 0;

    detalleTitulo && (detalleTitulo.textContent = "Detalle");
    detalleDescripcion && (detalleDescripcion.innerHTML = "");
    detallePedidosBox && (detallePedidosBox.innerHTML = "");

    detalleModal?.classList.remove("hidden");
    loadDetalle();
  }

  async function fetchDetalle(userId, offset, limit) {
    const params = new URLSearchParams();

    const r = getRangeISO();
    if (r.from) params.set("from", r.from);
    if (r.to) params.set("to", r.to);

    params.set("offset", String(offset));
    params.set("limit", String(limit));

    const url = `${base}/seguimiento/detalle/${encodeURIComponent(userId)}?${params.toString()}`;
    const res = await fetch(url, { headers: { "Accept": "application/json" } });
    const json = await res.json().catch(() => null);

    if (!res.ok) throw new Error(json?.message || `HTTP ${res.status}`);
    if (!json?.ok) throw new Error(json?.message || "Respuesta inválida");

    return json;
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

    const chip = document.createElement("button");
    chip.type = "button";
    chip.className =
      "shrink-0 text-left rounded-2xl border border-slate-200 bg-white px-3 py-2 shadow-sm hover:bg-slate-50 transition";

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

    // si quieres que al click filtre por ese pedido dentro del detalle, aquí puedes hacerlo
    chip.addEventListener("click", () => {
      // pequeño highlight visual
      chip.classList.add("ring-4", "ring-slate-200");
      setTimeout(() => chip.classList.remove("ring-4", "ring-slate-200"), 300);
    });

    frag.appendChild(chip);
  });

  detallePedidosBox.appendChild(frag);
}


  function statusBadge(text, strong = false) {
  const v = String(text ?? "-").trim();
  if (!v || v === "-") {
    return `<span class="text-slate-400 font-extrabold">-</span>`;
  }
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
      detalleBodyTable.innerHTML = `
        <div class="px-4 py-10 text-sm font-extrabold text-slate-500">
          No hay cambios en este rango.
        </div>
      `;
    }
    if (detalleBodyCards) {
      detalleBodyCards.innerHTML = `
        <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-4 text-sm font-extrabold text-slate-500">
          No hay cambios en este rango.
        </div>
      `;
    }
    return;
  }

  // Desktop table
  if (detalleBodyTable) {
    const frag = document.createDocumentFragment();

    rows.forEach(r => {
      const entidad = r.entidad || "-";
      const id = r.entidad_id ? String(r.entidad_id) : "-";
      const fecha = r.created_at || "-";
      const antes = r.estado_anterior ?? "-";
      const despues = r.estado_nuevo ?? "-";

      const div = document.createElement("div");
      div.className =
        "grid grid-cols-12 px-4 py-3 border-b border-slate-100 text-sm font-semibold text-slate-900 hover:bg-slate-50 transition";

      div.innerHTML = `
        <div class="col-span-3 text-slate-700 font-bold">${escapeHtml(fecha)}</div>
        <div class="col-span-2 text-slate-700 font-extrabold">${escapeHtml(entidad)}</div>
        <div class="col-span-2 font-extrabold">${escapeHtml(id)}</div>
        <div class="col-span-2">${statusBadge(antes, false)}</div>
        <div class="col-span-3">${statusBadge(despues, true)}</div>
      `;

      frag.appendChild(div);
    });

    detalleBodyTable.appendChild(frag);
  }

  // Mobile cards
  if (detalleBodyCards) {
    const frag = document.createDocumentFragment();

    rows.forEach(r => {
      const entidad = r.entidad || "-";
      const id = r.entidad_id ? String(r.entidad_id) : "-";
      const fecha = r.created_at || "-";
      const antes = r.estado_anterior ?? "-";
      const despues = r.estado_nuevo ?? "-";

      const card = document.createElement("div");
      card.className = "rounded-3xl border border-slate-200 bg-white shadow-sm p-4 mb-3";

      card.innerHTML = `
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="text-sm font-extrabold text-slate-900 truncate">
              ${escapeHtml(entidad)} #${escapeHtml(id)}
            </div>
            <div class="text-xs font-bold text-slate-500 mt-1">${escapeHtml(fecha)}</div>
          </div>
        </div>

        <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2">
          <div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2">
            <div class="text-[11px] font-extrabold text-slate-500 uppercase tracking-wide">Antes</div>
            <div class="mt-1">${statusBadge(antes, false)}</div>
          </div>
          <div class="rounded-2xl border border-slate-200 bg-white px-3 py-2">
            <div class="text-[11px] font-extrabold text-slate-500 uppercase tracking-wide">Después</div>
            <div class="mt-1">${statusBadge(despues, true)}</div>
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

      const r = getRangeISO();
      const rangeLabel = r.label ? r.label : "Histórico";

      // ✅ título + descripción acomodada
      if (detalleTitulo) detalleTitulo.textContent = `Detalle - ${json.user_name || "Usuario"}`;

      if (detalleDescripcion) {
        detalleDescripcion.innerHTML = [
          pill("Usuario", json.user_name || "-", true),
          pill("Email", json.user_email || "-"),
          pill("ID", String(json.user_id ?? detalleState.userId)),
          pill("Cambios", String(json.total ?? 0), true),
          pill("Pedidos tocados", String(json.pedidos_tocados ?? 0), true),
          pill("Rango", rangeLabel)
        ].join("");
      }

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
      renderPedidosTocados([]);
      renderDetalle([]);
      modalSetError("Error cargando detalle: " + (e?.message || e));
      console.error(e);
    } finally {
      modalSetLoading(false);
    }
  }

  // cerrar modal
  detalleCerrar?.addEventListener("click", (e) => {
    e.preventDefault();
    e.stopPropagation();
    closeModalDetalle();
  });

  detalleModal?.addEventListener("click", (e) => {
    const t = e.target;
    if (t && t.getAttribute && t.getAttribute("data-close") === "1") closeModalDetalle();
  });

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && detalleModal && !detalleModal.classList.contains("hidden")) {
      closeModalDetalle();
    }
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
    rangePicker?.clear();
    if (rangeEl) rangeEl.value = "";
    cargar();
  });

  btnActualizar?.addEventListener("click", cargar);

  // init
  cargar();
})();
