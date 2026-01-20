<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <meta name="csrf-token" content="<?= csrf_hash() ?>">
  <meta name="csrf-header" content="<?= csrf_header() ?>">

  <title>Mi cuenta - Panel</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/alpinejs" defer></script>
</head>

<body class="bg-slate-50">
<div class="min-h-screen flex">

  <!-- SIDEBAR -->
  <aside class="w-64 bg-slate-900 text-white p-4">
    <div class="text-lg font-bold mb-6">Panel Admin</div>
    <nav class="space-y-2 text-sm">
      <a class="block px-3 py-2 rounded hover:bg-slate-800" href="/dashboard">Dashboard</a>
      <a class="block px-3 py-2 rounded hover:bg-slate-800" href="/usuarios">Usuarios</a>
      <a class="block px-3 py-2 rounded bg-slate-800" href="/mi-cuenta">Mi cuenta</a>
    </nav>
  </aside>

  <main class="flex-1 p-8">
    <div class="max-w-3xl mx-auto">

      <div class="bg-white rounded-2xl border border-slate-200 p-6">
        <h1 class="text-3xl font-extrabold text-slate-900">Mi cuenta</h1>
        <p class="text-slate-500 mt-1">Cambia tu contraseña de forma segura</p>
      </div>

      <div class="mt-6 bg-white rounded-2xl border border-slate-200 p-6">
        <h2 class="text-lg font-bold text-slate-900">Cambiar contraseña</h2>

        <form id="formPass" class="mt-5 space-y-4">
          <div>
            <label class="text-xs font-semibold text-slate-600">Contraseña actual</label>
            <input name="current_password" type="password" required
              class="mt-1 w-full rounded-xl border border-slate-200 px-4 py-3 outline-none focus:ring-2 focus:ring-slate-900/20"/>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="text-xs font-semibold text-slate-600">Nueva contraseña</label>
              <input name="new_password" type="password" minlength="8" required
                class="mt-1 w-full rounded-xl border border-slate-200 px-4 py-3 outline-none focus:ring-2 focus:ring-slate-900/20"/>
              <p class="text-xs text-slate-400 mt-1">Mínimo 8 caracteres.</p>
            </div>

            <div>
              <label class="text-xs font-semibold text-slate-600">Confirmar nueva contraseña</label>
              <input name="confirm_password" type="password" minlength="8" required
                class="mt-1 w-full rounded-xl border border-slate-200 px-4 py-3 outline-none focus:ring-2 focus:ring-slate-900/20"/>
            </div>
          </div>

          <div class="flex items-center gap-3 pt-2">
            <button class="px-5 py-3 rounded-xl bg-blue-600 text-white font-semibold hover:bg-blue-700" type="submit">
              Guardar cambios
            </button>
            <span id="msg" class="text-sm"></span>
          </div>
        </form>
      </div>

    </div>
  </main>
</div>

<script>
function getCsrf() {
  const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
  const header = document.querySelector('meta[name="csrf-header"]')?.getAttribute('content');
  return { token, header };
}

document.getElementById('formPass').addEventListener('submit', async (e) => {
  e.preventDefault();
  const msg = document.getElementById('msg');
  msg.textContent = '';
  msg.className = 'text-sm';

  const fd = new FormData(e.target);
  const current = fd.get('current_password');
  const newp = fd.get('new_password');
  const conf = fd.get('confirm_password');

  if (newp !== conf) {
    msg.textContent = 'Las contraseñas no coinciden.';
    msg.className = 'text-sm text-red-600';
    return;
  }

  const { token, header } = getCsrf();

  const res = await fetch('/api/mi-cuenta/cambiar-password', {
    method: 'POST',
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      ...(header && token ? { [header]: token } : {})
    },
    body: JSON.stringify({ current_password: current, new_password: newp })
  });

  const data = await res.json().catch(() => ({}));

  // Si tu app rota el CSRF token en cada request y lo envías en respuesta,
  // aquí puedes refrescarlo. (Opcional: depende de tu config)
  // if (data?.csrf_hash) document.querySelector('meta[name="csrf-token"]').setAttribute('content', data.csrf_hash);

  if (!res.ok || !data.success) {
    msg.textContent = data.message || 'Error al cambiar contraseña.';
    msg.className = 'text-sm text-red-600';
    return;
  }

  e.target.reset();
  msg.textContent = 'Contraseña actualizada correctamente ✅';
  msg.className = 'text-sm text-green-600';
});
</script>
</body>
</html>
