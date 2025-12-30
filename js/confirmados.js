// =====================================================
// CONFIRMADOS.JS  -> Solo muestra pedidos "Preparado"
// =====================================================

let nextPageInfo = null;
let isLoading = false;

// Loader global (si existe en la vista)
function showLoader() {
  const el = document.getElementById("globalLoader");
  if (el) el.classList.remove("hidden");
}
function hideLoader() {
  const el = document.getElementById("globalLoader");
  if (el) el.classList.add("hidden");
}

// =====================================================
// INICIALIZAR
// =====================================================
document.addEventListener("DOMContentLoaded", () => {
  cargarPedidosPreparados();
});

// =====================================================
// CARGAR PEDIDOS (solo preparados)
// =====================================================
function cargarPedidosPreparados(pageInfo = null) {
  if (isLoading) return;
  isLoading = true;
  const Preparados = (data.orders || []).filter((p) => {
  const estado = (p.estado || "").trim().toLowerCase();
  return estado === "preparado" || estado === "preparados";
});

  showLoader();

  let url = "/dashboard/filter";
  if (pageInfo) url += "?page_info=" + encodeURIComponent(pageInfo);

  fetch(url)
    .then((res) => res.json())
    .then((data) => {
      if (!data.success) return;

      nextPageInfo = data.next_page_info ?? null;

      // ✅ Filtrar SOLO preparados
      const Preparados = (data.orders || []).filter(
        (p) => (p.estado || "").trim().toLowerCase() === "preparado"
      );

      actualizarTabla(Preparados);

      const btnSig = document.getElementById("btnSiguiente");
      if (btnSig) btnSig.disabled = !nextPageInfo;

      // ✅ contador mostrado (filtrado)
      const total = document.getElementById("total-pedidos");
      if (total) total.textContent = Preparados.length;
    })
    .catch((err) => console.error("Error cargando pedidos preparados:", err))
    .finally(() => {
      hideLoader();
      isLoading = false;
    });
}

// =====================================================
// SIGUIENTE PÁGINA
// =====================================================
function paginaSiguiente() {
  if (nextPageInfo) cargarPedidosPreparados(nextPageInfo);
}

// =====================================================
// TABLA PRINCIPAL
// =====================================================
function actualizarTabla(pedidos) {
  const tbody = document.getElementById("tablaPedidos");
  if (!tbody) return;

  tbody.innerHTML = "";

  if (!pedidos || !pedidos.length) {
    tbody.innerHTML = `
      <tr><td colspan="10" class="py-4 text-center text-gray-500">
        No se encontraron pedidos preparados
      </td></tr>`;
    return;
  }

  pedidos.forEach((p) => {
    tbody.innerHTML += `
      <tr class="border-b hover:bg-gray-50 transition">
        <td class="py-2 px-4">${p.numero}</td>
        <td class="py-2 px-4">${p.fecha}</td>
        <td class="py-2 px-4">${p.cliente}</td>
        <td class="py-2 px-4">${p.total}</td>

        <td class="py-2 px-2">
          <button onclick="abrirModal(${p.id})" class="font-semibold">
            ${p.estado}
          </button>
        </td>

        <!-- ✅ CORREGIDO: antes usabas pedido.id/pedido -->
        <td class="py-3 px-4" data-lastchange="${p.id}">
          ${renderLastChange(p)}
        </td>

        <td class="py-2 px-4">${formatearEtiquetas(p.etiquetas, p.id)}</td>
        <td class="py-2 px-4">${p.articulos}</td>
        <td class="py-2 px-4">${p.estado_envio}</td>
        <td class="py-2 px-4">${p.forma_envio}</td>

        <td class="py-2 px-4">
          <button onclick="verDetalles(${p.id})" class="text-blue-600 underline">
            Ver detalles
          </button>
        </td>
      </tr>
    `;
  });
}

{// =====================================================
// CONFIRMADOS.JS  -> Solo muestra pedidos "Preparado"
// =====================================================

let nextPageInfo = null;
let isLoading = false;

// Loader global (si existe en la vista)
function showLoader() {
  const el = document.getElementById("globalLoader");
  if (el) el.classList.remove("hidden");
}
function hideLoader() {
  const el = document.getElementById("globalLoader");
  if (el) el.classList.add("hidden");
}

// =====================================================
// INICIALIZAR
// =====================================================
document.addEventListener("DOMContentLoaded", () => {
  cargarPedidosPreparados();
});

// =====================================================
// CARGAR PEDIDOS (solo preparados)
// =====================================================
function cargarPedidosPreparados(pageInfo = null) {
  if (isLoading) return;
  isLoading = true;

  showLoader();

  let url = "/dashboard/filter";
  if (pageInfo) url += "?page_info=" + encodeURIComponent(pageInfo);

  fetch(url)
    .then((res) => res.json())
    .then((data) => {
      if (!data.success) return;

      nextPageInfo = data.next_page_info ?? null;

      // ✅ Filtrar SOLO preparados
      const Preparados = (data.orders || []).filter(
        (p) => (p.estado || "").trim().toLowerCase() === "preparado"
      );

      actualizarTabla(Preparados);

      const btnSig = document.getElementById("btnSiguiente");
      if (btnSig) btnSig.disabled = !nextPageInfo;

      // ✅ contador mostrado (filtrado)
      const total = document.getElementById("total-pedidos");
      if (total) total.textContent = Preparados.length;
    })
    .catch((err) => console.error("Error cargando pedidos preparados:", err))
    .finally(() => {
      hideLoader();
      isLoading = false;
    });
}

// =====================================================
// SIGUIENTE PÁGINA
// =====================================================
function paginaSiguiente() {
  if (nextPageInfo) cargarPedidosPreparados(nextPageInfo);
}

// =====================================================
// TABLA PRINCIPAL
// =====================================================
function actualizarTabla(pedidos) {
  const tbody = document.getElementById("tablaPedidos");
  if (!tbody) return;

  tbody.innerHTML = "";

  if (!pedidos || !pedidos.length) {
    tbody.innerHTML = `
      <tr><td colspan="10" class="py-4 text-center text-gray-500">
        No se encontraron pedidos preparados
      </td></tr>`;
    return;
  }

  pedidos.forEach((p) => {
    tbody.innerHTML += `
      <tr class="border-b hover:bg-gray-50 transition">
        <td class="py-2 px-4">${p.numero}</td>
        <td class="py-2 px-4">${p.fecha}</td>
        <td class="py-2 px-4">${p.cliente}</td>
        <td class="py-2 px-4">${p.total}</td>

        <td class="py-2 px-2">
          <button onclick="abrirModal(${p.id})" class="font-semibold">
            ${p.estado}
          </button>
        </td>

        <!-- ✅ CORREGIDO: antes usabas pedido.id/pedido -->
        <td class="py-3 px-4" data-lastchange="${p.id}">
          ${renderLastChange(p)}
        </td>

        <td class="py-2 px-4">${formatearEtiquetas(p.etiquetas, p.id)}</td>
        <td class="py-2 px-4">${p.articulos}</td>
        <td class="py-2 px-4">${p.estado_envio}</td>
        <td class="py-2 px-4">${p.forma_envio}</td>

        <td class="py-2 px-4">
          <button onclick="verDetalles(${p.id})" class="text-blue-600 underline">
            Ver detalles
          </button>
        </td>
      </tr>
    `;
  });
}

// =====================================================
// UTILIDADES que ya usas en tu dashboard.js
// 👉 Si ya las tienes globales por otro script, puedes borrar estas copias.
// =====================================================

function formatearEtiquetas(etiquetas, orderId) {
  if (!etiquetas) {
    return `<button onclick="abrirModalEtiquetas(${orderId}, '')"
            class="text-blue-600 underline">Agregar</button>`;
  }

  let lista = etiquetas.split(",").map((t) => t.trim());

  return `
    <div class="flex flex-wrap gap-2">
      ${lista
        .map(
          (tag) => `
          <span class="px-2 py-1 rounded-full text-xs font-semibold ${colorEtiqueta(
            tag
          )}">
            ${tag}
          </span>`
        )
        .join("")}
      <button onclick="abrirModalEtiquetas(${orderId}, '${etiquetas}')"
              class="text-blue-600 underline text-xs ml-2">
        Editar
      </button>
    </div>`;
}

function colorEtiqueta(tag) {
  tag = tag.toLowerCase().trim();
  if (tag.startsWith("d.")) return "bg-green-200 text-green-900";
  if (tag.startsWith("p.")) return "bg-yellow-200 text-yellow-900";
  return "bg-gray-200 text-gray-700";
}

function formatDateFull(dtStr) {
  if (!dtStr) return "-";
  const d = new Date(dtStr.replace(" ", "T"));
  if (isNaN(d)) return dtStr;

  const fecha = d.toLocaleDateString("es-ES", {
    weekday: "long",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  });
  const hora = d.toLocaleTimeString("es-ES", {
    hour12: false,
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
  });
  return `${fecha} ${hora}`;
}

function timeAgo(dtStr) {
  if (!dtStr) return "";
  const d = new Date(dtStr.replace(" ", "T"));
  if (isNaN(d)) return "";
  const diff = Date.now() - d.getTime();
  const sec = Math.floor(diff / 1000);
  const min = Math.floor(sec / 60);
  const hr = Math.floor(min / 60);
  const day = Math.floor(hr / 24);
  if (day > 0) return `${day}d ${hr % 24}h`;
  if (hr > 0) return `${hr}h ${min % 60}m`;
  if (min > 0) return `${min}m`;
  return `${sec}s`;

}

function renderLastChange(p) {
  const info = p.last_status_change;
  if (!info || !info.changed_at)
    return `<span class="text-gray-400 text-sm">—</span>`;

  return `
    <div class="text-sm">
      <div class="font-semibold text-gray-800">${info.user_name || "—"}</div>
      <div class="text-gray-600">${formatDateFull(info.changed_at)}</div>
      <div class="text-xs text-gray-500">Hace ${timeAgo(info.changed_at)}</div>
    </div>
  `;

}


// =====================================================
// UTILIDADES que ya usas en tu dashboard.js
// 👉 Si ya las tienes globales por otro script, puedes borrar estas copias.
// =====================================================

function formatearEtiquetas(etiquetas, orderId) {
  if (!etiquetas) {
    return `<button onclick="abrirModalEtiquetas(${orderId}, '')"
            class="text-blue-600 underline">Agregar</button>`;
  }

  let lista = etiquetas.split(",").map((t) => t.trim());

  return `
    <div class="flex flex-wrap gap-2">
      ${lista
        .map(
          (tag) => `
          <span class="px-2 py-1 rounded-full text-xs font-semibold ${colorEtiqueta(
            tag
          )}">
            ${tag}
          </span>`
        )
        .join("")}
      <button onclick="abrirModalEtiquetas(${orderId}, '${etiquetas}')"
              class="text-blue-600 underline text-xs ml-2">
        Editar
      </button>
    </div>`;
}

function colorEtiqueta(tag) {
  tag = tag.toLowerCase().trim();
  if (tag.startsWith("d.")) return "bg-green-200 text-green-900";
  if (tag.startsWith("p.")) return "bg-yellow-200 text-yellow-900";
  return "bg-gray-200 text-gray-700";
}

function formatDateFull(dtStr) {
  if (!dtStr) return "-";
  const d = new Date(dtStr.replace(" ", "T"));
  if (isNaN(d)) return dtStr;

  const fecha = d.toLocaleDateString("es-ES", {
    weekday: "long",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  });
  const hora = d.toLocaleTimeString("es-ES", {
    hour12: false,
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
  });
  return `${fecha} ${hora}`;
}

function timeAgo(dtStr) {
  if (!dtStr) return "";
  const d = new Date(dtStr.replace(" ", "T"));
  if (isNaN(d)) return "";
  const diff = Date.now() - d.getTime();
  const sec = Math.floor(diff / 1000);
  const min = Math.floor(sec / 60);
  const hr = Math.floor(min / 60);
  const day = Math.floor(hr / 24);
  if (day > 0) return `${day}d ${hr % 24}h`;
  if (hr > 0) return `${hr}h ${min % 60}m`;
  if (min > 0) return `${min}m`;
  return `${sec}s`;
}

function renderLastChange(p) {
  const info = p.last_status_change;
  if (!info || !info.changed_at)
    return `<span class="text-gray-400 text-sm">—</span>`;

  return `
    <div class="text-sm">
      <div class="font-semibold text-gray-800">${info.user_name || "—"}</div>
      <div class="text-gray-600">${formatDateFull(info.changed_at)}</div>
      <div class="text-xs text-gray-500">Hace ${timeAgo(info.changed_at)}</div>
    </div>
  `;
}
}
// ==================================