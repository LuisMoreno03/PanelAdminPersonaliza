document.addEventListener('alpine:init', () => {
  Alpine.data('supportChat', () => ({
    // session/env
    role: (String(window.SUPPORT?.role || '')).toLowerCase().trim(),
    userId: Number(window.SUPPORT?.userId || 0),
    endpoints: (window.SUPPORT?.endpoints || {}),

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
    previews: [],
    sending: false,
    errorText: '',

    // admin filters
    filter: 'unassigned',
    q: '',
    adminStatus: 'open',

    // notifications
    notifyEnabled: (localStorage.getItem('supportNotify') === '1'),
    lastMsgIdByTicket: {},

    // emojis
    showEmoji: false,
    quickEmojis: ['😀','😅','😂','😍','😎','🤝','🙏','🔥','✅','⚠️','❌','📦','🖼️','📝','📌','🚀'],

    pollTimer: null,

    get isAdmin() { return this.role === 'admin'; },

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
      // normaliza rol (por si viene "Admin")
      this.role = (this.role || '').toLowerCase().trim();

      await this.loadTickets();

      // abre primero si existe
      const first = this.filteredTickets[0];
      if (first && first.id) await this.openTicket(first.id);

      this.startPolling();
    },

    // ---------- helpers fetch ----------
    async api(url, options = {}) {
      const r = await fetch(url, options);
      let data = {};
      try { data = await r.json(); } catch (e) { data = {}; }
      return { ok: r.ok, status: r.status, data };
    },

    // -------- API ----------
    async loadTickets() {
      this.errorText = '';
      const { ok, data } = await this.api(this.endpoints.tickets);

      if (!ok) {
        this.tickets = [];
        this.errorText = data?.error || 'Error cargando tickets';
        return;
      }

      this.tickets = Array.isArray(data) ? data : [];
    },

    async openTicket(id) {
      this.isCreating = false;
      this.selectedTicketId = id;
      await this.loadTicket();
    },

    async loadTicket() {
      if (!this.selectedTicketId) return;

      const { ok, data } = await this.api(`${this.endpoints.ticket}/${this.selectedTicketId}`);
      if (!ok) {
        this.errorText = data?.error || 'No se pudo abrir el ticket';
        return;
      }

      this.ticket = data.ticket || null;
      this.messages = data.messages || [];
      this.attachments = data.attachments || {};
      this.adminStatus = this.ticket?.status || 'open';

      // guardar último id para notificar solo nuevos
      const last = this.messages[this.messages.length - 1];
      if (last?.id) this.lastMsgIdByTicket[this.selectedTicketId] = last.id;

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
      this.previews = [];
      this.errorText = '';
    },

    pickFiles(e) {
      const list = Array.from(e.target.files || []);
      this.files = list;

      // previews tipo whatsapp
      this.previews.forEach(p => URL.revokeObjectURL(p.url));
      this.previews = list.map(f => ({
        name: f.name,
        url: URL.createObjectURL(f),
      }));

      // reset input para permitir seleccionar el mismo archivo otra vez
      e.target.value = '';
    },

    removePreview(idx) {
      const p = this.previews[idx];
      if (p?.url) URL.revokeObjectURL(p.url);

      this.previews.splice(idx, 1);
      this.files.splice(idx, 1);
    },

    addEmoji(e) {
      this.draft = (this.draft || '') + e;
    },

    handleEnter(ev) {
      // Enter envía / Shift+Enter salto
      if (ev.shiftKey) {
        this.draft += '\n';
        return;
      }
      this.send();
    },

    isMine(m) {
      // producción: sender=user es mío
      // admin: sender=admin es mío
      if (this.isAdmin) return m.sender === 'admin';
      return m.sender === 'user';
    },

    async send() {
      this.errorText = '';
      if (this.sending) return;

      const hasText = (this.draft || '').trim().length > 0;
      const hasFiles = this.files.length > 0;

      if (!hasText && !hasFiles) {
        this.errorText = 'Escribe un mensaje o adjunta una imagen.';
        return;
      }

      this.sending = true;

      const fd = new FormData();
      fd.append('message', this.draft || '');

      if (this.isCreating && (this.orderId || '').trim()) {
        fd.append('order_id', (this.orderId || '').trim());
      }

      // IMPORTANTE: usar images[] para PHP, CI lo leerá como images
      this.files.forEach(f => fd.append('images[]', f));

      try {
        if (this.isCreating) {
          const { ok, data, status } = await this.api(this.endpoints.create, { method: 'POST', body: fd });

          if (!ok) {
            this.errorText = data?.error || `Error creando ticket (${status})`;
            return;
          }

          await this.loadTickets();
          this.isCreating = false;
          this.selectedTicketId = data.ticket_id;
          await this.loadTicket();
        } else {
          const { ok, data, status } = await this.api(`${this.endpoints.message}/${this.selectedTicketId}/message`, { method: 'POST', body: fd });

          if (!ok) {
            this.errorText = data?.error || `Error enviando mensaje (${status})`;
            return;
          }

          await this.loadTicket();
          await this.loadTickets();
        }

        // limpiar
        this.draft = '';
        this.orderId = '';
        this.files = [];
        this.previews.forEach(p => URL.revokeObjectURL(p.url));
        this.previews = [];
        this.showEmoji = false;

      } finally {
        this.sending = false;
      }
    },

    async acceptCase() {
      if (!this.ticket || !this.isAdmin) return;

      const { ok, data } = await this.api(`${this.endpoints.assign}/${this.ticket.id}/assign`, { method: 'POST' });
      if (!ok) {
        this.errorText = data?.error || 'No se pudo aceptar el caso';
        return;
      }

      await this.loadTicket();
      await this.loadTickets();
    },

    async updateStatus() {
      if (!this.ticket || !this.isAdmin) return;

      const fd = new FormData();
      fd.append('status', this.adminStatus);

      const { ok, data } = await this.api(`${this.endpoints.status}/${this.ticket.id}/status`, { method: 'POST', body: fd });
      if (!ok) {
        this.errorText = data?.error || 'No se pudo cambiar el estado';
        return;
      }

      await this.loadTicket();
      await this.loadTickets();
    },

    // -------- polling + notificaciones ----------
    startPolling() {
      this.stopPolling();
      this.pollTimer = setInterval(async () => {
        // recarga ticket abierto
        if (this.selectedTicketId) {
          const beforeId = this.lastMsgIdByTicket[this.selectedTicketId] || 0;

          await this.loadTicket();

          const last = this.messages[this.messages.length - 1];
          const afterId = last?.id || 0;

          if (this.notifyEnabled && afterId && afterId > beforeId) {
            // notifica solo si el último mensaje NO es mío
            if (last && !this.isMine(last)) {
              this.notify(`Nuevo mensaje · ${this.ticket?.ticket_code || 'Soporte'}`, last.message || '📷 Imagen');
            }
            this.lastMsgIdByTicket[this.selectedTicketId] = afterId;
          }
        }

        // recarga lista
        await this.loadTickets();
      }, 4000);
    },

    stopPolling() {
      if (this.pollTimer) clearInterval(this.pollTimer);
      this.pollTimer = null;
    },

    async toggleNotifications() {
      if (!("Notification" in window)) {
        this.errorText = 'Tu navegador no soporta notificaciones.';
        return;
      }

      if (!this.notifyEnabled) {
        const perm = await Notification.requestPermission();
        if (perm !== 'granted') {
          this.errorText = 'Permiso de notificaciones denegado.';
          return;
        }
      }

      this.notifyEnabled = !this.notifyEnabled;
      localStorage.setItem('supportNotify', this.notifyEnabled ? '1' : '0');
      this.errorText = '';
    },

    notify(title, body) {
      try {
        if (Notification.permission !== 'granted') return;
        new Notification(title, { body });
      } catch (e) {}
    },

    // -------- UI helpers ----------
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
