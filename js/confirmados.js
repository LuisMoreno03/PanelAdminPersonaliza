// =====================================================
// CONFIRMADOS.JS  -> Muestra pedidos preparados
// =====================================================

let nextPageInfo = null;
let isLoading = false;

function showLoader() {
  const el = document.getElementById("globalLoader");
  if (el) el.classList.remove("hidden");
}
function hideLoader() {
  const el = document.getElementById("globalLoader");
  if (el) el.classList.add("hidden");
}

document.addEventListener("DOMContentLoaded", () => {
  cargarPedidosPreparados();
});

function cargarPedidosPreparados(pageInfo = null) {
  if (isLoading) return;
  isLoading = true;

  showLoader();

  const base = window.BASE_URL || ""; // si no existe, usa root
  let url = `${base}/confirmados/filter`;
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

      // ✅ Filtro robusto: estado o tags/etiquetas
      const preparados = (data.orders || []).filter((p) => {
        const estado = (p.estado || p.status || p.fulfillment_status || "")
          .toString()
          .trim()
          .toLowerCase();

        const tags = (p.etiquetas || p.tags || "")
          .toString()
          .trim()
          .toLowerCase();

        return (
          estado === "preparado" ||
          estado === "preparados" ||
          tags.includes("preparado")
        );
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

function paginaSiguiente() {
  if (nextPageInfo) cargarPedidosPreparados(nextPageInfo);
}

function setTotal(n) {
  const total = document.getElementById("total-pedidos");
  if (total) total.textContent = String(n);
}
function setBtnSiguiente(pageInfo) {
  const btnSig = document.getElementById("btnSiguiente");
  if (btnSig) btnSig.disabled = !pageInfo;
}

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
    const id = p.id ?? p.order_id ?? "";
    tbody.innerHTML += `
      <tr class="border-b hover:bg-gray-50 transition">
        <td class="py-2 px-4">${p.numero ?? p.name ?? "-"}</td>
        <td class="py-2 px-4">${p.fecha ?? p.created_at ?? "-"}</td>
        <td class="py-2 px-4">${p.cliente ?? p.customer ?? "-"}</td>
        <td class="py-2 px-4">${p.total ?? p.total_price ?? "-"}</td>

        <td class="py-2 px-2">
          <span class="font-semibold">
            ${(p.estado ?? p.status ?? p.fulfillment_status ?? "-")}
          </span>
        </td>

        <td class="py-2 px-4">${formatearEtiquetas(p.etiquetas ?? p.tags, id)}</td>
        <td class="py-2 px-4">${p.articulos ?? p.line_items_count ?? "-"}</td>
        <td class="py-2 px-4">${p.estado_envio ?? "-"}</td>
        <td class="py-2 px-4">${p.forma_envio ?? "-"}</td>

        <td class="py-2 px-4">
          <button onclick="verDetalles && verDetalles('${id}')" class="text-blue-600 underline">
            Ver detalles
          </button>
        </td>
      </tr>
    `;
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