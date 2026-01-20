(() => {
  // Factory del componente
  function supportChatFactory() {
    const SUPPORT = window.SUPPORT || {};
    const endpoints = SUPPORT.endpoints || {};

    return {
      // session/env
      role: SUPPORT.role || '',
      userId: SUPPORT.userId || 0,
      endpoints,

      // ui state
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

      // admin
      filter: (SUPPORT.role === 'admin') ? 'unassigned' : 'mine',
      q: '',
      adminStatus: 'open',

      pollTimer: null,

      get isAdmin() { return this.role === 'admin'; },

      get filteredTickets() {
        let list = this.tickets;

        // search
        if (this.q.trim()) {
          const s = this.q.trim().toLowerCase();
          list = list.filter(t =>
            String(t.ticket_code || '').toLowerCase().includes(s) ||
            String(t.order_id || '').toLowerCase().includes(s)
          );
        }

        // role filter
        if (!this.isAdmin) return list;

        if (this.filter === 'unassigned') return list.filter(t => !t.assigned_to);
        if (this.filter === 'mine') return list.filter(t => String(t.assigned_to) === String(this.userId));
        return list;
      },

      async init() {
        if (!this.endpoints?.tickets || !this.endpoints?.ticket) {
          console.error('[SupportChat] endpoints faltantes', this.endpoints);
          return;
        }

        await this.loadTickets();

        // auto-open first ticket
        const first = this.filteredTickets[0];
        if (first && !this.selectedTicketId) {
          await this.openTicket(first.id);
        }
      },

      async loadTickets() {
        const r = await fetch(this.endpoints.tickets);
        this.tickets = await r.json();
      },

      async openTicket(id) {
        this.isCreating = false;
        this.selectedTicketId = id;
        await this.loadTicket();
        this.startPolling();
      },

      async loadTicket() {
        const r = await fetch(`${this.endpoints.ticket}/${this.selectedTicketId}`);
        const data = await r.json().catch(() => ({}));

        if (!r.ok) {
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

        try {
          if (this.isCreating) {
            const r = await fetch(this.endpoints.create, { method: 'POST', body: fd });
            const data = await r.json().catch(() => ({}));

            if (!r.ok) {
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
    };
  }

  // Exponer global (por si quieres x-data="supportChat()")
  window.supportChat = supportChatFactory;

  // Registrar siempre, incluso si Alpine ya empezó
  function register() {
    if (!window.Alpine) return;

    if (window.__supportChatRegistered) return;
    window.__supportChatRegistered = true;

    window.Alpine.data('supportChat', supportChatFactory);

    // Si Alpine ya estaba corriendo, inicializa el árbol del chat
    try {
      const el = document.querySelector('[x-data="supportChat"], [x-data="supportChat()"]');
      if (el) window.Alpine.initTree(el);
    } catch (e) {}
  }

  document.addEventListener('alpine:init', register);
  if (window.Alpine) register();
  document.addEventListener('DOMContentLoaded', () => { if (window.Alpine) register(); });
})();
