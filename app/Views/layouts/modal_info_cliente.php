<?php
// app/Views/layouts/modal_info_cliente.php
// Drawer / modal lateral derecho con info del cliente + lista de pedidos del cliente.
// Requiere JS global: abrirClienteDetalle(), cerrarClienteDetalle(), __pintarClienteDrawer(order)
?>

<!-- =========================
   DRAWER CLIENTE (LADO DERECHO)
========================= -->
<div id="modalClienteDetalle" class="hidden fixed inset-0 z-[10000]">
  <!-- Backdrop -->
  <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="cerrarClienteDetalle()"></div>

  <!-- Panel -->
  <div class="absolute right-0 top-0 h-full w-full sm:w-[560px] bg-white shadow-2xl border-l border-slate-200 flex flex-col">

    <!-- Header -->
    <div class="p-6 bg-gradient-to-r from-slate-950 via-slate-900 to-slate-950 border-b border-white/10">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <div class="text-white/70 text-xs font-extrabold uppercase tracking-wide">Cliente</div>
          <div id="cliNombre" class="text-white text-2xl font-extrabold leading-tight truncate">—</div>
          <div id="cliSub" class="text-white/70 text-sm mt-1 truncate">—</div>
        </div>

        <button type="button" onclick="cerrarClienteDetalle()"
          class="h-10 w-10 rounded-2xl bg-white/10 border border-white/10 text-white font-extrabold text-xl
                 hover:bg-white/20 active:scale-[0.99] transition">
          ×
        </button>
      </div>
    </div>

    <!-- Body -->
    <div class="p-6 overflow-auto space-y-5">

      <!-- Info -->
      <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
        <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500">Información</div>
        <div id="cliInfo" class="mt-3 text-sm text-slate-800 space-y-2">—</div>
      </div>

      <!-- Orders list -->
      <div class="rounded-3xl border border-slate-200 bg-white p-5">
        <div class="flex items-center justify-between">
          <div>
            <div class="text-xs font-extrabold uppercase tracking-wide text-slate-500">Pedidos del cliente</div>
            <div id="cliOrdersCount" class="text-sm font-extrabold text-slate-900 mt-1">0 pedido(s)</div>
          </div>
        </div>

        <div id="cliOrdersList" class="mt-4 space-y-2"></div>
      </div>

    </div>
  </div>
</div>
