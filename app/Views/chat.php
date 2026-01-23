<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= csrf_hash() ?>">
  <meta name="csrf-header" content="<?= csrf_header() ?>">

  <title>Chat Interno - Panel</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/alpinejs" defer></script>

  <style>
    body { background: #f3f4f6; }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(6px) scale(0.99); }
      to   { opacity: 1; transform: translateY(0) scale(1); }
    }
    .animate-fadeIn { animation: fadeIn .18s ease-out; }

    .soft-scroll::-webkit-scrollbar { height: 10px; width: 10px; }
    .soft-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
    .soft-scroll::-webkit-scrollbar-track { background: #eef2ff; border-radius: 999px; }

    /* ✅ Layout con menú */
    .layout {
      transition: padding-left .2s ease;
      padding-left: 16rem; /* 256px (md:w-64) */
    }
    .layout.menu-collapsed {
      padding-left: 5.25rem; /* 84px colapsado */
    }
    @media (max-width: 768px) {
      .layout, .layout.menu-collapsed { padding-left: 0 !important; }
    }

    /* ✅ Grid sin scroll para filas desktop (se adapta a ancho real) */
    ./* ✅ Fuerza que el contenedor del listado no “recorte” */
.table-wrap {
  width: 100%;
  max-width: 100%;
}

/* ✅ GRID responsive real (desktop) */
.orders-grid {
  display: grid;
  align-items: center;
  gap: .65rem;
  width: 100%;
}

/* ✅ Header + rows usan la misma grilla */
.orders-grid.cols {
  grid-template-columns:
    110px                     /* Pedido */
    92px                      /* Fecha */
    minmax(170px, 1.2fr)      /* Cliente */
    90px                      /* Total */
    160px                     /* Estado */
    minmax(140px, 0.9fr)      /* Último cambio */
    minmax(170px, 1fr)        /* Etiquetas */
    44px                      /* Art */
    140px                     /* Entrega */
    minmax(190px, 1fr)        /* Método entrega */
    130px;                    /* ✅ Ver detalles */
}

/* ✅ Importante: permite truncar sin romper el grid */
.orders-grid > div {
  min-width: 0;
}

/* ✅ Para el método de entrega: permite 2 líneas */
.metodo-entrega {
  white-space: normal;
  line-height: 1.1;
  display: -webkit-box;
  -webkit-line-clamp: 2;       /* máximo 2 líneas */
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* ✅ Si quieres “ver todo sí o sí” cuando el monitor sea pequeño,
   activa scroll solo en la tabla (opcional) */
.table-scroll {
  overflow-x: auto;
}
.table-scroll::-webkit-scrollbar { height: 10px; }
.table-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
.table-scroll::-webkit-scrollbar-track { background: #eef2ff; border-radius: 999px; }



    /* ✅ Cuando el ancho baja demasiado, pasamos a cards */
    @media (max-width: 1180px) {
      .desktop-orders { display: none !important; }
      .mobile-orders  { display: block !important; }
    }
    @media (min-width: 1181px) {
      .desktop-orders { display: block !important; }
      .mobile-orders  { display: none !important; }
    }
  </style>
</head>

<body class="min-h-screen bg-gradient-to-b from-slate-50 to-slate-100 overflow-x-hidden">

  <!-- MENU -->
  <?= view('layouts/menu') ?>

  <main id="mainLayout" class="layout">
    <div class="p-4 sm:p-6 lg:p-8">
      <div class="mx-auto w-full max-w-[1600px]">

        <!-- HEADER -->
        <section class="mb-6">
          <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5 flex items-start justify-between gap-4">
            <div>
              <h1 class="text-3xl font-extrabold text-slate-900">Chat Interno</h1>
              <p class="text-slate-500 mt-1">Comunicación Laboral</p>
            </div>
          </div>
        </section>

        <section class="mb-6">
  <!-- CHAT INTERNO -->
<section class="mb-6">
  <div class="rounded-3xl border border-slate-200 bg-white shadow-sm p-5">
    <div class="flex items-start justify-between gap-4 mb-4">
      <div>
        <h3 class="text-lg font-extrabold text-slate-900">Chat Interno</h3>
        <p class="text-slate-500 text-sm mt-1">Selecciona un usuario y escribe en tiempo real.</p>
      </div>
      <span id="socketStatus" class="text-xs font-bold px-3 py-1 rounded-full bg-slate-100 text-slate-700">
        Desconectado
      </span>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-[320px_1fr] gap-4">
      <!-- Lista usuarios -->
      <div class="rounded-2xl border border-slate-200 bg-slate-50">
        <div class="p-3 border-b border-slate-200 flex items-center gap-2">
          <input id="userSearch" type="text" placeholder="Buscar usuario..."
                 class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-slate-300 bg-white">
        </div>

        <div id="usersList" class="p-2 h-[520px] overflow-y-auto soft-scroll">
          <!-- items dinámicos -->
          <div class="text-sm text-slate-500 p-3">Cargando usuarios...</div>
        </div>
      </div>

      <!-- Chat -->
      <div class="rounded-2xl border border-slate-200 bg-white flex flex-col">
        <!-- Header chat -->
        <div class="p-4 border-b border-slate-200 flex items-center justify-between gap-3">
          <div class="min-w-0">
            <div id="activeUserName" class="font-extrabold text-slate-900 truncate">Selecciona un usuario</div>
            <div id="activeUserMeta" class="text-xs text-slate-500 truncate"></div>
          </div>
          <button id="btnRefreshMessages"
                  class="rounded-xl px-3 py-2 text-sm font-bold bg-slate-900 text-white hover:bg-slate-800 disabled:opacity-60">
            Refrescar
          </button>
        </div>

        <!-- Mensajes -->
        <div id="messagesBox" class="p-4 flex-1 overflow-y-auto soft-scroll bg-slate-50">
          <div class="text-sm text-slate-500">Abre una conversación para ver mensajes.</div>
        </div>

        <!-- Input -->
        <div class="p-4 border-t border-slate-200 bg-white">
          <div class="flex gap-2">
            <input id="messageInput" type="text" placeholder="Escribe un mensaje..."
                   class="flex-1 rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-slate-300"
                   disabled>
            <button id="btnSend"
                    class="rounded-xl px-4 py-2 font-bold bg-slate-900 text-white hover:bg-slate-800 disabled:opacity-60"
                    disabled>
              Enviar
            </button>
          </div>
          <p id="chatMsg" class="text-xs mt-2 text-slate-500"></p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Socket.io client -->
<script src="https://cdn.socket.io/4.7.5/socket.io.min.js"></script>

<script>
(function() {
  const csrfTokenMeta  = document.querySelector('meta[name="csrf-token"]');
  const csrfHeaderMeta = document.querySelector('meta[name="csrf-header"]');

  function csrf() {
    return {
      token: csrfTokenMeta ? csrfTokenMeta.getAttribute('content') : '',
      header: csrfHeaderMeta ? csrfHeaderMeta.getAttribute('content') : 'X-CSRF-TOKEN'
    };
  }

  // ===== Config =====
 // ===== Config =====
   // ===== Config =====
const SOCKET_URL = "https://paneladministrativopersonaliza.com:3001";
const DEBUG_URL_USERS = "https://paneladministrativopersonaliza.com/chat/users";
const DEBUG_URL_SEND  = "https://paneladministrativopersonaliza.com/chat/send";
const DEBUG_URL_SEND  = " https://paneladministrativopersonaliza.com/chat/markread";
const ADMIN_ID = <?= (int)session('user_id') ?>;
const ADMIN_NAME = <?= json_encode(session('nombre') ?? 'Admin') ?>;


// ===== API Endpoints (CodeIgniter) =====
const ENDPOINTS = {
  users: <?= json_encode(base_url('chat/users')) ?>,
  messages: <?= json_encode(base_url('chat/messages')) ?>,
  send: <?= json_encode(base_url('chat/send')) ?>,
  markRead: <?= json_encode(base_url('chat/markRead')) ?>,
};


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
  let users = [];                 // {id, name, email, isOnline, lastMessage, unread}
  let activeUserId = null;

  // ===== Helpers =====
  function setStatus(connected) {
    if (connected) {
      elStatus.textContent = "Conectado";
      elStatus.className = "text-xs font-bold px-3 py-1 rounded-full bg-emerald-100 text-emerald-700";
    } else {
      elStatus.textContent = "Desconectado";
      elStatus.className = "text-xs font-bold px-3 py-1 rounded-full bg-slate-100 text-slate-700";
    }
  }

  function escapeHtml(str) {
    return (str || "").replace(/[&<>"']/g, m => ({
      "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"
    }[m]));
  }

  function renderUsers(list) {
    const q = (elSearch.value || "").toLowerCase().trim();
    const filtered = list
      .filter(u => !q || (u.name||"").toLowerCase().includes(q) || (u.email||"").toLowerCase().includes(q))
      .sort((a,b) => (b.isOnline - a.isOnline) || ((b.unread||0)-(a.unread||0)));

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

    // bind clicks
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

  function scrollBottom() {
    elMessagesBox.scrollTop = elMessagesBox.scrollHeight;
  }

  async function apiGet(url) {
    const res = await fetch(url, { credentials: "include" });
    if (!res.ok) throw new Error("GET failed");
    return await res.json();
  }

  async function apiPost(url, body) {
    const c = csrf();
    const res = await fetch(url, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json", [c.header]: c.token },
      body: JSON.stringify(body)
    });
    const data = await res.json().catch(() => ({}));
    if (data && data.csrf && csrfTokenMeta) csrfTokenMeta.setAttribute("content", data.csrf);
    if (!res.ok) throw new Error(data.message || "POST failed");
    return data;
  }

  // ===== Load users list from CI (recent + online info will be updated by socket) =====
  async function loadUsers() {
    const data = await apiGet(ENDPOINTS.users);
    users = (data.users || []).map(u => ({
      id: u.id,
      name: u.name,
      email: u.email,
      isOnline: !!u.isOnline,
      lastMessage: u.lastMessage || "",
      unread: u.unread || 0
    }));
    renderUsers(users);
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
    elMessagesBox.innerHTML = msgs.map(renderMessageBubble).join("") || `<div class="text-sm text-slate-500">Sin mensajes.</div>`;
    scrollBottom();

    // marcar leídos
    try {
      await apiPost(ENDPOINTS.markRead, { userId: activeUserId });
      const idx = users.findIndex(x => String(x.id) === activeUserId);
      if (idx >= 0) users[idx].unread = 0;
      renderUsers(users);
    } catch(e) {}

    renderUsers(users);
  }

  
  // ===== Send message =====
  async function sendMessage() {
    const text = (elInput.value || "").trim();
    if (!text || !activeUserId) return;

    elSend.disabled = true;
    elInput.disabled = true;
    elChatMsg.textContent = "";

    try {
      // 1) Guardar en BD (CI)
      const saved = await apiPost(ENDPOINTS.send, {
        userId: activeUserId,
        message: text
      });

      // 2) Emitir por socket (tiempo real)
      socket?.emit("message:send", {
        toUserId: activeUserId,
        fromRole: "admin",
        fromId: ADMIN_ID,
        message: text
      });

      // 3) Pintar local rápido
      elMessagesBox.insertAdjacentHTML("beforeend", renderMessageBubble({
        sender_type: "admin",
        message: text,
        created_at: saved.createdAt || new Date().toLocaleString()
      }));
      scrollBottom();

      // actualizar preview
      const idx = users.findIndex(x => String(x.id) === String(activeUserId));
      if (idx >= 0) users[idx].lastMessage = text;

      elInput.value = "";
      renderUsers(users);

    } catch (e) {
      elChatMsg.textContent = "No se pudo enviar. Intenta de nuevo.";
    } finally {
      elSend.disabled = false;
      elInput.disabled = false;
      elInput.focus();
    }
  }


  // ===== Socket =====
  function initSocket() {
    socket = io(SOCKET_URL, { transports: ["websocket"] });

    socket.on("connect", () => {
      setStatus(true);
      socket.emit("register", { role: "admin", id: ADMIN_ID, name: ADMIN_NAME });
    });

    socket.on("disconnect", () => setStatus(false));

    // Actualización presencia
    socket.on("presence:update", (data) => {
      if (data.role !== "user") return;
      const idx = users.findIndex(x => String(x.id) === String(data.id));
      if (idx >= 0) {
        users[idx].isOnline = !!data.isOnline;
      }
      renderUsers(users);
    });

    // Mensaje entrante de usuario (para admin)
    socket.on("message:receive:admin", (payload) => {
      // payload: {toUserId, fromRole, fromId, message, createdAt}
      // Si el usuario activo es el que escribió, mostramos
      const fromUserId = String(payload.fromId);
      const msgText = payload.message || "";

      // actualiza preview/unread
      const idx = users.findIndex(x => String(x.id) === fromUserId);
      if (idx >= 0) {
        users[idx].lastMessage = msgText;
        if (String(activeUserId) !== fromUserId) users[idx].unread = (users[idx].unread || 0) + 1;
      }

      if (String(activeUserId) === fromUserId) {
        elMessagesBox.insertAdjacentHTML("beforeend", renderMessageBubble({
          sender_type: "user",
          message: msgText,
          created_at: payload.createdAt ? new Date(payload.createdAt).toLocaleString() : new Date().toLocaleString()
        }));
        scrollBottom();
      }

      renderUsers(users);
    });
  }

  // ===== Events =====
  elSend.addEventListener("click", sendMessage);
  elInput.addEventListener("keydown", (e) => {
    if (e.key === "Enter") sendMessage();
  });
  elRefresh.addEventListener("click", () => {
    if (!activeUserId) return;
    openConversation(activeUserId);
  });
  elSearch.addEventListener("input", () => renderUsers(users));

  // ===== Boot =====
  (async function boot() {
    setStatus(false);
    await loadUsers();
    initSocket();
  })();

})();


</script>

