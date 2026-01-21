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
  <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5">
    <h3 class="text-lg font-extrabold text-slate-900 mb-4">Cambiar clave</h3>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div>
        <label class="text-sm font-semibold text-slate-700">Clave actual</label>
        <input id="currentPassword" type="password" autocomplete="current-password"
               class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-slate-300"
               placeholder="••••••••">
      </div>

      <div>
        <label class="text-sm font-semibold text-slate-700">Nueva clave</label>
        <input id="newPassword" type="password" autocomplete="new-password"
               class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-slate-300"
               placeholder="Mínimo 8 caracteres">
      </div>

      <div>
        <label class="text-sm font-semibold text-slate-700">Confirmar nueva clave</label>
        <input id="confirmPassword" type="password" autocomplete="new-password"
               class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-slate-300"
               placeholder="Repite la nueva clave">
      </div>
    </div>

    <div class="mt-4 flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between">
      <p id="passMsg" class="text-sm"></p>

      <button id="btnChangePass"
              class="rounded-xl px-4 py-2 font-bold bg-slate-900 text-white hover:bg-slate-800 disabled:opacity-60 disabled:cursor-not-allowed">
        Guardar clave
      </button>
    </div>
  </div>
</section>
<script>
  (function () {
    const csrfTokenMeta  = document.querySelector('meta[name="csrf-token"]');
    const csrfHeaderMeta = document.querySelector('meta[name="csrf-header"]');

    function csrf() {
      return {
        token: csrfTokenMeta ? csrfTokenMeta.getAttribute('content') : '',
        header: csrfHeaderMeta ? csrfHeaderMeta.getAttribute('content') : 'X-CSRF-TOKEN'
      };
    }

    function setMsg(type, text) {
      const el = document.getElementById('passMsg');
      if (!el) return;
      el.textContent = text || '';
      el.className = 'text-sm ' + (type === 'ok' ? 'text-emerald-700' : 'text-rose-600');
    }

    const btn = document.getElementById('btnChangePass');
    if (!btn) return;

    btn.addEventListener('click', async () => {
      setMsg('', '');

      const currentPassword = document.getElementById('currentPassword').value.trim();
      const newPassword     = document.getElementById('newPassword').value.trim();
      const confirmPassword = document.getElementById('confirmPassword').value.trim();

      if (!currentPassword || !newPassword || !confirmPassword) {
        setMsg('err', 'Completa todos los campos.');
        return;
      }
      if (newPassword.length < 8) {
        setMsg('err', 'La nueva clave debe tener al menos 8 caracteres.');
        return;
      }
      if (newPassword !== confirmPassword) {
        setMsg('err', 'La confirmación no coincide con la nueva clave.');
        return;
      }
      if (currentPassword === newPassword) {
        setMsg('err', 'La nueva clave no puede ser igual a la actual.');
        return;
      }

      btn.disabled = true;
      btn.textContent = 'Guardando...';

      try {
        const c = csrf();

        const res = await fetch('<?= base_url("usuarios/cambiar-clave") ?>', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            [c.header]: c.token
          },
          body: JSON.stringify({ currentPassword, newPassword })
        });

        const data = await res.json().catch(() => ({}));

        // Si CI regenera csrf, lo refrescamos en el meta
        if (data && data.csrf && csrfTokenMeta) {
          csrfTokenMeta.setAttribute('content', data.csrf);
        }

        if (!res.ok || !data.ok) {
          setMsg('err', data.message || 'No se pudo actualizar la clave.');
          return;
        }

        setMsg('ok', data.message || 'Clave actualizada.');
        document.getElementById('currentPassword').value = '';
        document.getElementById('newPassword').value = '';
        document.getElementById('confirmPassword').value = '';
      } catch (e) {
        setMsg('err', 'Error de red. Intenta de nuevo.');
      } finally {
        btn.disabled = false;
        btn.textContent = 'Guardar clave';
      }
    });
  })();
</script>


   
</script>



  </script>

</body>
</html>
