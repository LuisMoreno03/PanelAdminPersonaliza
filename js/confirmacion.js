// confirmacion.js
// Encapsulado para NO chocar con dashboard.js
(function () {

  let isLoading = false;
  let cache = [];

  /* ===============================
     Helpers
  =============================== */

  function qs(id) {
    return document.getElementById(id);
  }

  function showLoader() {
    const el = qs("globalLoader");
    if (el) el.classList.remove("hidden");
  }

  function hideLoader() {
    const el = qs("globalLoader");
    if (el) el.classList.add("hidden");
  }

  function escapeHtml(str) {
    return String(str ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function csrfHeadersJson() {
    const headers = {
      "Accept": "application/json",
      "Content-Type": "application/json",
    };

    const token = document
      .querySelector('meta[name="csrf-token"]')
      ?.getAttribute("content");

    const header =
      document
        .querySelector('meta[name="csrf-header"]')
        ?.getAttribute("content") || "X-CSRF-TOKEN";

    if (token) headers[header] = token;
    return headers;
  }

  /* ===============================
     Render
  =============================== */

  function renderEstado(estado) {
    const s = String(estado || "").toLowerCase();

    if (s.includes("por preparar")) {
      return `
        <span class="inline-flex items-center gap-2 px-3 py-1.5
          rounded-2xl bg-slate-900 text-white text-xs font-extrabold">
          ⏳ Por preparar
        </span>`;
    }

    return `
      <span class="inline-flex items-center gap-2 px-3 py-1.5
        rounded-2xl bg-slate-200 text-slate-800 text-xs font-extrabold">
        ${escapeHtml(estado)}
      </span>`;
  }

  function renderList(orders) {
    const list = qs("confirmacionList");
    const empty = qs("confirmacionEmpty");

    if (!list) return;

    if (!orders || orders.length === 0) {
      list.innerHTML = "";
      if (empty) empty.classList.remove("hidden");
      return;
    }

    if (empty) empty.classList.add("hidden");

    list.innerHTML = orders
      .map((o) => {
        const id = String(o.id || "");
        return `
          <div class="grid grid-cols-[140px_120px_1fr_120px_160px_120px]
                      px-4 py-3 hover:bg-slate-50 transition">

            <div class="font-extrabold text-slate-900">
              ${escapeHtml(o.numero ?? "#" + id)}
            </div>

            <div class="text-slate-600">
              ${escapeHtml(o.fecha ?? "-")}
            </div>

            <div class="font-semibold text-slate-800 truncate">
              ${escapeHtml(o.cliente ?? "-")}
            </div>

            <div class="font-extrabold text-slate-900">
              ${escapeHtml(o.total ?? "-")}
            </div>

            <div>
              ${renderEstado(o.estado)}
            </div>

            <div class="text-right">
              <button
                class="px-3 py-2 rounded-2xl bg-blue-600
                       text-white text-[11px] font-extrabold
                       hover:bg-blue-700 transition"
                onclick="window.verDetalles && window.verDetalles('${id}')">
                Ver detalles →
              </button>
            </div>

          </div>
        `;
      })
      .join("");
  }

  /* ===============================
     API Calls
  =============================== */

  async function cargarQueue() {
    if (isLoading) return;
    isLoading = true;
    showLoader();

    try {
      const limit = Number(qs("limitSelect")?.value || 10);

      const url = new URL(
        window.API_CONFIRMACION.myQueue,
        window.location.origin
      );
      url.searchParams.set("limit", String(limit));

      const r = await fetch(url.toString(), {
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      });

      const d = await r.json().catch(() => null);

      if (!r.ok || !d || !d.success) {
        renderList([]);
        return;
      }

      cache = Array.isArray(d.orders) ? d.orders : [];
      renderList(cache);

    } catch (err) {
      console.error("Confirmación cargarQueue error:", err);
      renderList([]);
    } finally {
      isLoading = false;
      hideLoader();
    }
  }

  async function pullPedido() {
    if (isLoading) return;

    try {
      const r = await fetch(window.API_CONFIRMACION.pull, {
        method: "POST",
        credentials: "same-origin",
        headers: csrfHeadersJson(),
        body: JSON.stringify({}),
      });

      const d = await r.json().catch(() => null);

      if (!r.ok || !d?.success) {
        alert(d?.message || "No se pudo hacer pull");
        return;
      }

      await cargarQueue();

    } catch (err) {
      console.error("Confirmación pull error:", err);
      alert("Error haciendo pull");
    }
  }

  /* ===============================
     Init
  =============================== */

  document.addEventListener("DOMContentLoaded", () => {
    qs("btnPull")?.addEventListener("click", (e) => {
      e.preventDefault();
      pullPedido();
    });

    qs("limitSelect")?.addEventListener("change", () => {
      cargarQueue();
    });

    cargarQueue();
  });

})();
