(() => {
  let page = 1;
  let pages = 1;
  let request = 0;
  let searchTimer;
  let activePreset = 'all';
  let customFrom = '';
  let customTo = '';

  const AVATAR_PALETTES = [
    { bg: '#fef3c7', color: '#b45309', border: '#fde68a' }, // Amber / gold (like Nandini in mock)
    { bg: '#eff6ff', color: '#2563eb', border: '#bfdbfe' }, // Blue (like Pratik in mock)
    { bg: '#fdf4ff', color: '#a21caf', border: '#f5d0fe' }, // Purple/pink (like Client Desk in mock)
    { bg: '#e7f6ec', color: '#166534', border: '#bbf7d0' }, // Mint
    { bg: '#f1f5f9', color: '#475569', border: '#e2e8f0' }  // Slate
  ];

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function avatarPalette(phone) {
    const digits = String(phone || '').replace(/\D/g, '');
    let total = 0;
    for (const digit of digits) total += digit.charCodeAt(0);
    return AVATAR_PALETTES[total % AVATAR_PALETTES.length];
  }

  function formatPhone(phone) {
    const digits = String(phone || '').replace(/\D/g, '');
    const local = digits.length === 12 && digits.startsWith('91') ? digits.slice(2) : digits;
    if (local.length === 10) return '+91 ' + local.slice(0, 5) + ' ' + local.slice(5);
    return digits ? '+' + digits : 'Unknown';
  }

  function pad(n) {
    return String(n).padStart(2, '0');
  }

  function formatDateIso(d) {
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  }

  function formatReadableDate(d) {
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return `${pad(d.getDate())} ${months[d.getMonth()]} ${d.getFullYear()}`;
  }

  function getDateParams() {
    if (activePreset === 'all') {
      return { from: '', to: '' };
    }
    if (activePreset === 'today') {
      const todayStr = formatDateIso(new Date());
      return { from: todayStr, to: todayStr };
    }
    if (activePreset === 'yesterday') {
      const d = new Date();
      d.setDate(d.getDate() - 1);
      const yestStr = formatDateIso(d);
      return { from: yestStr, to: yestStr };
    }
    if (activePreset === '7') {
      const d = new Date();
      d.setDate(d.getDate() - 6);
      return { from: formatDateIso(d), to: '' };
    }
    if (activePreset === '30') {
      const d = new Date();
      d.setDate(d.getDate() - 29);
      return { from: formatDateIso(d), to: '' };
    }
    if (activePreset === 'custom') {
      return { from: customFrom, to: customTo };
    }
    return { from: '', to: '' };
  }

  function getDateLabel() {
    if (activePreset === 'all') return 'All Time';
    if (activePreset === 'today') {
      return `Today (${formatReadableDate(new Date())})`;
    }
    if (activePreset === 'yesterday') {
      const d = new Date();
      d.setDate(d.getDate() - 1);
      return `Yesterday (${formatReadableDate(d)})`;
    }
    if (activePreset === '7') return 'Last 7 Days';
    if (activePreset === '30') return 'Last 30 Days';
    if (activePreset === 'custom') {
      if (customFrom && customTo && customFrom === customTo) {
        const parts = customFrom.split('-');
        if (parts.length === 3) {
          const d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
          return formatReadableDate(d);
        }
        return customFrom;
      }
      return `${customFrom || 'Start'} to ${customTo || 'Now'}`;
    }
    return 'All Dates';
  }

  function updateDateUI() {
    const label = getDateLabel();
    const btn = document.getElementById('wa-sent-date-btn');
    const btnText = document.getElementById('wa-sent-date-btn-text');
    const hasDateFilter = activePreset !== 'all';

    if (btnText) {
      btnText.textContent = label;
    }
    if (btn) {
      btn.classList.toggle('is-filtered', hasDateFilter);
    }

    // Update active filter date chip
    const dateChip = document.getElementById('wa-sent-chip-date');
    const dateChipText = document.getElementById('wa-sent-chip-date-text');
    if (dateChip && dateChipText) {
      if (hasDateFilter) {
        dateChip.hidden = false;
        dateChipText.textContent = `📅 ${label}`;
      } else {
        dateChip.hidden = true;
      }
    }

    // Update preset pills active state
    document.querySelectorAll('.wa-sent-preset-pill').forEach((pill) => {
      const p = pill.dataset.preset;
      pill.classList.toggle('is-active', p === activePreset);
    });
    const allBtn = document.getElementById('wa-sent-preset-all');
    if (allBtn) {
      allBtn.classList.toggle('is-active', activePreset === 'all');
    }
  }

  function updateFilterStrip() {
    const phoneSelect = document.getElementById('wa-sent-phone');
    const devChip = document.getElementById('wa-sent-chip-device');
    const devChipText = document.getElementById('wa-sent-chip-device-text');
    const hasDevice = Boolean(phoneSelect && phoneSelect.value);
    if (devChip && devChipText) {
      if (hasDevice) {
        devChip.hidden = false;
        devChipText.textContent = `📱 ${phoneSelect.selectedOptions[0]?.textContent || 'Linked Device'}`;
      } else {
        devChip.hidden = true;
      }
    }

    const statusSelect = document.getElementById('wa-sent-status');
    const statusChip = document.getElementById('wa-sent-chip-status');
    const statusChipText = document.getElementById('wa-sent-chip-status-text');
    const hasStatus = Boolean(statusSelect && statusSelect.value);
    if (statusChip && statusChipText) {
      if (hasStatus) {
        statusChip.hidden = false;
        statusChipText.textContent = statusSelect.selectedOptions[0]?.textContent || statusSelect.value;
      } else {
        statusChip.hidden = true;
      }
    }

    const searchInput = document.getElementById('wa-sent-search');
    const hasSearch = Boolean(searchInput && searchInput.value.trim());
    const hasDate = activePreset !== 'all';

    // The entire active filter strip is ONLY visible if at least ONE filter is active
    const isAnyActive = hasDate || hasDevice || hasStatus || hasSearch;
    const strip = document.getElementById('wa-sent-filter-strip');
    if (strip) {
      strip.hidden = !isAnyActive;
    }
  }

  function formatWhen(iso) {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return { label: '', time: '' };
    const now = new Date();
    const start = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const that = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    const diff = Math.round((start - that) / 86400000);
    const timeStr = date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    let label = '';
    if (diff === 0) label = 'Today';
    else if (diff === 1) label = 'Yesterday';
    else label = date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
    return { label, time: timeStr };
  }

  function statusHtml(status) {
    if (status === 'waiting') return '<span class="wa-sent-pill is-waiting">Waiting</span>';
    if (status === 'failed') return '<span class="wa-sent-pill is-failed">Failed</span>';
    if (status === 'blocked') return '<span class="wa-sent-pill is-failed">Blocked</span>';
    return `
      <span class="wa-sent-status-delivered" title="Delivered">
        <svg class="wa-tick wa-tick-double" viewBox="0 0 16 15" aria-label="Delivered">
          <path d="M15.01 3.316l-.478-.372a.365.365 0 0 0-.51.063L8.666 9.88a.32.32 0 0 1-.484.032l-.358-.325a.32.32 0 0 0-.484.032l-.378.48a.418.418 0 0 0 .036.54l1.32 1.267a.32.32 0 0 0 .484-.034l6.272-8.048a.366.366 0 0 0-.064-.512zm-4.1 0l-.478-.372a.365.365 0 0 0-.51.063L4.566 9.88a.32.32 0 0 1-.484.032L1.892 7.77a.366.366 0 0 0-.515.006l-.423.433a.364.364 0 0 0 .006.514l3.255 3.185a.32.32 0 0 0 .484-.033l6.272-8.048a.365.365 0 0 0-.063-.51z"/>
        </svg>
        <span>Delivered</span>
      </span>
    `;
  }

  function copyPhoneToClipboard(phone) {
    if (!phone) return;
    navigator.clipboard?.writeText(phone).then(() => {
      const notice = document.getElementById('notice');
      if (notice) {
        notice.textContent = `Copied ${phone} to clipboard`;
        notice.hidden = false;
        setTimeout(() => { notice.hidden = true; }, 2000);
      }
    }).catch(() => {});
  }

  function renderRows(list) {
    const tbody = document.getElementById('wa-sent-rows');
    if (!tbody) return;
    tbody.replaceChildren();
    for (const row of list) {
      const tr = document.createElement('tr');
      const phone = row.phone || '';
      const snippet = (row.snippet || row.message || '').trim();
      const origSnippet = (row.orig_snippet || row.orig_message || '').trim();
      const sendCount = Number(row.send_count) || 1;
      const hasMultiple = sendCount > 1 && origSnippet && origSnippet !== snippet;
      const pal = avatarPalette(phone);
      const formattedPhone = formatPhone(phone);
      const when = formatWhen(row.created_at);
      const from = row.account_name || 'Whatsapp Campaign';

      tr.className = 'wa-sent-row';
      tr.tabIndex = 0;
      tr.innerHTML = `
        <td>
          <span class="wa-sent-to">
            <span class="wa-chat-avatar" style="background:${pal.bg}; color:${pal.color}; border:1px solid ${pal.border}; font-weight:700;">+91</span>
            <span class="wa-sent-to-details">
              <strong class="wa-sent-to-phone">${escapeHtml(formattedPhone)}</strong>
              ${row.name ? `<span class="wa-sent-to-name muted">${escapeHtml(row.name)}</span>` : ''}
            </span>
          </span>
        </td>
        <td class="wa-sent-from-cell">
          <span class="wa-sent-device-chip">
            <span class="wa-device-dot"></span>
            <span class="wa-device-chip-name">${escapeHtml(from)}</span>
          </span>
        </td>
        <td class="wa-sent-msg-cell">
          <span class="wa-sent-msg-bubble">${escapeHtml(snippet)}</span>
        </td>
        <td class="wa-sent-when-cell">
          <div class="wa-sent-when-main">${escapeHtml(when.label)}</div>
          <div class="wa-sent-when-time muted">${escapeHtml(when.time)}</div>
        </td>
        <td class="wa-sent-receipt-cell">
          ${statusHtml(row.status)}
        </td>
        <td class="wa-sent-actions-cell" onclick="event.stopPropagation()">
          <div class="wa-sent-actions-wrap">
            <button type="button" class="wa-sent-action-btn" title="Open Conversation in Chats" data-action="chat">
              <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            </button>
            <button type="button" class="wa-sent-action-btn" title="Copy Number" data-action="copy">
              <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
            </button>
          </div>
        </td>
      `;

      const openChat = () => window.openWaChatByPhone?.(phone, { id: row.device_id || '', name: row.account_name || '' });
      tr.addEventListener('click', openChat);
      tr.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); openChat(); }
      });

      const chatBtn = tr.querySelector('[data-action="chat"]');
      if (chatBtn) {
        chatBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          openChat();
        });
      }

      const copyBtn = tr.querySelector('[data-action="copy"]');
      if (copyBtn) {
        copyBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          copyPhoneToClipboard(phone);
        });
      }

      tbody.append(tr);
    }

    if (!list.length) {
      const tr = document.createElement('tr');
      const td = document.createElement('td');
      td.colSpan = 6;
      td.className = 'empty';
      const phone = document.getElementById('wa-sent-phone')?.value || '';
      td.innerHTML = phone
        ? '<h3>No sends from this phone</h3><p>Rows stay here after the phone is unlinked or blocked.</p>'
        : (activePreset !== 'all'
          ? `<h3>No messages found for this date</h3><p>Filtered by: ${escapeHtml(getDateLabel())}. Try choosing a broader date range or All Time.</p>`
          : '<h3>No WhatsApp sends yet</h3><p>Messages this desk handed to a linked phone appear here.</p>');
      tr.append(td);
      tbody.append(tr);
    }
  }

  async function loadWaSent() {
    const pageEl = document.getElementById('wa-sent');
    const rangeEl = document.getElementById('wa-sent-range');
    const prevBtn = document.getElementById('wa-sent-prev');
    const nextBtn = document.getElementById('wa-sent-next');
    if (!pageEl || pageEl.hidden) return;
    if (document.body.dataset.channel !== 'whatsapp') return;
    const current = ++request;
    if (prevBtn) prevBtn.disabled = true;
    if (nextBtn) nextBtn.disabled = true;
    if (rangeEl) rangeEl.textContent = 'Loading sent messages…';
    try {
      await fillPhones();
      updateDateUI();
      updateFilterStrip();

      const size = document.getElementById('wa-sent-size')?.value || 25;
      const search = document.getElementById('wa-sent-search')?.value.trim() || '';
      const status = document.getElementById('wa-sent-status')?.value || '';
      const phone = document.getElementById('wa-sent-phone');
      const params = new URLSearchParams({ page: String(page), page_size: String(size), search, status });
      if (phone?.value) {
        params.set('device', phone.value);
        params.set('device_name', phone.selectedOptions[0]?.textContent || '');
      }
      const { from, to } = getDateParams();
      if (from) params.set('from', from);
      if (to) params.set('to', to);

      const data = await api('whatsapp/sent?' + params);
      if (current !== request) return;
      const p = data.pagination || {};
      page = p.page || 1;
      pages = p.pages || 1;
      renderRows(data.messages || []);
      if (rangeEl) {
        rangeEl.textContent = p.total
          ? `${p.from}–${p.to} of ${p.total} records`
          : '0 records';
      }
      const pageLabel = document.getElementById('wa-sent-page');
      if (pageLabel) pageLabel.textContent = `Page ${page} of ${pages}`;
      if (prevBtn) prevBtn.disabled = page <= 1;
      if (nextBtn) nextBtn.disabled = page >= pages;
    } catch (error) {
      if (current !== request) return;
      if (rangeEl) rangeEl.textContent = 'Could not load sent messages.';
      renderRows([]);
    }
  }

  let phonesReady = false;

  async function fillPhones() {
    if (phonesReady) return;
    const select = document.getElementById('wa-sent-phone');
    if (!select) return;
    const current = select.value;
    try {
      const data = await api('whatsapp/device');
      select.replaceChildren();
      const all = document.createElement('option');
      all.value = '';
      all.textContent = 'All Linked Devices';
      select.append(all);
      (data.devices || []).forEach((device) => {
        const option = document.createElement('option');
        option.value = device.id || '';
        option.textContent = device.name ? `${device.name} (Active)` : 'Phone';
        select.append(option);
      });
      select.value = [...select.options].some((option) => option.value === current) ? current : '';
      phonesReady = true;
    } catch (e) {}
  }

  function reload(resetPage) {
    if (resetPage) page = 1;
    loadWaSent().catch(() => {});
  }

  function toggleDatePopover(e) {
    if (e) e.stopPropagation();
    const pop = document.getElementById('wa-sent-date-popover');
    if (!pop) return;
    pop.hidden = !pop.hidden;
    const btn = document.getElementById('wa-sent-date-btn');
    if (btn) btn.setAttribute('aria-expanded', !pop.hidden);

    if (!pop.hidden) {
      // Prefill custom date inputs if blank
      const fromInput = document.getElementById('wa-sent-date-from');
      const toInput = document.getElementById('wa-sent-date-to');
      if (fromInput && !fromInput.value) {
        const d = new Date();
        d.setDate(d.getDate() - 1);
        fromInput.value = formatDateIso(d);
      }
      if (toInput && !toInput.value) {
        toInput.value = formatDateIso(new Date());
      }
    }
  }

  function closeDatePopover() {
    const pop = document.getElementById('wa-sent-date-popover');
    if (pop) pop.hidden = true;
    const btn = document.getElementById('wa-sent-date-btn');
    if (btn) btn.setAttribute('aria-expanded', 'false');
  }

  function selectPreset(preset) {
    activePreset = preset;
    customFrom = '';
    customTo = '';
    closeDatePopover();
    updateDateUI();
    updateFilterStrip();
    reload(true);
  }

  function applyCustomRange() {
    const fromInput = document.getElementById('wa-sent-date-from');
    const toInput = document.getElementById('wa-sent-date-to');
    customFrom = fromInput?.value || '';
    customTo = toInput?.value || '';
    activePreset = (customFrom || customTo) ? 'custom' : 'all';
    closeDatePopover();
    updateDateUI();
    updateFilterStrip();
    reload(true);
  }

  function resetAllFilters() {
    activePreset = 'all';
    customFrom = '';
    customTo = '';
    const fromInput = document.getElementById('wa-sent-date-from');
    const toInput = document.getElementById('wa-sent-date-to');
    if (fromInput) fromInput.value = '';
    if (toInput) toInput.value = '';
    const search = document.getElementById('wa-sent-search');
    if (search) search.value = '';
    const phone = document.getElementById('wa-sent-phone');
    if (phone) phone.value = '';
    const status = document.getElementById('wa-sent-status');
    if (status) status.value = '';
    closeDatePopover();
    updateDateUI();
    updateFilterStrip();
    reload(true);
  }

  window.loadWaSent = loadWaSent;

  // Date Popover handlers
  document.getElementById('wa-sent-date-btn')?.addEventListener('click', toggleDatePopover);
  document.getElementById('wa-sent-date-popover')?.addEventListener('click', (e) => e.stopPropagation());
  document.addEventListener('click', () => closeDatePopover());

  // Preset buttons
  document.querySelectorAll('.wa-sent-preset-pill').forEach((btn) => {
    btn.addEventListener('click', () => selectPreset(btn.dataset.preset));
  });
  document.getElementById('wa-sent-preset-all')?.addEventListener('click', () => selectPreset('all'));

  // Custom range buttons
  document.getElementById('wa-sent-custom-apply')?.addEventListener('click', applyCustomRange);
  document.getElementById('wa-sent-custom-reset')?.addEventListener('click', () => {
    const fromInput = document.getElementById('wa-sent-date-from');
    const toInput = document.getElementById('wa-sent-date-to');
    if (fromInput) fromInput.value = '';
    if (toInput) toInput.value = '';
    selectPreset('all');
  });

  // Active filter chip dismiss handlers
  document.getElementById('wa-sent-chip-date-close')?.addEventListener('click', () => selectPreset('all'));
  document.getElementById('wa-sent-chip-device-close')?.addEventListener('click', () => {
    const phone = document.getElementById('wa-sent-phone');
    if (phone) phone.value = '';
    updateFilterStrip();
    reload(true);
  });
  document.getElementById('wa-sent-chip-status-close')?.addEventListener('click', () => {
    const status = document.getElementById('wa-sent-status');
    if (status) status.value = '';
    updateFilterStrip();
    reload(true);
  });
  document.getElementById('wa-sent-clear-all')?.addEventListener('click', resetAllFilters);

  // Toolbar events
  document.getElementById('wa-sent-refresh')?.addEventListener('click', () => reload(false));
  document.getElementById('wa-sent-phone')?.addEventListener('change', () => {
    updateFilterStrip();
    reload(true);
  });
  document.getElementById('wa-sent-status')?.addEventListener('change', () => {
    updateFilterStrip();
    reload(true);
  });
  document.getElementById('wa-sent-size')?.addEventListener('change', () => {
    try { localStorage.setItem('rsl_wa_sent_size', document.getElementById('wa-sent-size').value); } catch (e) {}
    reload(true);
  });
  document.getElementById('wa-sent-search')?.addEventListener('input', () => {
    updateFilterStrip();
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => reload(true), 250);
  });
  document.getElementById('wa-sent-prev')?.addEventListener('click', () => {
    page -= 1;
    reload(false);
  });
  document.getElementById('wa-sent-next')?.addEventListener('click', () => {
    page += 1;
    reload(false);
  });

  const savedSize = localStorage.getItem('rsl_wa_sent_size');
  if (savedSize && document.getElementById('wa-sent-size')) {
    document.getElementById('wa-sent-size').value = savedSize;
  }
  if (!document.getElementById('wa-sent')?.hidden && document.body.dataset.channel === 'whatsapp') {
    loadWaSent().catch(() => {});
  }
})();
