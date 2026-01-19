// confirmacion.js (ENCAPSULADO para no chocar con dashboard.js)
(function () {
  let confirmacionCache = [];
  let isLoadingConfirmacion = false;
  let liveInterval = null;

  function showLoader() {
    const el = document.getElementById("globalLoader");
    if (el) el.classList.remove("hidden");
  }
  function hideLoader() {
    const el = document.getElementById("globalLoader");
    if (el) el.classList.add("hidden");
  }

  function csrfHeadersJson() {
    const headers = { Accept: "application/json", "Content-Type": "application/json" };
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content");
    const csrfHeader = document.querySelector('meta[name="csrf-header"]')?.getAttribute("content") || "X-CSRF-TOKEN";
    if (csrfToken) headers[csrfHeader] = csrfToken;
    return headers;
  }

  function escapeHtml(str) {
    return String(str ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function isExpress(o) {
    const forma = String(o?.forma_envio || "").toLowerCase();
    const tags  = String(o?.etiquetas || "").toLowerCase();
    return forma.includes("express") || tags.includes("express") || tags.includes("urgente");
  }

  function renderEntregaPill(estadoEnvio) {
    // si dashboard.js lo expuso, úsalo
    if (typeof window.renderEntregaPill === "function") return window.renderEntregaPill(estadoEnvio);

    const s = String(estadoEnvio ?? "").toLowerCase().trim();
    if (!s || s === "null" || s === "-" || s === "unfulfilled") {
      return `<span class="inline-flex items-center px-3 py-1.5 rounded-full text-[11px] font-extrabold
        bg-slate-100 text-slate-800 border border-slate-200 whitespace-nowrap">⏳ Sin preparar</span>`;
    }
    return `<span class="inline-flex items-center px-3 py-1.5 rounded-full text-[11px] font-extrabold
      bg-white text-slate-900 border border-slate-200 whitespace-nowrap">📦 ${escapeHtml(estadoEnvio)}</span>`;
  }

  function renderEstadoPill(estado) {
    const s = String(estado || "").toLowerCase().trim();
    const base =
      "inline-flex items-center gap-2 px-3 py-1.5 rounded-2xl border text-xs font-extrabold shadow-sm tracking-wide uppercase";

    if (s.includes("por preparar")) {
      return `<span class="${base} bg-slate-900 border-slate-700 text-white">
        <span class="h-2 w-2 rounded-full bg-slate-300"></span>⏳ Por preparar</span>`;
    }
    if (s.includes("confirmado")) {
      return `<span class="${base} bg-fuchsia-600 border-fuchsia-700 text-white">
        <span class="h-2 w-2 rounded-full bg-white"></span>✅ Confirmado</span>`;
    }
    if (s.includes("faltan")) {
      return `<span class="${base} bg-yellow-400 border-yellow-500 text-black">
        <span class="h-2 w-2 rounded-full bg-black/70"></span>⚠️ Faltan archivos</span>`;
    }
    return `<span class="${base} bg-slate-50 border-slate-200 text-slate-800">
      <span class="h-2 w-2 rounded-full bg-slate-400"></span>${escapeHtml(estado)}</span>`;
  }

  function renderList(orders) {
    const list = document.getElementById("confirmacionList");
    const empty = document.getElementById("confirmacionEmpty");
    if (!list) return;

    if (!orders || !orders.length) {
      list.innerHTML = "";
      empty?.classList.remove("hidden");
      return;
    }
    empty?.classList.add("hidden");

    // ✅ Express primero (por si backend no lo ordenó)
    const sorted = [...orders].sort((a, b) => Number(isExpress(b)) - Number(isExpress(a)));

    list.innerHTML = sorted
      .map((o) => {
        const id = String(o.id ?? o.shopify_order_id ?? "");
        const expressBadge = isExpress(o)
          ? `<span class="ml-2 inline-flex items-center px-2 py-1 rounded-full text-[10px] font-extrabold
                bg-rose-100 text-rose-900 border border-rose-200 uppercase">Express</span>`
          : "";

        return `
          <div class="px-4 py-3 hover:bg-slate-50 transition grid grid-cols-1 lg:grid-cols-12 gap-3 items-center">
            <div class="lg:col-span-2 font-extrabold text-slate-900 whitespace-nowrap">
              ${escapeHtml(o.numero ?? ("#" + id))} ${expressBadge}
            </div>

            <div class="lg:col-span-2 text-slate-600 whitespace-nowrap">
              ${escapeHtml(o.fecha ?? "-")}
            </div>

            <div class="lg:col-span-3 min-w-0 font-semibold text-slate-800 truncate">
              ${escapeHtml(o.cliente ?? "-")}
            </div>

            <div class="lg:col-span-1 font-extrabold text-slate-900 whitespace-nowrap">
              ${escapeHtml(o.total ?? "-")}
            </div>

            <div class="lg:col-span-2 whitespace-nowrap">
              ${renderEstadoPill(o.estado ?? "Por preparar")}
            </div>

            <div class="lg:col-span-1 whitespace-nowrap">
              ${renderEntregaPill(o.estado_envio ?? null)}
            </div>

            <div class="lg:col-span-1 text-right whitespace-nowrap">
              <button type="button"
                onclick="window.verDetalles && window.verDetalles('${id}')"
                class="px-3 py-2 rounded-2xl bg-blue-600 text-white text-[11px] font-extrabold uppercase tracking-wide hover:bg-blue-700 transition">
                Ver detalles →
              </button>
            </div>
          </div>
        `;
      })
      .join("");
  }

  async function cargarQueue({ silent = false } = {}) {
    if (isLoadingConfirmacion) return;
    isLoadingConfirmacion = true;
    if (!silent) showLoader();

    try {
      const limit = Number(document.getElementById("limitSelect")?.value || 10);

      const url = new URL(window.API_CONFIRMACION.myQueue, window.location.origin);
      url.searchParams.set("limit", String(limit));

      const r = await fetch(url.toString(), {
        headers: { Accept: "application/json" },
        credentials: "same-origin",
      });
      const d = await r.json().catch(() => null);

      if (!r.ok || !d?.success) {
        confirmacionCache = [];
        renderList([]);
        return;
      }

      confirmacionCache = Array.isArray(d.orders) ? d.orders : [];

      // ✅ por seguridad: solo mostramos por preparar
      confirmacionCache = confirmacionCache.filter(o =>
        String(o?.estado || "").toLowerCase().includes("por preparar")
      );

      renderList(confirmacionCache);
    } catch (e) {
      console.error("cargarQueue error:", e);
      renderList([]);
    } finally {
      isLoadingConfirmacion = false;
      if (!silent) hideLoader();
    }
  }

  async function pull() {
    try {
      const r = await fetch(window.API_CONFIRMACION.pull, {
        method: "POST",
        headers: csrfHeadersJson(),
        credentials: "same-origin",
        body: JSON.stringify({}),
      });

      const d = await r.json().catch(() => null);
      if (!r.ok || !d?.success) {
        alert(d?.message || "No se pudo hacer pull");
        return;
      }

      await cargarQueue();
    } catch (e) {
      console.error("pull error:", e);
      alert("Error haciendo pull");
    }
  }

  function startLive(ms = 25000) {
    if (liveInterval) clearInterval(liveInterval);
    liveInterval = setInterval(() => {
      if (!isLoadingConfirmacion) cargarQueue({ silent: true });
    }, ms);
  }

  function setupRealtimeSync() {
    // ✅ Escucha cambios de estado desde otras pestañas (tu dashboard.js ya emite esto)
    try {
      if ("BroadcastChannel" in window) {
        const bc = new BroadcastChannel("panel_pedidos");
        bc.onmessage = (ev) => {
          const msg = ev?.data;
          if (msg?.type === "estado_changed") {
            // si un pedido se confirmó, en confirmación debe desaparecer
            cargarQueue({ silent: true });
          }
        };
      }
    } catch {}

    // fallback: storage event
    window.addEventListener("storage", (e) => {
      if (e.key === "pedido_estado_changed") {
        cargarQueue({ silent: true });
      }
    });
  }

  document.addEventListener("DOMContentLoaded", () => {
    document.getElementById("btnPull")?.addEventListener("click", (e) => {
      e.preventDefault();
      pull();
    });

    document.getElementById("limitSelect")?.addEventListener("change", () => {
      cargarQueue();
    });

    setupRealtimeSync();
    cargarQueue();
    startLive(30000);
  });
})();
