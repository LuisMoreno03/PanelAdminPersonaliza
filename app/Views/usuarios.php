<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= csrf_hash() ?>">
  <meta name="csrf-header" content="<?= csrf_header() ?>">

  <title>Usuarios - Panel</title>

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
              <h1 class="text-3xl font-extrabold text-slate-900">Usuarios</h1>
              <p class="text-slate-500 mt-1">Mi Cuenta</p>
            </div>
          </div>
        </section>

        <!-- USUARIOS -->
        <section class="mb-6 hidden">
          <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5">
            <h3 class="text-lg font-extrabold text-slate-900 mb-3">Estado de usuarios</h3>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
              <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                <div class="flex justify-between items-center mb-2">
                  <span class="font-bold text-emerald-900">Conectados</span>
                  <span id="onlineCount" class="font-extrabold">0</span>
                </div>
                <ul id="onlineUsers" class="text-sm space-y-1"></ul>
              </div>

              <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4">
                <div class="flex justify-between items-center mb-2">
                  <span class="font-bold text-rose-900">Desconectados</span>
                  <span id="offlineCount" class="font-extrabold">0</span>
                </div>
                <ul id="offlineUsers" class="text-sm space-y-1"></ul>
              </div>
            </div>
          </div>
        </section>

   
</script>



  </script>

</body>
</html>
