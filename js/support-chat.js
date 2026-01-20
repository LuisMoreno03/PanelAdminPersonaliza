document.addEventListener('alpine:init', () => {
  Alpine.data('supportChat', () => ({
    role: (window.SUPPORT?.role || ''),
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

    filter: (window.SUPPORT?.role === 'admin') ? 'unassigned' : 'mine',
    q: '',
    adminStatus: 'open',

    pollTimer: null,

    get isAdmin() { return this.role === 'admin'; },

    get filteredTickets() {
      let list = this.tickets || [];

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

    async fetchJSON(url, opts = {}) {
      const r = await fetch(url, opts);
      let data = null;
      try { data = await r.json(); } catch (e) { data = null; }
      return { r, data };
    },

    async init() {
      await this.loadTickets();

      const first = this.filteredTickets[0];
      if (first && !this.selectedTicketId) {
        await this.openTicket(first.id);
      }
    },

    async loadTickets() {
      const { r, data } = await this.fetchJSON(this.endpoints.tickets);
      if (!r.ok) {
        console.error('[SupportChat] loadTickets error', r.status, data);
        this.tickets = [];
        return;
      }
      this.tickets = Array.isArray(data) ? data : [];
    },

    async openTicket(id) {
      this.isCreating = false;
      this.selectedTicketId = id;
      await this.loadTicket();
      this.startPolling();
    },

    async loadTicket() {
      if (!this.selectedTicketId) return;

      const base = this.endpoints.ticket.endsWith('/') ? this.endpoints.ticket : (this.endpoints.ticket + '/');
      const { r, data } = await this.fetchJSON(`${base}${this.selectedTicketId}`);

      if (!r.ok) {
        console.error('[SupportChat] loadTicket error', r.status, data);
        alert(data?.error || 'No se pudo abrir el ticket');
        return;
      }

      this.ticket = data?.ticket || null;
      this.messages = data?.messages || [];
      this.attachments = data?.attachments || {};
      this.adminStatus = this.ticket?.status || 'open';

      this.scrollToBottom();
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

      // ✅ clave "images" (y backend soporta images[] también)
      this.files.forEach(f => fd.append('images', f));

      try {
        if (this.isCreating) {
          const { r, data } = await this.fetchJSON(this.endpoints.create, { method: 'POST', body: fd });

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
          const base = this.endpoints.message.endsWith('/') ? this.endpoints.message : (this.endpoints.message + '/');
          const { r, data } = await this.fetchJSON(`${base}${this.selectedTicketId}/message`, { method: 'POST', body: fd });

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

      const base = this.endpoints.assign.endsWith('/') ? this.endpoints.assign : (this.endpoints.assign + '/');
      const { r, data } = await this.fetchJSON(`${base}${this.ticket.id}/assign`, { method: 'POST' });

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

      const base = this.endpoints.status.endsWith('/') ? this.endpoints.status : (this.endpoints.status + '/');
      const { r, data } = await this.fetchJSON(`${base}${this.ticket.id}/status`, { method: 'POST', body: fd });

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
      })[s] || s;
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
