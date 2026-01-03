<!-- SIDEBAR MODERNO + RESPONSIVE + COLAPSABLE (Alpine.js) -->
<div
  x-data="{
    open: false,          // móvil
    collapsed: false,     // desktop
    init() {
      // estado guardado
      this.collapsed = localStorage.getItem('menuCollapsed') === '1';
      this.syncMain();

      // cerrar menú móvil si pasas a desktop
      window.addEventListener('resize', () => {
        if (window.innerWidth >= 768) this.open = false;
      });
    },
    close() { this.open = false },
    toggleMobile() { this.open = !this.open },
    toggleCollapse() {
      this.collapsed = !this.collapsed;
      localStorage.setItem('menuCollapsed', this.collapsed ? '1' : '0');
      this.syncMain();
    },
    syncMain() {
      // ✅ Ajusta el main (dashboard) si existe
      try {
        if (window.setMenuCollapsed) {
          window.setMenuCollapsed(this.collapsed);
        } else {
          const main = document.getElementById('mainLayout');
          if (main) {
            main.classList.toggle('menu-collapsed', this.collapsed);
          }
        }
        window.dispatchEvent(new Event('resize'));
      } catch (e) {}
    }
  }"
  x-init="init()"
  x-on:keydown.window.escape="close()"
>

  <!-- TOP BAR MÓVIL -->
  <div class="md:hidden sticky top-0 z-40 bg-white/80 backdrop-blur border-b border-slate-200">
    <div class="flex items-center justify-between px-4 py-3">
      <button
        @click="toggleMobile()"
        class="inline-flex items-center justify-center h-11 w-11 rounded-xl border border-slate-200 bg-white shadow-sm active:scale-[0.98] transition"
        aria-label="Abrir menú"
      >
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-slate-800" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
      </button>

      <div class="flex flex-col leading-tight">
        <span class="text-sm font-extrabold text-slate-900">Panel Admin</span>
        <span class="text-xs text-slate-500">Usuario: <?= esc(session('nombre') ?? '—') ?></span>
      </div>

      <div class="w-11"></div>
    </div>
  </div>

  <!-- OVERLAY (solo móvil) -->
  <div
    x-show="open"
    x-transition.opacity
    class="fixed inset-0 bg-black/40 backdrop-blur-sm z-40 md:hidden"
    @click="close()"
    style="display:none"
    aria-hidden="true"
  ></div>

  <!-- SIDEBAR -->
  <aside
    :class="[
      // móvil
      open ? 'translate-x-0' : '-translate-x-full',
      // desktop colapsado/ancho
      collapsed ? 'md:w-[84px]' : 'md:w-64'
    ]"
    class="fixed md:translate-x-0 top-0 left-0 z-50 md:z-30 h-full w-72
           bg-slate-900 text-white shadow-2xl transform transition-all duration-300"
  >

    <!-- HEADER -->
    <div class="p-5 border-b border-white/10">
      <div class="flex items-start justify-between gap-3">

        <div class="min-w-0">
          <h2 class="text-lg font-extrabold tracking-tight truncate"
              x-show="!collapsed"
              x-transition.opacity
              style="display:none">
            Panel Admin
          </h2>

          <p class="text-xs text-white/70 mt-1"
             x-show="!collapsed"
             x-transition.opacity
             style="display:none">
            Usuario: <span class="font-semibold text-white"><?= esc(session('nombre') ?? '—') ?></span>
          </p>

          <!-- mini header cuando está colapsado -->
          <div x-show="collapsed" style="display:none" class="flex flex-col gap-1">
            <div class="text-[11px] font-extrabold tracking-wide text-white/90">PA</div>
            <div class="text-[10px] text-white/50 truncate"><?= esc(session('nombre') ?? '—') ?></div>
          </div>
        </div>

        <div class="flex items-center gap-2">
          <!-- ✅ Colapsar (solo desktop) -->
          <button
            @click="toggleCollapse()"
            class="hidden md:inline-flex items-center justify-center h-10 w-10 rounded-xl bg-white/10 hover:bg-white/15 transition"
            :aria-label="collapsed ? 'Expandir menú' : 'Colapsar menú'"
            :title="collapsed ? 'Expandir menú' : 'Colapsar menú'"
          >
            <!-- Icono -->
            <svg x-show="!collapsed" style="display:none" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            <svg x-show="collapsed" style="display:none" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
          </button>

          <!-- ✅ cerrar (móvil) -->
          <button
            @click="close()"
            class="md:hidden inline-flex items-center justify-center h-10 w-10 rounded-xl bg-white/10 hover:bg-white/15 transition"
            aria-label="Cerrar menú"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>

      </div>

      <!-- Search (opcional) solo si NO está colapsado -->
      <div class="mt-4" x-show="!collapsed" x-transition.opacity style="display:none">
        <div class="relative">
          <span class="absolute left-3 top-1/2 -translate-y-1/2 text-white/50">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M21 21l-4.3-4.3m1.3-5.2a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
          </span>
          <input
            type="text"
            placeholder="Buscar…"
            class="w-full bg-white/10 border border-white/10 rounded-xl pl-9 pr-3 py-2 text-sm
                   placeholder:text-white/40 focus:outline-none focus:ring-2 focus:ring-white/20"
          />
        </div>
      </div>
    </div>

    <!-- NAV -->
    <nav class="p-3 space-y-1">

      <?php
        $path = service('uri')->getPath();
        $isActive = function($needle) use ($path) { return str_contains('/'.$path, $needle); };
        $item = function($href, $active, $label, $icon) { ?>
          <a href="<?= $href ?>"
             @click="close()"
             class="group flex items-center gap-3 px-4 py-3 rounded-2xl transition
                    <?= $active ? 'bg-white/12 ring-1 ring-white/15' : 'hover:bg-white/10' ?>">
            <span class="text-white/90 shrink-0">
              <?= $icon ?>
            </span>

            <!-- texto solo expandido -->
            <span class="font-semibold truncate"
                  x-show="!collapsed"
                  x-transition.opacity
                  style="display:none">
              <?= $label ?>
            </span>

            <!-- tooltip cuando colapsado -->
            <span x-show="collapsed" style="display:none"
                  class="pointer-events-none absolute left-[92px] opacity-0 group-hover:opacity-100 transition
                         bg-slate-950 text-white text-xs font-semibold px-3 py-2 rounded-xl shadow-lg">
              <?= $label ?>
            </span>
          </a>
      <?php }; ?>

      <?php $item(base_url('dashboard'),   $isActive('dashboard'),   'Dashboard',  '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 13h8V3H3v10zm10 8h8V11h-8v10zM3 21h8v-6H3v6zm10-18h8v6h-8V3z"/></svg>'); ?>
      <?php $item(base_url('confirmados'), $isActive('confirmados'), 'Confirmados','<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12v8a2 2 0 01-2 2H6a2 2 0 01-2-2v-8m16 0V8a2 2 0 00-2-2H6a2 2 0 00-2 2v4m16 0H4m8-8v16"/></svg>'); ?>
      <?php $item(base_url('produccion'),  $isActive('produccion'),  'Producción', '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2 9m13-9l2 9M10 21a1 1 0 100-2 1 1 0 000 2zm8 0a1 1 0 100-2 1 1 0 000 2z"/></svg>'); ?>
      <?php $item(base_url('placas'),      $isActive('placas'),      'Placas',     '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4 8 4 8-4zm0 0v10l-8 4-8-4V7m8 4v10"/></svg>'); ?>
      <?php $item(base_url('usuarios'),    $isActive('usuarios'),    'Usuarios',   '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A8.967 8.967 0 0112 15c2.5 0 4.764 1.02 6.879 2.804M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>'); ?>

      <div class="my-3 border-t border-white/10"></div>

      <a href="<?= base_url('logout') ?>"
         class="group flex items-center gap-3 px-4 py-3 rounded-2xl transition hover:bg-rose-600/20 relative">
        <span class="text-rose-200 shrink-0">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h6a2 2 0 012 2v1"/>
          </svg>
        </span>

        <span class="font-semibold text-rose-100 truncate"
              x-show="!collapsed"
              x-transition.opacity
              style="display:none">
          Cerrar sesión
        </span>

        <span x-show="collapsed" style="display:none"
              class="pointer-events-none absolute left-[92px] opacity-0 group-hover:opacity-100 transition
                     bg-slate-950 text-white text-xs font-semibold px-3 py-2 rounded-xl shadow-lg">
          Cerrar sesión
        </span>
      </a>

    </nav>

    <!-- FOOTER -->
    <div class="mt-auto p-4 border-t border-white/10 text-xs text-white/50">
      <div class="flex items-center justify-between">
        <span x-show="!collapsed" style="display:none">Panel</span>
        <span class="font-semibold text-white/70" x-show="!collapsed" style="display:none">v1.0</span>

        <span x-show="collapsed" style="display:none" class="w-full text-center text-white/50">v1</span>
      </div>
    </div>

  </aside>

</div>
