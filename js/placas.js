/* global window, document, fetch */

(() => {
  // ----------------------------
  // Helpers
  // ----------------------------
  const q = (id) => document.getElementById(id);

  function escapeHtml(str) {
    return (str || "").replace(/[&<>"']/g, (s) => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
    }[s]));
  }

  function formatFecha(fechaISO) {
    if (!fechaISO) return "";
    const d = new Date(String(fechaISO).replace(" ", "T"));
    if (isNaN(d)) return String(fechaISO);
    return d.toLocaleString("es-ES", {
      year: "numeric", month: "2-digit", day: "2-digit",
      hour: "2-digit", minute: "2-digit"
    });
  }

  function normalizeText(s) {
    return String(s || "")
      .toLowerCase()
      .normalize("NFD").replace(/[\u0300-\u036f]/g, "")
      .trim();
  }

  function safeJsonParse(text) {
    try { return JSON.parse(text); } catch (e) { return null; }
  }

  function csrfPair() {
    const name = document.querySelector('meta[name="csrf-name"]')?.getAttribute("content");
    const hash = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content");
    return { name, hash };
  }

  function addCsrf(fd) {
    const { name, hash } = csrfPair();
    if (name && hash) fd.append(name, hash);
    return fd;
  }

  function joinUrl(base, tail) {
    if (!base) return "";
    const b = String(base).replace(/\/+$/, "");
    const t = String(tail).replace(/^\/+/, "");
    return `${b}/${t}`;
  }

  function bytesToKb(size) {
    const kb = Math.round((Number(size || 0)) / 1024);
    return `${kb} KB`;
  }

  function isImageMime(mime) {
    return String(mime || "").startsWith("image/");
  }

  function isPdfMime(mime) {
    return String(mime || "").includes("pdf");
  }

  // ----------------------------
  // API (desde el view)
  // ----------------------------
  const API = window.PLACAS_API || window.API || {};
  if (!API.listar || !API.stats) {
    console.warn("⚠️ Falta window.PLACAS_API con endpoints. Revisa tu view.");
  }

  // ----------------------------
  // State
  // ----------------------------
  let placasMap = {};      // id -> item
  let loteIndex = {};      // lote_id -> items[]
  let searchTerm = "";

  // ----------------------------
  // Modal "VER LOTE" (inyectado)
  // ----------------------------
  function ensureLoteModal() {
    if (q("loteInfoBackdrop")) return;

    const wrap = document.createElement("div");
    wrap.id = "loteInfoBackdrop";
    wrap.className = "fixed inset-0 bg-black/50 hidden z-[10050]";

    wrap.innerHTML = `
      <div class="w-full h-full flex items-start justify-center p-2 sm:p-4">
        <div class="bg-white w-full max-w-6xl rounded-2xl shadow-xl overflow-hidden flex flex-col max-h-[95vh]">
          <div class="px-4 sm:px-6 py-4 border-b flex items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="text-lg sm:text-xl font-black leading-tight" id="loteInfoTitle">Detalle del lote</div>
              <div class="text-xs sm:text-sm text-gray-500 mt-1" id="loteInfoSub">—</div>
            </div>
            <button id="loteInfoClose"
              class="shrink-0 inline-flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-bold bg-gray-100 hover:bg-gray-200">
              Cerrar
            </button>
          </div>

          <div class="p-4 sm:p-6 overflow-auto">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
              <div class="lg:col-span-1">
                <div class="border rounded-2xl p-4">
                  <div class="font-extrabold text-sm mb-2">Información</div>
                  <div class="text-sm text-gray-700 space-y-2">
                    <div><span class="text-gray-500">Lote:</span> <span class="font-bold" id="loteInfoLote">—</span></div>
                    <div><span class="text-gray-500">Nota/Número:</span> <span class="font-bold" id="loteInfoNota">—</span></div>
                    <div><span class="text-gray-500">Fecha:</span> <span class="font-bold" id="loteInfoFecha">—</span></div>
                    <div><span class="text-gray-500">Archivos:</span> <span class="font-bold" id="loteInfoTotal">0</span></div>
                  </div>
                </div>

                <div class="border rounded-2xl p-4 mt-4">
                  <div class="font-extrabold text-sm mb-2">Pedidos vinculados</div>
                  <div class="text-xs text-gray-500 mb-2">Se guardan en <code class="px-2 py-0.5 bg-gray-100 rounded">pedidos_json</code></div>
                  <div class="flex flex-wrap gap-2" id="loteInfoPedidos">
                    <span class="text-sm text-gray-400">Sin pedidos</span>
                  </div>
                </div>
              </div>

              <div class="lg:col-span-2">
                <div class="border rounded-2xl overflow-hidden">
                  <div class="px-4 py-3 border-b flex items-center justify-between">
                    <div class="font-extrabold text-sm">Archivos del lote</div>
                    <div class="text-xs text-gray-500" id="loteInfoHint">Abrir o descargar fácilmente</div>
                  </div>

                  <div class="overflow-auto">
                    <table class="w-full text-sm">
                      <thead class="bg-gray-50 text-gray-600">
                        <tr>
                          <th class="text-left px-4 py-3">Archivo</th>
                          <th class="text-left px-4 py-3 hidden sm:table-cell">Tipo</th>
                          <th class="text-left px-4 py-3 hidden md:table-cell">Tamaño</th>
                          <th class="text-right px-4 py-3">Acciones</th>
                        </tr>
                      </thead>
                      <tbody id="loteInfoFiles">
                        <tr><td class="px-4 py-4 text-gray-400" colspan="4">Cargando…</td></tr>
                      </tbody>
                    </table>
                  </div>
                </div>

                <div class="text-xs text-gray-500 mt-3">
                  Tip: <b>Abrir</b> usa <code class="px-2 py-0.5 bg-gray-100 rounded">/inline</code> (preview) y <b>Descargar</b> usa <code class="px-2 py-0.5 bg-gray-100 rounded">/descargar</code>.
                </div>
              </div>
            </div>
          </div>

          <div class="px-4 sm:px-6 py-4 border-t flex justify-end gap-2">
            <button id="loteInfoCloseBottom"
              class="rounded-xl px-4 py-2 text-sm font-bold bg-gray-100 hover:bg-gray-200">
              Cerrar
            </button>
          </div>
        </div>
      </div>
    `;

    document.body.appendChild(wrap);

    const close = () => wrap.classList.add("hidden");
    q("loteInfoClose")?.addEventListener("click", close);
    q("loteInfoCloseBottom")?.addEventListener("click", close);
    wrap.addEventListener("click", (e) => {
      if (e.target && e.target.id === "loteInfoBackdrop") close();
    });
  }

  function openLoteModal() {
    ensureLoteModal();
    q("loteInfoBackdrop")?.classList.remove("hidden");
  }

  function renderPedidosChips(pedidos) {
    const box = q("loteInfoPedidos");
    if (!box) return;

    const arr = Array.isArray(pedidos) ? pedidos : [];
    if (!arr.length) {
      box.innerHTML = `<span class="text-sm text-gray-400">Sin pedidos vinculados</span>`;
      return;
    }

    box.innerHTML = arr.map((p) => {
      const label =
        typeof p === "string"
          ? p
          : (p.number || p.numero || p.pedido || p.label || p.id || "");

      return `
        <span class="inline-flex items-center rounded-full bg-blue-50 text-blue-700 px-3 py-1 text-xs font-extrabold">
          ${escapeHtml(label)}
        </span>
      `;
    }).join("");
  }

  function renderFilesTable(files) {
    const tbody = q("loteInfoFiles");
    if (!tbody) return;

    const arr = Array.isArray(files) ? files : [];
    if (!arr.length) {
      tbody.innerHTML = `<tr><td class="px-4 py-5 text-gray-400" colspan="4">No hay archivos en este lote.</td></tr>`;
      return;
    }

    tbody.innerHTML = arr.map((f) => {
      const name = (f.original || f.nombre || `archivo_${f.id}`) + "";
      const mime = (f.mime || "") + "";
      const size = bytesToKb(f.size || 0);

      const inlineUrl = f.url || (API.inline ? joinUrl(API.inline, f.id) : "");
      const downloadUrl = f.download_url || (API.descargarBase ? joinUrl(API.descargarBase, f.id) : "");

      const canInline = !!inlineUrl;
      const canDownload = !!downloadUrl;

      const thumb = (isImageMime(mime) && canInline) ? `
        <img src="${escapeHtml(inlineUrl)}" class="w-10 h-10 rounded-lg object-cover border" alt="">
      ` : `
        <div class="w-10 h-10 rounded-lg border bg-gray-50 flex items-center justify-center text-xs font-black text-gray-500">
          ${isPdfMime(mime) ? "PDF" : "FILE"}
        </div>
      `;

      const btnBase = "rounded-xl px-3 py-2 text-xs font-extrabold";
      const disabled = "opacity-50 cursor-not-allowed pointer-events-none";

      return `
        <tr class="border-t">
          <td class="px-4 py-3">
            <div class="flex items-center gap-3 min-w-0">
              ${thumb}
              <div class="min-w-0">
                <div class="font-extrabold truncate">${escapeHtml(name)}</div>
                <div class="text-xs text-gray-500 truncate">#${escapeHtml(String(f.id))}</div>
              </div>
            </div>
          </td>

          <td class="px-4 py-3 hidden sm:table-cell">
            <div class="text-xs text-gray-600">${escapeHtml(mime || "—")}</div>
          </td>

          <td class="px-4 py-3 hidden md:table-cell">
            <div class="text-xs text-gray-600">${escapeHtml(size)}</div>
          </td>

          <td class="px-4 py-3 text-right">
            <div class="inline-flex gap-2">
              <button type="button"
                class="${btnBase} bg-gray-100 hover:bg-gray-200 ${canInline ? "" : disabled}"
                ${canInline ? `onclick="window.open('${escapeHtml(inlineUrl)}','_blank')"` : ""}>
                Abrir
              </button>

              <button type="button"
                class="${btnBase} bg-blue-600 text-white hover:brightness-110 ${canDownload ? "" : disabled}"
                ${canDownload ? `onclick="window.open('${escapeHtml(downloadUrl)}','_blank')"` : ""}>
                Descargar
              </button>
            </div>
          </td>
        </tr>
      `;
    }).join("");
  }

  async function abrirDetalleLoteDesdeArchivoId(fileId) {
    ensureLoteModal();
    openLoteModal();

    q("loteInfoTitle").textContent = "Cargando…";
    q("loteInfoSub").textContent = "";
    q("loteInfoLote").textContent = "—";
    q("loteInfoNota").textContent = "—";
    q("loteInfoFecha").textContent = "—";
    q("loteInfoTotal").textContent = "0";
    q("loteInfoFiles").innerHTML = `<tr><td class="px-4 py-4 text-gray-400" colspan="4">Cargando…</td></tr>`;
    q("loteInfoPedidos").innerHTML = `<span class="text-sm text-gray-400">Cargando…</span>`;

    const url = API.ver ? joinUrl(API.ver, fileId) : (API.info ? joinUrl(API.info, fileId) : "");
    if (!url) {
      q("loteInfoTitle").textContent = "Error";
      q("loteInfoFiles").innerHTML = `<tr><td class="px-4 py-4 text-red-500" colspan="4">Falta API.ver en window.PLACAS_API</td></tr>`;
      return;
    }

    try {
      const res = await fetch(url, { cache: "no-store" });
      const text = await res.text();
      const data = safeJsonParse(text);

      if (!res.ok || !data || !data.success) {
        q("loteInfoTitle").textContent = "No se pudo cargar";
        q("loteInfoFiles").innerHTML = `<tr><td class="px-4 py-4 text-red-500" colspan="4">
          ${escapeHtml((data && data.message) ? data.message : text.slice(0, 180))}
        </td></tr>`;
        q("loteInfoPedidos").innerHTML = `<span class="text-sm text-gray-400">—</span>`;
        return;
      }

      const lote = data.lote || {};
      const files = data.files || [];

      const loteNombre = (lote.lote_nombre || "Lote").trim();
      const loteId = lote.lote_id || "";
      const nota = lote.numero_placa || "—";
      const fecha = lote.created_at ? formatFecha(lote.created_at) : "—";
      const total = lote.total_files ?? files.length ?? 0;

      q("loteInfoTitle").textContent = `📦 ${loteNombre}`;
      q("loteInfoSub").textContent = loteId ? `ID: ${loteId}` : "";
      q("loteInfoLote").textContent = loteNombre;
      q("loteInfoNota").textContent = nota || "—";
      q("loteInfoFecha").textContent = fecha;
      q("loteInfoTotal").textContent = String(total);

      renderPedidosChips(lote.pedidos || []);
      renderFilesTable(files);

    } catch (e) {
      q("loteInfoTitle").textContent = "Error";
      q("loteInfoFiles").innerHTML = `<tr><td class="px-4 py-4 text-red-500" colspan="4">
        ${escapeHtml(String(e.message || e))}
      </td></tr>`;
    }
  }

  // ----------------------------
  // Listado (vista agrupada por día)
  // ----------------------------
  function itemMatches(it, term) {
    if (!term) return true;
    const hay = normalizeText([
      it.nombre, it.original, it.id, it.mime, it.lote_id, it.lote_nombre, it.numero_placa
    ].join(" "));
    return hay.includes(term);
  }

  async function cargarStats() {
    if (!API.stats || !q("placasHoy")) return;
    try {
      const res = await fetch(API.stats, { cache: "no-store" });
      const text = await res.text();
      const data = safeJsonParse(text);
      if (data?.success) q("placasHoy").textContent = data.data?.total ?? 0;
    } catch (e) {}
  }

  async function cargarVistaAgrupada() {
    if (!API.listar) return;

    placasMap = {};
    loteIndex = {};

    const cont = q("contenedorDias");
    if (!cont) return;

    try {
      const res = await fetch(API.listar, { cache: "no-store" });
      const text = await res.text();
      const data = safeJsonParse(text);

      if (!res.ok || !data?.success || !Array.isArray(data.dias)) {
        cont.innerHTML = `<div class="text-sm text-gray-500">No hay datos para mostrar.</div>`;
        return;
      }

      if (q("placasHoy")) q("placasHoy").textContent = data.placas_hoy ?? 0;

      const term = normalizeText(searchTerm);
      const dias = term ? data.dias.map(d => ({
        ...d,
        lotes: (d.lotes || []).map(l => ({
          ...l,
          items: (l.items || []).filter(it => itemMatches(it, term))
        })).filter(l => (l.items || []).length > 0)
      })).filter(d => (d.lotes || []).length > 0) : data.dias;

      if (term && !dias.length) {
        cont.innerHTML = `<div class="text-sm text-gray-500">No hay resultados para "<b>${escapeHtml(searchTerm)}</b>".</div>`;
        return;
      }

      cont.innerHTML = "";

      for (const dia of dias) {
        const diaBox = document.createElement("div");
        diaBox.className = "w-full bg-white border border-gray-200 rounded-2xl p-4 sm:p-5 shadow-sm";

        diaBox.innerHTML = `
          <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
              <div class="text-lg sm:text-xl font-black truncate">${escapeHtml(dia.fecha)}</div>
              <div class="text-sm text-gray-500 mt-0.5">
                Total: <span class="font-bold text-gray-700">${escapeHtml(String(dia.total_archivos || 0))}</span>
              </div>
            </div>

            <span class="shrink-0 inline-flex items-center rounded-full bg-gray-100 px-3 py-1 text-xs font-extrabold text-gray-700">
              ${escapeHtml(String((dia.lotes || []).length))} lote(s)
            </span>
          </div>

          <div class="mt-4 grid gap-3 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3" data-dia-grid></div>
        `;

        const grid = diaBox.querySelector("[data-dia-grid]");
        cont.appendChild(diaBox);

        for (const lote of (dia.lotes || [])) {
          const lid = String(lote.lote_id ?? "");
          const lnombre = (lote.lote_nombre || "").trim() || "Sin nombre";
          const items = lote.items || [];
          const total = items.length;

          loteIndex[lid] = items;
          items.forEach(it => { placasMap[it.id] = it; });

          const principal = items[0] || null;

          const thumb =
            (API.inline && principal?.id ? joinUrl(API.inline, principal.id) : "") ||
            principal?.thumb_url ||
            principal?.url ||
            "";

          const created = formatFecha(lote.created_at || principal?.created_at || "");
          const nota = (lote.numero_placa || "").trim();
          const pedidosCount = Array.isArray(lote.pedidos)
            ? lote.pedidos.length
            : (Number(lote.pedidos_count || 0) || 0);

          const card = document.createElement("div");
          card.className = "group border border-gray-200 rounded-2xl p-4 bg-white hover:shadow-md transition cursor-pointer";

          card.innerHTML = `
            <div class="flex items-start gap-4">
              <div class="relative w-16 h-16 rounded-2xl border bg-gray-50 overflow-hidden shrink-0 flex items-center justify-center">
                <div class="text-[10px] font-black text-gray-400">LOTE</div>
                ${thumb ? `<img src="${escapeHtml(thumb)}" class="absolute inset-0 w-full h-full object-cover"
                  onerror="this.style.display='none';" />` : ``}
              </div>

              <div class="min-w-0 flex-1">
                <div class="flex items-start justify-between gap-3">
                  <div class="min-w-0">
                    <div class="font-black truncate">📦 ${escapeHtml(lnombre)}</div>

                    <div class="mt-1 flex flex-wrap gap-2 text-xs">
                      <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-1 font-extrabold text-gray-700">
                        ${escapeHtml(String(total))} archivo(s)
                      </span>

                      ${pedidosCount ? `
                        <span class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-1 font-extrabold text-blue-700">
                          ${escapeHtml(String(pedidosCount))} pedido(s)
                        </span>
                      ` : ``}

                      ${nota ? `
                        <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-1 font-extrabold text-amber-700">
                          Nota: ${escapeHtml(nota)}
                        </span>
                      ` : ``}
                    </div>

                    <div class="text-xs text-gray-500 mt-2 truncate">
                      ${created ? escapeHtml(created) : "—"}
                    </div>
                  </div>

                  <div class="shrink-0 flex items-center gap-2">
                    <button type="button"
                      class="rounded-xl px-3 py-2 text-xs font-extrabold bg-gray-900 text-white hover:brightness-110"
                      data-ver-lote>
                      Ver
                    </button>

                    ${API.descargarPngLote ? `
                      <a class="rounded-xl px-3 py-2 text-xs font-extrabold bg-emerald-600 text-white hover:brightness-110"
                        href="${escapeHtml(joinUrl(API.descargarPngLote, encodeURIComponent(lid)))}"
                        onclick="event.stopPropagation()">
                        PNG
                      </a>
                    ` : ``}

                    ${API.descargarJpgLote ? `
                      <a class="rounded-xl px-3 py-2 text-xs font-extrabold bg-blue-600 text-white hover:brightness-110"
                        href="${escapeHtml(joinUrl(API.descargarJpgLote, encodeURIComponent(lid)))}"
                        onclick="event.stopPropagation()">
                        JPG
                      </a>
                    ` : ``}
                  </div>
                </div>
              </div>
            </div>
          `;

          card.querySelector("[data-ver-lote]")?.addEventListener("click", (e) => {
            e.stopPropagation();
            const first = (loteIndex[lid] || [])[0];
            if (!first?.id) return;
            abrirDetalleLoteDesdeArchivoId(first.id);
          });

          card.addEventListener("click", () => {
            const first = (loteIndex[lid] || [])[0];
            if (!first?.id) return;
            abrirDetalleLoteDesdeArchivoId(first.id);
          });

          grid.appendChild(card);
        }
      }

    } catch (e) {
      cont.innerHTML = `<div class="text-sm text-gray-500">Error cargando archivos.</div>`;
    }
  }

  // ----------------------------
  // Buscador principal
  // ----------------------------
  function initSearch() {
    const input = q("searchInput");
    const clear = q("searchClear");
    if (!input) return;

    let t = null;

    const apply = (v) => {
      searchTerm = v || "";
      if (clear) clear.classList.toggle("hidden", !searchTerm.trim());
      cargarVistaAgrupada();
    };

    input.addEventListener("input", (e) => {
      clearTimeout(t);
      t = setTimeout(() => apply(e.target.value), 120);
    });

    if (clear) {
      clear.addEventListener("click", () => {
        input.value = "";
        apply("");
        input.focus();
      });
    }
  }

  // ----------------------------
  // Modal "CARGAR PLACA" (bridge EXACTO con tus IDs)
  // ----------------------------
  function initModalCargaPlaca() {
    const openBtn = document.getElementById("btnAbrirModalCarga");
    const backdrop = document.getElementById("modalCargaBackdrop");

    if (!openBtn || !backdrop) return;

    const show = () => {
      backdrop.classList.remove("hidden");
      backdrop.style.display = "block"; // fuerza por si hay CSS extra
      document.body.classList.add("overflow-hidden");
    };

    const hide = () => {
      backdrop.classList.add("hidden");
      backdrop.style.display = "none";
      document.body.classList.remove("overflow-hidden");
    };

    openBtn.addEventListener("click", (e) => {
      e.preventDefault();
      e.stopPropagation();
      show();
    });

    document.getElementById("btnCerrarCarga")?.addEventListener("click", (e) => {
      e.preventDefault();
      hide();
    });

    document.getElementById("btnCancelarCarga")?.addEventListener("click", (e) => {
      e.preventDefault();
      hide();
    });

    backdrop.addEventListener("click", (e) => {
      if (e.target === backdrop) hide();
    });

    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && !backdrop.classList.contains("hidden")) hide();
    });

    window.__PLACAS_CARGA_MODAL = { show, hide }; // debug
  }

  // ----------------------------
  // Init
  // ----------------------------
  async function init() {
    initModalCargaPlaca(); // ✅ ahora abre el modal
    initSearch();
    await cargarStats();
    await cargarVistaAgrupada();

    setInterval(async () => {
      await cargarStats();
      await cargarVistaAgrupada();
    }, 600000);
  }

  // Export para debug si quieres
  window.__PLACAS = {
    abrirDetalleLoteDesdeArchivoId,
    recargar: async () => { await cargarStats(); await cargarVistaAgrupada(); }
  };

  // ✅ Asegura que el DOM exista antes de buscar ids
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
