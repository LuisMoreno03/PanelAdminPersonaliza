document.addEventListener('alpine:init', () => {
  Alpine.data('supportChat', () => ({
    role: (String(window.SUPPORT?.role || '')).toLowerCase().trim(),
    userId: Number(window.SUPPORT?.userId || 0),
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
    previews: [],
    sending: false,
    errorText: '',

    filter: 'unassigned',
    q: '',
    adminStatus: 'open',

    // ✅ Notificaciones
    notifyEnabled: false,
    lastMsgIdSeen: 0,
    pollTimer: null,

    // ✅ Emoji picker
    emojiPicker: null,
    emojiOpen: false,

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
      console.log('[SupportChat] role=', this.role, 'userId=', this.userId);

      // notifs persisted
      this.notifyEnabled = localStorage.getItem('support_notify') === '1' && Notification?.permission === 'granted';

      // emoji picker
      this.setupEmojiPicker();

      await this.loadTickets();
      const first = this.filteredTickets[0];
      if (first && first.id) await this.openTicket(first.id);

      // polling para refrescar ticket abierto (y disparar notificación)
      this.startPolling();
    },

    setupEmojiPicker() {
      try {
        if (!window.EmojiButton) return;
        this.emojiPicker = new window.EmojiButton({
          position: 'top-start',
          zIndex: 9999
        });
        this.emojiPicker.on('emoji', (emoji) => {
          this.draft = (this.draft || '') + emoji;
        });
      } catch (e) {
        console.warn('Emoji picker error', e);
      }
    },

    toggleEmojiPicker() {
      if (!this.emojiPicker) return;
      // abre cerca del botón (evento no llega aquí), así que lo abrimos centrado en body
      this.emojiPicker.togglePicker(document.body);
    },

    startPolling() {
      if (this.pollTimer) clearInterval(this.pollTimer);
      this.pollTimer = setInterval(async () => {
        // refresca lista cada cierto tiempo (por si llegan tickets nuevos)
        await this.loadTickets();

        // si hay un ticket abierto, refrescar y notificar
        if (this.selectedTicketId) {
          await this.pollOpenTicket();
        }
      }, 5000);
    },

    async pollOpenTicket() {
      const { ok, data } = await this.api(`${this.endpoints.ticket}/${this.selectedTicketId}`);
      if (!ok) return;

      const newMessages = data.messages || [];
      const last = newMessages.length ? newMessages[newMessages.length - 1] : null;
      const lastId = last?.id ? Number(last.id) : 0;

      // primera vez
      if (!this.lastMsgIdSeen) this.lastMsgIdSeen = lastId;

      // si llegó uno nuevo
      if (lastId > this.lastMsgIdSeen) {
        const isMine = this.isMine(last);

        // actualiza UI
        this.ticket = data.ticket || this.ticket;
        this.messages = newMessages;
        this.attachments = data.attachments || {};
        this.adminStatus = this.ticket?.status || this.adminStatus;

        // notifica si NO es mío
        if (!isMine) {
          this.notify(`Nuevo mensaje (${this.ticket?.ticket_code || 'Soporte'})`, last?.message || 'Te enviaron una imagen');
        }

        this.lastMsgIdSeen = lastId;
        this.scrollToBottom();
      }
    },

    notify(title, body) {
      if (!this.notifyEnabled) return;
      if (!('Notification' in window)) return;
      if (Notification.permission !== 'granted') return;

      try {
        new Notification(title, { body });
      } catch (e) {}
    },

    async toggleNotifications() {
      if (!('Notification' in window)) {
        this.errorText = 'Tu navegador no soporta notificaciones.';
        return;
      }

      if (Notification.permission === 'granted') {
        this.notifyEnabled = !this.notifyEnabled;
        localStorage.setItem('support_notify', this.notifyEnabled ? '1' : '0');
        return;
      }

      const perm = await Notification.requestPermission();
      if (perm === 'granted') {
        this.notifyEnabled = true;
        localStorage.setItem('support_notify', '1');
        this.notify('Notificaciones activadas', 'Te avisaré cuando llegue un mensaje.');
      } else {
        this.notifyEnabled = false;
        localStorage.setItem('support_notify', '0');
        this.errorText = 'Permiso de notificaciones denegado.';
      }
    },

    async api(url, options = {}) {
      const r = await fetch(url, options);
      let data = {};
      try { data = await r.json(); } catch (e) { data = {}; }
      return { ok: r.ok, status: r.status, data };
    },

    async loadTickets() {
      this.errorText = '';
      const { ok, data } = await this.api(this.endpoints.tickets);
      if (!ok) {
        this.tickets = [];
        this.errorText = data?.error || 'Error cargando tickets';
        if (data?.debug) console.warn('[tickets debug]', data.debug);
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
        if (data?.debug) console.warn('[ticket debug]', data.debug);
        return;
      }

      this.ticket = data.ticket || null;
      this.messages = data.messages || [];
      this.attachments = data.attachments || {};
      this.adminStatus = this.ticket?.status || 'open';

      // para notificaciones
      const last = this.messages.length ? this.messages[this.messages.length - 1] : null;
      this.lastMsgIdSeen = last?.id ? Number(last.id) : 0;

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
      this.previews.forEach(p => URL.revokeObjectURL(p.url));
      this.previews = [];
      this.errorText = '';
      this.lastMsgIdSeen = 0;
    },

    pickFiles(e) {
      const list = Array.from(e.target.files || []);
      this.files = list;

      this.previews.forEach(p => URL.revokeObjectURL(p.url));
      this.previews = list.map(f => ({ name: f.name, url: URL.createObjectURL(f) }));

      e.target.value = '';
    },

    removePreview(idx) {
      const p = this.previews[idx];
      if (p?.url) URL.revokeObjectURL(p.url);
      this.previews.splice(idx, 1);
      this.files.splice(idx, 1);
    },

    isMine(m) {
      if (!m) return false;
      if (this.isAdmin) return m.sender === 'admin';
      return m.sender === 'user';
    },

    async send() {
      this.errorText = '';
      if (this.sending) return;

      // evita /ticket/null/message
      if (!this.isCreating && !this.ticket) {
        this.errorText = 'Selecciona un ticket o crea uno nuevo.';
        return;
      }

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

      this.files.forEach(f => fd.append('images[]', f));

      try {
        if (this.isCreating) {
          const { ok, data, status } = await this.api(this.endpoints.create, { method: 'POST', body: fd });
          if (!ok) {
            this.errorText = data?.error || `Error creando ticket (${status})`;
            if (data?.debug) console.warn('[create debug]', data.debug);
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
            if (data?.debug) console.warn('[message debug]', data.debug);
            return;
          }

          await this.loadTicket();
          await this.loadTickets();
        }

        this.draft = '';
        this.orderId = '';
        this.files = [];
        this.previews.forEach(p => URL.revokeObjectURL(p.url));
        this.previews = [];

      } finally {
        this.sending = false;
      }
    },

    async acceptCase() {
      if (!this.ticket || !this.isAdmin) return;

      const { ok, data } = await this.api(`${this.endpoints.assign}/${this.ticket.id}/assign`, { method: 'POST' });
      if (!ok) {
        this.errorText = data?.error || 'No se pudo aceptar el caso';
        if (data?.debug) console.warn('[assign debug]', data.debug);
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
        if (data?.debug) console.warn('[status debug]', data.debug);
        return;
      }

      await this.loadTicket();
      await this.loadTickets();
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
