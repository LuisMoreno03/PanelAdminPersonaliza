(() => {
  document.addEventListener("DOMContentLoaded", () => {
    const base = window.API_BASE || "";

    // ------------------ Helpers UI ------------------
    const globalLoader = document.getElementById("globalLoader");
    const errorBox = document.getElementById("errorBox");

    const inputBuscar = document.getElementById("inputBuscar");
    const btnLimpiarBusqueda = document.getElementById("btnLimpiarBusqueda");

    const fromEl = document.getElementById("from"); // input Desde
    const toEl = document.getElementById("to");     // input Hasta
    const btnFiltrar = document.getElementById("btnFiltrar");
    const btnLimpiarFechas = document.getElementById("btnLimpiarFechas"); // Quitar filtros
    const btnActualizar = document.getElementById("btnActualizar"); // si existe

    const totalUsuariosEl = document.getElementById("total-usuarios");
    const totalCambiosEl = document.getElementById("total-cambios");
    const totalPedidosModificadosEl =
      document.getElementById("total-pedidos-modificados") ||
      document.getElementById("total-pedidos"); // opcional si lo tienes

    const tabla2xl = document.getElementById("tablaSeguimiento");       // contenedor desktop
    const tablaXl = document.getElementById("tablaSeguimientoTable");   // contenedor alternate
    const cards = document.getElementById("cardsSeguimiento");          // mobile cards

    // ------------------ Modal ------------------
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

    // ✅ nuevos (si existen en tu HTML)
    const detalleChipsWrap = document.getElementById("detallePedidosChips");
    const detallePedidosCount = document.getElementById("detallePedidosCount");

    const detalleKpiCambios = document.getElementById("detalleKpiCambios");
    const detalleKpiPedidos = document.getElementById("detalleKpiPedidos");
    const detalleKpiConfirmados = document.getElementById("detalleKpiConfirmados");
    const detalleKpiDisenos = document.getElementById("detalleKpiDisenos");
    const detalleKpiRango = document.getElementById("detalleKpiRango");

    const detalleUserPill = document.getElementById("detalleUserPill");
    const detalleEmailPill = document.getElementById("detalleEmailPill");
    const detalleIdPill = document.getElementById("detalleIdPill");

    // ------------------ State ------------------
    let cacheRows = [];
    let cacheStats = { pedidos_modificados: 0 };
    let cacheRange = { from: null, to: null };

    let detalleState = {
      userId: null,
      userName: "",
      userEmail: "-",
      offset: 0,
      limit: 50,
      total: 0,
      pedidos: [],
      kpis: { confirmados: 0, disenos: 0 },
    };

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

    function normalizeStr(s) {
      return String(s ?? "").trim().toLowerCase();
    }

    // ------------------ Calendario moderno (Flatpickr si existe) ------------------
    function initDatePickers() {
      if (!fromEl || !toEl) return;

      // Si existe flatpickr en window, lo usamos
      if (window.flatpickr) {
        const cfg = {
          dateFormat: "Y-m-d",      // enviamos al backend en ISO
          allowInput: true,
        };

        try {
          window.flatpickr(fromEl, cfg);
          window.flatpickr(toEl, cfg);
        } catch (e) {
          console.warn("Flatpickr no pudo inicializar:", e);
        }
      } else {
        // fallback: si quieres, ponlos type="date" en HTML
        // aquí no forzamos nada para no romper tu UI
      }
    }

    // ------------------ Fetchers ------------------
    function buildResumenURL() {
      const params = new URLSearchParams();
      if (fromEl?.value) params.set("from", fromEl.value);
      if (toEl?.value) params.set("to", toEl.value);
      return `${base}/seguimiento/resumen?${params.toString()}`;
    }

    async function fetchResumen() {
      const url = buildResumenURL();
      const res = await fetch(url, { method: "GET", headers: { Accept: "application/json" } });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const json = await res.json();
      if (!json?.ok) throw new Error(json?.message || "Respuesta inválida");
      return json;
    }

    function buildDetalleURL(userId, offset, limit) {
      const params = new URLSearchParams();
      if (fromEl?.value) params.set("from", fromEl.value);
      if (toEl?.value) params.set("to", toEl.value);
      params.set("offset", String(offset));
      params.set("limit", String(limit));
      return `${base}/seguimiento/detalle/${encodeURIComponent(userId)}?${params.toString()}`;
    }

    async function fetchDetalle(userId, offset, limit) {
      const url = buildDetalleURL(userId, offset, limit);
      const res = await fetch(url, { method: "GET", headers: { Accept: "application/json" } });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const json = await res.json();
      if (!json?.ok) throw new Error(json?.message || "Respuesta inválida");
      return json;
    }

    // ------------------ Data transforms ------------------
    function applySearch(rows) {
      const q = normalizeStr(inputBuscar?.value);
      if (!q) return rows;

      return rows.filter((r) => {
        const name = normalizeStr(r.user_name);
        const email = normalizeStr(r.user_email);
        const id = String(r.user_id ?? "");
        return name.includes(q) || email.includes(q) || id.includes(q);
      });
    }

    function sortRows(rows) {
      // orden consistente: sin usuario arriba, luego total_cambios desc, pedidos_tocados desc, nombre asc
      return [...rows].sort((a, b) => {
        const a0 = Number(a.user_id ?? 0) === 0 ? 1 : 0;
        const b0 = Number(b.user_id ?? 0) === 0 ? 1 : 0;
        if (a0 !== b0) return b0 - a0;

        const at = Number(a.total_cambios ?? 0);
        const bt = Number(b.total_cambios ?? 0);
        if (at !== bt) return bt - at;

        const ap = Number(a.pedidos_tocados ?? 0);
        const bp = Number(b.pedidos_tocados ?? 0);
        if (ap !== bp) return bp - ap;

        return String(a.user_name ?? "").localeCompare(String(b.user_name ?? ""), "es");
      });
    }

    function updateCounters(rows) {
      const totalUsuarios = rows.length;
      const totalCambios = rows.reduce((acc, r) => acc + Number(r.total_cambios || 0), 0);

      if (totalUsuariosEl) totalUsuariosEl.textContent = String(totalUsuarios);
      if (totalCambiosEl) totalCambiosEl.textContent = String(totalCambios);

      if (totalPedidosModificadosEl) {
        totalPedidosModificadosEl.textContent = String(cacheStats?.pedidos_modificados ?? 0);
      }
    }

    // ------------------ Render resumen (lista) ------------------
    function renderEmpty(target) {
      if (!target) return;
      target.innerHTML = `
        <div class="px-4 py-6 text-sm font-bold text-slate-500">
          No hay registros para mostrar.
        </div>
      `;
    }

    function renderTableRows(rows, target) {
      if (!target) return;
      target.innerHTML = "";

      if (!rows.length) {
        renderEmpty(target);
        return;
      }

      const frag = document.createDocumentFragment();

      rows.forEach((r) => {
        const userId = Number(r.user_id ?? 0);
        const userName = r.user_name || (userId === 0 ? "Sin usuario (no registrado)" : `Usuario #${userId}`);
        const userEmail = r.user_email || "-";

        const pedidosTocados = Number(r.pedidos_tocados ?? 0);
        const total = Number(r.total_cambios ?? 0);
        const ultimo = fmtDate(r.ultimo_cambio);

        const confirmados = Number(r.confirmados ?? 0);
        const disenos = Number(r.disenos ?? 0);

        const row = document.createElement("div");
        row.className =
          "seg-grid-cols px-4 py-3 border-b border-slate-100 text-sm font-semibold text-slate-900 hover:bg-slate-50 transition";

        row.innerHTML = `
          <div class="min-w-0">
            <div class="font-extrabold truncate">${escapeHtml(userName)}</div>
            <div class="text-[12px] text-slate-500 font-bold">ID: ${escapeHtml(userId)}</div>
          </div>

          <div class="min-w-0 truncate text-slate-700 font-bold">${escapeHtml(userEmail)}</div>

          <div class="text-center">
            <span class="inline-flex items-center px-3 py-1 rounded-2xl bg-slate-100 text-slate-900 font-extrabold text-xs">
              ${escapeHtml(pedidosTocados)}
            </span>
          </div>

          <div class="text-right">
            <div class="inline-flex items-center gap-2">
              <span class="inline-flex items-center px-3 py-1 rounded-2xl bg-slate-900 text-white font-extrabold text-xs">
                ${escapeHtml(total)}
              </span>
              <span class="hidden xl:inline text-[12px] font-bold text-slate-600">
                ${escapeHtml(ultimo)}
              </span>
            </div>
            <div class="xl:hidden text-[12px] font-bold text-slate-600 mt-1">${escapeHtml(ultimo)}</div>

            <div class="mt-2 flex gap-2 justify-end text-[11px] font-extrabold text-slate-600">
              <span class="px-2 py-1 rounded-xl border border-slate-200 bg-white">Confirmados: ${escapeHtml(confirmados)}</span>
              <span class="px-2 py-1 rounded-xl border border-slate-200 bg-white">Diseños: ${escapeHtml(disenos)}</span>
            </div>
          </div>

          <div class="text-right">
            <button type="button"
              class="inline-flex items-center justify-center h-9 px-4 rounded-2xl bg-white border border-slate-200 text-slate-900 font-extrabold text-xs shadow-sm hover:bg-slate-100 transition"
              data-user="${escapeHtml(userId)}">
              Ver
            </button>
          </div>
        `;

        row.querySelector("button[data-user]")?.addEventListener("click", () => {
          openModalDetalle(userId, userName, userEmail);
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

        const pedidosTocados = Number(r.pedidos_tocados ?? 0);
        const total = Number(r.total_cambios ?? 0);
        const ultimo = fmtDate(r.ultimo_cambio);

        const confirmados = Number(r.confirmados ?? 0);
        const disenos = Number(r.disenos ?? 0);

        const card = document.createElement("div");
        card.className = "rounded-3xl border border-slate-200 bg-white shadow-sm p-4 mb-3";

        card.innerHTML = `
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="text-base font-extrabold truncate">${escapeHtml(userName)}</div>
              <div class="text-sm font-bold text-slate-600 truncate">${escapeHtml(userEmail)}</div>
              <div class="text-xs font-bold text-slate-500 mt-1">ID: ${escapeHtml(userId)}</div>
            </div>

            <div class="shrink-0 text-right">
              <div class="inline-flex items-center px-3 py-1 rounded-2xl bg-slate-900 text-white font-extrabold text-xs">
                ${escapeHtml(total)} cambios
              </div>
              <div class="mt-2 inline-flex items-center px-3 py-1 rounded-2xl bg-slate-100 text-slate-900 font-extrabold text-xs">
                ${escapeHtml(pedidosTocados)} pedidos
              </div>
            </div>
          </div>

          <div class="mt-3 text-sm font-bold text-slate-700">
            Último cambio: <span class="font-extrabold">${escapeHtml(ultimo)}</span>
          </div>

          <div class="mt-2 flex gap-2 text-[12px] font-extrabold text-slate-700">
            <span class="px-2 py-1 rounded-xl border border-slate-200 bg-white">Confirmados: ${escapeHtml(confirmados)}</span>
            <span class="px-2 py-1 rounded-xl border border-slate-200 bg-white">Diseños: ${escapeHtml(disenos)}</span>
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
          openModalDetalle(userId, userName, userEmail);
        });

        frag.appendChild(card);
      });

      cards.appendChild(frag);
    }

    function renderAll() {
      const rows = sortRows(applySearch(cacheRows));
      updateCounters(rows);
      renderTableRows(rows, tabla2xl);
      renderTableRows(rows, tablaXl);
      renderCards(rows);
    }

    async function cargar() {
      setError("");
      setLoading(true);

      try {
        const json = await fetchResumen();
        cacheRows = Array.isArray(json.data) ? json.data : [];
        cacheStats = json.stats || { pedidos_modificados: 0 };
        cacheRange = json.range || { from: null, to: null };
        renderAll();
      } catch (e) {
        cacheRows = [];
        cacheStats = { pedidos_modificados: 0 };
        renderAll();
        setError("Error cargando seguimiento: " + (e?.message || e));
        console.error(e);
      } finally {
        setLoading(false);
      }
    }

    // ------------------ Modal detalle ------------------
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
      modalSetError("");

      if (detalleBodyTable) detalleBodyTable.innerHTML = "";
      if (detalleBodyCards) detalleBodyCards.innerHTML = "";
      if (detalleChipsWrap) detalleChipsWrap.innerHTML = "";
    }

    function setDetalleHeader() {
      if (detalleTitulo) detalleTitulo.textContent = `Detalle - ${detalleState.userName || "-"}`;
      if (detalleSub) detalleSub.textContent = `Usuario ID: ${detalleState.userId}`;

      if (detalleUserPill) detalleUserPill.textContent = detalleState.userName || "-";
      if (detalleEmailPill) detalleEmailPill.textContent = detalleState.userEmail || "-";
      if (detalleIdPill) detalleIdPill.textContent = `ID: ${detalleState.userId}`;

      // KPIs arriba del modal (si existen)
      if (detalleKpiCambios) detalleKpiCambios.textContent = String(detalleState.total || 0);
      if (detalleKpiPedidos) detalleKpiPedidos.textContent = String(detalleState.pedidos?.length || 0);
      if (detalleKpiConfirmados) detalleKpiConfirmados.textContent = String(detalleState.kpis?.confirmados || 0);
      if (detalleKpiDisenos) detalleKpiDisenos.textContent = String(detalleState.kpis?.disenos || 0);

      if (detalleKpiRango) {
        const f = fromEl?.value || "";
        const t = toEl?.value || "";
        detalleKpiRango.textContent = (f || t) ? `${f || "…"} a ${t || "…"}`
                                              : "Histórico";
      }
    }

    function openModalDetalle(userId, userName, userEmail) {
      const uid = Number(userId ?? 0);
      detalleState.userId = uid;
      detalleState.userName = userName || (uid === 0 ? "Sin usuario (no registrado)" : `Usuario #${uid}`);
      detalleState.userEmail = userEmail || "-";
      detalleState.offset = 0;
      detalleState.total = 0;
      detalleState.pedidos = [];
      detalleState.kpis = { confirmados: 0, disenos: 0 };

      setDetalleHeader();

      detalleModal?.classList.remove("hidden");
      loadDetalle();
    }

    function renderPedidosChips(pedidos) {
      if (!detalleChipsWrap) return;
      detalleChipsWrap.innerHTML = "";

      const list = Array.isArray(pedidos) ? pedidos : [];

      if (detallePedidosCount) {
        detallePedidosCount.textContent = String(list.length);
      }

      if (!list.length) {
        detalleChipsWrap.innerHTML = `
          <div class="text-sm font-bold text-slate-500 py-2">
            No hay pedidos tocados en este rango.
          </div>
        `;
        return;
      }

      const frag = document.createDocumentFragment();

      list.forEach((p) => {
        const entidad = String(p.entidad || "pedido");
        const id = p.entidad_id ? String(p.entidad_id) : "-";
        const cambios = Number(p.cambios || 0);
        const ultimo = fmtDate(p.ultimo);

        const chip = document.createElement("div");
        chip.className = "shrink-0 rounded-2xl border border-slate-200 bg-white shadow-sm px-3 py-2 min-w-[220px]";
        chip.innerHTML = `
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <div class="text-sm font-extrabold text-slate-900 truncate">
                ${escapeHtml(entidad)} #${escapeHtml(id)}
              </div>
              <div class="text-[11px] font-bold text-slate-500 mt-1">
                Último: ${escapeHtml(ultimo)}
              </div>
            </div>
            <div class="shrink-0">
              <span class="inline-flex items-center px-2 py-1 rounded-xl bg-slate-900 text-white text-[11px] font-extrabold">
                ${escapeHtml(cambios)} cambios
              </span>
            </div>
          </div>
        `;
        frag.appendChild(chip);
      });

      detalleChipsWrap.appendChild(frag);
    }

    function renderDetalle(rows) {
      if (detalleBodyTable) detalleBodyTable.innerHTML = "";
      if (detalleBodyCards) detalleBodyCards.innerHTML = "";

      const list = Array.isArray(rows) ? rows : [];

      if (!list.length) {
        if (detalleBodyTable) {
          detalleBodyTable.innerHTML = `<div class="px-4 py-6 text-sm font-extrabold text-slate-500">No hay cambios.</div>`;
        }
        if (detalleBodyCards) {
          detalleBodyCards.innerHTML = `<div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-4 text-sm font-extrabold text-slate-500">No hay cambios.</div>`;
        }
        return;
      }

      // Table (desktop)
      if (detalleBodyTable) {
        const frag = document.createDocumentFragment();

        list.forEach((r) => {
          const div = document.createElement("div");
          div.className =
            "grid grid-cols-12 px-4 py-3 border-b border-slate-100 text-sm font-semibold text-slate-900 hover:bg-slate-50 transition";

          div.innerHTML = `
            <div class="col-span-3 text-slate-700 font-bold">${escapeHtml(r.created_at || "-")}</div>
            <div class="col-span-2">${escapeHtml(r.entidad || "-")}</div>
            <div class="col-span-2">${r.entidad_id ? escapeHtml(String(r.entidad_id)) : "-"}</div>

            <div class="col-span-2">
              <span class="inline-flex items-center px-3 py-1 rounded-2xl border border-slate-200 bg-white text-slate-900 font-extrabold text-xs">
                ${escapeHtml(r.estado_anterior ?? "-")}
              </span>
            </div>

            <div class="col-span-3">
              <span class="inline-flex items-center px-3 py-1 rounded-2xl bg-slate-900 text-white font-extrabold text-xs">
                ${escapeHtml(r.estado_nuevo ?? "-")}
              </span>
            </div>
          `;

          frag.appendChild(div);
        });

        detalleBodyTable.appendChild(frag);
      }

      // Cards (mobile)
      if (detalleBodyCards) {
        const frag = document.createDocumentFragment();

        list.forEach((r) => {
          const card = document.createElement("div");
          card.className = "rounded-3xl border border-slate-200 bg-white shadow-sm p-4 mb-3";

          card.innerHTML = `
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="text-sm font-extrabold text-slate-900 truncate">
                  ${escapeHtml(r.entidad || "-")} ${r.entidad_id ? "#" + escapeHtml(String(r.entidad_id)) : ""}
                </div>
                <div class="text-xs font-bold text-slate-600 mt-0.5">${escapeHtml(r.created_at || "-")}</div>
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
        detalleState.userName = json.user_name || detalleState.userName;
        detalleState.userEmail = json.user_email || detalleState.userEmail;
        detalleState.pedidos = Array.isArray(json.pedidos) ? json.pedidos : [];
        detalleState.kpis = json.kpis || { confirmados: 0, disenos: 0 };

        setDetalleHeader();
        renderPedidosChips(detalleState.pedidos);
        renderDetalle(json.data || []);

        // Paginación
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
        if (detalleChipsWrap) detalleChipsWrap.innerHTML = "";
        modalSetError("Error cargando detalle: " + (e?.message || e));
        console.error(e);
      } finally {
        modalSetLoading(false);
      }
    }

    // ------------------ Eventos modal ------------------
    detalleCerrar?.addEventListener("click", (e) => {
      e.preventDefault();
      e.stopPropagation();
      closeModalDetalle();
    });

    // Para cerrar por overlay: tu overlay debe tener data-close="1"
    detalleModal?.addEventListener("click", (e) => {
      const el = e.target;
      const isOverlay = el && el.getAttribute && el.getAttribute("data-close") === "1";
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

    // ------------------ UI principal ------------------
    inputBuscar?.addEventListener("input", renderAll);

    btnLimpiarBusqueda?.addEventListener("click", () => {
      if (inputBuscar) inputBuscar.value = "";
      renderAll();
    });

    btnFiltrar?.addEventListener("click", () => {
      cargar();
    });

    btnLimpiarFechas?.addEventListener("click", () => {
      if (fromEl) fromEl.value = "";
      if (toEl) toEl.value = "";
      cargar();
    });

    btnActualizar?.addEventListener("click", () => {
      cargar();
    });

    // ------------------ Init ------------------
    initDatePickers();
    cargar(); // carga inicial
  });
})();
