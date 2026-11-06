(() => {
  let chats = [];
  let allChats = [];
  let cachedDevices = [];
  let openId = 0;
  let pendingPhone = '';
  let listFilter = 'all';
  let sending = false;

  const AVATAR_PALETTES = [
    { bg: '#e7f6ec', color: '#166534', border: '#bbf7d0' },
    { bg: '#eff6ff', color: '#2563eb', border: '#bfdbfe' },
    { bg: '#fdf4ff', color: '#a21caf', border: '#f5d0fe' },
    { bg: '#fef3c7', color: '#b45309', border: '#fde68a' },
    { bg: '#f1f5f9', color: '#475569', border: '#e2e8f0' }
  ];

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function phoneKey(phone) {
    const digits = String(phone || '').replace(/\D/g, '');
    return digits.length > 10 ? digits.slice(-10) : digits;
  }

  function formatPhone(phone) {
    const digits = String(phone || '').replace(/\D/g, '');
    const local = digits.length === 12 && digits.startsWith('91') ? digits.slice(2) : digits;
    if (local.length === 10) return '+91 ' + local.slice(0, 5) + ' ' + local.slice(5);
    return digits ? '+' + digits : 'Unknown';
  }

  function displayName(chat) {
    return formatPhone(chat.phone);
  }

  function avatarPalette(phone) {
    const digits = String(phone || '').replace(/\D/g, '');
    let total = 0;
    for (const digit of digits) total += digit.charCodeAt(0);
    return AVATAR_PALETTES[total % AVATAR_PALETTES.length];
  }

  function avatarInitials(phone, name) {
    if (name && name.toLowerCase() !== 'you' && !name.startsWith('+')) {
      const parts = name.trim().split(/\s+/);
      if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
      return parts[0].slice(0, 2).toUpperCase();
    }
    const digits = String(phone || '').replace(/\D/g, '');
    if (digits.startsWith('91')) return '+91';
    if (digits.length >= 2) return '+' + digits.slice(0, 2);
    return 'WA';
  }

  function clockText(iso) {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  }

  function dayLabel(iso) {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    const now = new Date();
    const start = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const that = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    const diff = Math.round((start - that) / 86400000);
    if (diff === 0) return 'Today';
    if (diff === 1) return 'Yesterday';
    return date.toLocaleDateString('en-GB');
  }

  function whenText(iso) {
    const label = dayLabel(iso);
    if (label === 'Today') return clockText(iso);
    return label;
  }

  function phoneChoice() {
    const select = document.getElementById('wa-chat-phone');
    const option = select?.selectedOptions?.[0];
    return {
      id: select?.value || '',
      name: select?.value ? (option?.textContent || '') : '',
    };
  }

  function deviceForChat(chat) {
    const choice = phoneChoice();
    if (choice.id) {
      const dev = cachedDevices.find(d => String(d.id) === String(choice.id));
      if (dev) return dev;
    }
    let senders = chat?.senders;
    let devId = chat?.device_id;
    if ((!senders || senders.length === 0) && chat) {
      const match = allChats.find(c => (chat.id && String(c.id) === String(chat.id)) || (c.phone && chat.phone && phoneKey(c.phone) === phoneKey(chat.phone)));
      if (match) {
        if (match.senders && match.senders.length > 0) senders = match.senders;
        if (match.device_id) devId = match.device_id;
      }
    }
    if (senders && senders.length > 0) {
      const sender = senders[0];
      const dev = cachedDevices.find(d => (sender.id && String(d.id) === String(sender.id)) || (sender.name && d.name && d.name.toLowerCase() === sender.name.toLowerCase()));
      if (dev) return dev;
      return { name: sender.name || 'Whatsapp Campaign', phone: '' };
    }
    if (devId) {
      const dev = cachedDevices.find(d => String(d.id) === String(devId));
      if (dev) return dev;
    }
    const readyDev = cachedDevices.find(d => d.status === 'ready' || d.status === 'connected');
    if (readyDev) return readyDev;
    if (cachedDevices[0]) return cachedDevices[0];
    return { name: 'Whatsapp Campaign', phone: '' };
  }

  function chatDeviceBadge(chat) {
    if (chat.senders && chat.senders.length > 0 && chat.senders[0].name) {
      return chat.senders[0].name;
    }
    if (chat.device_name) return chat.device_name;
    return 'Whatsapp Campaign';
  }

  function fromPhone(chat) {
    const choice = phoneChoice();
    if (!choice.id) return true;
    return (chat.senders || []).some((sender) => (
      (sender.id && sender.id === choice.id)
      || (!sender.id && sender.name && sender.name.toLowerCase() === choice.name.toLowerCase())
    ));
  }

  function scopedChats() {
    return allChats.filter(fromPhone);
  }

  function visibleChats() {
    const query = (document.getElementById('wa-chat-search')?.value || '').trim().toLowerCase();
    const scoped = scopedChats();
    const unreadTotal = scoped.filter((chat) => Number(chat.unread_count) > 0).length;
    const allTotal = document.getElementById('wa-all-total');
    const unreadLabel = document.getElementById('wa-unread-total');
    if (allTotal) allTotal.textContent = String(scoped.length);
    if (unreadLabel) unreadLabel.textContent = String(unreadTotal);

    const activeCountEl = document.getElementById('wa-chat-active-count');
    if (activeCountEl) {
      const count = scoped.length;
      activeCountEl.textContent = `${count} Active Conversation${count === 1 ? '' : 's'}`;
    }

    let rows = listFilter === 'unread' ? scoped.filter((chat) => Number(chat.unread_count) > 0) : scoped;
    if (!query) return rows;
    return rows.filter((chat) => [chat.name, chat.phone, chat.last_message].join(' ').toLowerCase().includes(query));
  }

  function renderList() {
    const list = document.getElementById('wa-chat-list');
    if (!list) return;
    chats = visibleChats();
    list.replaceChildren();
    if (!chats.length) {
      const empty = document.createElement('p');
      empty.className = 'wa-chat-list-empty muted';
      const searching = (document.getElementById('wa-chat-search')?.value || '').trim();
      const phone = phoneChoice();
      empty.textContent = searching
        ? 'No chats match'
        : (listFilter === 'unread' ? 'No unread chats' : (phone.id ? 'No chats from this phone' : 'No chats yet'));
      list.append(empty);
      return;
    }
    for (const chat of chats) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'wa-chat-item' + (chat.id === openId ? ' is-on' : '');
      const title = displayName(chat);
      const unread = Number(chat.unread_count) || 0;
      const badge = unread > 0 ? `<span class="wa-unread">${unread > 99 ? '99+' : unread}</span>` : '';
      const initials = avatarInitials(chat.phone, chat.name);
      const pal = avatarPalette(chat.phone);
      const devBadge = chatDeviceBadge(chat);
      const lastMsg = chat.last_message || chat.phone || '';
      const timeStr = whenText(chat.last_at);

      button.innerHTML = `
        <span class="wa-chat-avatar" style="background:${pal.bg}; color:${pal.color}; border:1px solid ${pal.border}; font-weight:700;">${escapeHtml(initials)}</span>
        <span class="wa-chat-copy">
          <span class="wa-chat-title-row">
            <strong>${escapeHtml(title)}</strong>
          </span>
          <span class="wa-chat-snippet-row">
            <span>${escapeHtml(lastMsg)}</span>
          </span>
        </span>
        <span class="wa-chat-side">
          <time>${escapeHtml(timeStr)}</time>
          ${badge}
        </span>
      `;
      button.addEventListener('click', () => openThread(chat.id));
      list.append(button);
    }
  }

  function renderThread(data) {
    const pane = document.getElementById('wa-chat-thread');
    if (!pane) return;
    const chat = data.chat || {};
    const title = displayName(chat);
    const dev = deviceForChat(chat);
    const devName = dev.name || 'WAC 1';
    const devPhone = dev.phone ? ` (${formatPhone(dev.phone)})` : '';
    const avatarInit = avatarInitials(chat.phone, chat.name);
    const pal = avatarPalette(chat.phone);

    const oneTick = '<svg class="wa-tick" viewBox="0 0 16 15" aria-hidden="true"><path d="M10.91 3.316l-.478-.372a.365.365 0 0 0-.51.063L4.566 9.88a.32.32 0 0 1-.484.032L1.892 7.77a.366.366 0 0 0-.515.006l-.423.433a.364.364 0 0 0 .006.514l3.255 3.185a.32.32 0 0 0 .484-.033l6.272-8.048a.365.365 0 0 0-.063-.51z"/></svg>';
    const twoTick = '<svg class="wa-tick wa-tick-double" viewBox="0 0 16 15" aria-hidden="true"><path d="M15.01 3.316l-.478-.372a.365.365 0 0 0-.51.063L8.666 9.88a.32.32 0 0 1-.484.032l-.358-.325a.32.32 0 0 0-.484.032l-.378.48a.418.418 0 0 0 .036.54l1.32 1.267a.32.32 0 0 0 .484-.034l6.272-8.048a.366.366 0 0 0-.064-.512zm-4.1 0l-.478-.372a.365.365 0 0 0-.51.063L4.566 9.88a.32.32 0 0 1-.484.032L1.892 7.77a.366.366 0 0 0-.515.006l-.423.433a.364.364 0 0 0 .006.514l3.255 3.185a.32.32 0 0 0 .484-.033l6.272-8.048a.365.365 0 0 0-.063-.51z"/></svg>';
    let lastDay = '';
    const lines = (data.messages || []).map((message) => {
      const side = message.direction === 'out' ? 'out' : 'in';
      let mark = '';
      if (side === 'out') {
        const read = message.status === 'read' || message.status === 'seen';
        const delivered = read || message.status === 'delivered';
        mark = delivered ? twoTick.replace('wa-tick wa-tick-double', read ? 'wa-tick wa-tick-double is-read' : 'wa-tick wa-tick-double') : oneTick;
      }
      const day = dayLabel(message.created_at);
      const chip = day && day !== lastDay ? `<div class="wa-day">${escapeHtml(day)}</div>` : '';
      if (day) lastDay = day;
      return `${chip}<div class="wa-bubble ${side}"><p>${escapeHtml(message.body)}</p><span class="wa-bubble-meta"><time>${escapeHtml(clockText(message.created_at))}</time>${mark}</span></div>`;
    }).join('');

    pane.innerHTML = `
      <div class="wa-chat-head">
        <div class="wa-chat-head-left">
          <span class="wa-chat-avatar" style="background:${pal.bg}; color:${pal.color}; border:1px solid ${pal.border}; font-weight:700;">${escapeHtml(avatarInit)}</span>
          <div class="wa-chat-head-info">
            <div style="display:flex; align-items:center; gap:6px;">
              <strong>${escapeHtml(title)}</strong>
              <span class="wa-chat-status-active">Active</span>
            </div>
            <p class="wa-chat-head-sub">
              <span class="status-dot"></span>
              <span>Linked via <strong>${escapeHtml(devName)}</strong>${escapeHtml(devPhone)}</span>
            </p>
          </div>
        </div>
      </div>
      <div class="wa-chat-stream">${lines || '<p class="muted" style="padding:16px 0;">No messages in this chat.</p>'}</div>
      <form class="wa-chat-compose">
        <div class="wa-chat-dispatch-info">
          <span><span class="status-dot"></span> Replying as: <strong style="color:#142e25">${escapeHtml(devName)}</strong>${escapeHtml(devPhone)}</span>
          <span class="wa-chat-press-enter">Press <strong>Enter</strong> to dispatch</span>
        </div>
        <div class="wa-chat-bar">
          <button type="button" class="wa-chat-tool" data-tool="emoji" aria-label="Emoji">☺</button>
          <input name="message" type="text" maxlength="4000" placeholder="Type a message" autocomplete="off" aria-label="Type a message">
          <button type="submit" class="wa-chat-send" aria-label="Send">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
          </button>
        </div>
        <div class="wa-emoji-pop" hidden></div>
      </form>
    `;
    const form = pane.querySelector('.wa-chat-compose');
    const input = form?.message;
    const pop = form?.querySelector('.wa-emoji-pop');
    const emojis = ['😀','😁','😂','😊','😍','🙏','👍','👋','🔥','✅','❤️','🎉'];
    if (pop) {
      pop.replaceChildren();
      emojis.forEach((emoji) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = emoji;
        button.addEventListener('click', () => {
          input.value += emoji;
          input.focus();
          pop.hidden = true;
        });
        pop.append(button);
      });
    }
    form?.querySelector('[data-tool="emoji"]')?.addEventListener('click', () => {
      if (pop) pop.hidden = !pop.hidden;
    });
    form?.addEventListener('submit', event => {
      event.preventDefault();
      const message = input.value.trim();
      if (!message || !chat.phone || sending) return;
      input.value = '';
      const stream = pane.querySelector('.wa-chat-stream');
      if (stream) {
        const bubble = document.createElement('div');
        bubble.className = 'wa-bubble out';
        bubble.innerHTML = `<p>${escapeHtml(message)}</p><span class="wa-bubble-meta"><time>${escapeHtml(clockText(new Date().toISOString()))}</time></span>`;
        stream.append(bubble);
        stream.scrollTop = stream.scrollHeight;
      }
      sending = true;
      const send = chat.remote_jid
        ? api('whatsapp/inbox/send', { method: 'POST', body: JSON.stringify({ remote_jid: chat.remote_jid, phone: chat.phone, message }) })
        : api('whatsapp/send', { method: 'POST', body: JSON.stringify({ phone: chat.phone, message }) });
      send.then(() => openThread(chat.id))
        .catch(error => notice(error.message, true))
        .finally(() => { sending = false; });
    });
    pane.querySelector('.wa-chat-stream')?.scrollTo(0, pane.querySelector('.wa-chat-stream').scrollHeight);
  }

  function renderEmptyThread() {
    const pane = document.getElementById('wa-chat-thread');
    if (!pane) return;
    const phone = phoneChoice();
    const title = phone.id ? 'No chats from this phone' : 'No chats yet';
    const note = phone.id
      ? 'This list shows the customers that phone messaged.'
      : 'A chat opens here as soon as you send a message.';
    pane.innerHTML = `<div class="wa-chat-empty"><p class="wa-empty-title">${escapeHtml(title)}</p><p class="muted">${escapeHtml(note)}</p></div>`;
  }

  function threadPath(id) {
    const choice = phoneChoice();
    const device = choice.id
      ? '&device=' + encodeURIComponent(choice.id) + '&device_name=' + encodeURIComponent(choice.name)
      : '';
    return String(id).includes('@')
      ? 'whatsapp/chats/thread?remote_jid=' + encodeURIComponent(id) + device
      : 'whatsapp/chats/' + id + (device ? '?' + device.slice(1) : '');
  }

  async function openThread(id) {
    openId = id;
    renderList();
    const path = threadPath(id);
    const data = await api(path);
    if (openId !== id) return;
    const opened = chats.find((chat) => chat.id === id) || allChats.find((chat) => chat.id === id);
    if (opened) opened.unread_count = 0;
    if (data && data.chat && opened) {
      if ((!data.chat.senders || !data.chat.senders.length) && opened.senders && opened.senders.length) {
        data.chat.senders = opened.senders;
      }
      if (!data.chat.device_id && opened.device_id) {
        data.chat.device_id = opened.device_id;
      }
    }
    renderList();
    renderThread(data);
  }

  let phonesReady = false;
  let pendingSender = null;

  function applySender(sender) {
    const select = document.getElementById('wa-chat-phone');
    if (!select) return false;
    if (!sender) {
      select.value = '';
      return true;
    }
    const match = [...select.options].find((option) => (
      (sender.id && option.value === sender.id) || (sender.name && option.textContent === sender.name)
    ));
    if (!match) return false;
    select.value = match.value;
    return true;
  }

  async function fillPhones() {
    if (phonesReady) return;
    const select = document.getElementById('wa-chat-phone');
    if (!select) return;
    const current = select.value;
    const data = await api('whatsapp/device');
    cachedDevices = data.devices || [];
    select.replaceChildren();
    const all = document.createElement('option');
    all.value = '';
    const totalCount = (data.devices || []).length;
    all.textContent = totalCount ? `All linked devices (${totalCount})` : 'All linked devices';
    select.append(all);
    (data.devices || []).forEach((device) => {
      const option = document.createElement('option');
      option.value = device.id || '';
      const phoneSuffix = device.phone ? ` (${formatPhone(device.phone)})` : '';
      option.textContent = (device.name || 'Phone') + phoneSuffix;
      select.append(option);
    });
    if (pendingSender && applySender(pendingSender)) pendingSender = null;
    else select.value = [...select.options].some((option) => option.value === current) ? current : '';
    phonesReady = true;
  }

  async function loadWaChats() {
    const page = document.getElementById('wa-chats');
    if (!page || page.hidden) return;
    if (document.body.dataset.channel !== 'whatsapp') return;
    await fillPhones();
    const data = await api('whatsapp/chats');
    allChats = data.chats || [];
    if (pendingPhone) {
      const want = pendingPhone;
      pendingPhone = '';
      const match = allChats.find((chat) => phoneKey(chat.phone) === want || phoneKey(chat.id) === want);
      openId = match ? match.id : 0;
      renderList();
      if (match) openThread(match.id);
      else renderEmptyThread();
      return;
    }
    if (openId && !scopedChats().some((chat) => chat.id === openId)) openId = 0;
    renderList();
    if (!openId && chats[0]) { openThread(chats[0].id); return; }
    if (!openId) renderEmptyThread();
  }

  window.loadWaChats = () => { loadWaChats().catch(() => renderEmptyThread()); };
  window.openWaChatByPhone = (phone, sender) => {
    pendingPhone = phoneKey(phone);
    pendingSender = sender && (sender.id || sender.name) ? sender : null;
    if (!pendingSender || !applySender(pendingSender)) {
      const select = document.getElementById('wa-chat-phone');
      if (select && !pendingSender) select.value = '';
    } else {
      pendingSender = null;
    }
    const search = document.getElementById('wa-chat-search');
    if (search) search.value = '';
    listFilter = 'all';
    document.querySelectorAll('.wa-filter[data-filter]').forEach((item) => item.classList.toggle('is-on', item.dataset.filter === 'all'));
    window.view?.('wa-chats');
  };
  setInterval(() => {
    const page = document.getElementById('wa-chats');
    if (!page || page.hidden || document.body.dataset.channel !== 'whatsapp') return;
    if (sending) return;
    loadWaChats().catch(() => {});
    const typing = document.querySelector('.wa-chat-compose input');
    if (openId && String(openId).includes('@') && (!typing || !typing.value)) openThread(openId);
  }, 8000);
  document.getElementById('wa-chat-search')?.addEventListener('input', () => renderList());
  document.getElementById('wa-chat-phone')?.addEventListener('change', () => {
    renderList();
    if (openId && chats.some((chat) => chat.id === openId)) openThread(openId).catch(() => renderEmptyThread());
    else if (chats[0]) openThread(chats[0].id).catch(() => renderEmptyThread());
    else { openId = 0; renderEmptyThread(); }
  });
  document.querySelectorAll('.wa-filter[data-filter]').forEach((button) => {
    button.addEventListener('click', () => {
      listFilter = button.dataset.filter || 'all';
      document.querySelectorAll('.wa-filter[data-filter]').forEach((item) => item.classList.toggle('is-on', item === button));
      renderList();
    });
  });
  document.getElementById('wa-new-message')?.addEventListener('click', () => window.view?.('message'));
  document.getElementById('wa-chats-new-msg-btn')?.addEventListener('click', () => window.view?.('message'));
  if (!document.getElementById('wa-chats')?.hidden && document.body.dataset.channel === 'whatsapp') {
    loadWaChats().catch(() => renderEmptyThread());
  }
})();