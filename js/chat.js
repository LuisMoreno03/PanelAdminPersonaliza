(() => {
  const CFG = window.CHAT_CFG || {};
  const SOCKET_URL = CFG.SOCKET_URL;
  const ADMIN_ID = CFG.ADMIN_ID;
  const ADMIN_NAME = CFG.ADMIN_NAME;
  const ENDPOINTS = CFG.ENDPOINTS || {};

  // ===== CSRF (CodeIgniter) =====
  const csrfTokenMeta = document.querySelector('meta[name="csrf-token"]');
  const csrfHeaderMeta = document.querySelector('meta[name="csrf-header"]');

  const csrf = () => ({
    token: csrfTokenMeta ? csrfTokenMeta.getAttribute('content') : '',
    header: csrfHeaderMeta ? csrfHeaderMeta.getAttribute('content') : 'X-CSRF-TOKEN'
  });

  // ===== UI refs =====
  const elUsersList = document.getElementById("usersList");
  const elSearch = document.getElementById("userSearch");
  const elStatus = document.getElementById("socketStatus");

  const elActiveUserName = document.getElementById("activeUserName");
  const elActiveUserMeta = document.getElementById("activeUserMeta");
  const elMessagesBox = document.getElementById("messagesBox");

  const elInput = document.getElementById("messageInput");
  const elSend = document.getElementById("btnSend");
  const elRefresh = document.getElementById("btnRefreshMessages");
  const elChatMsg = document.getElementById("chatMsg");

  // ===== State =====
  let socket = null;
  let users = []; // {id, name, email, isOnline, lastMessage, unread}
  let activeUserId = null;

  let pollUsersTimer = null;
  let pollMessagesTimer = null;
  let pingTimer = null;

  // ===== Helpers =====
  const setStatus = (connected) => {
    if (connected) {
      elStatus.textContent = "Conectado";
      elStatus.className = "text-xs font-bold px-3 py-1 rounded-full bg-emerald-100 text-emerald-700";
    } else {
      elStatus.textContent = "Desconectado";
      elStatus.className = "text-xs font-bold px-3 py-1 rounded-full bg-slate-100 text-slate-700";
    }
  };

  const escapeHtml = (str) =>
    String(str ?? "").replace(/[&<>"']/g, m => ({
      "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"
    }[m]));

  const scrollBottom = () => { elMessagesBox.scrollTop = elMessagesBox.scrollHeight; };

  function renderUsers(list) {
    const q = (elSearch.value || "").toLowerCase().trim();

    const filtered = list
      .filter(u => !q || (u.name||"").toLowerCase().includes(q) || (u.email||"").toLowerCase().includes(q))
      .sort((a,b) => (Number(b.isOnline) - Number(a.isOnline)) || (Number(b.unread||0) - Number(a.unread||0)));

    if (!filtered.length) {
      elUsersList.innerHTML = `<div class="text-sm text-slate-500 p-3">Sin usuarios.</div>`;
      return;
    }

    elUsersList.innerHTML = filtered.map(u => {
      const active = String(u.id) === String(activeUserId);
      return `
        <button data-user-id="${u.id}"
          class="w-full text-left p-3 rounded-2xl border ${active ? "border-slate-900 bg-white" : "border-slate-200 bg-white"} hover:border-slate-400 transition mb-2">
          <div class="flex items-center justify-between gap-2">
            <div class="min-w-0">
              <div class="flex items-center gap-2">
                <span class="inline-block w-2.5 h-2.5 rounded-full ${u.isOnline ? "bg-emerald-500" : "bg-slate-300"}"></span>
                <div class="font-bold text-slate-900 truncate">${escapeHtml(u.name || ("Usuario " + u.id))}</div>
              </div>
              <div class="text-xs text-slate-500 truncate">${escapeHtml(u.email || "")}</div>
              <div class="text-xs text-slate-600 truncate mt-1">${escapeHtml(u.lastMessage || "")}</div>
            </div>
            ${(u.unread||0) ? `<span class="text-xs font-extrabold px-2 py-1 rounded-full bg-rose-100 text-rose-700">${u.unread}</span>` : ``}
          </div>
        </button>
      `;
    }).join("");

    elUsersList.querySelectorAll("button[data-user-id]").forEach(btn => {
      btn.addEventListener("click", () => openConversation(btn.getAttribute("data-user-id")));
    });
  }

  function renderMessageBubble({ sender_type, message, created_at }) {
    const mine = sender_type === "admin";
    return `
      <div class="flex ${mine ? "justify-end" : "justify-start"} mb-2 animate-fadeIn">
        <div class="${mine ? "bg-slate-900 text-white" : "bg-white border border-slate-200 text-slate-900"} max-w-[80%] rounded-2xl px-3 py-2 shadow-sm">
          <div class="text-sm whitespace-pre-wrap">${escapeHtml(message)}</div>
          <div class="text-[10px] mt-1 ${mine ? "text-slate-200" : "text-slate-400"}">${escapeHtml(created_at)}</div>
        </div>
      </div>
    `;
  }

  // ===== API =====
  async function apiGet(url) {
    const res = await fetch(url, { credentials: "include" });
    const data = await res.json().catch(() => ({}));
    if (data?.csrf && csrfTokenMeta) csrfTokenMeta.setAttribute("content", data.csrf);
    if (!res.ok) throw new Error(`GET failed ${res.status}`);
    return data;
  }

  async function apiPost(url, body) {
    const c = csrf();
    const res = await fetch(url, {
      method: "POST",
      credentials: "include",
      headers: {
        "Content-Type": "application/json",
        [c.header]: c.token
      },
      body: JSON.stringify(body ?? {})
    });

    const data = await res.json().catch(() => ({}));
    if (data?.csrf && csrfTokenMeta) csrfTokenMeta.setAttribute("content", data.csrf);
    if (!res.ok) throw new Error(data?.message || `POST failed ${res.status}`);
    return data;
  }

  // ===== Load users =====
  async function loadUsers() {
    const data = await apiGet(ENDPOINTS.users);
    users = (data.users || []).map(u => ({
      id: u.id,
      name: u.name,
      email: u.email,
      isOnline: !!u.isOnline,
      lastMessage: u.lastMessage || "",
      unread: Number(u.unread || 0)
    }));
    renderUsers(users);

    // refresca meta del user activo sin romper UI
    if (activeUserId) {
      const u = users.find(x => String(x.id) === String(activeUserId));
      if (u) {
        elActiveUserName.textContent = u.name || ("Usuario " + u.id);
        elActiveUserMeta.textContent = u.email || "";
      }
    }
  }

  // ===== Open conversation =====
  async function openConversation(userId) {
    activeUserId = String(userId);
    const u = users.find(x => String(x.id) === activeUserId);

    elActiveUserName.textContent = u ? (u.name || ("Usuario " + u.id)) : ("Usuario " + userId);
    elActiveUserMeta.textContent = u ? (u.email || "") : "";

    elInput.disabled = false;
    elSend.disabled = false;

    elMessagesBox.innerHTML = `<div class="text-sm text-slate-500">Cargando mensajes...</div>`;

    const data = await apiGet(`${ENDPOINTS.messages}/${encodeURIComponent(activeUserId)}`);
    const msgs = data.messages || [];

    elMessagesBox.innerHTML =
      msgs.map(renderMessageBubble).join("") ||
      `<div class="text-sm text-slate-500">Sin mensajes.</div>`;

    scrollBottom();

    // marcar leído backend
    try {
      await apiPost(ENDPOINTS.read, { userId: activeUserId });
      const idx = users.findIndex(x => String(x.id) === activeUserId);
      if (idx >= 0) users[idx].unread = 0;
    } catch (_) {}

    renderUsers(users);

    // ✅ polling mensajes por si socket se cae
    if (pollMessagesTimer) clearInterval(pollMessagesTimer);
    pollMessagesTimer = setInterval(async () => {
      if (!activeUserId) return;
      // recarga conversación (simple y estable)
      try {
        const d = await apiGet(`${ENDPOINTS.messages}/${encodeURIComponent(activeUserId)}`);
        const m = d.messages || [];
        // re-render “sin duplicar” (base segura)
        elMessagesBox.innerHTML =
          m.map(renderMessageBubble).join("") ||
          `<div class="text-sm text-slate-500">Sin mensajes.</div>`;
        scrollBottom();
        // limpia unread
        const idx = users.findIndex(x => String(x.id) === String(activeUserId));
        if (idx >= 0) users[idx].unread = 0;
        renderUsers(users);
      } catch (_) {}
    }, 4000);
  }

  // ===== Send message =====
  async function sendMessage() {
    const text = (elInput.value || "").trim();
    if (!text || !activeUserId) return;

    elSend.disabled = true;
    elInput.disabled = true;
    elChatMsg.textContent = "";

    try {
      // 1) Guardar en BD
      const saved = await apiPost(ENDPOINTS.send, { userId: activeUserId, message: text });

      // 2) Emitir por socket (si hay)
      if (socket && socket.connected) {
        socket.emit("message:send", {
          toUserId: activeUserId,
          fromRole: "admin",
          fromId: ADMIN_ID,
          fromName: ADMIN_NAME,
          message: text,
          createdAt: saved.createdAt || new Date().toISOString()
        });
      }

      // 3) Pintar local
      elMessagesBox.insertAdjacentHTML("beforeend", renderMessageBubble({
        sender_type: "admin",
        message: text,
        created_at: saved.createdAt || new Date().toLocaleString()
      }));
      scrollBottom();

      // preview
      const idx = users.findIndex(x => String(x.id) === String(activeUserId));
      if (idx >= 0) users[idx].lastMessage = text;

      elInput.value = "";
      renderUsers(users);
    } catch (e) {
      elChatMsg.textContent = "No se pudo enviar. Intenta de nuevo.";
      console.error(e);
    } finally {
      elSend.disabled = false;
      elInput.disabled = false;
      elInput.focus();
    }
  }

  // ===== Socket =====
  function initSocket() {
    socket = io(SOCKET_URL, {
      transports: ["websocket", "polling"],
      reconnection: true,
      reconnectionAttempts: Infinity,
      reconnectionDelayMax: 5000,
      withCredentials: true
    });

    socket.on("connect", () => {
      setStatus(true);
      socket.emit("register", { role: "admin", id: ADMIN_ID, name: ADMIN_NAME });
    });

    socket.on("disconnect", () => setStatus(false));
    socket.on("connect_error", () => setStatus(false));

    // presence:update => {role:"user", id, isOnline:true/false}
    socket.on("presence:update", (data) => {
      if (!data || data.role !== "user") return;
      const idx = users.findIndex(x => String(x.id) === String(data.id));
      if (idx >= 0) users[idx].isOnline = !!data.isOnline;
      renderUsers(users);
    });

    // message:receive:admin => {fromId, message, createdAt}
    socket.on("message:receive:admin", async (payload) => {
      if (!payload) return;

      const fromUserId = String(payload.fromId);
      const msgText = String(payload.message || "");

      const idx = users.findIndex(x => String(x.id) === fromUserId);
      if (idx >= 0) {
        users[idx].lastMessage = msgText;
        if (String(activeUserId) !== fromUserId) {
          users[idx].unread = Number(users[idx].unread || 0) + 1;
        }
      }

      if (String(activeUserId) === fromUserId) {
        elMessagesBox.insertAdjacentHTML("beforeend", renderMessageBubble({
          sender_type: "user",
          message: msgText,
          created_at: payload.createdAt
            ? new Date(payload.createdAt).toLocaleString()
            : new Date().toLocaleString()
        }));
        scrollBottom();

        // marcar leído
        try {
          await apiPost(ENDPOINTS.read, { userId: activeUserId });
          const idx2 = users.findIndex(x => String(x.id) === String(activeUserId));
          if (idx2 >= 0) users[idx2].unread = 0;
        } catch (_) {}
      }

      renderUsers(users);
    });
  }

  // ===== Ping (online admin) =====
  async function ping() {
    try { await apiPost(ENDPOINTS.ping, {}); } catch (_) {}
  }

  // ===== Events =====
  elSend.addEventListener("click", sendMessage);
  elInput.addEventListener("keydown", (e) => { if (e.key === "Enter") sendMessage(); });

  elRefresh.addEventListener("click", () => { if (activeUserId) openConversation(activeUserId); });
  elSearch.addEventListener("input", () => renderUsers(users));

  // ===== Boot =====
  (async () => {
    setStatus(false);

    try {
      await loadUsers();
    } catch (e) {
      elUsersList.innerHTML = `<div class="text-sm text-rose-600 p-3">No se pudieron cargar usuarios.</div>`;
      console.error(e);
    }

    initSocket();

    // ✅ refresca usuarios/estados sin depender del socket
    pollUsersTimer = setInterval(loadUsers, 8000);

    // ✅ mantener online admin
    ping();
    pingTimer = setInterval(ping, 25000);
  })();
})();
