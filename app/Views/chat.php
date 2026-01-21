<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= csrf_hash() ?>">
  <meta name="csrf-header" content="<?= csrf_header() ?>">

  <title>Chat Interno - Panel</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/alpinejs" defer></script>

  <style>
    body { background: #f3f4f6; }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(6px) scale(0.99); }
      to   { opacity: 1; transform: translateY(0) scale(1); }
    }
    .animate-fadeIn { animation: fadeIn .18s ease-out; }

    .soft-scroll::-webkit-scrollbar { height: 10px; width: 10px; }
    .soft-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
    .soft-scroll::-webkit-scrollbar-track { background: #eef2ff; border-radius: 999px; }

    /* ✅ Layout con menú */
    .layout {
      transition: padding-left .2s ease;
      padding-left: 16rem; /* 256px (md:w-64) */
    }
    .layout.menu-collapsed {
      padding-left: 5.25rem; /* 84px colapsado */
    }
    @media (max-width: 768px) {
      .layout, .layout.menu-collapsed { padding-left: 0 !important; }
    }

    /* ✅ Grid sin scroll para filas desktop (se adapta a ancho real) */
    ./* ✅ Fuerza que el contenedor del listado no “recorte” */
.table-wrap {
  width: 100%;
  max-width: 100%;
}

/* ✅ GRID responsive real (desktop) */
.orders-grid {
  display: grid;
  align-items: center;
  gap: .65rem;
  width: 100%;
}

/* ✅ Header + rows usan la misma grilla */
.orders-grid.cols {
  grid-template-columns:
    110px                     /* Pedido */
    92px                      /* Fecha */
    minmax(170px, 1.2fr)      /* Cliente */
    90px                      /* Total */
    160px                     /* Estado */
    minmax(140px, 0.9fr)      /* Último cambio */
    minmax(170px, 1fr)        /* Etiquetas */
    44px                      /* Art */
    140px                     /* Entrega */
    minmax(190px, 1fr)        /* Método entrega */
    130px;                    /* ✅ Ver detalles */
}

/* ✅ Importante: permite truncar sin romper el grid */
.orders-grid > div {
  min-width: 0;
}

/* ✅ Para el método de entrega: permite 2 líneas */
.metodo-entrega {
  white-space: normal;
  line-height: 1.1;
  display: -webkit-box;
  -webkit-line-clamp: 2;       /* máximo 2 líneas */
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* ✅ Si quieres “ver todo sí o sí” cuando el monitor sea pequeño,
   activa scroll solo en la tabla (opcional) */
.table-scroll {
  overflow-x: auto;
}
.table-scroll::-webkit-scrollbar { height: 10px; }
.table-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
.table-scroll::-webkit-scrollbar-track { background: #eef2ff; border-radius: 999px; }



    /* ✅ Cuando el ancho baja demasiado, pasamos a cards */
    @media (max-width: 1180px) {
      .desktop-orders { display: none !important; }
      .mobile-orders  { display: block !important; }
    }
    @media (min-width: 1181px) {
      .desktop-orders { display: block !important; }
      .mobile-orders  { display: none !important; }
    }
  </style>
</head>

<body class="min-h-screen bg-gradient-to-b from-slate-50 to-slate-100 overflow-x-hidden">

  <!-- MENU -->
  <?= view('layouts/menu') ?>

  <main id="mainLayout" class="layout">
    <div class="p-4 sm:p-6 lg:p-8">
      <div class="mx-auto w-full max-w-[1600px]">

        <!-- HEADER -->
        <section class="mb-6">
          <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5 flex items-start justify-between gap-4">
            <div>
              <h1 class="text-3xl font-extrabold text-slate-900">Chat Interno</h1>
              <p class="text-slate-500 mt-1">Comunicación Laboral</p>
            </div>
          </div>
        </section>

        <section class="mb-6">
  <!-- CHAT INTERNO -->
<section class="mb-6">
  <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5">
    <div class="flex items-start justify-between gap-4 mb-4">
      <div>
        <h3 class="text-lg font-extrabold text-slate-900">Chat Interno</h3>
        <p class="text-slate-500 text-sm mt-1">Selecciona un usuario y escribe en tiempo real.</p>
      </div>
      <span id="socketStatus" class="text-xs font-bold px-3 py-1 rounded-full bg-slate-100 text-slate-700">
        Desconectado
      </span>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-[320px_1fr] gap-4">
      <!-- Lista usuarios -->
      <div class="rounded-2xl border border-slate-200 bg-slate-50">
        <div class="p-3 border-b border-slate-200 flex items-center gap-2">
          <input id="userSearch" type="text" placeholder="Buscar usuario..."
                 class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-slate-300 bg-white">
        </div>

        <div id="usersList" class="p-2 h-[520px] overflow-y-auto soft-scroll">
          <!-- items dinámicos -->
          <div class="text-sm text-slate-500 p-3">Cargando usuarios...</div>
        </div>
      </div>

      <!-- Chat -->
      <div class="rounded-2xl border border-slate-200 bg-white flex flex-col">
        <!-- Header chat -->
        <div class="p-4 border-b border-slate-200 flex items-center justify-between gap-3">
          <div class="min-w-0">
            <div id="activeUserName" class="font-extrabold text-slate-900 truncate">Selecciona un usuario</div>
            <div id="activeUserMeta" class="text-xs text-slate-500 truncate"></div>
          </div>
          <button id="btnRefreshMessages"
                  class="rounded-xl px-3 py-2 text-sm font-bold bg-slate-900 text-white hover:bg-slate-800 disabled:opacity-60">
            Refrescar
          </button>
        </div>

        <!-- Mensajes -->
        <div id="messagesBox" class="p-4 flex-1 overflow-y-auto soft-scroll bg-slate-50">
          <div class="text-sm text-slate-500">Abre una conversación para ver mensajes.</div>
        </div>

        <!-- Input -->
        <div class="p-4 border-t border-slate-200 bg-white">
          <div class="flex gap-2">
            <input id="messageInput" type="text" placeholder="Escribe un mensaje..."
                   class="flex-1 rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-slate-300"
                   disabled>
            <button id="btnSend"
                    class="rounded-xl px-4 py-2 font-bold bg-slate-900 text-white hover:bg-slate-800 disabled:opacity-60"
                    disabled>
              Enviar
            </button>
          </div>
          <p id="chatMsg" class="text-xs mt-2 text-slate-500"></p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Socket.io client -->
<script src="https://cdn.socket.io/4.7.5/socket.io.min.js"></script>
