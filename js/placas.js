/* global window, document, fetch */

(() => {
  const API = {
    listarPorDia: "/placas/archivos/listar-por-dia",
    info: (id) => `/placas/archivos/info/${id}`,
  };

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  function esc(str) {
    return String(str ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function formatBytes(bytes) {
    const b = Number(bytes || 0);
    if (!b) return "0 B";
    const k = 1024;
    const sizes = ["B", "KB", "MB", "GB"];
    const i = Math.floor(Math.log(b) / Math.log(k));
    return `${(b / Math.pow(k, i)).toFixed(i === 0 ? 0 : 1)} ${sizes[i]}`;
  }

  function isImage(mime = "") {
    return String(mime).startsWith("image/");
  }

  function isPdf(mime = "", name = "") {
    return String(mime).includes("pdf") || String(name).toLowerCase().endsWith(".pdf");
  }

  function pickCoverItem(lote) {
    if (!lote?.items?.length) return null;
    // prefer image as cover
    const img = lote.items.find((it) => isImage(it.mime));
    return img || lote.items[0];
  }

  function ensureRoot() {
    let root = $("#placasRoot");
    if (!root) {
      root = document.createElement("div");
      root.id = "placasRoot";
      document.body.appendChild(root);
    }
    return root;
  }

  function baseLayout() {
    const root = ensureRoot();
    root.innerHTML = `
      <div class="w-full">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between mb-4">
          <div>
            <h1 class="text-xl font-bold text-slate-900">PLACAS</h1>
            <p id="placasSub" class="text-sm text-slate-500">Cargando...</p>
          </div>

          <div class="flex flex-col md:flex-row gap-2 w-full md:w-auto">
            <div class="relative w-full md:w-[360px]">
              <input id="placasSearch" type="text"
                class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-500"
                placeholder="Buscar por lote, archivo o pedido..." />
            </div>

            <button id="placasReload"
              class="rounded-lg bg-slate-900 text-white px-4 py-2 text-sm font-semibold hover:bg-slate-800">
              Recargar
            </button>
          </div>
        </div>

        <div id="placasList" class="space-y-6"></div>
      </div>

      <!-- Modal (Ver) -->
      <div id="placasModalWrap" class="fixed inset-0 z-[9999] hidden">
        <div class="absolute inset-0 bg-black/40"></div>

        <div class="absolute inset-0 p-2 md:p-6 flex items-center justify-center">
          <div class="w-full max-w-6xl bg-white rounded-2xl shadow-xl overflow-hidden">
            <div class="flex items-start justify-between gap-3 p-4 border-b">
              <div>
                <h2 id="placasModalTitle" class="text-lg font-bold text-slate-900">Detalle del lote</h2>
                <p id="placasModalMeta" class="text-sm text-slate-500"></p>
              </div>
              <button id="placasModalClose"
                class="rounded-lg border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50">
                Cerrar
              </button>
            </div>

            <div class="p-4 md:p-6 grid grid-cols-1 lg:grid-cols-3 gap-4">
              <div class="lg:col-span-1 space-y-4">
                <div class="rounded-xl border border-slate-200 p-4">
                  <h3 class="text-sm font-semibold text-slate-900 mb-2">Datos del lote</h3>
                  <div id="placasModalLote" class="text-sm text-slate-700 space-y-1"></div>
                </div>

                <div class="rounded-xl border border-slate-200 p-4">
                  <h3 class="text-sm font-semibold text-slate-900 mb-2">Pedidos vinculados</h3>
                  <div id="placasModalPedidos" class="text-sm text-slate-700 space-y-2"></div>
                </div>
              </div>

              <div class="lg:col-span-2">
                <div class="flex items-center justify-between mb-3">
                  <h3 class="text-sm font-semibold text-slate-900">Archivos del lote</h3>
                  <span id="placasModalCount" class="text-xs text-slate-500"></span>
                </div>

                <div id="placasModalFiles" class="grid grid-cols-1 md:grid-cols-2 gap-3"></div>
              </div>
            </div>

            <div class="p-4 border-t flex justify-end gap-2">
              <button id="placasModalOk"
                class="rounded-lg bg-blue-600 text-white px-4 py-2 text-sm font-semibold hover:bg-blue-700">
                Listo
              </button>
            </div>
          </div>
        </div>
      </div>
    `;
  }

  function renderEmpty(msg) {
    const list = $("#placasList");
    if (!list) return;
    list.innerHTML = `
      <div class="rounded-xl border border-slate-200 bg-white p-6 text-center">
        <p class="text-sm text-slate-600">${esc(msg)}</p>
      </div>
    `;
  }

  function renderDias(data, q = "") {
    const list = $("#placasList");
    const sub = $("#placasSub");
    if (!list || !sub) return;

    const placasHoy = Number(data?.placas_hoy || 0);
    sub.textContent = `Placas hoy: ${placasHoy}`;

    const dias = Array.isArray(data?.dias) ? data.dias : [];

    const query = String(q || "").trim().toLowerCase();

    // Filtrado simple
    const filtered = dias
      .map((d) => {
        const lotes = Array.isArray(d.lotes) ? d.lotes : [];
        const lotesFiltrados = lotes.filter((l) => {
          const cover = pickCoverItem(l);
          const inLote =
            String(l.lote_nombre || "").toLowerCase().includes(query) ||
            String(l.lote_id || "").toLowerCase().includes(query) ||
            String(l.created_at || "").toLowerCase().includes(query);

          const inFiles = (l.items || []).some((it) => {
            return (
              String(it.original || "").toLowerCase().includes(query) ||
              String(it.nombre || "").toLowerCase().includes(query) ||
              String(it.mime || "").toLowerCase().includes(query)
            );
          });

          // pedidos_json viene string en items; pero en list puede venir en item
          const pedidosText = String(cover?.pedidos_json || "");
          const inPedidos = pedidosText.toLowerCase().includes(query);

          if (!query) return true;
          return inLote || inFiles || inPedidos;
        });

        return { ...d, lotes: lotesFiltrados };
      })
      .filter((d) => (d.lotes || []).length > 0);

    if (!filtered.length) {
      renderEmpty(query ? "No hay resultados para tu búsqueda." : "No hay placas para mostrar.");
      return;
    }

    list.innerHTML = filtered
      .map((dia) => {
        const lotesHtml = (dia.lotes || [])
          .map((l) => {
            const cover = pickCoverItem(l);
            const coverImg = cover && isImage(cover.mime)
              ? `<img src="${esc(cover.url)}" alt="preview" class="h-14 w-14 rounded-lg object-cover border border-slate-200" />`
              : `<div class="h-14 w-14 rounded-lg border border-slate-200 bg-slate-50 flex items-center justify-center text-xs text-slate-500">
                   ${cover ? esc((cover.original || "").split(".").pop() || "FILE").toUpperCase().slice(0,4) : "FILE"}
                 </div>`;

            const total = (l.items || []).length;
            const created = esc(l.created_at || "");
            const title = esc(l.lote_nombre || "Sin nombre");
            const loteId = esc(l.lote_id || "");

            // Usamos el ID del primer item para consultar info del lote (porque info se resuelve por lote_id internamente)
            const infoId = cover ? Number(cover.id || 0) : 0;

            return `
              <div class="rounded-xl border border-slate-200 bg-white p-4 flex items-center justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                  ${coverImg}
                  <div class="min-w-0">
                    <div class="font-semibold text-slate-900 truncate">${title}</div>
                    <div class="text-xs text-slate-500 truncate">
                      ${total} archivo(s) • ${created ? created : "sin fecha"} • ${loteId ? `ID: ${loteId}` : ""}
                    </div>
                  </div>
                </div>

                <button
                  class="placasVerBtn rounded-lg bg-slate-900 text-white px-3 py-2 text-sm font-semibold hover:bg-slate-800"
                  data-id="${infoId}">
                  Ver
                </button>
              </div>
            `;
          })
          .join("");

        return `
          <section class="space-y-3">
            <div class="flex items-center justify-between">
              <div>
                <div class="text-sm font-semibold text-slate-900">${esc(dia.fecha)}</div>
                <div class="text-xs text-slate-500">Total archivos: ${Number(dia.total_archivos || 0)}</div>
              </div>
            </div>
            <div class="space-y-3">${lotesHtml}</div>
          </section>
        `;
      })
      .join("");

    // bind botones Ver
    $$(".placasVerBtn", list).forEach((btn) => {
      btn.addEventListener("click", async () => {
        const id = Number(btn.dataset.id || 0);
        if (!id) return;
        await openModal(id);
      });
    });
  }

  function modalOpen() {
    const wrap = $("#placasModalWrap");
    if (!wrap) return;
    wrap.classList.remove("hidden");
    document.body.classList.add("overflow-hidden");
  }

  function modalClose() {
    const wrap = $("#placasModalWrap");
    if (!wrap) return;
    wrap.classList.add("hidden");
    document.body.classList.remove("overflow-hidden");
  }

  function renderPedidos(pedidos) {
    const box = $("#placasModalPedidos");
    if (!box) return;

    const arr = Array.isArray(pedidos) ? pedidos : [];
    if (!arr.length) {
      box.innerHTML = `<div class="text-xs text-slate-500">Sin pedidos vinculados.</div>`;
      return;
    }

    // Admite formatos distintos: string, {number, cliente}, etc.
    box.innerHTML = `
      <div class="flex flex-wrap gap-2">
        ${arr.map((p) => {
          if (typeof p === "string") {
            return `<span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-700">${esc(p)}</span>`;
          }
          const num = p.number || p.numero || p.pedido || p.order_number || "";
          const cliente = p.cliente || p.customer || "";
          const label = `${num ? num : "Pedido"}${cliente ? " • " + cliente : ""}`;
          return `<span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-700">${esc(label)}</span>`;
        }).join("")}
      </div>
    `;
  }

  function fileCardHTML(f) {
    const name = f.original || f.nombre || `archivo_${f.id}`;
    const mime = f.mime || "";
    const size = formatBytes(f.size || 0);
    const created = f.created_at || "";

    const preview = isImage(mime)
      ? `<img src="${esc(f.url)}" alt="${esc(name)}" class="h-40 w-full object-cover rounded-lg border border-slate-200" />`
      : isPdf(mime, name)
        ? `<iframe src="${esc(f.url)}" class="h-40 w-full rounded-lg border border-slate-200 bg-white"></iframe>`
        : `<div class="h-40 w-full rounded-lg border border-slate-200 bg-slate-50 flex items-center justify-center text-sm text-slate-500">
             Vista previa no disponible
           </div>`;

    return `
      <div class="rounded-xl border border-slate-200 bg-white p-3">
        ${preview}

        <div class="mt-3 flex items-start justify-between gap-2">
          <div class="min-w-0">
            <div class="text-sm font-semibold text-slate-900 truncate">${esc(name)}</div>
            <div class="text-xs text-slate-500">
              ${esc(mime || "application/octet-stream")} • ${esc(size)}${created ? ` • ${esc(created)}` : ""}
            </div>
          </div>

          <div class="flex gap-2 shrink-0">
            <a href="${esc(f.url)}" target="_blank"
              class="rounded-lg border border-slate-200 px-3 py-2 text-xs hover:bg-slate-50">
              Abrir
            </a>
            <a href="${esc(f.download_url)}"
              class="rounded-lg bg-blue-600 text-white px-3 py-2 text-xs font-semibold hover:bg-blue-700">
              Descargar
            </a>
          </div>
        </div>
      </div>
    `;
  }

  async function openModal(id) {
    const title = $("#placasModalTitle");
    const meta = $("#placasModalMeta");
    const loteBox = $("#placasModalLote");
    const filesBox = $("#placasModalFiles");
    const count = $("#placasModalCount");

    if (title) title.textContent = "Cargando detalle...";
    if (meta) meta.textContent = "";
    if (loteBox) loteBox.innerHTML = "";
    if (filesBox) filesBox.innerHTML = "";
    if (count) count.textContent = "";

    modalOpen();

    try {
      const res = await fetch(API.info(id), { headers: { "Accept": "application/json" } });
      const data = await res.json().catch(() => null);

      if (!res.ok || !data || !data.success) {
        throw new Error((data && data.message) ? data.message : `Error HTTP ${res.status}`);
      }

      const lote = data.lote || {};
      const files = Array.isArray(data.files) ? data.files : [];

      if (title) title.textContent = lote.lote_nombre ? `Lote: ${lote.lote_nombre}` : "Detalle del lote";
      if (meta) meta.textContent = `${lote.lote_id ? "ID: " + lote.lote_id + " • " : ""}${lote.created_at ? lote.created_at + " • " : ""}${lote.total_files ? lote.total_files + " archivo(s)" : ""}`;
      if (count) count.textContent = `${files.length} archivo(s)`;

      if (loteBox) {
        loteBox.innerHTML = `
          <div><span class="text-slate-500">Lote ID:</span> <span class="font-medium">${esc(lote.lote_id || "-")}</span></div>
          <div><span class="text-slate-500">Nombre:</span> <span class="font-medium">${esc(lote.lote_nombre || "-")}</span></div>
          <div><span class="text-slate-500">Número placa/nota:</span> <span class="font-medium">${esc(lote.numero_placa || "-")}</span></div>
          <div><span class="text-slate-500">Fecha:</span> <span class="font-medium">${esc(lote.created_at || "-")}</span></div>
          <div><span class="text-slate-500">Total archivos:</span> <span class="font-medium">${esc(lote.total_files ?? files.length)}</span></div>
        `;
      }

      renderPedidos(lote.pedidos);

      if (filesBox) {
        filesBox.innerHTML = files.length
          ? files.map(fileCardHTML).join("")
          : `<div class="text-sm text-slate-500">Este lote no tiene archivos.</div>`;
      }

    } catch (err) {
      if (title) title.textContent = "No se pudo cargar el detalle";
      if (meta) meta.textContent = "";
      if (filesBox) filesBox.innerHTML = `<div class="text-sm text-red-600">${esc(err.message || "Error desconocido")}</div>`;
    }
  }

  async function loadAndRender() {
    try {
      const sub = $("#placasSub");
      if (sub) sub.textContent = "Cargando...";

      const res = await fetch(API.listarPorDia, { headers: { "Accept": "application/json" } });
      const data = await res.json().catch(() => null);

      if (!res.ok || !data || !data.success) {
        throw new Error((data && data.message) ? data.message : `Error HTTP ${res.status}`);
      }

      window.__PLACAS_DATA__ = data;
      renderDias(data, ($("#placasSearch")?.value || ""));

    } catch (err) {
      renderEmpty(`Error cargando placas: ${err.message || "desconocido"}`);
    }
  }

  function bindEvents() {
    const close = $("#placasModalClose");
    const ok = $("#placasModalOk");
    const wrap = $("#placasModalWrap");
    const reload = $("#placasReload");
    const search = $("#placasSearch");

    close?.addEventListener("click", modalClose);
    ok?.addEventListener("click", modalClose);

    // click fuera
    wrap?.addEventListener("click", (e) => {
      if (e.target === wrap || e.target?.classList?.contains("bg-black/40")) modalClose();
    });

    // ESC
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") modalClose();
    });

    reload?.addEventListener("click", loadAndRender);

    let t = null;
    search?.addEventListener("input", () => {
      clearTimeout(t);
      t = setTimeout(() => {
        renderDias(window.__PLACAS_DATA__ || {}, search.value || "");
      }, 150);
    });
  }

  document.addEventListener("DOMContentLoaded", async () => {
    baseLayout();
    bindEvents();
    await loadAndRender();
  });
})();
