document.addEventListener('alpine:init', () => {
  Alpine.data('supportChat', () => ({
    role: String(window.SUPPORT?.role || '').toLowerCase(),
    userId: (window.SUPPORT?.userId || 0),
    endpoints: (window.SUPPORT?.endpoints || {}),

    tickets: [],
    selectedTicketId: null,
    ticket: null,
    messages: [],
    attachments: {},

    isCreating: false,
    draft: '',
    orderId: '',
    files: [],
    sending: false,

    filter: 'unassigned', // admin default
    q: '',
    adminStatus: 'open',
    pollTimer: null,

    get isAdmin() {
      const r = String(this.role || '').toLowerCase();
      return (r === 'admin' || r === 'administrador' || r === 'superadmin');
    },

    get filteredTickets() {
      let list = Array.isArray(this.tickets) ? this.tickets : [];

      if (this.q.trim()) {
        const s = this.q.trim().toLowerCase();
        list = list.filter(t =>
          String(t.ticket_code || '').toLowerCase().includes(s) ||
          String(t.order_id || '').toLowerCase().includes(s)
        );
      }

      if (!this.isAdmin) return list;

      if (this.filter === 'unassigned') return list.filter(t => !t.assigned_to);
      if (this.filter === 'mine') return list.filter(t => String(t.assigned_to) === String(this.userId));
      return list;
    },

    async init() {
      // admin por defecto: sin asignar, produccion: mine no aplica ya que backend filtra
      if (!this.isAdmin) this.filter = 'all';

      await this.loadTickets();

      // auto-open first ticket si existe
      const first = this.filteredTickets[0];
      if (first?.id) {
        await this.openTicket(first.id);
      }
    },

    async loadTickets() {
      try {
        const r = await fetch(this.endpoints.tickets, { headers: { 'Accept': 'application/json' }});
        const data = await r.json().catch(() => null);

        if (!r.ok) {
          console.error('[SupportChat] loadTickets error', r.status, data);
          this.tickets = [];
          return;
        }
        this.tickets = Array.isArray(data) ? data : [];
      } catch (e) {
        console.error('[SupportChat] loadTickets exception', e);
        this.tickets = [];
      }
    },

    async openTicket(id) {
      this.isCreating = false;
      this.selectedTicketId = id;
      await this.loadTicket();
      this.startPolling();
    },

    async loadTicket() {
      if (!this.selectedTicketId) return;

      try {
        const r = await fetch(`${this.endpoints.ticket}/${this.selectedTicketId}`, {
          headers: { 'Accept': 'application/json' }
        });
        const data = await r.json().catch(() => ({}));

        if (!r.ok) {
          console.error('[SupportChat] loadTicket error', r.status, data);
          alert(data?.error || 'No se pudo abrir el ticket');
          return;
        }

        this.ticket = data.ticket || null;
        this.messages = Array.isArray(data.messages) ? data.messages : [];
        this.attachments = data.attachments || {};
        this.adminStatus = this.ticket?.status || 'open';

        this.scrollToBottom();
      } catch (e) {
        console.error('[SupportChat] loadTicket exception', e);
        alert('Error interno abriendo ticket');
      }
    },

    startNew() {
      if (this.isAdmin) return;

      this.isCreating = true;
      this.selectedTicketId = null;
      this.ticket = null;
      this.messages = [];
      this.attachments = {};
      this.draft = '';
      this.orderId = '';
      this.files = [];
      this.stopPolling();
    },

    pickFiles(e) {
      this.files = Array.from(e.target.files || []);
    },

    async send() {
      if (this.sending) return;
      if (!this.draft.trim() && this.files.length === 0) return;

      this.sending = true;

      const fd = new FormData();
      fd.append('message', this.draft);

      if (this.isCreating && this.orderId.trim()) {
        fd.append('order_id', this.orderId.trim());
      }

      // OJO: backend soporta images y images[]
      this.files.forEach(f => fd.append('images[]', f));

      try {
        if (this.isCreating) {
          const r = await fetch(this.endpoints.create, { method: 'POST', body: fd });
          const data = await r.json().catch(() => ({}));

          if (!r.ok) {
            console.error('[SupportChat] create error', r.status, data);
            alert(data?.error || 'Error creando ticket');
            return;
          }

          await this.loadTickets();
          this.isCreating = false;
          this.selectedTicketId = data.ticket_id;
          await this.loadTicket();
          this.startPolling();
        } else {
          const r = await fetch(`${this.endpoints.message}/${this.selectedTicketId}/message`, { method: 'POST', body: fd });
          const data = await r.json().catch(() => ({}));

          if (!r.ok) {
            console.error('[SupportChat] message error', r.status, data);
            alert(data?.error || 'Error enviando mensaje');
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

    async acceptCase() {
      if (!this.ticket || !this.isAdmin) return;

      const r = await fetch(`${this.endpoints.assign}/${this.ticket.id}/assign`, { method: 'POST' });
      const data = await r.json().catch(() => ({}));

      if (!r.ok) {
        console.error('[SupportChat] assign error', r.status, data);
        alert(data?.error || 'No se pudo aceptar el caso');
        return;
      }

      await this.loadTicket();
      await this.loadTickets();
    },

    async updateStatus() {
      if (!this.ticket || !this.isAdmin) return;

      const fd = new FormData();
      fd.append('status', this.adminStatus);

      const r = await fetch(`${this.endpoints.status}/${this.ticket.id}/status`, { method: 'POST', body: fd });
      const data = await r.json().catch(() => ({}));

      if (!r.ok) {
        console.error('[SupportChat] status error', r.status, data);
        alert(data?.error || 'No se pudo cambiar el estado');
        return;
      }

      await this.loadTicket();
      await this.loadTickets();
    },

    startPolling() {
      this.stopPolling();
      this.pollTimer = setInterval(async () => {
        if (this.selectedTicketId) await this.loadTicket();
        await this.loadTickets();
      }, 4000);
    },

    stopPolling() {
      if (this.pollTimer) clearInterval(this.pollTimer);
      this.pollTimer = null;
    },

    scrollToBottom() {
      this.$nextTick(() => {
        const el = this.$refs.thread;
        if (el) el.scrollTop = el.scrollHeight;
      });
    },

    formatTime(dt) {
      if (!dt) return '';
      const t = String(dt).split(' ')[1] || '';
      return t.slice(0, 5);
    },

    formatDT(dt) {
      if (!dt) return '';
      return String(dt).slice(0, 16);
    },

    statusLabel(s) {
      return ({
        open: 'Abierto',
        in_progress: 'En proceso',
        waiting_customer: 'Esperando info',
        resolved: 'Resuelto',
        closed: 'Cerrado'
      })[s] || s || '';
    },

    badgeClass(s) {
      return ({
        open: 'bg-amber-100 text-amber-700',
        in_progress: 'bg-blue-100 text-blue-700',
        waiting_customer: 'bg-purple-100 text-purple-700',
        resolved: 'bg-green-100 text-green-700',
        closed: 'bg-slate-200 text-slate-700'
      })[s] || 'bg-slate-100 text-slate-700';
    }
  }));
});
