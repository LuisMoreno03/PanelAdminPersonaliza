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
    return String(mime || "").toLowerCase().includes("pdf");
  }

  // ----------------------------
  // API (desde el view)
  // ----------------------------
  const API = window.PLACAS_API || window.API || {};
  if (!API.listar || !API.stats) {
    console.warn("⚠️ Falta window.PLACAS_API con endpoints. Revisa tu view.");
  }

  // ----------------------------
  // State principal listado
  // ----------------------------
  let placasMap = {};      // id -> item
  let loteIndex = {};      // lote_id -> items[]
  let searchTerm = "";

  // ----------------------------
  // State modal carga
  // ----------------------------
  let pedidosAll = [];
  let pedidosLoaded = false;
  let pedidosLoading = false;

  const pedidosSelected = new Map(); // key -> {id, number, cliente, raw}
  let selectedFiles = [];
  let objectUrls = [];

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

    // placeholders
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

          // thumb SOLO si es imagen (evita pedir inline para archivos no imagen)
          let thumb = "";
          if (principal?.id && isImageMime(principal?.mime) && API.inline) {
            thumb = joinUrl(API.inline, principal.id);
          } else if (principal?.thumb_url && isImageMime(principal?.mime)) {
            thumb = principal.thumb_url;
          } else {
            thumb = "";
          }

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

  // ============================================================
  // ✅ MODAL CARGA PLACA (pedidos + preview + upload)
  // ============================================================
  function getModalBackdrop() {
    return (
      document.getElementById("modalCargaBackdrop") ||
      document.getElementById("modalCargaPlacaBackdrop") ||
      document.getElementById("modalCargaPlaca") ||
      document.querySelector("[data-placas-modal-carga]")
    );
  }

  function showModalCarga() {
    const backdrop = getModalBackdrop();
    if (!backdrop) return;
    backdrop.classList.remove("hidden");
    document.body.classList.add("overflow-hidden");
  }

  function hideModalCarga() {
    const backdrop = getModalBackdrop();
    if (!backdrop) return;
    backdrop.classList.add("hidden");
    document.body.classList.remove("overflow-hidden");
  }

  function setCargaMsg(html, kind) {
    const el = q("cargaMsg");
    if (!el) return;
    const cls = kind === "error" ? "text-red-600" : (kind === "ok" ? "text-emerald-700" : "text-gray-600");
    el.className = `mt-2 text-sm ${cls}`;
    el.innerHTML = html || "";
  }

  function setProgress(on, pct, label) {
    const wrap = q("uploadProgressWrap");
    const bar = q("uploadProgressBar");
    const text = q("uploadProgressText");
    const lab = q("uploadProgressLabel");

    if (!wrap || !bar || !text || !lab) return;

    if (!on) {
      wrap.classList.add("hidden");
      bar.style.width = "0%";
      text.textContent = "0%";
      lab.textContent = "Subiendo…";
      return;
    }

    wrap.classList.remove("hidden");
    const p = Math.max(0, Math.min(100, Number(pct || 0)));
    bar.style.width = `${p}%`;
    text.textContent = `${p}%`;
    lab.textContent = label || "Subiendo…";
  }

  function clearObjectUrls() {
    objectUrls.forEach((u) => {
      try { URL.revokeObjectURL(u); } catch (e) {}
    });
    objectUrls = [];
  }

  function updateArchivosCount() {
    const el = q("cargaArchivosCount");
    if (!el) return;
    el.textContent = `${selectedFiles.length} archivo(s)`;
  }

  function renderPreviewArchivos() {
    const box = q("cargaPreview");
    if (!box) return;

    clearObjectUrls();
    updateArchivosCount();

    if (!selectedFiles.length) {
      box.className = "mt-3 flex h-[360px] items-center justify-center rounded-2xl border border-gray-200 bg-gray-50 text-sm text-gray-400";
      box.innerHTML = "Vista previa";
      return;
    }

    box.className = "mt-3 h-[360px] overflow-auto rounded-2xl border border-gray-200 bg-white p-3";
    box.innerHTML = `
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3" id="__prevGrid"></div>
    `;

    const grid = box.querySelector("#__prevGrid");
    selectedFiles.forEach((file, idx) => {
      const mime = file.type || "";
      const size = bytesToKb(file.size || 0);

      let thumbHtml = `
        <div class="w-12 h-12 rounded-xl border bg-gray-50 flex items-center justify-center text-[10px] font-black text-gray-500">
          ${isPdfMime(mime) ? "PDF" : "FILE"}
        </div>
      `;

      if (isImageMime(mime)) {
        const url = URL.createObjectURL(file);
        objectUrls.push(url);
        thumbHtml = `<img src="${escapeHtml(url)}" class="w-12 h-12 rounded-xl border object-cover" alt="">`;
      }

      const card = document.createElement("div");
      card.className = "border rounded-2xl p-3 flex items-start gap-3";

      card.innerHTML = `
        <div class="shrink-0">${thumbHtml}</div>
        <div class="min-w-0 flex-1">
          <div class="font-extrabold text-sm truncate" title="${escapeHtml(file.name)}">${escapeHtml(file.name)}</div>
          <div class="text-xs text-gray-500 mt-0.5 truncate">${escapeHtml(mime || "—")}</div>
          <div class="text-xs text-gray-500 mt-0.5">${escapeHtml(size)}</div>
          <button type="button" data-remove-file="${idx}"
            class="mt-2 inline-flex items-center rounded-xl bg-gray-100 px-3 py-1 text-xs font-extrabold hover:bg-gray-200">
            Quitar
          </button>
        </div>
      `;

      grid.appendChild(card);
    });

    // quitar archivo (reconstruye FileList con DataTransfer)
    box.querySelectorAll("[data-remove-file]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const i = Number(btn.getAttribute("data-remove-file"));
        if (Number.isNaN(i)) return;

        selectedFiles.splice(i, 1);

        const input = q("cargaArchivo");
        if (input) {
          const dt = new DataTransfer();
          selectedFiles.forEach((f) => dt.items.add(f));
          input.files = dt.files;
        }

        renderPreviewArchivos();
      });
    });
  }

  function normalizePedido(raw) {
    // soporta múltiples formatos
    const obj = (raw && typeof raw === "object") ? raw : { number: String(raw || "") };

    const numberRaw =
      obj.number ?? obj.numero ?? obj.pedido ?? obj.order_number ?? obj.orderNumber ??
      obj.name ?? obj.numero_pedido ?? obj.num ?? obj.id ?? "";

    let number = String(numberRaw || "").trim();
    if (!number) number = "PEDIDO";
    // si viene sin #, lo dejamos tal cual (por si es "PEDIDO00123"); si viene num puro, también.
    // Pero si quieres forzar #, descomenta:
    // if (!number.startsWith("#")) number = `#${number}`;

    const cliente =
      obj.cliente ?? obj.customer ?? obj.cliente_nombre ?? obj.nombre_cliente ?? obj.name_customer ?? obj.nombre ?? "";

    const idRaw =
      obj.id ?? obj.order_id ?? obj.orderId ?? obj.pedido_id ?? obj.pedidoId ?? number;

    const id = String(idRaw || number).trim();

    return { id, number, cliente: String(cliente || "").trim(), raw: obj };
  }

  function pedidoMatches(p, term) {
    if (!term) return true;
    const hay = normalizeText(`${p.number} ${p.id} ${p.cliente}`);
    return hay.includes(term);
  }

  function renderPedidosUI() {
    const lista = q("cargaPedidosLista");
    const sel = q("cargaPedidosSeleccionados");
    const vin = q("cargaPedidosVinculados");
    const footer = q("cargaPedidosFooter");
    const bus = q("cargaBuscarPedido");

    if (!lista || !sel || !vin) return;

    const term = normalizeText(bus?.value || "");

    // LISTA
    const shown = pedidosAll.filter((p) => pedidoMatches(p, term));
    if (!pedidosAll.length) {
      lista.innerHTML = `<div class="p-3 text-xs text-gray-500">No hay pedidos para mostrar.</div>`;
    } else if (!shown.length) {
      lista.innerHTML = `<div class="p-3 text-xs text-gray-500">Sin resultados.</div>`;
    } else {
      lista.innerHTML = shown.map((p) => {
        const key = p.id || p.number;
        const active = pedidosSelected.has(key);
        return `
          <button type="button" data-pedido-key="${escapeHtml(key)}"
            class="w-full text-left rounded-xl border px-3 py-2 mb-2 ${active ? "bg-blue-50 border-blue-200" : "bg-white border-gray-200 hover:bg-gray-50"}">
            <div class="font-extrabold text-xs truncate">${escapeHtml(p.number)}</div>
            <div class="text-[11px] text-gray-500 truncate">${escapeHtml(p.cliente || "—")}</div>
          </button>
        `;
      }).join("");
    }

    // SELECCIONADOS
    const selectedArr = Array.from(pedidosSelected.values());
    if (!selectedArr.length) {
      sel.innerHTML = `<div class="p-3 text-xs text-gray-500">Selecciona pedidos de “Por producir”.</div>`;
      vin.innerHTML = `<div class="p-3 text-xs text-gray-500">Al seleccionar pedidos, aquí aparecen vinculados.</div>`;
    } else {
      const chips = selectedArr.map((p) => {
        const key = p.id || p.number;
        return `
          <div class="flex items-center justify-between gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 mb-2">
            <div class="min-w-0">
              <div class="text-xs font-extrabold truncate">${escapeHtml(p.number)}</div>
              <div class="text-[11px] text-gray-500 truncate">${escapeHtml(p.cliente || "—")}</div>
            </div>
            <button type="button" data-remove-pedido="${escapeHtml(key)}"
              class="shrink-0 rounded-lg bg-white border px-2 py-1 text-[11px] font-extrabold hover:bg-gray-100">
              Quitar
            </button>
          </div>
        `;
      }).join("");

      sel.innerHTML = chips;
      vin.innerHTML = chips; // “Vinculados (auto)” = mismos seleccionados
    }

    if (footer) {
      footer.textContent = pedidosAll.length
        ? `Mostrando ${shown.length} de ${pedidosAll.length}. Seleccionados: ${pedidosSelected.size}.`
        : "";
    }

    // listeners LISTA
    lista.querySelectorAll("[data-pedido-key]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const key = btn.getAttribute("data-pedido-key");
        if (!key) return;
        const p = pedidosAll.find((x) => (x.id || x.number) === key);
        if (!p) return;

        if (pedidosSelected.has(key)) pedidosSelected.delete(key);
        else pedidosSelected.set(key, p);

        renderPedidosUI();
      });
    });

    // listeners remove
    sel.querySelectorAll("[data-remove-pedido]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const key = btn.getAttribute("data-remove-pedido");
        if (!key) return;
        pedidosSelected.delete(key);
        renderPedidosUI();
      });
    });
  }

  async function cargarPedidos() {
    if (pedidosLoading || pedidosLoaded) return;
    pedidosLoading = true;

    const lista = q("cargaPedidosLista");
    if (lista) lista.innerHTML = `<div class="p-3 text-xs text-gray-500">Cargando pedidos…</div>`;

    // Endpoint recomendado: API.pedidos
    // Si no existe, intentamos algunos fallbacks (por si ya tienes uno funcionando).
    const candidates = [
      API.pedidos,
      joinUrl(window.location.origin, "porproducir/pull"),
      joinUrl(window.location.origin, "produccion/my-queue"),
      joinUrl(window.location.origin, "montaje/my-queue"),
    ].filter(Boolean);

    let data = null;

    for (const url of candidates) {
      try {
        const res = await fetch(url, { cache: "no-store" });
        const text = await res.text();
        const json = safeJsonParse(text);
        if (!json) continue;

        // aceptamos formatos comunes:
        // - array directo
        // - {data:[...]} / {pedidos:[...]} / {orders:[...]}
        let arr =
          Array.isArray(json) ? json :
          (Array.isArray(json.data) ? json.data :
          (Array.isArray(json.pedidos) ? json.pedidos :
          (Array.isArray(json.orders) ? json.orders : null)));

        if (!arr) continue;

        data = arr;
        break;
      } catch (e) {}
    }

    if (!data) {
      pedidosAll = [];
      pedidosLoaded = true;
      pedidosLoading = false;
      renderPedidosUI();
      // mensaje suave
      const footer = q("cargaPedidosFooter");
      if (footer) {
        footer.textContent = "⚠️ No se pudo cargar pedidos. Añade window.PLACAS_API.pedidos con un endpoint JSON.";
      }
      return;
    }

    pedidosAll = data.map(normalizePedido).filter((p) => p && (p.id || p.number));
    pedidosLoaded = true;
    pedidosLoading = false;

    renderPedidosUI();
  }

  function resetModalCarga() {
    // campos
    if (q("cargaLoteNombre")) q("cargaLoteNombre").value = "";
    if (q("cargaNumero")) q("cargaNumero").value = "";
    if (q("cargaBuscarPedido")) q("cargaBuscarPedido").value = "";

    // pedidos
    pedidosSelected.clear();
    renderPedidosUI();

    // archivos
    selectedFiles = [];
    const input = q("cargaArchivo");
    if (input) input.value = "";
    renderPreviewArchivos();

    // msg/progreso
    setCargaMsg("", "");
    setProgress(false, 0, "");
  }

  function initModalCargaPlaca() {
    const openBtn =
      q("btnAbrirModalCarga") ||
      document.getElementById("btnSubirPlaca") ||
      document.querySelector("[data-open-modal-carga]");

    const backdrop = getModalBackdrop();

    if (!openBtn) {
      console.warn("⚠️ No existe el botón para abrir el modal (id btnAbrirModalCarga).");
      return;
    }
    if (!backdrop) {
      console.warn("⚠️ No se encontró el modal de carga. Asegura id='modalCargaBackdrop' en el contenedor.");
      return;
    }

    // abrir
    openBtn.addEventListener("click", (e) => {
      e.preventDefault();
      setCargaMsg("", "");
      setProgress(false, 0, "");
      showModalCarga();
      cargarPedidos(); // carga pedidos al abrir
      renderPedidosUI();
      renderPreviewArchivos();
    });

    // cerrar (tus IDs reales)
    const closeBtns = [
      q("btnCerrarCarga"),
      q("btnCancelarCarga"),
      q("btnCerrarModalCarga"),
      q("btnCerrarModal"),
    ].filter(Boolean);

    closeBtns.forEach((btn) => btn.addEventListener("click", (e) => {
      e.preventDefault();
      hideModalCarga();
    }));

    // click fuera
    backdrop.addEventListener("click", (e) => {
      if (e.target === backdrop) hideModalCarga();
    });

    // Escape
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && !backdrop.classList.contains("hidden")) hideModalCarga();
    });

    // buscar pedidos
    q("cargaBuscarPedido")?.addEventListener("input", () => renderPedidosUI());

    // input archivos -> preview
    q("cargaArchivo")?.addEventListener("change", (e) => {
      selectedFiles = Array.from(e.target.files || []);
      renderPreviewArchivos();
    });

    // guardar -> upload
    q("btnGuardarCarga")?.addEventListener("click", async (e) => {
      e.preventDefault();
      await guardarLote();
    });

    // debug
    window.__PLACAS_CARGA_MODAL = { show: showModalCarga, hide: hideModalCarga, reset: resetModalCarga };
  }

  function buildPedidosJsonForSave() {
    // Guardamos un formato que tu modal “Ver” entiende perfecto:
    // [{number:"#PEDIDO001234", id:"...", cliente:"..."}]
    return Array.from(pedidosSelected.values()).map((p) => ({
      number: p.number,
      id: p.id,
      cliente: p.cliente
    }));
  }

  function xhrUpload(url, formData, onProgress) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open("POST", url, true);
      xhr.responseType = "text";

      xhr.upload.onprogress = (evt) => {
        if (!evt.lengthComputable) return;
        const pct = Math.round((evt.loaded / evt.total) * 100);
        onProgress?.(pct);
      };

      xhr.onload = () => resolve({ status: xhr.status, text: xhr.responseText });
      xhr.onerror = () => reject(new Error("Error de red al subir."));
      xhr.send(formData);
    });
  }

  async function guardarLote() {
    if (!API.subir) {
      setCargaMsg("❌ Falta <b>API.subir</b> en window.PLACAS_API", "error");
      return;
    }

    const loteNombre = (q("cargaLoteNombre")?.value || "").trim();
    const numeroPlaca = (q("cargaNumero")?.value || "").trim();

    if (!loteNombre) {
      setCargaMsg("❌ El <b>nombre del lote</b> es obligatorio.", "error");
      return;
    }

    if (!selectedFiles.length) {
      setCargaMsg("❌ Debes seleccionar al menos <b>1 archivo</b>.", "error");
      return;
    }

    const btn = q("btnGuardarCarga");
    if (btn) {
      btn.disabled = true;
      btn.classList.add("opacity-60", "cursor-not-allowed");
    }

    setCargaMsg("Subiendo…", "");
    setProgress(true, 0, "Subiendo…");

    try {
      const pedidosJson = JSON.stringify(buildPedidosJsonForSave());

      const fd = new FormData();
      fd.append("lote_nombre", loteNombre);
      fd.append("numero_placa", numeroPlaca);
      fd.append("pedidos_json", pedidosJson);

      // ✅ IMPORTANTE: tu backend lee $files['archivos']
      // usando "archivos[]" PHP lo convierte a "archivos" correctamente.
      selectedFiles.forEach((f) => fd.append("archivos[]", f, f.name));

      addCsrf(fd);

      const result = await xhrUpload(API.subir, fd, (pct) => {
        setProgress(true, pct, "Subiendo…");
      });

      const data = safeJsonParse(result.text);

      if (result.status >= 200 && result.status < 300 && data?.success) {
        setProgress(true, 100, "Completado");
        setCargaMsg(`✅ ${escapeHtml(data.message || "Archivos subidos correctamente")}`, "ok");

        // refrescar listado
        await cargarStats();
        await cargarVistaAgrupada();

        // limpiar y cerrar
        setTimeout(() => {
          resetModalCarga();
          hideModalCarga();
        }, 450);

      } else {
        const msg = (data && (data.message || data.error)) ? (data.message || data.error) : result.text;
        setCargaMsg(`❌ ${escapeHtml(String(msg || "No se pudo subir."))}`, "error");
        setProgress(false, 0, "");
      }

    } catch (err) {
      setCargaMsg(`❌ ${escapeHtml(String(err.message || err))}`, "error");
      setProgress(false, 0, "");
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.classList.remove("opacity-60", "cursor-not-allowed");
      }
    }
  }

  // ----------------------------
  // Init
  // ----------------------------
  async function init() {
    initModalCargaPlaca();
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
    recargar: async () => { await cargarStats(); await cargarVistaAgrupada(); },
  };

  init();
})();
