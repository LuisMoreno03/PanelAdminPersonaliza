<?php
// app/Views/layouts/modal_info_cliente.php
// Drawer / modal lateral derecho para info de cliente + pedidos del cliente
?>

<!-- =========================
   MODAL INFO CLIENTE (DRAWER RIGHT)
========================= -->
<div id="modalClienteInfo" class="hidden fixed inset-0 z-[10000]">
  <!-- Overlay -->
  <div
    class="absolute inset-0 bg-black/50 backdrop-blur-sm"
    onclick="cerrarClienteDetalle()"
    aria-hidden="true"
  ></div>

  <!-- Panel -->
  <aside
    class="absolute right-0 top-0 h-full w-full sm:w-[520px] bg-white shadow-2xl border-l border-slate-200
           flex flex-col animate-fadeIn"
    role="dialog"
    aria-modal="true"
    aria-labelledby="cliTitle"
  >
    <!-- Header -->
    <div class="px-5 py-4 border-b border-slate-200 flex items-start justify-between gap-3">
      <div class="min-w-0">
        <div class="text-xs font-extrabold uppercase tracking-wider text-slate-500">Cliente</div>
        <h3 id="cliTitle" class="text-xl font-extrabold text-slate-900 truncate">—</h3>
        <p id="cliSubtitle" class="text-sm text-slate-500 mt-1 truncate">—</p>
      </div>

      <button type="button" onclick="cerrarClienteDetalle()"
        class="h-10 w-10 rounded-2xl border border-slate-200 bg-white text-slate-600
               hover:text-slate-900 hover:border-slate-300 transition font-extrabold text-xl leading-none">
        ×
      </button>
    </div>

    <!-- Body -->
    <div class="flex-1 overflow-auto p-5 space-y-4">

      <!-- Info principal -->
      <div class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between">
          <div class="font-extrabold text-slate-900">Información</div>
          <span id="cliBadge"
            class="text-[11px] font-extrabold px-2.5 py-1 rounded-full border bg-slate-50 border-slate-200 text-slate-700">
            —
          </span>
        </div>

        <div id="cliInfo" class="p-4 text-sm text-slate-800">
          <div class="text-slate-500">—</div>
        </div>
      </div>

      <!-- Dirección envío -->
      <div class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200">
          <div class="font-extrabold text-slate-900">Envío</div>
        </div>
        <div id="cliEnvio" class="p-4 text-sm text-slate-800">
          <div class="text-slate-500">—</div>
        </div>
      </div>

      <!-- Pedidos del cliente -->
      <div class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between gap-3">
          <div class="font-extrabold text-slate-900">Pedidos del cliente</div>
          <span id="cliOrdersCount"
            class="text-[11px] font-extrabold px-2.5 py-1 rounded-full border bg-slate-50 border-slate-200 text-slate-700">
            0
          </span>
        </div>

        <div id="cliOrders" class="p-4">
          <div class="text-sm text-slate-500">—</div>
        </div>
      </div>

    </div>

    <!-- Footer -->
    <div class="px-5 py-4 border-t border-slate-200 flex items-center justify-end gap-2 bg-white">
      <button type="button" onclick="cerrarClienteDetalle()"
        class="px-4 py-2 rounded-2xl bg-slate-100 border border-slate-200 text-slate-900
               font-extrabold text-xs uppercase tracking-wide hover:bg-slate-200 transition">
        Cerrar
      </button>
    </div>
  </aside>
</div>

<script>
/* =========================
   Drawer Cliente - helpers
========================= */

// Abre/cierra
window.abrirClienteDetalle = function () {
  const m = document.getElementById("modalClienteInfo");
  if (!m) return;
  m.classList.remove("hidden");
  document.documentElement.classList.add("overflow-hidden");
  document.body.classList.add("overflow-hidden");
};

window.cerrarClienteDetalle = function () {
  const m = document.getElementById("modalClienteInfo");
  if (!m) return;
  m.classList.add("hidden");
  document.documentElement.classList.remove("overflow-hidden");
  document.body.classList.remove("overflow-hidden");
};

// Helpers sanitize (compat)
function escapeHtml(str) {
  return String(str ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function escapeAttr(str) {
  return String(str ?? "")
    .replace(/&/g, "&amp;")
    .replace(/"/g, "&quot;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
}

/* =========================
   Pintar info desde verDetalles
   (llámala al final de tu fetch)
========================= */
window.__pintarClienteDrawer = function (order) {
  try {
    const o = order || {};
    const c = o.customer || {};
    const a = o.shipping_address || {};

    const fullName =
      `${c.first_name || ""} ${c.last_name || ""}`.trim() ||
      String(a.name || "").trim() ||
      "—";

    const email = o.email || c.email || "—";
    const phone = o.phone || a.phone || "—";
    const customerId = c.id || "—";

    const title = document.getElementById("cliTitle");
    const subtitle = document.getElementById("cliSubtitle");
    const badge = document.getElementById("cliBadge");

    if (title) title.textContent = fullName;
    if (subtitle) subtitle.textContent = email !== "—" ? email : phone;
    if (badge) badge.textContent = customerId !== "—" ? `ID ${customerId}` : "Sin ID";

    const info = document.getElementById("cliInfo");
    if (info) {
      info.innerHTML = `
        <div class="space-y-2">
          <div><span class="text-slate-500 font-bold">Nombre:</span> <span class="font-semibold">${escapeHtml(fullName)}</span></div>
          <div><span class="text-slate-500 font-bold">Email:</span> <span class="font-semibold break-words">${escapeHtml(email)}</span></div>
          <div><span class="text-slate-500 font-bold">Tel:</span> <span class="font-semibold">${escapeHtml(phone)}</span></div>
          <div><span class="text-slate-500 font-bold">Customer ID:</span> <span class="font-semibold">${escapeHtml(customerId)}</span></div>
        </div>
      `;
    }

    const envio = document.getElementById("cliEnvio");
    if (envio) {
      const line1 = [a.address1, a.address2].filter(Boolean).join(" · ");
      const line2 = [a.zip, a.city].filter(Boolean).join(" ");
      const line3 = [a.province, a.country].filter(Boolean).join(" · ");

      envio.innerHTML = `
        <div class="space-y-1">
          <div class="font-extrabold text-slate-900">${escapeHtml(a.name || fullName || "—")}</div>
          <div>${escapeHtml(line1 || "—")}</div>
          <div>${escapeHtml(line2 || "")}</div>
          <div>${escapeHtml(line3 || "")}</div>
          <div class="pt-2"><span class="text-slate-500 font-bold">Tel envío:</span> <span class="font-semibold">${escapeHtml(a.phone || phone || "—")}</span></div>
        </div>
      `;
    }
  } catch (e) {
    console.error("__pintarClienteDrawer error:", e);
  }
};

/* =========================
   Pintar lista de pedidos del cliente
   - usa ordersCache / ordersById si existen
   - llama: window.__pintarPedidosDelCliente(customerId, email)
========================= */
window.__pintarPedidosDelCliente = function (customerId, email) {
  try {
    const wrap = document.getElementById("cliOrders");
    const count = document.getElementById("cliOrdersCount");
    if (!wrap) return;

    const cid = String(customerId || "").trim();
    const em = String(email || "").trim().toLowerCase();

    // Fuente: ordersCache (tabla principal)
    const arr = Array.isArray(window.ordersCache) ? window.ordersCache : [];

    const matches = arr.filter(o => {
      const ocid = String(o?.customer_id || o?.customer?.id || "").trim();
      const oem = String(o?.email || "").trim().toLowerCase();
      return (cid && ocid && ocid === cid) || (!!em && oem === em);
    });

    if (count) count.textContent = String(matches.length || 0);

    if (!matches.length) {
      wrap.innerHTML = `<div class="text-sm text-slate-500">No se encontraron pedidos en la lista actual.</div>`;
      return;
    }

    // Orden por fecha (si hay created_at)
    matches.sort((a, b) => {
      const da = new Date(a?.created_at || a?.createdAt || 0).getTime();
      const db = new Date(b?.created_at || b?.createdAt || 0).getTime();
      return db - da;
    });

    wrap.innerHTML = `
      <div class="space-y-2">
        ${matches.map(o => {
          const id = o?.id ?? o?.order_id ?? "";
          const name = o?.name ?? ("#" + id);
          const total = (o?.total_price ?? o?.total ?? "").toString();
          const estado = o?.estado ?? o?.status ?? "-";
          const fecha = o?.created_at ?? o?.createdAt ?? "";

          // pill del dashboard (si existe)
          const pill = (typeof window.renderEstadoPill === "function")
            ? window.renderEstadoPill(estado)
            : `<span class="px-2 py-1 rounded-full bg-slate-100 border border-slate-200 text-xs font-extrabold">${escapeHtml(String(estado))}</span>`;

          return `
            <button type="button"
              onclick="try{ window.verDetalles && window.verDetalles('${escapeAttr(String(id))}'); }catch(e){}"
              class="w-full text-left rounded-2xl border border-slate-200 bg-white hover:bg-slate-50 transition p-3">
              <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                  <div class="font-extrabold text-slate-900 truncate">${escapeHtml(String(name))}</div>
                  <div class="text-xs text-slate-500 mt-0.5 truncate">${escapeHtml(String(fecha || ""))}</div>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                  ${pill}
                  ${total ? `<span class="text-xs font-extrabold text-slate-900">${escapeHtml(total)}€</span>` : ""}
                </div>
              </div>
            </button>
          `;
        }).join("")}
      </div>
    `;
  } catch (e) {
    console.error("__pintarPedidosDelCliente error:", e);
  }
};
</script>
