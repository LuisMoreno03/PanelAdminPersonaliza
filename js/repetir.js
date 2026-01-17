// =====================================================
// REPETIR.JS  -> SOLO pedidos con estado "Repetir"
// Pagina por "page" (NO page_info)
// =====================================================

let isLoading = false;
let lastRenderedHash = "";
let currentPage = 1;
let totalPages = 1;

// ---------------- LOADER then----------------
function showLoader() {
  document.getElementById("globalLoader")?.classList.remove("hidden");
}
function hideLoader() {
  document.getElementById("globalLoader")?.classList.add("hidden");
}

// ---------------- INIT ----------------
document.addEventListener("DOMContentLoaded", () => {
  wirePagination();
  cargarPedidosRepetir(1);
  startAutoRefresh();
});

// ---------------- PAGINATION UI ----------------
function wirePagination() {
  const btnPrev = document.getElementById("btnAnterior");
  const btnNext = document.getElementById("btnSiguiente");

  btnPrev?.addEventListener("click", () => {
    if (isLoading) return;
    if (currentPage <= 1) return;
    cargarPedidosRepetir(currentPage - 1);
  });

  btnNext?.addEventListener("click", () => {
    if (isLoading) return;
    if (currentPage >= totalPages) return;
    cargarPedidosRepetir(currentPage + 1);
  });
}

function setPagination({ page, total_pages }) {
  currentPage = Number(page || 1);
  totalPages = Number(total_pages || 1);

  const pill = document.getElementById("pillPagina");
  if (pill) pill.textContent = `Página ${currentPage}`;

  const pillTotal = document.getElementById("pillPaginaTotal");
  if (pillTotal) pillTotal.textContent = `Página ${totalPages}`;

  const btnPrev = document.getElementById("btnAnterior");
  const btnNext = document.getElementById("btnSiguiente");

  if (btnPrev) {
    btnPrev.disabled = currentPage <= 1;
    btnPrev.classList.toggle("opacity-50", btnPrev.disabled);
    btnPrev.classList.toggle("cursor-not-allowed", btnPrev.disabled);
  }

  if (btnNext) {
    btnNext.disabled = currentPage >= totalPages;
    btnNext.classList.toggle("opacity-50", btnNext.disabled);
    btnNext.classList.toggle("cursor-not-allowed", btnNext.disabled);
  }
}

function cargarPedidosRepetir(page = 1, { silent = false } = {}) {
  if (isLoading) return;
  isLoading = true;

  if (!silent) showLoader();

  const baseUrl = window.API?.filter || "/repetir/filter";
  const url = `${baseUrl}?page=${encodeURIComponent(page || 1)}`;

  fetch(url, {
    headers: {
      Accept: "application/json",
      "X-Requested-With": "XMLHttpRequest",
    },
    credentials: "same-origin",
  })
    .then((res) => res.json())
    .then((data) => {
      console.log("ORDERS RAW:", data.orders?.map(o => ({ id: o.id, estado: o.estado })));

      if (!data?.success) {
        actualizarTabla([]);
        setPagination({ page: 1, total_pages: 1 });
        return;
      }

      const pedidos = data.orders || [];


      const hash = JSON.stringify(pedidos.map((p) => p.id));
      if (hash === lastRenderedHash && Number(data.page) === currentPage) return;
      lastRenderedHash = hash;

      actualizarTabla(pedidos);

      setPagination({
        page: data.page,
        total_pages: data.total_pages || 1,
      });
    })
    .catch((err) => {
      console.error("❌ Error:", err);
      actualizarTabla([]);
    })
    .finally(() => {
      if (!silent) hideLoader();
      isLoading = false;
    });
}


// ---------------- TABLE ----------------
function actualizarTabla(pedidos) {
  const wrap = document.getElementById("tablaPedidos");
  if (!wrap) return;

  wrap.innerHTML = "";

  if (!pedidos.length) {
    wrap.innerHTML = `
      <div class="px-4 py-6 text-center text-slate-500">
        No hay pedidos para repetir
      </div>`;
    return;
  }

  pedidos.forEach((p) => {
    const row = document.createElement("div");
    row.className = "orders-grid cols px-4 py-3 text-sm hover:bg-slate-50";

    row.innerHTML = `
      <div class="font-extrabold">${p.numero ?? "-"}</div>
      <div>${p.fecha ?? "-"}</div>
      <div class="truncate">${p.cliente ?? "-"}</div>
      <div class="font-bold">${p.total ?? "-"}</div>
      <div class="font-extrabold text-blue-700">Repetir</div>
      <div>—</div>
      <div>${typeof formatearEtiquetas === "function" ? formatearEtiquetas(p.etiquetas, p.id) : (p.etiquetas ?? "")}</div>
      <div class="text-center">${p.articulos ?? "-"}</div>
      <div>${p.estado_envio ?? "-"}</div>
      <div class="metodo-entrega">${p.forma_envio ?? "-"}</div>
      <div class="text-right">
        <button onclick="verDetalles('${p.id}')" class="text-blue-600 underline">
          Ver
        </button>
      </div>
    `;
    wrap.appendChild(row);
  });
}

// ---------------- POLLING ----------------
let autoRefreshTimer = null;
function startAutoRefresh() {
  stopAutoRefresh();
  autoRefreshTimer = setInterval(() => {
    if (!isLoading) cargarPedidosRepetir(currentPage, { silent: true });
  }, 7000);
}
function stopAutoRefresh() {
  if (autoRefreshTimer) clearInterval(autoRefreshTimer);
}
