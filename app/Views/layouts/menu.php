<!-- SIDEBAR RESPONSIVE -->
<div x-data="{ open: false }">

    <!-- Botón hamburger para móviles -->
    <button @click="open = !open" 
        class="md:hidden p-4 text-gray-200 focus:outline-none">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8" fill="none"
            viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M4 6h16M4 12h16M4 18h16" />
        </svg>
    </button>

    <!-- Sidebar -->
    <aside 
        :class="open ? 'translate-x-0' : '-translate-x-full'"
        class="fixed md:translate-x-0 top-0 left-0 h-full w-64 bg-gray-900 text-white shadow-xl transform transition-all duration-300 z-50">

        <div class="p-6 text-2xl font-bold border-b border-gray-700">
            Panel Admin
        </div>

        <nav class="mt-6 space-y-2 px-4">

            <a href="<?= base_url('dashboard') ?>"
                class="block px-4 py-3 rounded-lg hover:bg-gray-700 transition">
                📊 Dashboard
            </a>

            <a href="<?= base_url('pedidos') ?>"
                class="block px-4 py-3 rounded-lg hover:bg-gray-700 transition">
                🛒 Produccion
            </a>

            <a href="<?= base_url('productos') ?>"
                class="block px-4 py-3 rounded-lg hover:bg-gray-700 transition">
                🎁 Placas
            </a>

            <a href="<?= base_url('usuarios') ?>"
                class="block px-4 py-3 rounded-lg hover:bg-gray-700 transition">
                👤 Usuarios
            </a>

            <a href="<?= base_url('/logout') ?>"
                class="block px-4 py-3 rounded-lg hover:bg-red-600 transition mt-10">
                ❌ Cerrar sesión
            </a>

        </nav>
    </aside>

</div>
