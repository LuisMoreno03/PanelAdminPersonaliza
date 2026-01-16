// =====================================================
// REPETIR.JS  -> Muestra pedidos para repetir
// =====================================================

let nextPageInfo = null;
let isLoading = false;
let lastRenderedHash = "";
let currentPageInfo = null;

function showLoader() {
  const el = document.getElementById("globalLoader");
  if (el) el.classList.remove("hidden");
}
function hideLoader() {
  const el = document.getElementById("globalLoader");
  if (el) el.classList.add("hidden");
}

// -------------------------------
// Utils: detectar "Repetir"
// -------------------------------
function isRepetirPedido(p) {
  const estado = (p.estado || p.status || "")
    .toString()
    .trim()
    .toLowerCase();

  // tags vienen como "Urgente, Repetir, ..."
  const tagsRaw = (p.etiquetas || p.tags || "").toString();
  const tags = tagsRaw
    .split(",")
    .map(t => t.trim().toLowerCase())
    .filter(Boolean);

  return estado === "repetir" || tags.includes("repetir");
}

document.addEventListener("DOMContentLoaded", () => {
  // botones (por si no los tenías enganchados)
  const btnSig = document.getElementById("btnSiguiente");
  if (btnSig) btnSig.addEventListener("click", () => paginaSiguiente());

  currentPageInfo = null;
  cargarPedidosRepetir(currentPageInfo);
  startAutoRefresh();
});

function cargarPedidosRepetir(pageInfo = null, { silent = false } = {}) {
  currentPageInfo = pageInfo;
  if (isLoading) return;
  isLoading = true;

  if (!silent) showLoader();

  // usa el endpoint definido en la vista
  let url = (window.API && window.API.filter) ? window.API.filter : "/repetir/filter";
  if (pageInfo) url += (url.includes("?") ? "&" : "?") + `page_info=${encodeURIComponent(pageInfo)}`;

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

      try {
        return JSON.parse(text);
      } catch {
        throw new Error("Respuesta inválida: no se pudo parsear JSON.");
      }
    })
    .then((data) => {
      if (!data || !data.success) {
        actualizarTabla([]);
        setTotal(0);
        setBtnSiguiente(null);
        return;
      }

      nextPageInfo = data.next_page_info ?? null;

      // ✅ filtra correctamente aquí
      const pedidosRepetir = (data.orders || []).filter(isRepetirPedido);

      // hash para no re-render si no cambia
      const hash = JSON.stringify(
        pedidosRepetir.map((p) => ({
          id: p.id,
          estado: p.estado,
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

      actualizarTabla(pedidosRepetir);
      setTotal(pedidosRepetir.length);
      setBtnSiguiente(nextPageInfo);
    })
    .catch((err) => {
      console.error("ERROR:", err.message);
      actualizarTabla([]);
      setTotal(0);
      setBtnSiguiente(null);
    })
    .finally(() => {
      if (!silent) hideLoader();
      isLoading = false;
    });
}

function paginaSiguiente() {
  if (nextPageInfo) cargarPedidosRepetir(nextPageInfo);
}

function setTotal(n) {
  const total = document.getElementById("total-repetir");
  if (total) total.textContent = String(n);
}

function setBtnSiguiente(pageInfo) {
  const btnSig = document.getElementById("btnSiguiente");
  if (btnSig) btnSig.disabled = !pageInfo;
}

function actualizarTabla(pedidos) {
  const wrap = document.getElementById("tablaPedidos");
  if (!wrap) {
    console.error("❌ No existe #tablaPedidos");
    return;
  }

  wrap.innerHTML = "";

  if (!pedidos || !pedidos.length) {
    wrap.innerHTML = `
      <div class="px-4 py-6 text-center text-slate-500">
        No se encontraron pedidos para repetir
      </div>`;
    return;
  }

  pedidos.forEach((p) => {
    const id = p.id ?? "";

    const row = document.createElement("div");
    row.className = "orders-grid cols px-4 py-3 text-sm hover:bg-slate-50 transition";

    row.innerHTML = `
      <div class="font-extrabold">${p.numero ?? "-"}</div>
      <div class="text-slate-500">${p.fecha ?? "-"}</div>
      <div class="truncate">${p.cliente ?? "-"}</div>
      <div class="font-bold">${p.total ?? "-"}</div>

      <div class="font-extrabold">${p.estado ?? "-"}</div>

      <div class="text-slate-500">—</div>

      <div class="truncate">${formatearEtiquetas(p.etiquetas ?? "", id)}</div>

      <div class="text-center">${p.articulos ?? "-"}</div>
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
let autoRefreshEveryMs = 7000;

function startAutoRefresh() {
  stopAutoRefresh();
  autoRefreshTimer = setInterval(() => {
    if (isLoading) return;
    cargarPedidosRepetir(currentPageInfo, { silent: true });
  }, autoRefreshEveryMs);
}

function stopAutoRefresh() {
  if (autoRefreshTimer) {
    clearInterval(autoRefreshTimer);
    autoRefreshTimer = null;
  }
}
