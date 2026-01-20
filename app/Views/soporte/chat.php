<div class="min-h-[70vh] p-4" x-data="supportChat()" x-init="init()">

  <script>
    const USER_ROLE = "<?= esc(session('rol') ?? '') ?>";
    const IS_ADMIN = USER_ROLE === 'admin';
    const MY_USER_ID = <?= (int)(session('user_id') ?? 0) ?>;
  </script>

  <div class="grid md:grid-cols-[340px_1fr] gap-4">

    <!-- LISTA -->
    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
      <div class="p-4 border-b border-slate-200 flex items-center justify-between">
        <div class="font-extrabold text-slate-900">Soporte</div>

        <!-- Nuevo ticket SOLO produccion -->
        <button
          x-show="!IS_ADMIN"
          @click="startNew()"
          class="text-sm font-semibold px-3 py-2 rounded-xl border border-slate-200 hover:bg-slate-50">
          Nuevo
        </button>
      </div>

      <!-- Filtros SOLO admin -->
      <div class="p-3 border-b border-slate-200" x-show="IS_ADMIN">
        <div class="flex gap-2">
          <button @click="filter='unassigned'"
            class="px-3 py-2 rounded-xl text-xs font-semibold border"
            :class="filter==='unassigned' ? 'bg-slate-900 text-white border-slate-900' : 'border-slate-200 hover:bg-slate-50'">
            Sin asignar
          </button>

          <button @click="filter='mine'"
            class="px-3 py-2 rounded-xl text-xs font-semibold border"
            :class="filter==='mine' ? 'bg-slate-900 text-white border-slate-900' : 'border-slate-200 hover:bg-slate-50'">
            Asignados a mí
          </button>

          <button @click="filter='all'"
            class="px-3 py-2 rounded-xl text-xs font-semibold border"
            :class="filter==='all' ? 'bg-slate-900 text-white border-slate-900' : 'border-slate-200 hover:bg-slate-50'">
            Todos
          </button>
        </div>
      </div>

      <div class="p-3 space-y-2 max-h-[70vh] overflow-auto">
        <template x-for="t in filteredTickets" :key="t.id">
          <button
            @click="openTicket(t.id)"
            class="w-full text-left p-3 rounded-2xl border transition"
            :class="selectedTicketId===t.id ? 'border-slate-900' : 'border-slate-200 hover:bg-slate-50'">

            <div class="flex items-center justify-between gap-2">
              <div class="font-bold text-slate-900" x-text="t.ticket_code"></div>

              <span class="text-xs font-semibold px-2 py-1 rounded-lg"
                    :class="badgeClass(t.status)"
                    x-text="statusLabel(t.status)">
              </span>
            </div>

            <div class="text-xs text-slate-500 mt-1" x-show="t.order_id">
              Pedido: <span class="font-semibold" x-text="t.order_id"></span>
            </div>

            <!-- admin: mostrar asignación -->
            <div class="text-xs text-slate-500 mt-1" x-show="IS_ADMIN">
              <template x-if="t.assigned_to">
                <span>Aceptado por: <span class="font-semibold" x-text="t.assigned_name || ('#'+t.assigned_to)"></span></span>
              </template>
              <template x-if="!t.assigned_to">
                <span class="text-amber-700 font-semibold">Sin asignar</span>
              </template>
            </div>

          </button>
        </template>

        <div x-show="filteredTickets.length===0" class="text-sm text-slate-500 p-3">
          No hay tickets en este filtro.
        </div>
      </div>
    </div>

    <!-- CHAT -->
    <div class="bg-white rounded-2xl border border-slate-200 flex flex-col overflow-hidden">
      <div class="p-4 border-b border-slate-200 flex items-start justify-between gap-3">
        <div>
          <div class="font-extrabold text-slate-900">
            <span x-show="ticket" x-text="ticket.ticket_code"></span>
            <span x-show="isCreating" class="text-slate-500">Nuevo ticket</span>
          </div>

          <div class="text-xs text-slate-500 mt-1" x-show="ticket">
            Estado: <span class="font-semibold" x-text="statusLabel(ticket.status)"></span>

            <template x-if="ticket.order_id">
              <span> · Pedido: <span class="font-semibold" x-text="ticket.order_id"></span></span>
            </template>

            <template x-if="ticket.assigned_to">
              <span>
                · Aceptado por:
                <span class="font-semibold" x-text="ticket.assigned_name || ('#'+ticket.assigned_to)"></span>
                · <span class="font-semibold" x-text="ticket.assigned_at"></span>
              </span>
            </template>
          </div>

          <!-- Botón aceptar caso (admin + sin asignar) -->
          <div class="mt-2" x-show="ticket && IS_ADMIN && !ticket.assigned_to">
            <button
              @click="acceptCase()"
              class="px-3 py-2 rounded-xl bg-emerald-600 text-white text-sm font-semibold hover:opacity-90">
              Aceptar caso
            </button>
          </div>
        </div>

        <!-- Admin: cambiar estado -->
        <div x-show="ticket && IS_ADMIN" class="flex flex-col gap-2">
          <select
            x-model="adminStatus"
            class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
            <option value="open">Abierto</option>
            <option value="in_progress">En proceso</option>
            <option value="waiting_customer">Esperando info</option>
            <option value="resolved">Resuelto</option>
            <option value="closed">Cerrado</option>
          </select>

          <button
            @click="updateStatus()"
            class="px-3 py-2 rounded-xl border border-slate-200 text-sm font-semibold hover:bg-slate-50">
            Guardar estado
          </button>
        </div>
      </div>

      <!-- MENSAJES -->
      <div class="p-4 space-y-3 flex-1 overflow-auto" x-ref="thread">
        <template x-for="m in messages" :key="m.id">
          <div class="flex" :class="m.sender==='user' ? 'justify-end' : 'justify-start'">
            <div class="max-w-[80%] rounded-2xl px-4 py-3"
                 :class="m.sender==='user' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-900'">
              <div x-show="m.message" class="text-sm whitespace-pre-wrap" x-text="m.message"></div>

              <!-- adjuntos -->
              <div class="mt-2 grid grid-cols-2 gap-2" x-show="attachments[m.id]">
                <template x-for="a in (attachments[m.id] || [])" :key="a.id">
                  <a class="block overflow-hidden rounded-xl border border-white/10"
                     :href="`<?= base_url('soporte/attachment') ?>/${a.id}`"
                     target="_blank">
                    <img class="w-full h-28 object-cover" :src="`<?= base_url('soporte/attachment') ?>/${a.id}`" alt="">
                  </a>
                </template>
              </div>

              <div class="text-[10px] opacity-70 mt-2" x-text="m.created_at"></div>
            </div>
          </div>
        </template>

        <div x-show="!ticket && !isCreating" class="text-sm text-slate-500">
          Selecciona un ticket para ver el chat.
        </div>
      </div>

      <!-- FORM ENVIAR -->
      <form class="p-4 border-t border-slate-200 space-y-3" @submit.prevent="send()">

        <!-- order_id solo en creación -->
        <div class="grid md:grid-cols-[240px_1fr] gap-3" x-show="isCreating">
          <input type="text" x-model="orderId"
                 placeholder="Número de pedido (opcional)"
                 class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-200">
          <div class="text-xs text-slate-500 flex items-center">
            Si aplica a un pedido, pon el número aquí.
          </div>
        </div>

        <textarea x-model="draft" rows="2"
          class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-slate-200"
          placeholder="Escribe tu mensaje..."></textarea>

        <div class="flex items-center justify-between gap-3">
          <input type="file" multiple accept="image/*" @change="pickFiles($event)"
                 class="text-sm" name="images[]">

          <button type="submit"
            class="px-4 py-2 rounded-xl bg-slate-900 text-white font-semibold disabled:opacity-50"
            :disabled="sending">
            <span x-show="!sending">Enviar</span>
            <span x-show="sending">Enviando…</span>
          </button>
        </div>

        <!-- preview -->
        <div class="flex gap-2 flex-wrap" x-show="files.length">
          <template x-for="(f, idx) in files" :key="idx">
            <div class="text-xs px-2 py-1 rounded-lg bg-slate-100 border border-slate-200">
              <span x-text="f.name"></span>
              <button type="button" class="ml-2 text-red-600" @click="files.splice(idx,1)">x</button>
            </div>
          </template>
        </div>

      </form>
    </div>
  </div>
</div>

<script>
function supportChat(){
  return {
    // data
    tickets: [],
    filter: IS_ADMIN ? 'unassigned' : 'mine',

    ticket: null,
    selectedTicketId: null,
    messages: [],
    attachments: {},

    // admin
    adminStatus: 'open',

    // create/send
    isCreating: false,
    orderId: '',
    draft: '',
    files: [],
    sending: false,

    // polling
    pollTimer: null,

    // computed
    get filteredTickets(){
      if (!IS_ADMIN) return this.tickets;

      if (this.filter === 'unassigned') return this.tickets.filter(t => !t.assigned_to);
      if (this.filter === 'mine') return this.tickets.filter(t => String(t.assigned_to) === String(MY_USER_ID));
      return this.tickets;
    },

    async init(){
      await this.loadTickets();
      if (!IS_ADMIN && this.tickets.length && !this.selectedTicketId) {
        this.openTicket(this.tickets[0].id);
      }
      if (IS_ADMIN && this.filteredTickets.length && !this.selectedTicketId) {
        this.openTicket(this.filteredTickets[0].id);
      }
    },

    async loadTickets(){
      const r = await fetch("<?= base_url('soporte/tickets') ?>");
      this.tickets = await r.json();
    },

    startNew(){
      this.isCreating = true;
      this.ticket = null;
      this.selectedTicketId = null;
      this.messages = [];
      this.attachments = {};
      this.orderId = '';
      this.draft = '';
      this.files = [];
      this.stopPolling();
    },

    async openTicket(id){
      this.isCreating = false;
      this.selectedTicketId = id;
      await this.loadTicket();
      this.startPolling();
    },

    async loadTicket(){
      const r = await fetch(`<?= base_url('soporte/ticket') ?>/${this.selectedTicketId}`);
      const data = await r.json();

      if (data.error) {
        alert(data.error);
        return;
      }

      this.ticket = data.ticket;
      this.messages = data.messages || [];
      this.attachments = data.attachments || {};
      this.adminStatus = this.ticket?.status || 'open';

      this.scrollToBottom();
    },

    startPolling(){
      this.stopPolling();
      this.pollTimer = setInterval(() => {
        if (this.selectedTicketId) this.loadTicket();
        this.loadTickets();
      }, 4000);
    },

    stopPolling(){
      if (this.pollTimer) clearInterval(this.pollTimer);
      this.pollTimer = null;
    },

    pickFiles(e){
      this.files = Array.from(e.target.files || []);
    },

    async send(){
      if (this.sending) return;
      if (!this.draft.trim() && this.files.length === 0) return;

      this.sending = true;

      const fd = new FormData();
      fd.append('message', this.draft);

      if (this.isCreating && this.orderId.trim()) {
        fd.append('order_id', this.orderId.trim());
      }

      this.files.forEach(f => fd.append('images[]', f));

      try{
        if (this.isCreating){
          const r = await fetch("<?= base_url('soporte/ticket') ?>", { method:'POST', body: fd });
          const data = await r.json();

          if (!r.ok) {
            alert(data.error || 'Error creando ticket');
            return;
          }

          await this.loadTickets();
          this.isCreating = false;
          this.selectedTicketId = data.ticket_id;
          await this.loadTicket();
          this.startPolling();
        } else {
          const r = await fetch(`<?= base_url('soporte/ticket') ?>/${this.selectedTicketId}/message`, { method:'POST', body: fd });
          const data = await r.json().catch(()=>({}));
          if (!r.ok) {
            alert(data.error || 'Error enviando mensaje');
            return;
          }

          await this.loadTicket();
          await this.loadTickets();
        }

        this.draft = '';
        this.files = [];
      } finally {
        this.sending = false;
      }
    },

    async acceptCase(){
      if (!this.ticket) return;

      const r = await fetch(`<?= base_url('soporte/ticket') ?>/${this.ticket.id}/assign`, { method:'POST' });
      const data = await r.json().catch(()=>({}));

      if (!r.ok) {
        alert(data.error || 'No se pudo asignar');
        return;
      }

      await this.loadTicket();
      await this.loadTickets();
    },

    async updateStatus(){
      if (!this.ticket || !IS_ADMIN) return;

      const fd = new FormData();
      fd.append('status', this.adminStatus);

      const r = await fetch(`<?= base_url('soporte/ticket') ?>/${this.ticket.id}/status`, { method:'POST', body: fd });
      const data = await r.json().catch(()=>({}));

      if (!r.ok) {
        alert(data.error || 'No se pudo cambiar el estado');
        return;
      }

      await this.loadTicket();
      await this.loadTickets();
    },

    scrollToBottom(){
      this.$nextTick(() => {
        const el = this.$refs.thread;
        if (el) el.scrollTop = el.scrollHeight;
      });
    },

    statusLabel(s){
      return ({
        open: 'Abierto',
        in_progress: 'En proceso',
        waiting_customer: 'Esperando info',
        resolved: 'Resuelto',
        closed: 'Cerrado'
      })[s] || s;
    },

    badgeClass(s){
      return ({
        open: 'bg-amber-100 text-amber-700',
        in_progress: 'bg-blue-100 text-blue-700',
        waiting_customer: 'bg-purple-100 text-purple-700',
        resolved: 'bg-green-100 text-green-700',
        closed: 'bg-slate-200 text-slate-700',
      })[s] || 'bg-slate-100 text-slate-700';
    }
  }
}
</script>
