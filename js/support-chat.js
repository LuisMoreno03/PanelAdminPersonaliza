window.supportChat = function () {
  const SUPPORT = window.SUPPORT || {};
  const endpoints = SUPPORT.endpoints || {};

  return {
    role: String(SUPPORT.role || '').toLowerCase(),
    userId: Number(SUPPORT.userId || 0),
    endpoints,

    tickets: [],
    selectedTicketId: null,
    ticket: null,
    messages: [],
    attachments: {},

    isCreating: false,
    draft: '',
    orderId: '',

    // preview
    files: [],
    sending: false,

    // admin
    filter: 'unassigned',
    q: '',
    adminStatus: 'open',

    // polling
    pollTimer: null,

    // notifications
    notifyEnabled: (localStorage.getItem('support_notify') === '1'),
    lastMaxMsgId: 0,

    // emoji
    emojiOpen: false,
    emojis: ["😀","😁","😂","🤣","😊","😍","😘","😎","🤔","😅","😭","😡","👍","👎","🙏","👏","🔥","🎉","✅","❌","⭐","💡","🛠️","📦","📌","📎","📷","🧾","💬","🧠","🕒","🚀","❤️","💚","💛","💙","🤝","🙌","🤯","😴","🥳"],

    get isAdmin() {
      const r = String(this.role || '').toLowerCase();
      return (r.includes('admin') || r === 'administrador' || r === 'administrator' || r === 'superadmin' || r === 'root' || r === '1');
    },

    get filteredTickets() {
      let list = Array.isArray(this.tickets) ? this.tickets : [];

      const s = this.q.trim().toLowerCase();
      if (s) {
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
      // si entras como admin, por defecto ves TODO
      if (this.isAdmin) this.filter = 'all';

      await this.loadTickets();

      const first = this.filteredTickets[0];
      if (first && first.id) await this.openTicket(first.id);

      this.startPolling();
    },

    addEmoji(e) {
      this.draft = (this.draft || '') + e;
      this.emojiOpen = false;
    },

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

    pickFiles(e) {
      const selected = Array.from(e.target.files || []);
      selected.forEach(file => {
        if (!file.type.startsWith('image/')) return;
        const url = URL.createObjectURL(file);
        this.files.push({ file, url, name: file.name });
      });
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
    },

    async loadTickets() {
      try {
        const r = await fetch(endpoints.tickets, { headers: { 'Accept': 'application/json' }});
        const data = await r.json().catch(() => null);

        if (!r.ok) {
          console.error('[SupportChat] tickets error', r.status, data);
          this.tickets = [];
          return;
        }

        this.tickets = Array.isArray(data) ? data : [];
      } catch (e) {
        console.error('[SupportChat] tickets exception', e);
        this.tickets = [];
      }
    },

    async openTicket(id) {
      this.isCreating = false;
      this.selectedTicketId = id;
      await this.loadTicket();
    },

    async loadTicket() {
      if (!this.selectedTicketId) return;

      try {
        const r = await fetch(`${endpoints.ticketBase}/${this.selectedTicketId}`, { headers: { 'Accept': 'application/json' }});
        const data = await r.json().catch(() => ({}));

        if (!r.ok) {
          console.error('[SupportChat] ticket error', r.status, data);
          alert(data?.error || 'No se pudo abrir el ticket');
          return;
        }

        this.ticket = data.ticket || null;
        this.messages = Array.isArray(data.messages) ? data.messages : [];
        this.attachments = data.attachments || {};
        this.adminStatus = (this.ticket && this.ticket.status) ? this.ticket.status : 'open';

        // 🔔 detectar mensaje nuevo (del otro lado)
        const maxId = this.messages.reduce((acc, m) => Math.max(acc, Number(m.id || 0)), 0);
        if (this.lastMaxMsgId && maxId > this.lastMaxMsgId) {
          const last = this.messages.find(m => Number(m.id) === maxId) || this.messages[this.messages.length - 1];
          const sender = String(last?.sender || '');

          const incoming = this.isAdmin ? (sender === 'user') : (sender === 'admin');
          if (incoming) {
            const txt = String(last?.message || '').trim();
            const body = txt ? txt.slice(0, 80) : '📷 Imagen / adjunto';
            this.notify(`Nuevo mensaje · ${this.ticket?.ticket_code || 'Soporte'}`, body);
          }
        }
        this.lastMaxMsgId = maxId;

        this.scrollToBottom();

      } catch (e) {
        console.error('[SupportChat] loadTicket exception', e);
        alert('Error interno abriendo ticket');
      }
    },

    async send() {
      if (this.sending) return;
      if (!this.draft.trim() && this.files.length === 0) return;

      this.sending = true;

      const fd = new FormData();
      fd.append('message', this.draft);

      if (this.isCreating && this.orderId.trim()) fd.append('order_id', this.orderId.trim());

      // ⚠️ name="images[]" en PHP suele ser más compatible:
      // lo enviamos como images[] (y el controller lo lee como images[])
      this.files.forEach(x => fd.append('images[]', x.file));

      try {
        if (this.isCreating) {
          const r = await fetch(endpoints.ticketBase, { method: 'POST', body: fd });
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
        } else {
          const r = await fetch(`${endpoints.ticketBase}/${this.selectedTicketId}/message`, { method: 'POST', body: fd });
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

    async acceptCase() {
      if (!this.ticket || !this.isAdmin) return;

      const r = await fetch(`${endpoints.ticketBase}/${this.ticket.id}/assign`, { method: 'POST' });
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

      const r = await fetch(`${endpoints.ticketBase}/${this.ticket.id}/status`, { method: 'POST', body: fd });
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
      if (this.pollTimer) clearInterval(this.pollTimer);
      this.pollTimer = setInterval(async () => {
        await this.loadTickets();
        if (this.selectedTicketId) await this.loadTicket();
      }, 3500);
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
};
