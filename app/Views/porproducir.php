<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- CSRF -->
  <meta name="csrf-token" content="<?= csrf_hash() ?>">
  <meta name="csrf-header" content="<?= csrf_header() ?>">

  <title>Por producir · Panel</title>

  <!-- Tailwind -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/alpinejs" defer></script>

  <style>
    body { background: #f3f4f6; }

    /* Layout menú */
    .layout { transition: padding-left .2s ease; padding-left: 16rem; }
    .layout.menu-collapsed { padding-left: 5.25rem; }
    @media (max-width: 768px) { .layout, .layout.menu-collapsed { padding-left: 0 !important; } }

    /* Orders grid (MISMO ESTILO) */
    .orders-grid {
      display: grid;
      align-items: center;
      gap: .65rem;
      width: 100%;
    }
    .pedido-overdue{
      background:#ffe5e5;
      border:1px solid #ff3b3b;
    }

    /* Ajusta columnas si tu data difiere */
    .orders-grid.cols {
      grid-template-columns:
        140px     /* Pedido */
        92px      /* Fecha */
        minmax(170px, 1.2fr) /* Cliente */
        90px      /* Total */
        160px     /* Estado */
        minmax(120px, 0.9fr) /* Último cambio */
        minmax(160px, 1fr)   /* Etiquetas */
        44px      /* Art */
        140px     /* Entrega */
        minmax(190px, 1fr)   /* Método */
        130px;    /* Ver */
    }

    .orders-grid > div { min-width: 0; }

    .table-scroll { overflow-x: auto; }

    /* Sticky header */
    .table-header {
      position: sticky;
      top: 0;
      z-index: 10;
      background: #f8fafc;
    }

    /* Badges */
    .badge {
      display:inline-flex;
      align-items:center;
      gap:.35rem;
      padding:.35rem .6rem;
      border-radius: 999px;
      font-weight: 800;
      font-size: 11px;
      border: 1px solid #e2e8f0;
      background:#f8fafc;
      color:#0f172a;
      white-space: nowrap;
    }
  </style>
</head>

<body class="min-h-screen bg-gradient-to-b from-slate-50 to-slate-100 overflow-x-hidden">

<?= view('layouts/menu') ?>

<main id="mainLayout" class="layout">
  <div class="p-4 sm:p-6 lg:p-8">
    <div class="mx-auto w-full max-w-[1600px] space-y-6">

      <!-- ================= HEADER ================= -->
      <section>
        <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h1 class="text-3xl font-extrabold text-slate-900">Por producir</h1>
            <p class="text-slate-500 mt-1 text-sm">
              Pedidos en estado <b>Diseñado</b> listos para pasar a <b>Enviado</b> según método de entrega.
            </p>
          </div>

          <span class="px-4 py-2 rounded-2xl bg-slate-50 border border-slate-200 font-extrabold text-sm">
            Pedidos: <span id="total-pedidos">0</span>
          </span>
        </div>
      </section>

      <!-- ================= LISTADO ================= -->
      <section class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">

        <!-- Acciones -->
        <div class="px-4 py-4 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
          <div class="font-extrabold text-slate-900">
            Listado de pedidos
          </div>

          <div class="flex flex-wrap gap-2">
            <button id="btnTraer5"
              class="px-4 py-2 rounded-xl bg-slate-900 text-white font-extrabold hover:bg-slate-800 transition">
              Traer 5
            </button>

            <button id="btnTraer10"
              class="px-4 py-2 rounded-xl bg-slate-900 text-white font-extrabold hover:bg-slate-800 transition">
              Traer 10
            </button>

            <span class="badge">
              Estado origen: Diseñado
            </span>
          </div>
        </div>

        <!-- Alert -->
        <div id="ppAlert" class="hidden px-4 py-3 text-sm font-bold bg-rose-50 text-rose-700 border-b border-rose-200"></div>

        <!-- Tabla -->
        <div class="table-scroll">
          <!-- Header -->
          <div class="orders-grid cols px-4 py-3 text-[11px] uppercase tracking-wider text-slate-600 border-b table-header">
            <div>Pedido</div>
            <div>Fecha</div>
            <div>Cliente</div>
            <div>Total</div>
            <div>Estado</div>
            <div>Último cambio</div>
            <div>Etiquetas</div>
            <div class="text-center">Art</div>
            <div>Entrega</div>
            <div>Método</div>
            <div class="text-right">Ver</div>
          </div>

          <!-- Body -->
          <div id="tablaPedidos" class="divide-y"></div>
        </div>
      </section>

    </div>
  </div>
</main>

<!-- ================= MODAL DETALLES (FULL SCREEN) ================= -->
<div id="modalDetallesFull" class="hidden fixed inset-0 z-[9999] bg-black/50 backdrop-blur-sm">
  <div class="h-full w-full bg-white flex flex-col">

    <!-- HEADER -->
    <div class="px-6 sm:px-8 py-4 border-b border-slate-200 flex items-center justify-between gap-4">
      <div class="min-w-0">
        <div class="text-xs font-extrabold uppercase tracking-wider text-slate-500">
          Detalles del pedido
        </div>
        <h2 id="detTitulo" class="text-2xl font-extrabold text-slate-900 truncate">—</h2>
        <p id="detCliente" class="text-sm text-slate-500 mt-1 truncate">—</p>
      </div>

      <div class="flex items-center gap-2 shrink-0">
        <button type="button" onclick="copiarJsonPedido?.()"
          class="px-4 py-2 rounded-xl bg-slate-100 border border-slate-200 text-slate-900 font-extrabold text-xs uppercase tracking-wide hover:bg-slate-200 transition">
          Copiar JSON
        </button>

        <button type="button" onclick="abrirClienteDetalle?.()"
          class="px-4 py-2 rounded-xl bg-slate-900 text-white font-extrabold text-xs uppercase tracking-wide hover:bg-slate-800 transition">
          Cliente
        </button>

        <button type="button" onclick="cerrarModalDetalles()"
          class="h-10 w-10 rounded-xl border border-slate-200 bg-white text-slate-600 hover:text-slate-900 hover:border-slate-300 transition font-extrabold text-xl leading-none">
          ×
        </button>
      </div>
    </div>

    <!-- BODY -->
    <div class="flex-1 overflow-auto">
      <div class="max-w-[1500px] mx-auto px-6 sm:px-8 py-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-4">
          <div id="detProductos"></div>
        </div>

        <div id="detResumen"
          class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5 h-fit sticky top-6">
        </div>
      </div>
    </div>

  </div>
</div>

<!-- ================= LOADER ================= -->
<div id="globalLoader" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-[100]">
  <div class="bg-white p-6 rounded-3xl shadow-xl text-center w-[200px]">
    <div class="w-10 h-10 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto"></div>
    <p class="mt-4 font-extrabold text-slate-900">Cargando…</p>
  </div>
</div>

<!-- ================= API ================= -->
<script>
window.API = {
  pull: "<?= site_url('porproducir/pull') ?>",
  updateMetodo: "<?= site_url('porproducir/update-metodo') ?>", // si tu endpoint es distinto, cámbialo
  detalles: "<?= site_url('dashboard/detalles') ?>"
};

window.CSRF = {
  token: document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
  header: document.querySelector('meta[name="csrf-header"]').getAttribute('content')
};
</script>

<!-- ================= JS ================= -->
<script src="<?= base_url('js/porproducir.js?v=' . time()) ?>"></script>

<script>
  (function () {
    const main = document.getElementById('mainLayout');
    if (!main) return;

    const collapsed = localStorage.getItem('menuCollapsed') === '1';
    main.classList.toggle('menu-collapsed', collapsed);

    window.setMenuCollapsed = function (v) {
      main.classList.toggle('menu-collapsed', !!v);
      localStorage.setItem('menuCollapsed', v ? '1' : '0');
      window.dispatchEvent(new Event('resize'));
    };
  })();

  // Helpers del modal (si tu confirmacion.js ya los trae, aquí no estorban)
  function cerrarModalDetalles() {
    document.getElementById('modalDetallesFull')?.classList.add('hidden');
  }
</script> 

</body>
</html>
