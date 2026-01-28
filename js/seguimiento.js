(() => {
  const base = window.API_BASE || "";

  const globalLoader = document.getElementById("globalLoader");
  const errorBox = document.getElementById("errorBox");

  const inputBuscar = document.getElementById("inputBuscar");
  const btnLimpiarBusqueda = document.getElementById("btnLimpiarBusqueda");

  const fromEl = document.getElementById("from");
  const toEl = document.getElementById("to");
  const btnFiltrar = document.getElementById("btnFiltrar");
  const btnLimpiarFechas = document.getElementById("btnLimpiarFechas");

  const totalUsuariosEl = document.getElementById("total-usuarios");
  const totalCambiosEl = document.getElementById("total-cambios");

  const tabla2xl = document.getElementById("tablaSeguimiento");
  const tablaXl = document.getElementById("tablaSeguimientoTable");
  const cards = document.getElementById("cardsSeguimiento");

  // Modal detalle
  const detalleModal = document.getElementById("detalleModal");
  const detalleCerrar = document.getElementById("detalleCerrar");
  const detalleTitulo = document.getElementById("detalleTitulo");
  const detalleSub = document.getElementById("detalleSub");
  const detalleLoading = document.getElementById("detalleLoading");
  const detalleError = document.getElementById("detalleError");
  const detalleBodyTable = document.getElementById("detalleBodyTable");
  const detalleBodyCards = document.getElementById("detalleBodyCards");
  const detallePrev = document.getElementById("detallePrev");
  const detalleNext = document.getElementById("detalleNext");
  const detallePaginacionInfo = document.getElementById("detallePaginacionInfo");

  let cacheRows = [];
  let detalleState = { userId: null, userName: "", offset: 0, limit: 50, total: 0 };

  function isoToday() {
    const d = new Date();
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, "0");
    const dd = String(d.getDate()).padStart(2, "0");
    return `${yyyy}-${mm}-${dd}`;
  }

  function ensureTodayDefaults() {
    const t = isoToday();
    if (fromEl && !fromEl.value) fromEl.value = t;
    if (toEl && !toEl.value) toEl.value = t;
  }

  function setLoading(v) {
    if (!globalLoader) return;
    globalLoader.classList.toggle("hidden", !v);
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

  function renderTableRows(rows, target) {
    if (!target) return;
    target.innerHTML = "";

    if (!rows.length) {
      target.innerHTML = `
        <div class="px-4 py-6 text-sm font-bold text-slate-500">
          No hay registros para mostrar.
        </div>
      `;
      return;
    }

    const frag = document.createDocumentFragment();

    rows.forEach((r) => {
      const userId = Number(r.user_id ?? 0);
      const userName = r.user_name || (userId === 0 ? "Sin usuario (no registrado)" : `Usuario #${userId}`);
      const userEmail = r.user_email || "-";
      const total = Number(r.total_cambios || 0);
      const ultimo = fmtDate(r.ultimo_cambio);

      const row = document.createElement("div");
      row.className =
        "seg-grid-cols px-4 py-3 border-b border-slate-100 text-sm font-semibold text-slate-900 " +
        "hover:bg-slate-50 transition";

      row.innerHTML = `
        <div class="min-w-0">
          <div class="font-extrabold truncate">${escapeHtml(userName)}</div>
          <div class="text-[12px] text-slate-500 font-bold">ID: ${escapeHtml(userId)}</div>
        </div>

        <div class="min-w-0 truncate text-slate-700 font-bold">${escapeHtml(userEmail)}</div>

        <div class="text-right">
          <span class="inline-flex items-center px-3 py-1 rounded-2xl bg-slate-900 text-white font-extrabold text-xs">
            ${escapeHtml(total)}
          </span>
        </div>

        <div class="text-slate-700 font-bold">${escapeHtml(ultimo)}</div>

        <div class="text-right">
          <button type="button"
            class="inline-flex items-center justify-center h-9 px-4 rounded-2xl bg-white border border-slate-200 text-slate-900 font-extrabold text-xs shadow-sm hover:bg-slate-100 transition"
            data-user="${escapeHtml(userId)}"
            data-name="${escapeHtml(userName)}">
            Ver
          </button>
        </div>
      `;

      row.querySelector("button[data-user]")?.addEventListener("click", (e) => {
        const uid = e.currentTarget.getAttribute("data-user");
        const uname = e.currentTarget.getAttribute("data-name");
        openModalDetalle(uid, uname);
      });

      frag.appendChild(row);
    });

    target.appendChild(frag);
  }

  function renderCards(rows) {
    if (!cards) return;
    cards.innerHTML = "";

    if (!rows.length) {
      cards.innerHTML = `
        <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-4 text-sm font-bold text-slate-500">
          No hay registros para mostrar.
        </div>
      `;
      return;
    }

    const frag = document.createDocumentFragment();

    rows.forEach((r) => {
      const userId = Number(r.user_id ?? 0);
      const userName = r.user_name || (userId === 0 ? "Sin usuario (no registrado)" : `Usuario #${userId}`);
      const userEmail = r.user_email || "-";
      const total = Number(r.total_cambios || 0);
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

          <div class="shrink-0">
            <span class="inline-flex items-center px-3 py-1 rounded-2xl bg-slate-900 text-white font-extrabold text-xs">
              ${escapeHtml(total)} cambios
            </span>
          </div>
        </div>

        <div class="mt-3 text-sm font-bold text-slate-700">
          Último cambio: <span class="font-extrabold">${escapeHtml(ultimo)}</span>
        </div>

        <div class="mt-3 flex justify-end">
          <button type="button"
            class="h-10 px-4 rounded-2xl bg-white border border-slate-200 text-slate-900 font-extrabold text-sm shadow-sm hover:bg-slate-100 transition"
            data-user="${escapeHtml(userId)}"
            data-name="${escapeHtml(userName)}">
            Ver
          </button>
        </div>
      `;

      card.querySelector("button[data-user]")?.addEventListener("click", (e) => {
        const uid = e.currentTarget.getAttribute("data-user");
        const uname = e.currentTarget.getAttribute("data-name");
        openModalDetalle(uid, uname);
      });

      frag.appendChild(card);
    });

    cards.appendChild(frag);
  }

  async function fetchResumen() {
    ensureTodayDefaults();

    const params = new URLSearchParams();
    if (fromEl?.value) params.set("from", fromEl.value);
    if (toEl?.value) params.set("to", toEl.value);

    const url = `${base}/seguimiento/resumen?${params.toString()}`;

    const res = await fetch(url, { method: "GET", headers: { "Accept": "application/json" } });

    if (!res.ok) {
      let msg = `HTTP ${res.status}`;
      try {
        const j = await res.json();
        if (j?.message) msg += ` - ${j.message}`;
      } catch (_) {}
      throw new Error(msg);
    }

    const json = await res.json();
    if (!json?.ok) throw new Error(json?.message || "Respuesta inválida del servidor.");
    return json.data || [];
  }

  function renderAll() {
    const rows = applySearch(cacheRows);
    updateCounters(rows);
    renderTableRows(rows, tabla2xl);
    renderTableRows(rows, tablaXl);
    renderCards(rows);
  }

  async function cargar() {
    setError("");
    setLoading(true);
    try {
      cacheRows = await fetchResumen();
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

  window.__seguimientoRefresh = cargar;

  // ---------------- MODAL DETALLE ----------------
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
    if (detalleBodyTable) detalleBodyTable.innerHTML = "";
    if (detalleBodyCards) detalleBodyCards.innerHTML = "";
    modalSetError("");
  }

  function openModalDetalle(userId, userName) {
    const uid = Number(userId ?? 0);
    detalleState.userId = uid;
    detalleState.userName = (userName && userName !== "null")
      ? userName
      : (uid === 0 ? "Sin usuario (no registrado)" : `Usuario #${uid}`);

    detalleState.offset = 0;

    if (detalleTitulo) detalleTitulo.textContent = `Detalle - ${detalleState.userName}`;
    if (detalleSub) detalleSub.textContent = `Usuario ID: ${detalleState.userId}`;

    detalleModal?.classList.remove("hidden");
    loadDetalle();
  }

  async function fetchDetalle(userId, offset, limit) {
    ensureTodayDefaults();

    const params = new URLSearchParams();
    if (fromEl?.value) params.set("from", fromEl.value);
    if (toEl?.value) params.set("to", toEl.value);
    params.set("offset", String(offset));
    params.set("limit", String(limit));

    const url = `${base}/seguimiento/detalle/${encodeURIComponent(userId)}?${params.toString()}`;

    const res = await fetch(url, { method: "GET", headers: { "Accept": "application/json" } });

    if (!res.ok) {
      let msg = `HTTP ${res.status}`;
      try {
        const j = await res.json();
        if (j?.message) msg += ` - ${j.message}`;
      } catch (_) {}
      throw new Error(msg);
    }

    const json = await res.json();
    if (!json?.ok) throw new Error(json?.message || "Respuesta inválida del servidor.");
    return json;
  }

  function renderDetalle(rows) {
    if (detalleBodyTable) detalleBodyTable.innerHTML = "";
    if (detalleBodyCards) detalleBodyCards.innerHTML = "";

    if (!rows || rows.length === 0) {
      if (detalleBodyTable) {
        detalleBodyTable.innerHTML = `
          <div class="px-4 py-6 text-sm font-extrabold text-slate-500">
            No hay cambios para mostrar.
          </div>
        `;
      }
      if (detalleBodyCards) {
        detalleBodyCards.innerHTML = `
          <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-4 text-sm font-extrabold text-slate-500">
            No hay cambios para mostrar.
          </div>
        `;
      }
      return;
    }

    // Desktop table
    if (detalleBodyTable) {
      const frag = document.createDocumentFragment();
      rows.forEach(r => {
        const div = document.createElement("div");
        div.className = "grid grid-cols-12 px-4 py-3 border-b border-slate-100 text-sm font-semibold text-slate-900 hover:bg-slate-50 transition";
        div.innerHTML = `
          <div class="col-span-3 text-slate-700 font-bold">${escapeHtml(r.created_at || "-")}</div>
          <div class="col-span-2">${escapeHtml(r.entidad || "-")}</div>
          <div class="col-span-2">${r.entidad_id ? escapeHtml(String(r.entidad_id)) : "-"}</div>
          <div class="col-span-2 text-slate-700">${escapeHtml(r.estado_anterior ?? "-")}</div>
          <div class="col-span-2 text-slate-900 font-extrabold">${escapeHtml(r.estado_nuevo ?? "-")}</div>
          <div class="col-span-1 text-right text-[11px] font-extrabold text-slate-500">${escapeHtml(r.source || "")}</div>
        `;
        frag.appendChild(div);
      });
      detalleBodyTable.appendChild(frag);
    }

    // Mobile cards
    if (detalleBodyCards) {
      const frag = document.createDocumentFragment();
      rows.forEach(r => {
        const card = document.createElement("div");
        card.className = "rounded-3xl border border-slate-200 bg-white shadow-sm p-4 mb-3";
        card.innerHTML = `
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="text-sm font-extrabold text-slate-900 truncate">
                ${escapeHtml(r.entidad || "-")} ${r.entidad_id ? "#" + escapeHtml(String(r.entidad_id)) : ""}
              </div>
              <div class="text-xs font-bold text-slate-600 mt-0.5">
                ${escapeHtml(r.created_at || "-")}
              </div>
            </div>
            <div class="shrink-0 text-[11px] font-extrabold text-slate-500">
              ${escapeHtml(r.source || "")}
            </div>
          </div>

          <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2">
            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2">
              <div class="text-[11px] font-extrabold text-slate-500 uppercase tracking-wide">Antes</div>
              <div class="text-sm font-bold text-slate-900 mt-0.5">${escapeHtml(r.estado_anterior ?? "-")}</div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white px-3 py-2">
              <div class="text-[11px] font-extrabold text-slate-500 uppercase tracking-wide">Después</div>
              <div class="text-sm font-extrabold text-slate-900 mt-0.5">${escapeHtml(r.estado_nuevo ?? "-")}</div>
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
      modalSetError("Error cargando detalle: " + (e?.message || e));
      console.error(e);
    } finally {
      modalSetLoading(false);
    }
  }

  // Cerrar modal: botón, overlay, ESC
  detalleCerrar?.addEventListener("click", (e) => {
    e.preventDefault();
    e.stopPropagation();
    closeModalDetalle();
  });

  detalleModal?.addEventListener("click", (e) => {
    const isOverlay = e.target && e.target.getAttribute && e.target.getAttribute("data-close") === "1";
    if (isOverlay) closeModalDetalle();
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

  // ✅ Quitar filtros => vuelve a HOY
  btnLimpiarFechas?.addEventListener("click", () => {
    const t = isoToday();
    if (fromEl) fromEl.value = t;
    if (toEl) toEl.value = t;
    cargar();
  });

  // Carga inicial (HOY)
  ensureTodayDefaults();
  cargar();
})();
