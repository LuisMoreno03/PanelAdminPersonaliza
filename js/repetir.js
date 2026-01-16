// =====================================================
// REPETIR.JS  -> SOLO pedidos con estado "Repetir"
// =====================================================

let nextPageInfo = null;
let isLoading = false;
let lastRenderedHash = "";
let currentPageInfo = null;

// ---------------- LOADER ----------------
function showLoader() {
  document.getElementById("globalLoader")?.classList.remove("hidden");
}
function hideLoader() {
  document.getElementById("globalLoader")?.classList.add("hidden");
}

// ---------------- INIT ----------------
document.addEventListener("DOMContentLoaded", () => {
  currentPageInfo = null;
  cargarPedidosRepetir();
  startAutoRefresh();
});

// ---------------- FETCH ----------------
function cargarPedidosRepetir(pageInfo = null, { silent = false } = {}) {
  if (isLoading) return;
  isLoading = true;

  if (!silent) showLoader();

  let url = "/repetir/filter";
  if (pageInfo) url += `?page_info=${encodeURIComponent(pageInfo)}`;

  fetch(url, {
    headers: {
      Accept: "application/json",
      "X-Requested-With": "XMLHttpRequest",
    },
    credentials: "same-origin",
  })
    .then(res => res.json())
    .then(data => {
      if (!data?.success) {
        actualizarTabla([]);
        return;
      }

      nextPageInfo = data.next_page_info ?? null;

      // ✅ FILTRO REAL
      const pedidos = (data.orders || []).filter(p =>
        (p.estado || "").toLowerCase() === "repetir"
      );

      const hash = JSON.stringify(pedidos.map(p => p.id));
      if (hash === lastRenderedHash) return;
      lastRenderedHash = hash;

      actualizarTabla(pedidos);
      setTotal(pedidos.length);
      setBtnSiguiente(nextPageInfo);
    })
    .catch(err => {
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
  if (!wrap) {
    console.error("❌ No existe #tablaPedidos");
    return;
  }

  wrap.innerHTML = "";

  if (!pedidos.length) {
    wrap.innerHTML = `
      <div class="px-4 py-6 text-center text-slate-500">
        No hay pedidos para repetir
      </div>`;
    return;
  }

  pedidos.forEach(p => {
    const row = document.createElement("div");
    row.className = "orders-grid cols px-4 py-3 text-sm hover:bg-slate-50";

    row.innerHTML = `
      <div class="font-extrabold">${p.numero}</div>
      <div>${p.fecha}</div>
      <div class="truncate">${p.cliente}</div>
      <div class="font-bold">${p.total}</div>
      <div class="font-extrabold text-blue-700">Repetir</div>
      <div>—</div>
      <div>${formatearEtiquetas(p.etiquetas, p.id)}</div>
      <div>${p.articulos ?? "-"}</div>
      <div>${p.estado_envio ?? "-"}</div>
      <div>${p.forma_envio ?? "-"}</div>
      <div class="text-right">
        <button onclick="verDetalles('${p.id}')" class="text-blue-600 underline">
          Ver
        </button>
      </div>
    `;
    wrap.appendChild(row);
  });
}

// ---------------- HELPERS ----------------
function setTotal(n) {
  const el = document.getElementById("total-repetir");
  if (el) el.textContent = String(n);
}


function setBtnSiguiente(v) {
  const btn = document.getElementById("btnSiguiente");
  if (btn) btn.disabled = !v;
}


// ---------------- POLLING ----------------
let autoRefreshTimer = null;
function startAutoRefresh() {
  stopAutoRefresh();
  autoRefreshTimer = setInterval(() => {
    if (!isLoading) cargarPedidosRepetir(currentPageInfo, { silent: true });
  }, 7000);
}
function stopAutoRefresh() {
  if (autoRefreshTimer) clearInterval(autoRefreshTimer);
}
