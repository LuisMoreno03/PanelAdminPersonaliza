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

  showLoader();

  // ✅ IMPORTANTE: usa la ruta real de confirmados
  let url = "/confirmados/filter";
  if (pageInfo) url += "?page_info=" + encodeURIComponent(pageInfo);

 
  fetch(url, {
    headers: {
      "Accept": "application/json",
      "X-Requested-With": "XMLHttpRequest",
    },
    credentials: "same-origin",
  })
    .then(async (res) => {
      const text = await res.text();

      console.log("URL:", url);
      console.log("STATUS:", res.status);
      console.log("RAW:", text.slice(0, 300));

      // Si devuelve HTML (login/redirect), cortamos
      if (text.trim().startsWith("<")) {
        throw new Error("El endpoint devolvió HTML (no JSON). Revisa ruta/sesión/controlador.");
      }

      let data;
      try {
        data = JSON.parse(text);
      } catch (e) {
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

      // ✅ Filtrar SOLO preparados (singular/plural, mayúsculas/minúsculas)
      const preparados = (data.orders || []).filter((p) => {
        const estado = (p.estado || p.status || "").trim().toLowerCase();
        return estado === "preparado" || estado === "preparados";
      });

      actualizarTabla(preparados);
      setTotal(preparados.length);
      setBtnSiguiente(nextPageInfo);
    })
    .catch((err) => {
      console.error("ERROR:", err.message);
      actualizarTabla([]);
      setTotal(0);
      setBtnSiguiente(null);
    })
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

// Helpers UI
function setTotal(n) {
  const total = document.getElementById("total-pedidos");
  if (total) total.textContent = String(n);
}
function setBtnSiguiente(pageInfo) {
  const btnSig = document.getElementById("btnSiguiente");
  if (btnSig) btnSig.disabled = !pageInfo;
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
        <td class="py-2 px-4">${p.numero ?? "-"}</td>
        <td class="py-2 px-4">${p.fecha ?? "-"}</td>
        <td class="py-2 px-4">${p.cliente ?? "-"}</td>
        <td class="py-2 px-4">${p.total ?? "-"}</td>

        <td class="py-2 px-2">
          <button onclick="abrirModal(${p.id})" class="font-semibold">
            ${p.estado ?? p.status ?? "-"}
          </button>
        </td>

      
        <td class="py-3 px-4" data-lastchange="${p.id}">
          ${renderLastChange(p)}
        </td>

        <td class="py-2 px-4">${formatearEtiquetas(p.etiquetas, p.id)}</td>
        <td class="py-2 px-4">${p.articulos ?? "-"}</td>
        <td class="py-2 px-4">${p.estado_envio ?? "-"}</td>
        <td class="py-2 px-4">${p.forma_envio ?? "-"}</td>

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
// UTILIDADES (si ya existen en otro JS global, elimina estas)
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
          <span class="px-2 py-1 rounded-full text-xs font-semibold ${colorEtiqueta(tag)}">
            ${tag}
          </span>`
        )
        .join("")}
      <button onclick="abrirModalEtiquetas(${orderId}, '${escapeQuotes(etiquetas)}')"
              class="text-blue-600 underline text-xs ml-2">
        Editar
      </button>
    </div>`;
}

function escapeQuotes(str) {
  return String(str).replace(/'/g, "\\'");
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
  if (!info || !info.changed_at) {
    return `<span class="text-gray-400 text-sm">—</span>`;
  }

  return `
    <div class="text-sm">
      <div class="font-semibold text-gray-800">${info.user_name || "—"}</div>
      <div class="text-gray-600">${formatDateFull(info.changed_at)}</div>
      <div class="text-xs text-gray-500">Hace ${timeAgo(info.changed_at)}</div>
    </div>
  `;
}
