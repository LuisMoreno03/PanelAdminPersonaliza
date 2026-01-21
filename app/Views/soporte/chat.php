<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Soporte | Chat</title>

  <script src="https://cdn.tailwindcss.com"></script>

  <!-- Si tu panel YA carga Alpine, comenta esta línea -->
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>

  <meta name="color-scheme" content="light" />
  <style>[x-cloak]{display:none!important}</style>
</head>

<body class="min-h-screen bg-slate-100">

  <?= view('layouts/menu') ?>

  <main id="mainLayout" class="min-h-screen md:pl-64 transition-all">
    <div class="p-4 md:p-6">
      <div class="mx-auto max-w-7xl">

        <script>
          window.SUPPORT = {
            role: "<?= esc($forcedRole ?? (session('rol') ?? session('role') ?? '')) ?>",
            userId: <?= (int)(session('user_id') ?? 0) ?>,
            base: "<?= base_url() ?>",
            endpoints: {
              tickets: "<?= base_url('soporte/tickets') ?>",
              ticketBase: "<?= base_url('soporte/ticket') ?>",
              attachment: "<?= base_url('soporte/attachment') ?>"
            }
          };
        </script>

        <div class="grid lg:grid-cols-[380px_1fr] gap-4"
             x-data="supportChat()"
             x-init="init()"
             x-cloak>

          <!-- LISTA -->
          <section class="bg-white rounded-2xl border border-slate-200 overflow-hidden flex flex-col min-h-[78vh]">

            <div class="p-4 border-b border-slate-200 flex items-center justify-between">
              <div class="flex items-center gap-3 min-w-0">
                <div class="h-10 w-10 rounded-full bg-slate-900 text-white grid place-items-center font-extrabold shrink-0">S</div>
                <div class="min-w-0">
                  <div class="font-extrabold text-slate-900 truncate">Soporte</div>
                  <div class="text-xs text-slate-500 truncate"
                       x-text="isAdmin ? 'Vista Admin (todos los tickets)' : 'Mis tickets (producción)'"></div>
                  <div class="text-[11px] text-slate-400 truncate">
                    Rol detectado: <span class="font-semibold" x-text="role"></span>
                  </div>
                </div>
              </div>

              <button x-show="!isAdmin"
                      @click="startNew()"
                      class="px-3 py-2 rounded-xl text-sm font-semibold border border-slate-200 hover:bg-slate-50"
                      type="button">
                Nuevo
              </button>
            </div>

            <div class="p-3 border-b border-slate-200">
              <div class="relative">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M21 21l-4.3-4.3m1.3-5.2a7 7 0 11-14 0 7 7 0 0114 0z"/>
                  </svg>
                </span>
                <input x-model="q"
                       type="text"
                       placeholder="Buscar por ticket o pedido…"
                       class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-9 pr-3 py-2 text-sm
                              placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200" />
              </div>

              <div class="mt-3 flex gap-2" x-show="isAdmin">
                <button type="button" @click="filter='unassigned'"
                        class="px-3 py-2 rounded-xl text-xs font-semibold border"
                        :class="filter==='unassigned' ? 'bg-slate-900 text-white border-slate-900' : 'border-slate-200 hover:bg-slate-50'">
                  Sin asignar
                </button>

                <button type="button" @click="filter='mine'"
                        class="px-3 py-2 rounded-xl text-xs font-semibold border"
                        :class="filter==='mine' ? 'bg-slate-900 text-white border-slate-900' : 'border-slate-200 hover:bg-slate-50'">
                  Asignados a mí
                </button>

                <button type="button" @click="filter='all'"
                        class="px-3 py-2 rounded-xl text-xs font-semibold border"
                        :class="filter==='all' ? 'bg-slate-900 text-white border-slate-900' : 'border-slate-200 hover:bg-slate-50'">
                  Todos
                </button>
              </div>
            </div>

            <div class="flex-1 overflow-auto">
              <template x-for="(t,i) in filteredTickets" :key="t.id ?? t.ticket_code ?? i">
                <button type="button"
                        @click="openTicket(t.id)"
                        class="w-full text-left px-4 py-3 border-b border-slate-100 hover:bg-slate-50 transition flex items-start gap-3"
                        :class="selectedTicketId===t.id ? 'bg-slate-50' : ''">
                  <div class="h-11 w-11 rounded-full bg-slate-200 grid place-items-center font-extrabold text-slate-700 shrink-0">#</div>

                  <div class="min-w-0 flex-1">
                    <div class="flex items-center justify-between gap-2">
                      <div class="font-bold text-slate-900 truncate" x-text="t.ticket_code"></div>
                      <span class="text-[11px] font-semibold px-2 py-1 rounded-lg"
                            :class="badgeClass(t.status)"
                            x-text="statusLabel(t.status)"></span>
                    </div>

                    <div class="text-xs text-slate-500 mt-1 truncate">
                      <template x-if="t.order_id">
                        <span>Pedido: <span class="font-semibold" x-text="t.order_id"></span></span>
                      </template>
                      <template x-if="!t.order_id"><span>—</span></template>
                    </div>

                    <div class="text-xs text-slate-500 mt-1" x-show="isAdmin">
                      <template x-if="t.assigned_to">
                        <span>Aceptado por <span class="font-semibold" x-text="t.assigned_name || ('#'+t.assigned_to)"></span></span>
                      </template>
                      <template x-if="!t.assigned_to">
                        <span class="text-amber-700 font-semibold">Sin asignar</span>
                      </template>
                    </div>
                  </div>
                </button>
              </template>

              <div class="p-4 text-sm text-slate-500" x-show="filteredTickets.length===0">
                No hay tickets en este filtro.
              </div>
            </div>

            <div class="p-3 border-t border-slate-200 text-xs text-slate-500 flex items-center justify-between">
              <span x-text="`Total: ${tickets.length}`"></span>
              <span class="font-semibold text-slate-700">Soporte Interno</span>
            </div>
          </section>

          <!-- CHAT -->
          <section class="rounded-2xl border border-slate-200 overflow-hidden flex flex-col bg-white min-h-[78vh]">

            <div class="px-4 py-3 border-b border-slate-200 bg-[#f0f2f5] flex items-center justify-between gap-3">
              <div class="flex items-center gap-3 min-w-0">
                <div class="h-10 w-10 rounded-full bg-emerald-600 text-white grid place-items-center font-extrabold shrink-0">W</div>

                <div class="min-w-0">
                  <div class="font-extrabold text-slate-900 truncate">
                    <span x-show="ticket" x-text="(ticket && ticket.ticket_code) ? ticket.ticket_code : ''"></span>
                    <span x-show="isCreating" class="text-slate-600">Nuevo ticket</span>
                    <span x-show="!ticket && !isCreating" class="text-slate-600">Selecciona un ticket</span>
                  </div>

                  <!-- ✅ NUNCA accedas ticket.xxx sin validar -->
                  <div class="text-xs text-slate-600 truncate" x-show="ticket">
                    <span class="font-semibold" x-text="ticket ? statusLabel(ticket.status) : ''"></span>

                    <span x-show="ticket && ticket.order_id">
                      · Pedido: <span class="font-semibold" x-text="ticket ? ticket.order_id : ''"></span>
                    </span>

                    <span x-show="ticket && ticket.assigned_to">
                      · Aceptado por
                      <span class="font-semibold" x-text="ticket ? (ticket.assigned_name || ('#'+ticket.assigned_to)) : ''"></span>
                    </span>

                    <span x-show="ticket && ticket.assigned_at">
                      · <span class="font-semibold" x-text="ticket ? formatDT(ticket.assigned_at) : ''"></span>
                    </span>
                  </div>
                </div>
              </div>

              <div class="flex items-center gap-2">
                <button type="button"
                        @click="toggleNotifications()"
                        class="h-10 w-10 rounded-full bg-white border border-slate-200 grid place-items-center hover:bg-slate-50"
                        :title="notifyEnabled ? 'Notificaciones activadas' : 'Activar notificaciones'">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0a3 3 0 01-6 0"/>
                  </svg>
                </button>

                <!-- ✅ Admin actions -->
                <div class="flex items-center gap-2" x-show="ticket && isAdmin">
                  <button type="button"
                          x-show="ticket && !ticket.assigned_to"
                          @click="acceptCase()"
                          class="px-3 py-2 rounded-xl bg-emerald-600 text-white text-sm font-semibold hover:opacity-90">
                    Aceptar caso
                  </button>

                  <select x-model="adminStatus" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                    <option value="open">Abierto</option>
                    <option value="in_progress">En proceso</option>
                    <option value="waiting_customer">Esperando info</option>
                    <option value="resolved">Resuelto</option>
                    <option value="closed">Cerrado</option>
                  </select>

                  <button type="button"
                          @click="updateStatus()"
                          class="px-3 py-2 rounded-xl border border-slate-200 bg-white text-sm font-semibold hover:bg-slate-50">
                    Guardar
                  </button>
                </div>
              </div>
            </div>

            <div class="flex-1 overflow-auto px-4 py-4 bg-[#efeae2]" x-ref="thread">
              <div class="h-full grid place-items-center text-center px-6" x-show="!ticket && !isCreating">
                <div class="max-w-sm">
                  <div class="mx-auto h-14 w-14 rounded-full bg-white border border-slate-200 grid place-items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-slate-700" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M8 10h8M8 14h5m9-2a8 8 0 01-8 8H7l-4 3V6a8 8 0 018-8h3a8 8 0 018 8z"/>
                    </svg>
                  </div>
                  <div class="mt-3 font-extrabold text-slate-900">Soporte interno</div>
                  <div class="mt-1 text-sm text-slate-600">Selecciona un ticket para ver la conversación.</div>
                </div>
              </div>

              <div class="space-y-2" x-show="ticket || isCreating">
                <template x-for="m in messages" :key="m.id">
                  <div class="flex" :class="m.sender==='user' ? 'justify-end' : 'justify-start'">
                    <div class="max-w-[82%] shadow-sm px-3 py-2"
                         :class="m.sender==='user'
                           ? 'bg-emerald-200 text-slate-900 rounded-2xl rounded-tr-md'
                           : 'bg-white text-slate-900 rounded-2xl rounded-tl-md'">

                      <div class="text-sm whitespace-pre-wrap break-words" x-show="m.message" x-text="m.message"></div>

                      <div class="mt-2 grid grid-cols-2 gap-2" x-show="attachments[m.id]">
                        <template x-for="a in (attachments[m.id] || [])" :key="a.id">
                          <a class="block overflow-hidden rounded-xl border border-black/10 bg-white"
                             :href="`${SUPPORT.endpoints.attachment}/${a.id}`"
                             target="_blank">
                            <img class="w-full h-28 object-cover"
                                 :src="`${SUPPORT.endpoints.attachment}/${a.id}`" alt="">
                          </a>
                        </template>
                      </div>

                      <div class="mt-1 text-[10px] text-slate-600 flex justify-end" x-text="formatTime(m.created_at)"></div>
                    </div>
                  </div>
                </template>
              </div>
            </div>

            <form class="px-4 py-3 border-t border-slate-200 bg-[#f0f2f5]" @submit.prevent="send()">

              <div class="grid md:grid-cols-[240px_1fr] gap-3 mb-3" x-show="isCreating">
                <input type="text" x-model="orderId" placeholder="Número de pedido (opcional)"
                       class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-200">
                <div class="text-xs text-slate-500 flex items-center">Asócialo a un pedido si aplica.</div>
              </div>

              <!-- Preview WhatsApp -->
              <div class="mb-3" x-show="files.length">
                <div class="flex gap-2 overflow-x-auto pb-1">
                  <template x-for="(f, idx) in files" :key="f.url">
                    <div class="relative h-16 w-16 rounded-xl overflow-hidden border border-black/10 bg-white shrink-0">
                      <img :src="f.url" class="h-full w-full object-cover" alt="">
                      <button type="button" @click="removeFile(idx)"
                              class="absolute top-1 right-1 h-6 w-6 rounded-full bg-black/60 text-white grid place-items-center text-xs">
                        ✕
                      </button>
                    </div>
                  </template>
                </div>
              </div>

              <div class="relative">
                <div x-show="emojiOpen" @click.outside="emojiOpen=false"
                     class="absolute bottom-14 left-0 z-10 w-72 bg-white border border-slate-200 rounded-2xl shadow-lg p-3">
                  <div class="text-xs font-semibold text-slate-500 mb-2">Emojis</div>
                  <div class="grid grid-cols-8 gap-2">
                    <template x-for="e in emojis" :key="e">
                      <button type="button" class="text-xl hover:bg-slate-50 rounded-lg" @click="addEmoji(e)" x-text="e"></button>
                    </template>
                  </div>
                </div>

                <div class="flex items-end gap-2">
                  <button type="button"
                          @click="emojiOpen=!emojiOpen"
                          class="h-11 w-11 grid place-items-center rounded-full bg-white border border-slate-200 hover:bg-slate-50"
                          title="Emojis">🙂</button>

                  <label class="h-11 w-11 grid place-items-center rounded-full bg-white border border-slate-200 cursor-pointer hover:bg-slate-50">
                    <input type="file" multiple accept="image/*" class="hidden" @change="pickFiles($event)">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-700" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828L18 9.828a4 4 0 10-5.656-5.656L6.343 10.172a6 6 0 108.485 8.485L20 13"/>
                    </svg>
                  </label>

                  <textarea x-model="draft" rows="1" placeholder="Escribe un mensaje…"
                            class="flex-1 resize-none rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm
                                   focus:outline-none focus:ring-2 focus:ring-slate-200 max-h-32"></textarea>

                  <button type="submit"
                          class="h-11 w-11 rounded-full bg-emerald-600 text-white grid place-items-center hover:opacity-90 disabled:opacity-50"
                          :disabled="sending" title="Enviar">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                  </button>
                </div>
              </div>

            </form>

          </section>

        </div>

      </div>
    </div>
  </main>

  <!-- Cache buster -->
  <script src="<?= base_url('js/support-chat.js?v=' . time()) ?>"></script>
</body>
</html>
