(() => {
  function supportChatFactory() {
    const SUPPORT = window.SUPPORT || {};
    const endpoints = SUPPORT.endpoints || {};

    return {
      role: SUPPORT.role || '',
      userId: SUPPORT.userId || 0,
      endpoints,
      csrf: SUPPORT.csrf || null,

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

      filter: (SUPPORT.role === 'admin') ? 'unassigned' : 'mine',
      q: '',
      adminStatus: 'open',

      pollTimer: null,

      get isAdmin() { return this.role === 'admin'; },

      get filteredTickets() {
        let list = this.tickets;

        const query = this.q.trim().toLowerCase();
        if (query) {
          list = list.filter(t =>
            String(t?.ticket_code || '').toLowerCase().includes(query) ||
            String(t?.order_id || '').toLowerCase().includes(query)
          );
        }

        if (!this.isAdmin) return list;

        if (this.filter === 'unassigned') return list.filter(t => !t?.assigned_to);
        if (this.filter === 'mine') return list.filter(t => String(t?.assigned_to) === String(this.userId));
        return list;
      },

      async init() {
        if (!this.endpoints?.tickets || !this.endpoints?.ticket) {
          console.error('[SupportChat] endpoints faltantes', this.endpoints);
          return;
        }
        await this.loadTickets();

        const first = this.filteredTickets.find(t => t?.id);
        if (first?.id && !this.selectedTicketId) {
          await this.openTicket(first.id);
        }
      },

      async loadTickets() {
        const r = await fetch(this.endpoints.tickets);
        const data = await r.json().catch(() => null);

        if (!r.ok) {
          console.error('[SupportChat] loadTickets error', r.status, data);
          return;
        }

        let list = Array.isArray(data) ? data : (data?.tickets || []);
        list = list.filter(Boolean);

        const map = new Map();
        for (const t of list) {
          const k = String(t.id ?? t.ticket_code ?? '');
          if (!k) continue;
          if (!map.has(k)) map.set(k, t);
        }
        this.tickets = [...map.values()];
      },

      async openTicket(id) {
        if (!id) return;
        this.isCreating = false;
        this.selectedTicketId = id;
        await this.loadTicket();
        this.startPolling();
      },

      async loadTicket() {
        if (!this.selectedTicketId) return;

        const url = `${this.endpoints.ticket}${this.selectedTicketId}`;
        const r = await fetch(url);
        const data = await r.json().catch(() => ({}));

        if (!r.ok) {
          console.error('[SupportChat] loadTicket error', r.status, data);
          alert(data?.error || 'No se pudo abrir el ticket');
          return;
        }

        this.ticket = data.ticket || null;
        this.messages = data.messages || [];
        this.attachments = data.attachments || {};
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

      _appendCSRF(fd) {
        if (this.csrf && this.csrf.name && this.csrf.hash) {
          fd.append(this.csrf.name, this.csrf.hash);
        }
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

        this.files.forEach(f => fd.append('images[]', f));
        this._appendCSRF(fd);

        try {
          if (this.isCreating) {
            const r = await fetch(this.endpoints.create, { method: 'POST', body: fd });
            const data = await r.json().catch(() => ({}));

            if (!r.ok) {
              console.error('[SupportChat] create error', r.status, data);
              alert(data?.error || 'Error creando ticket');
              return;
            }

            const newId = data.ticket_id ?? data.id ?? data.ticket?.id ?? null;

            await this.loadTickets();
            this.isCreating = false;

            if (!newId) {
              alert('Ticket creado, pero el servidor no devolvió el ID.');
              return;
            }

            this.selectedTicketId = newId;
            await this.loadTicket();
            this.startPolling();
          } else {
            if (!this.selectedTicketId) return;

            const r = await fetch(`${this.endpoints.message}${this.selectedTicketId}/message`, { method: 'POST', body: fd });
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

        const r = await fetch(`${this.endpoints.assign}${this.ticket.id}/assign`, { method: 'POST' });
        const data = await r.json().catch(() => ({}));

        if (!r.ok) {
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
        this._appendCSRF(fd);

        const r = await fetch(`${this.endpoints.status}${this.ticket.id}/status`, { method: 'POST', body: fd });
        const data = await r.json().catch(() => ({}));

        if (!r.ok) {
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
        return t.slice(0,5);
      },

      formatDT(dt) {
        if (!dt) return '';
        return String(dt).slice(0,16);
      },

      statusLabel(s) {
        return ({
          open: 'Abierto',
          in_progress: 'En proceso',
          waiting_customer: 'Esperando info',
          resolved: 'Resuelto',
          closed: 'Cerrado'
        })[s] || (s || '');
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
    };
  }

  function register() {
    if (!window.Alpine) return;
    if (window.__supportChatRegistered) return;
    window.__supportChatRegistered = true;
    window.Alpine.data('supportChat', supportChatFactory);
  }

  document.addEventListener('alpine:init', register);
  if (window.Alpine) register();
})();
