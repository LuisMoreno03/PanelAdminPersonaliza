// ===============================
// DASHBOARD.JS - Shopify 50 en 50 + modales guardan
// ===============================

let nextPageInfo = null;
let isLoading = false;
let allOrdersMap = new Map(); // id => order

// Helpers
const $ = (sel) => document.querySelector(sel);

function showLoader() { $("#globalLoader")?.classList.remove("hidden"); }
function hideLoader() { $("#globalLoader")?.classList.add("hidden"); }

function setProgress(text) {
  const el = $("#progressPedidos");
  if (el) el.textContent = text || "";
}

// ===============================
// Render
// ===============================
function renderOrders(orders, append = false) {
  const tbody = $("#tbodyPedidos");
  if (!tbody) return;

  if (!append) tbody.innerHTML = "";

  if (!orders || orders.length === 0) {
    if (!append) {
      tbody.innerHTML = `<tr><td colspan="8" class="py-6 text-center text-gray-500">No se encontraron pedidos</td></tr>`;
    }
    return;
  }

  const rows = orders.map(o => {
    const last = o.last_status_change?.changed_at
      ? `${o.last_status_change.user_name || "Sistema"} - ${o.last_status_change.changed_at}`
      : "-";

    return `
      <tr data-order-id="${o.id}">
        <td class="py-3">${escapeHtml(o.numero || "-")}</td>
        <td class="py-3">${escapeHtml(o.fecha || "-")}</td>
        <td class="py-3">${escapeHtml(o.cliente || "-")}</td>
        <td class="py-3">${escapeHtml(o.total || "-")}</td>
        <td class="py-3">
          <button class="btnEstado px-3 py-1 rounded bg-gray-100" data-id="${o.id}" data-estado="${escapeAttr(o.estado || "")}">
            ${escapeHtml(o.estado || "-")}
          </button>
        </td>
        <td class="py-3">${escapeHtml(last)}</td>
        <td class="py-3">
          <button class="btnEtiquetas px-3 py-1 rounded bg-gray-100" data-id="${o.id}" data-tags="${escapeAttr(o.etiquetas || "")}">
            ${escapeHtml((o.etiquetas || "").slice(0, 35) || "Editar")}
          </button>
        </td>
        <td class="py-3">${escapeHtml(String(o.articulos ?? 0))}</td>
      </tr>
    `;
  }).join("");

  if (append) tbody.insertAdjacentHTML("beforeend", rows);
  else tbody.innerHTML = rows;

  bindRowButtons();
}

function bindRowButtons() {
  document.querySelectorAll(".btnEstado").forEach(btn => {
    btn.onclick = () => openEstadoModal(btn.dataset.id, btn.dataset.estado || "");
  });

  document.querySelectorAll(".btnEtiquetas").forEach(btn => {
    btn.onclick = () => openEtiquetasModal(btn.dataset.id, btn.dataset.tags || "");
  });
}

// ===============================
// Cargar 1 página (50)
// ===============================
async function loadOrdersPage(pageInfo = null, append = false) {
  if (isLoading) return;
  isLoading = true;
  showLoader();

  try {
    const url = new URL("/dashboard/filter", window.location.origin);
    url.searchParams.set("limit", "50");
    if (pageInfo) url.searchParams.set("page_info", pageInfo);

    const res = await fetch(url.toString(), { credentials: "include" });
    const data = await res.json();

    if (!data.success) throw new Error(data.message || "Error cargando pedidos");

    nextPageInfo = data.next_page_info || null;

    // guardamos en map
    (data.orders || []).forEach(o => allOrdersMap.set(String(o.id), o));

    renderOrders(data.orders || [], append);

    setProgress(`Cargados: ${allOrdersMap.size}`);

  } catch (e) {
    console.error(e);
    alert("Error: " + (e.message || e));
  } finally {
    hideLoader();
    isLoading = false;
  }
}

// ===============================
// Cargar TODA Shopify 50 en 50
// ===============================
async function loadAllOrders() {
  allOrdersMap.clear();
  nextPageInfo = null;

  // primera página
  await loadOrdersPage(null, false);

  // siguientes
  while (nextPageInfo) {
    const pi = nextPageInfo;
    await loadOrdersPage(pi, true);
  }

  setProgress(`Carga completa: ${allOrdersMap.size} pedidos`);
}

// ===============================
// Modales
// ===============================

function openEstadoModal(orderId, estadoActual) {
  $("#modalEstado")?.classList.remove("hidden");
  $("#estadoOrderId").value = orderId;
  $("#estadoSelect").value = estadoActual || "Por preparar";
}

function closeEstadoModal() {
  $("#modalEstado")?.classList.add("hidden");
}

async function saveEstadoModal() {
  const id = $("#estadoOrderId").value;
  const estado = $("#estadoSelect").value;

  const res = await fetch("/dashboard/save-estado", {
    method: "POST",
    credentials: "include",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ id, estado })
  });

  const data = await res.json();
  if (!data.success) return alert(data.message || "No se pudo guardar estado");

  // refrescar solo la fila en UI
  updateRowLocal(id, { estado, last_status_change: { user_name: "Tú", changed_at: new Date().toISOString().slice(0,19).replace("T"," ") } });
  closeEstadoModal();
}

function openEtiquetasModal(orderId, tagsActuales) {
  $("#modalEtiquetas")?.classList.remove("hidden");
  $("#tagsOrderId").value = orderId;
  $("#tagsInput").value = tagsActuales || "";
}

function closeEtiquetasModal() {
  $("#modalEtiquetas")?.classList.add("hidden");
}

async function saveEtiquetasModal(syncShopify = true) {
  const id = $("#tagsOrderId").value;
  const tags = $("#tagsInput").value;

  // 1) guardar BD
  const res = await fetch("/dashboard/save-etiquetas", {
    method: "POST",
    credentials: "include",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ id, tags })
  });

  const data = await res.json();
  if (!data.success) return alert(data.message || "No se pudo guardar etiquetas");

  // 2) opcional: sync Shopify
  if (syncShopify) {
    const r2 = await fetch("/shopify/update-tags", {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id, tags })
    });
    const d2 = await r2.json();
    if (!d2.success) console.warn("No se sincronizó Shopify:", d2.error);
  }

  // refrescar fila
  updateRowLocal(id, { etiquetas: tags });
  closeEtiquetasModal();
}

// ===============================
// Update local row UI
// ===============================
function updateRowLocal(id, patch) {
  const key = String(id);
  const current = allOrdersMap.get(key) || { id };
  const updated = { ...current, ...patch };
  allOrdersMap.set(key, updated);

  // actualizar fila DOM
  const tr = document.querySelector(`tr[data-order-id="${CSS.escape(key)}"]`);
  if (!tr) return;

  // Estado button
  if (patch.estado !== undefined) {
    const btn = tr.querySelector(".btnEstado");
    if (btn) {
      btn.textContent = updated.estado || "-";
      btn.dataset.estado = updated.estado || "";
    }
  }

  // Etiquetas button
  if (patch.etiquetas !== undefined) {
    const btn = tr.querySelector(".btnEtiquetas");
    if (btn) {
      btn.textContent = (updated.etiquetas || "").slice(0, 35) || "Editar";
      btn.dataset.tags = updated.etiquetas || "";
    }
  }
}

function escapeHtml(s) {
  return String(s ?? "").replace(/[&<>"']/g, m => ({ "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;" }[m]));
}
function escapeAttr(s) { return escapeHtml(s).replace(/"/g, "&quot;"); }

// ===============================
// Init
// ===============================
document.addEventListener("DOMContentLoaded", () => {
  // Carga inicial (1 página)
  loadOrdersPage(null, false);

  // Botones modales
  $("#btnEstadoClose")?.addEventListener("click", closeEstadoModal);
  $("#btnEstadoSave")?.addEventListener("click", saveEstadoModal);

  $("#btnTagsClose")?.addEventListener("click", closeEtiquetasModal);
  $("#btnTagsSave")?.addEventListener("click", () => saveEtiquetasModal(true));

  // Si tienes botón "Cargar todo"
  $("#btnCargarTodo")?.addEventListener("click", loadAllOrders);

  // Botón siguiente página (si lo usas)
  $("#btnSiguiente")?.addEventListener("click", () => {
    if (nextPageInfo) loadOrdersPage(nextPageInfo, true);
  });
});
