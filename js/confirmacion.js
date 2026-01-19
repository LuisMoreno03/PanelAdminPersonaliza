// confirmacion.js (ENCAPSULADO para no chocar con dashboard.js)
(function () {
  let confirmacionCache = [];
  let isLoadingConfirmacion = false;

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

  function renderEstadoPill(estado) {
    const s = String(estado || "").toLowerCase().trim();
    const base =
      "inline-flex items-center gap-2 px-3 py-1.5 rounded-2xl border text-xs font-extrabold shadow-sm tracking-wide uppercase";

    if (s.includes("por preparar")) {
      return `<span class="${base} bg-slate-900 border-slate-700 text-white"><span class="h-2 w-2 rounded-full bg-slate-300"></span>⏳ Por preparar</span>`;
    }
    if (s.includes("confirmado")) {
      return `<span class="${base} bg-fuchsia-600 border-fuchsia-700 text-white"><span class="h-2 w-2 rounded-full bg-white"></span>✅ Confirmado</span>`;
    }
    return `<span class="${base} bg-slate-50 border-slate-200 text-slate-800"><span class="h-2 w-2 rounded-full bg-slate-400"></span>${escapeHtml(estado)}</span>`;
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

    list.innerHTML = orders
      .map((o) => {
        const id = String(o.id ?? o.shopify_order_id ?? "");
        return `
          <div class="orders-grid cols px-4 py-3 text-[13px] hover:bg-slate-50 transition">
            <div class="font-extrabold text-slate-900 whitespace-nowrap">${escapeHtml(o.numero ?? ("#" + id))}</div>
            <div class="text-slate-600 whitespace-nowrap">${escapeHtml(o.fecha ?? "-")}</div>
            <div class="min-w-0 font-semibold text-slate-800 truncate">${escapeHtml(o.cliente ?? "-")}</div>
            <div class="font-extrabold text-slate-900 whitespace-nowrap">${escapeHtml(o.total ?? "-")}</div>
            <div class="whitespace-nowrap">${renderEstadoPill(o.estado ?? "Por preparar")}</div>
            <div class="text-right whitespace-nowrap">
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

  async function cargarQueue() {
    if (isLoadingConfirmacion) return;
    isLoadingConfirmacion = true;
    showLoader();

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
      renderList(confirmacionCache);
    } catch (e) {
      console.error("cargarQueue error:", e);
      renderList([]);
    } finally {
      isLoadingConfirmacion = false;
      hideLoader();
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

  document.addEventListener("DOMContentLoaded", () => {
    document.getElementById("btnPull")?.addEventListener("click", (e) => {
      e.preventDefault();
      pull();
    });

    document.getElementById("limitSelect")?.addEventListener("change", () => {
      cargarQueue();
    });

    cargarQueue();
  });
})();
