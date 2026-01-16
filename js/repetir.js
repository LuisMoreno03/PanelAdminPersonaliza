// =====================================================
// REPETIR.JS  -> Muestra pedidos para repetir
// =====================================================

let nextPageInfo = null;
let isLoading = false;
let lastRenderedHash = "";


function showLoader() {
  const el = document.getElementById("globalLoader");
  if (el) el.classList.remove("hidden");
}
function hideLoader() {
  const el = document.getElementById("globalLoader");
  if (el) el.classList.add("hidden");
}

document.addEventListener("DOMContentLoaded", () => {
  currentPageInfo = null;
  cargarPedidosPreparados(currentPageInfo);
  startAutoRefresh(); // 👈 tiempo real
});


function cargarPedidosPreparados(pageInfo = null, { silent = false } = {}) {
  currentPageInfo = pageInfo; // 👈 guarda la página actual
  if (isLoading) return;
  isLoading = true;

  if (!silent) showLoader();


  const base = window.BASE_URL || ""; // si no existe, usa root
  let url = `${base}/repetir/filter`;
  if (pageInfo) url += `?page_info=${encodeURIComponent(pageInfo)}`;

  fetch(url, {
    headers: {
      Accept: "application/json",
      "X-Requested-With": "XMLHttpRequest",
    },
    credentials: "same-origin",
  })
    .then(async (res) => {
      const text = await res.text();

      console.log("URL:", url);
      console.log("STATUS:", res.status);
      console.log("RAW:", text.slice(0, 300));

      if (text.trim().startsWith("<")) {
        throw new Error("El endpoint devolvió HTML (no JSON). Revisa sesión/ruta/controlador.");
      }

      let data;
      try {
        data = JSON.parse(text);
      } catch {
        throw new Error("Respuesta inválida: no se pudo parsear JSON.");
      }

      return data;
    })
    .then((data) => {
  if (!data || !data.success) {
    actualizarTabla([]);
    setTotal(0);
    setBtnSiguiente(null);
    return;
  }

  nextPageInfo = data.next_page_info ?? null;

  const pedidos = data.orders || [];

  const hash = JSON.stringify(
    pedidos.map((p) => ({
      id: p.id,
      estado: p.estado ?? p.status,
      etiquetas: p.etiquetas,
      total: p.total,
      fecha: p.fecha,
    }))
  );

  if (hash === lastRenderedHash) {
    setBtnSiguiente(nextPageInfo);
    return;
  }
  lastRenderedHash = hash;

  actualizarTabla(pedidos);
  setTotal(pedidos.length);
  setBtnSiguiente(nextPageInfo);
})

  wrap.innerHTML = "";

  if (!pedidos || !pedidos.length) {
    wrap.innerHTML = `
      <div class="px-4 py-6 text-center text-slate-500">
        No se encontraron pedidos para repetir
      </div>`;
    return;
  }

  pedidos.forEach((p) => {
    const id = p.id ?? p.order_id ?? "";

    const row = document.createElement("div");
    row.className = "orders-grid cols px-4 py-3 text-sm hover:bg-slate-50 transition";

    row.innerHTML = `
      <div class="font-extrabold">${p.numero ?? p.name ?? "-"}</div>
      <div class="text-slate-500">${p.fecha ?? p.created_at ?? "-"}</div>
      <div class="truncate">${p.cliente ?? p.customer ?? "-"}</div>
      <div class="font-bold">${p.total ?? p.total_price ?? "-"}</div>

      <div class="font-extrabold">${p.estado ?? p.status ?? p.fulfillment_status ?? "-"}</div>

      <div class="text-slate-500">—</div>

      <div class="truncate">${formatearEtiquetas(p.etiquetas ?? p.tags, id)}</div>

      <div class="text-center">${p.articulos ?? p.line_items_count ?? "-"}</div>
      <div>${p.estado_envio ?? "-"}</div>
      <div class="metodo-entrega">${p.forma_envio ?? "-"}</div>

      <div class="text-right">
        <button onclick="verDetalles && verDetalles('${id}')"
                class="text-blue-600 font-bold underline">
          Ver detalles
        </button>
      </div>
    `;

    wrap.appendChild(row);
  });
}


// =====================================================
// Etiquetas
// =====================================================

function formatearEtiquetas(etiquetas, orderId) {
  if (!etiquetas) {
    return `<button onclick="abrirModalEtiquetas && abrirModalEtiquetas('${orderId}', '')"
            class="text-blue-600 underline">Agregar</button>`;
  }

  const lista = String(etiquetas).split(",").map((t) => t.trim()).filter(Boolean);

  return `
    <div class="flex flex-wrap gap-2">
      ${lista
        .map(
          (tag) => `
          <span class="px-2 py-1 rounded-full text-xs font-semibold ${colorEtiqueta(tag)}">
            ${tag}
          </span>`
        )
        .join("")}
      <button onclick="abrirModalEtiquetas && abrirModalEtiquetas('${orderId}', '${escapeQuotes(etiquetas)}')"
              class="text-blue-600 underline text-xs ml-2">
        Editar
      </button>
    </div>`;
}

function escapeQuotes(str) {
  return String(str).replace(/'/g, "\\'");
}

function colorEtiqueta(tag) {
  tag = String(tag).toLowerCase().trim();
  if (tag.startsWith("d.")) return "bg-green-200 text-green-900";
  if (tag.startsWith("p.")) return "bg-yellow-200 text-yellow-900";
  return "bg-gray-200 text-gray-700";
}

// =====================================================
// TIEMPO REAL (POLLING)
// =====================================================
let autoRefreshTimer = null;
let autoRefreshEveryMs = 7000; // 7s (puedes bajar a 3-5s si quieres)
let currentPageInfo = null;    // guardamos la página actual

function startAutoRefresh() {
  stopAutoRefresh();
  autoRefreshTimer = setInterval(() => {
    if (isLoading) return;
    cargarPedidosPreparados(currentPageInfo, { silent: true });
  }, autoRefreshEveryMs);
}


function stopAutoRefresh() {
  if (autoRefreshTimer) {
    clearInterval(autoRefreshTimer);
    autoRefreshTimer = null;
  }
}
