<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <meta name="csrf-token" content="<?= csrf_hash() ?>">
  <meta name="csrf-header" content="<?= csrf_header() ?>">

  <title>Usuarios - Panel</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/alpinejs" defer></script>
</head>

<body class="bg-slate-50">
<div class="min-h-screen flex">

  <!-- SIDEBAR (reusa el tuyo; aquí va un ejemplo) -->
  <aside class="w-64 bg-slate-900 text-white p-4">
    <div class="text-lg font-bold mb-6">Panel Admin</div>
    <nav class="space-y-2 text-sm">
      <a class="block px-3 py-2 rounded hover:bg-slate-800" href="/dashboard">Dashboard</a>
      <a class="block px-3 py-2 rounded bg-slate-800" href="/usuarios">Usuarios</a>
      <a class="block px-3 py-2 rounded hover:bg-slate-800" href="/mi-cuenta">Mi cuenta</a>
    </nav>
  </aside>

  <!-- CONTENT -->
  <main class="flex-1 p-8" x-data="usuariosPage()" x-init="init()">
    <div class="max-w-6xl mx-auto">

      <div class="bg-white rounded-2xl border border-slate-200 p-6">
        <h1 class="text-3xl font-extrabold text-slate-900">Usuarios</h1>
        <p class="text-slate-500 mt-1">Listado y gestión de usuarios</p>
      </div>

      <div class="mt-6 bg-white rounded-2xl border border-slate-200 p-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
          <div class="flex items-center gap-3">
            <input
              type="text"
              x-model="q"
              placeholder="Buscar por nombre, email o rol..."
              class="w-full md:w-96 rounded-xl border border-slate-200 px-4 py-3 outline-none focus:ring-2 focus:ring-slate-900/20"
            />
            <button
              @click="load()"
              class="px-4 py-3 rounded-xl bg-blue-600 text-white font-semibold hover:bg-blue-700"
            >
              Recargar
            </button>
          </div>

          <div class="text-sm text-slate-500">
            <span x-text="filtered.length"></span> usuarios
          </div>
        </div>

        <div class="mt-5 overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead>
              <tr class="text-left text-slate-500 border-b">
                <th class="py-3 pr-4">ID</th>
                <th class="py-3 pr-4">Nombre</th>
                <th class="py-3 pr-4">Rol</th>
                <th class="py-3 pr-4">Email</th>
                <th class="py-3 pr-4">Creado</th>
              </tr>
            </thead>

            <tbody>
              <template x-if="loading">
                <tr><td class="py-4 text-slate-400" colspan="5">Cargando…</td></tr>
              </template>

              <template x-if="!loading && error">
                <tr><td class="py-4 text-red-600" colspan="5" x-text="error"></td></tr>
              </template>

              <template x-if="!loading && !error && filtered.length === 0">
                <tr><td class="py-4 text-slate-400" colspan="5">No hay resultados</td></tr>
              </template>

              <template x-for="u in filtered" :key="u.id">
                <tr class="border-b last:border-0">
                  <td class="py-3 pr-4 font-semibold text-slate-900" x-text="u.id"></td>
                  <td class="py-3 pr-4 text-slate-900" x-text="u.nombre"></td>
                  <td class="py-3 pr-4">
                    <span class="px-2 py-1 rounded-full bg-slate-100 text-slate-700 font-semibold" x-text="u.role"></span>
                  </td>
                  <td class="py-3 pr-4 text-slate-700" x-text="u.email"></td>
                  <td class="py-3 pr-4 text-slate-500" x-text="u.created_at ?? ''"></td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </main>
</div>

<script>
function usuariosPage() {
  return {
    users: [],
    q: '',
    loading: false,
    error: '',

    get filtered() {
      const s = this.q.trim().toLowerCase();
      if (!s) return this.users;
      return this.users.filter(u =>
        String(u.id).includes(s) ||
        (u.nombre || '').toLowerCase().includes(s) ||
        (u.email || '').toLowerCase().includes(s) ||
        (u.role || '').toLowerCase().includes(s)
      );
    },

    init() { this.load(); },

    async load() {
      this.loading = true;
      this.error = '';
      try {
        const res = await fetch('/api/usuarios', { credentials: 'include' });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.success) throw new Error(data.message || 'Error cargando usuarios');
        this.users = data.users || [];
      } catch (e) {
        this.error = e.message || 'Error';
        this.users = [];
      } finally {
        this.loading = false;
      }
    }
  }
}
</script>

</body>
</html>
