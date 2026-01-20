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

    // ✅ files = [{ file, url, name }]
    files: [],
    sending: false,

    filter: 'unassigned',
    q: '',
    adminStatus: 'open',
    pollTimer: null,

    // 🔔 notifications
    notifyEnabled: false,
    lastSeenByTicket: {}, // ticketId -> last msg id visto (para detectar nuevos)

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
      // persist notifications toggle
      this.notifyEnabled = localStorage.getItem('support_notify') === '1';

      if (!this.isAdmin) this.filter = 'all';

      await this.loadTickets();

      const first = this.filteredTickets[0];
      if (first?.id) await this.openTicket(first.id);
    },

    // ---------- Notifications ----------
    async toggleNotifications() {
      if (!('Notification' in window)) {
        alert('Tu navegador no soporta notificaciones.');
        return;
      }

      if (Notification.permission === 'granted') {
        this.notifyEnabled = !this.notifyEnabled;
        localStorage.setItem('support_notify', this.notifyEnabled ? '1' : '0');
        if (this.notifyEnabled) this.beep();
        return;
      }

      const perm = await Notification.requestPermission();
      if (perm === 'granted') {
        this.notifyEnabled = true;
        localStorage.setItem('support_notify', '1');
        this.beep();
        new Notification('Soporte', { body: 'Notificaciones activadas ✅' });
      } else {
        this.notifyEnabled = false;
        localStorage.setItem('support_notify', '0');
        alert('No se activaron las notificaciones.');
      }
    },

    notify(title, body) {
      if (!this.notifyEnabled) return;
      if (!('Notification' in window)) return;
      if (Notification.permission !== 'granted') return;

      try {
        new Notification(title, { body });
        this.beep();
      } catch (e) {}
    },

    beep() {
      // beep simple (sin archivo)
      try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        const ctx = new AudioCtx();
        const o = ctx.createOscillator();
        const g = ctx.createGain();
        o.connect(g); g.connect(ctx.destination);
        o.frequency.value = 880;
        g.gain.value = 0.05;
        o.start();
        o.stop(ctx.currentTime + 0.12);
      } catch (e) {}
    },

    handleIncomingMessages(ticketId, ticketCode, messages) {
      if (!Array.isArray(messages) || messages.length === 0) return;

      const maxId = Math.max(...messages.map(m => Number(m.id || 0)));
      const prev = this.lastSeenByTicket[ticketId];

      // primera vez: no notifica
      if (prev === undefined) {
        this.lastSeenByTicket[ticketId] = maxId;
        return;
      }

      if (maxId > prev) {
        const last = messages.find(m => Number(m.id) === maxId) || messages[messages.length - 1];
        const sender = String(last?.sender || '');
        const isIncoming = this.isAdmin ? (sender === 'user') : (sender === 'admin');

        if (isIncoming) {
          const txt = (last?.message || '').trim();
          const body = txt ? txt.slice(0, 80) : '📷 Imagen / adjunto';
          this.notify(`Nuevo mensaje · ${ticketCode}`, body);
        }

        this.lastSeenByTicket[ticketId] = maxId;
      }
    },

    // ---------- API ----------
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

        // 🔔 notificar si llegaron mensajes nuevos
        if (this.ticket?.id) {
          this.handleIncomingMessages(this.ticket.id, this.ticket.ticket_code || `#${this.ticket.id}`, this.messages);
        }

        this.scrollToBottom();
      } catch (e) {
        console.error('[SupportChat] loadTicket exception', e);
        alert('Error interno abriendo ticket');
      }
    },

    // ---------- NEW TICKET ----------
    startNew() {
      if (this.isAdmin) return;

      this.isCreating = true;
      this.selectedTicketId = null;
      this.ticket = null;
      this.messages = [];
      this.attachments = {};
      this.draft = '';
      this.orderId = '';
      this.clearFiles();
      this.stopPolling();
    },

    // ---------- FILES (preview like WhatsApp) ----------
    pickFiles(e) {
      const selected = Array.from(e.target.files || []);
      selected.forEach(file => {
        if (!file.type.startsWith('image/')) return;
        const url = URL.createObjectURL(file);
        this.files.push({ file, url, name: file.name });
      });

      // permite volver a seleccionar la misma imagen
      e.target.value = '';
    },

    removeFile(idx) {
      try { URL.revokeObjectURL(this.files[idx]?.url); } catch (e) {}
      this.files.splice(idx, 1);
    },

    clearFiles() {
      this.files.forEach(f => { try { URL.revokeObjectURL(f.url); } catch(e) {} });
      this.files = [];
    },

    // ---------- SEND ----------
    async send() {
      if (this.sending) return;
      if (!this.draft.trim() && this.files.length === 0) return;

      this.sending = true;

      const fd = new FormData();
      fd.append('message', this.draft);

      if (this.isCreating && this.orderId.trim()) {
        fd.append('order_id', this.orderId.trim());
      }

      // ✅ IMPORTANT: enviar como "images" (no images[])
      this.files.forEach(x => fd.append('images', x.file));

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
        this.clearFiles();
      } finally {
        this.sending = false;
      }
    },

    // ---------- ADMIN ----------
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

    // ---------- polling ----------
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

    // ---------- UI ----------
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
