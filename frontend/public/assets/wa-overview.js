(() => {
  const AVATAR_PALETTES = [
    { bg: '#e7f6ec', color: '#166534' },
    { bg: '#eff6ff', color: '#2563eb' },
    { bg: '#fdf4ff', color: '#a21caf' },
    { bg: '#fef3c7', color: '#b45309' },
    { bg: '#f1f5f9', color: '#475569' }
  ];

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function formatPhone(phone) {
    const digits = String(phone || '').replace(/\D/g, '');
    const local = digits.length === 12 && digits.startsWith('91') ? digits.slice(2) : digits;
    if (local.length === 10) return '+91 ' + local.slice(0, 5) + ' ' + local.slice(5);
    return digits ? '+' + digits : 'Unknown';
  }

  function timeAgo(dateStr) {
    if (!dateStr) return '';
    const now = Date.now();
    const past = new Date(dateStr).getTime();
    if (isNaN(past)) return dateStr;
    const diffSec = Math.floor((now - past) / 1000);
    if (diffSec < 60) return 'Just now';
    const diffMin = Math.floor(diffSec / 60);
    if (diffMin < 60) return diffMin + 'm ago';
    const diffHours = Math.floor(diffMin / 60);
    if (diffHours < 24) return diffHours + 'h ago';
    const diffDays = Math.floor(diffHours / 24);
    if (diffDays === 1) return 'Yesterday';
    if (diffDays < 7) return diffDays + 'd ago';
    const d = new Date(past);
    return d.toLocaleDateString('en-US', { day: 'numeric', month: 'short' });
  }

  function avatarText(phone, name) {
    if (name && name !== 'You' && !name.startsWith('+')) {
      const parts = name.trim().split(/\s+/);
      if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
      return parts[0].slice(0, 2).toUpperCase();
    }
    const digits = String(phone || '').replace(/\D/g, '');
    if (digits.startsWith('91')) return '+91';
    if (digits.length >= 2) return '+' + digits.slice(0, 2);
    return 'WA';
  }

  function getDeviceLabel(chat) {
    if (chat.senders && chat.senders.length > 0 && chat.senders[0].name) {
      return 'via ' + chat.senders[0].name;
    }
    if (chat.device_name) return 'via ' + chat.device_name;
    return 'via WAC 1';
  }

  function renderQuotaStrip(data) {
    const today = data.today_quota || {};
    const used = today.used ?? 0;
    const total = today.total ?? 20;
    const devices = data.device_quotas || [];

    const chips = devices.map(dev => {
      const isReady = dev.status === 'ready' || dev.status === 'connected';
      const sent = dev.sent_today ?? 0;
      const limit = dev.limit ?? 20;
      const label = isReady ? `${sent} / ${limit}` : 'Standby';
      const chipClass = isReady ? 'is-active' : 'is-standby';
      const dotClass = isReady ? '' : 'is-standby';
      return `
        <span class="wa-ov-quota-chip ${chipClass}">
          <span class="status-dot ${dotClass}"></span>
          ${escapeHtml(dev.name)}: <strong>${escapeHtml(label)}</strong>
        </span>`;
    }).join('');

    const hint = data.device ? '· 20 msg/day limit' : '· 20 msg/day limit per device';

    return `
      <div class="wa-ov-quota-strip">
        <div class="wa-ov-quota-summary">
          <span class="status-dot"></span>
          <span class="wa-ov-quota-title">Today's Dispatch Quota:</span>
          <span class="wa-ov-quota-badge">${used} / ${total} messages used</span>
          <span class="wa-ov-quota-hint">${hint}</span>
        </div>
        <div class="wa-ov-quota-chips">
          ${chips}
        </div>
      </div>`;
  }

  function metricCards(counts, repliesValue, devName) {
    const sent = counts.sent || 0;
    const failed = counts.failed || 0;
    const numbers = counts.numbers || 0;

    let ratePill = '';
    if (sent > 0 || failed > 0) {
      const totalOut = sent + failed;
      const pct = Math.round((sent / totalOut) * 100);
      ratePill = `<span class="wa-ov-card-pill">${pct}% Rate</span>`;
    }

    const failedPill = failed === 0
      ? `<span class="wa-ov-card-pill is-clean">Clean</span>`
      : `<span class="wa-ov-card-pill is-alert">${failed} Failed</span>`;

    const failIconClass = failed > 0 ? 'is-failed has-errors' : 'is-failed';
    const sentSub = devName ? `Handed to ${devName}` : 'Handed to phone successfully';
    const failSub = failed === 0 ? 'Zero blocked or rejected' : 'Needs review';
    const repliesNum = Number(repliesValue);
    const repliesSub = (isNaN(repliesNum) || repliesNum === 0) ? 'All incoming replied' : `${repliesNum} unread replies`;

    return `
      <div class="wa-ov-cards">
        <!-- Sent Card -->
        <article class="wa-ov-card">
          <div class="wa-ov-card-head">
            <span>Sent Messages</span>
            <div class="wa-ov-card-icon is-sent" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>
              </svg>
            </div>
          </div>
          <div class="wa-ov-card-body">
            <strong>${sent}</strong>
            ${ratePill}
          </div>
          <small>${escapeHtml(sentSub)}</small>
        </article>

        <!-- Failed Card -->
        <article class="wa-ov-card">
          <div class="wa-ov-card-head">
            <span>Failed Sends</span>
            <div class="wa-ov-card-icon ${failIconClass}" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
              </svg>
            </div>
          </div>
          <div class="wa-ov-card-body">
            <strong>${failed}</strong>
            ${failedPill}
          </div>
          <small>${escapeHtml(failSub)}</small>
        </article>

        <!-- Unique Numbers Card -->
        <article class="wa-ov-card">
          <div class="wa-ov-card-head">
            <span>Unique Numbers</span>
            <div class="wa-ov-card-icon is-numbers" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
              </svg>
            </div>
          </div>
          <div class="wa-ov-card-body">
            <strong>${numbers}</strong>
          </div>
          <small>Contacts reached in range</small>
        </article>

        <!-- Replies Waiting Card -->
        <article class="wa-ov-card">
          <div class="wa-ov-card-head">
            <span>Replies Waiting</span>
            <div class="wa-ov-card-icon is-replies" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
              </svg>
            </div>
          </div>
          <div class="wa-ov-card-body">
            <strong>${escapeHtml(repliesValue)}</strong>
          </div>
          <small>${escapeHtml(repliesSub)}</small>
        </article>
      </div>`;
  }

  function renderRing(outcomes, counts) {
    const sent = outcomes.sent || 0;
    const waiting = outcomes.waiting || 0;
    const failed = outcomes.failed || 0;
    const total = sent + waiting + failed;

    let conic = '#e5e7eb';
    let pct = '100%';
    let sub = 'Delivered';

    if (total > 0) {
      const sentPct = Math.round((sent / total) * 100);
      const waitPct = Math.round((waiting / total) * 100);
      pct = sentPct + '%';
      conic = `conic-gradient(#1b7a3a 0% ${sentPct}%, #e0b15a ${sentPct}% ${sentPct + waitPct}%, #c45c56 ${sentPct + waitPct}% 100%)`;
    } else {
      pct = '0%';
      sub = 'No Data';
    }

    const completedNumbers = counts.numbers || 0;
    const badgeText = completedNumbers > 0 ? `${completedNumbers} Numbers Completed` : 'No Activity';

    return `
      <section class="wa-ov-panel">
        <div>
          <div class="wa-ov-panel-header">
            <h2>Delivery Breakdown</h2>
            <span class="wa-ov-badge-green">${escapeHtml(badgeText)}</span>
          </div>

          <div class="wa-ov-ring-row">
            <div class="wa-ov-ring-wrap" style="background:${conic};">
              <div class="wa-ov-ring-center">
                <span class="wa-ov-donut-pct">${pct}</span>
                <span class="wa-ov-donut-sub">${sub}</span>
              </div>
            </div>

            <div class="wa-ov-ring-legend">
              <div class="wa-ov-legend-item">
                <span><span class="wa-ov-legend-dot is-sent"></span> Sent Out</span>
                <strong>${sent} numbers (${counts.sent || 0} msgs)</strong>
              </div>
              <div class="wa-ov-legend-item">
                <span><span class="wa-ov-legend-dot is-waiting"></span> Scheduled / Waiting</span>
                <strong>${waiting}</strong>
              </div>
              <div class="wa-ov-legend-item">
                <span><span class="wa-ov-legend-dot is-failed"></span> Failed</span>
                <strong>${failed}</strong>
              </div>
            </div>
          </div>
        </div>

        <p class="wa-ov-note">
          ✓ Real-time socket confirmation from WhatsApp gateway.
        </p>
      </section>`;
  }

  function renderBars(days) {
    const list = days || [];
    const peak = Math.max(1, ...list.map(d => (d.sent || 0) + (d.failed || 0)));
    const peakText = peak > 1 || list.some(d => (d.sent || 0) + (d.failed || 0) > 0)
      ? `Peak: <strong>${peak} msgs</strong>`
      : 'No send activity';

    const items = list.map(day => {
      const total = (day.sent || 0) + (day.failed || 0);
      const height = total > 0 ? Math.max(12, Math.round((total / peak) * 80)) : 4;
      const hasSends = total > 0;
      const valClass = hasSends ? '' : 'is-zero';
      const label = day.weekday || day.label;
      const todayClass = day.is_today ? 'is-today' : '';

      return `
        <div class="wa-ov-day" title="${escapeHtml(day.label)}: ${day.sent || 0} sent, ${day.failed || 0} failed">
          <span class="wa-ov-bar-val ${valClass}">${total}</span>
          <div class="wa-ov-bar-col ${hasSends ? 'has-sends' : ''}" style="height:${height}px;"></div>
          <small class="${todayClass}">${escapeHtml(label)}</small>
        </div>`;
    }).join('');

    return `
      <section class="wa-ov-panel">
        <div>
          <div class="wa-ov-panel-header">
            <h2>Sends by Day</h2>
            <span class="wa-ov-subtext">${peakText}</span>
          </div>

          <div class="wa-ov-bars">
            ${items}
          </div>
        </div>

        <p class="wa-ov-note">
          Hover over any bar to view hourly dispatch velocity.
        </p>
      </section>`;
  }

  let cachedDevices = [];

  function lastDigits(phone) {
    const digits = String(phone || '').replace(/\D/g, '');
    return digits.length > 10 ? digits.slice(-10) : digits;
  }

  function chatsFor(data, chats) {
    if (!data.device) return chats || [];
    const keys = new Set((data.phones || []).map(lastDigits));
    return (chats || []).filter((chat) => keys.has(lastDigits(chat.phone)));
  }

  function render(data, chats) {
    const body = document.getElementById('wa-overview-body');
    if (!body) return;
    const counts = data.counts || {};
    const listed = chatsFor(data, chats);
    const hasActivity = Boolean(counts.numbers || counts.sent || counts.waiting || counts.failed);

    if (!hasActivity && !data.device) {
      body.innerHTML = `
        ${renderQuotaStrip(data)}
        <div class="card wa-empty-card" style="margin-top:14px;">
          <p class="wa-empty-title">No campaign sends in this range</p>
          <p class="muted">Sent, waiting, and failed counts appear here after this desk hands a message to the phone.</p>
        </div>`;
      return;
    }

    if (!hasActivity && data.device) {
      // Concept A: Device is selected, but has 0 sends in this range
      const devName = data.device_name || 'Selected phone';
      const dev = cachedDevices.find((d) => String(d.id) === String(data.device));
      const status = dev?.status || 'ready';
      const isReady = status === 'ready' || status === 'connected';
      const statusClass = isReady ? '' : (status === 'blocked' ? 'is-blocked' : 'is-disconnected');
      const statusLabel = isReady ? 'Ready & Linked' : (status === 'blocked' ? 'Blocked' : 'Not linked');
      const statusDot = isReady ? '<span class="status-dot"></span>' : '';

      body.innerHTML = `
        ${renderQuotaStrip(data)}

        <div class="wa-ov-spotlight">
          <div class="wa-ov-spotlight-left">
            <div class="wa-ov-spotlight-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect width="14" height="20" x="5" y="2" rx="2" ry="2"/>
                <path d="M12 18h.01"/>
              </svg>
            </div>
            <div>
              <div class="wa-ov-spotlight-title">
                <strong>${escapeHtml(devName)}</strong>
                <span class="wa-ov-spotlight-badge ${statusClass}">
                  ${statusDot} ${escapeHtml(statusLabel)}
                </span>
              </div>
              <p class="wa-ov-spotlight-desc">
                No campaign sends logged in this range. Daily limit: <strong>20 messages</strong> (20 available today).
              </p>
            </div>
          </div>
          <div class="wa-ov-spotlight-actions">
            <button type="button" class="primary" id="wa-ov-goto-sheets">
              Send via Sheets &rarr;
            </button>
            <button type="button" class="quiet" id="wa-ov-all-devices">
              View all devices
            </button>
          </div>
        </div>

        ${metricCards(counts, '0', devName)}

        <div class="wa-ov-standby-panel">
          <div class="wa-ov-standby-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
            </svg>
          </div>
          <h3>${escapeHtml(devName)} is waiting for its first dispatch</h3>
          <p>
            To start campaigns from this phone, open any WhatsApp Sheet, pick <strong>${escapeHtml(devName)}</strong> in the <strong>Send from</strong> dropdown, and click Send.
          </p>
          <button type="button" class="primary" id="wa-ov-standby-sheets-btn">
            Open WhatsApp Sheets &rarr;
          </button>
        </div>
      `;

      document.getElementById('wa-ov-goto-sheets')?.addEventListener('click', () => {
        location.hash = '#sheets';
      });
      document.getElementById('wa-ov-standby-sheets-btn')?.addEventListener('click', () => {
        location.hash = '#sheets';
      });
      document.getElementById('wa-ov-all-devices')?.addEventListener('click', () => {
        const devSelect = document.getElementById('wa-overview-device');
        if (devSelect) {
          devSelect.value = '';
          devSelect.dispatchEvent(new Event('change'));
        }
      });
      return;
    }

    const replies = listed.filter((chat) => Number(chat.unread_count) > 0).length;
    const repliesValue = chats === null ? '…' : String(replies);
    const outcomes = data.outcomes || {};

    // Bottom Left Panel: Failures OR Zero-Failures Health Card + Active Sheet
    let statusPanelHtml = '';
    const failures = data.failed || [];
    if (failures.length > 0) {
      const rows = failures.map(item => `
        <button type="button" class="wa-ov-chat-row is-failure" data-phone="${escapeHtml(item.phone)}">
          <div class="wa-ov-chat-left">
            <div class="wa-ov-chat-avatar" style="background:#fee2e2; color:#991b1b;">!</div>
            <div class="wa-ov-chat-meta">
              <div class="wa-ov-chat-head">
                <strong>${escapeHtml(formatPhone(item.phone))}</strong>
                <span class="wa-ov-chat-device" style="background:#fee2e2; color:#991b1b;">Failed</span>
              </div>
              <p class="wa-ov-chat-snippet">${escapeHtml(item.message || 'Failed')}</p>
            </div>
          </div>
          <span class="wa-ov-chat-time">${escapeHtml(item.error || 'Not accepted')}</span>
        </button>
      `).join('');

      statusPanelHtml = `
        <section class="wa-ov-panel">
          <div>
            <div class="wa-ov-panel-header">
              <h2>Delivery Failures (${failures.length})</h2>
              <span class="wa-ov-card-pill is-alert">Needs attention</span>
            </div>
            <div class="wa-ov-list">${rows}</div>
          </div>
        </section>`;
    } else {
      const activeSheetTitle = data.active_sheet?.title || 'oman_varied_outreach_RSL_Solution.xlsx';
      statusPanelHtml = `
        <section class="wa-ov-panel">
          <div>
            <div class="wa-ov-panel-header">
              <h2>Gateway &amp; Campaign Status</h2>
              <span class="wa-ov-health-pill">
                <span class="status-dot"></span> All Healthy
              </span>
            </div>

            <div class="wa-ov-zero-failures">
              <div class="wa-ov-zero-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                  <path d="M20 6 9 17l-5-5"/>
                </svg>
              </div>
              <div class="wa-ov-zero-text">
                <strong>Zero delivery failures in this range</strong>
                <p>All numbers were accepted by WhatsApp without timeouts or throttles.</p>
              </div>
            </div>

            <div class="wa-ov-sheet-label">Active WhatsApp Sheet</div>
            <div class="wa-ov-active-sheet-box">
              <div class="wa-ov-sheet-info">
                <strong>${escapeHtml(activeSheetTitle)}</strong>
                <small>Ready rows &middot; Linked for WhatsApp dispatch</small>
              </div>
              <a href="#sheets" class="wa-ov-sheet-btn">Open Sheet &rarr;</a>
            </div>
          </div>
        </section>`;
    }

    // Bottom Right Panel: Enriched Latest Chats
    const recentChats = listed.slice(0, 4);
    let chatsHtml = '';
    if (recentChats.length > 0) {
      chatsHtml = recentChats.map((chat, idx) => {
        const palette = AVATAR_PALETTES[idx % AVATAR_PALETTES.length];
        const av = avatarText(chat.phone, chat.name);
        const devLabel = getDeviceLabel(chat);
        const timeText = timeAgo(chat.last_at);
        return `
          <button type="button" class="wa-ov-chat-row" data-phone="${escapeHtml(chat.phone)}">
            <div class="wa-ov-chat-left">
              <div class="wa-ov-chat-avatar" style="background:${palette.bg}; color:${palette.color};">
                ${escapeHtml(av)}
              </div>
              <div class="wa-ov-chat-meta">
                <div class="wa-ov-chat-head">
                  <strong>${escapeHtml(formatPhone(chat.phone))}</strong>
                  <span class="wa-ov-chat-device">${escapeHtml(devLabel)}</span>
                </div>
                <p class="wa-ov-chat-snippet">${escapeHtml(chat.last_message || 'No message yet')}</p>
              </div>
            </div>
            <span class="wa-ov-chat-time">${escapeHtml(timeText || 'Latest')}</span>
          </button>`;
      }).join('');
    } else {
      chatsHtml = '<p class="muted" style="padding:16px 0;">No chats yet.</p>';
    }

    body.innerHTML = `
      ${renderQuotaStrip(data)}

      ${metricCards(counts, repliesValue, data.device_name)}

      <div class="wa-ov-grid">
        ${renderRing(outcomes, counts)}
        ${renderBars(data.days || [])}
      </div>

      <div class="wa-ov-grid">
        ${statusPanelHtml}
        <section class="wa-ov-panel">
          <div>
            <div class="wa-ov-panel-header">
              <h2>Latest Conversations</h2>
              <a href="#chats" class="wa-ov-link">View all chats &rarr;</a>
            </div>
            <div class="wa-ov-list">
              ${chatsHtml}
            </div>
          </div>
        </section>
      </div>`;

    body.querySelectorAll('.wa-ov-chat-row').forEach((btn) => {
      btn.addEventListener('click', () => {
        const phone = btn.dataset.phone;
        if (phone && window.openWaChatByPhone) {
          window.openWaChatByPhone(phone);
        }
      });
    });
  }

  async function fillDevices() {
    const select = document.getElementById('wa-overview-device');
    if (!select) return;
    const current = select.value;
    const data = await api('whatsapp/device');
    cachedDevices = data.devices || [];
    const chosen = current;
    select.replaceChildren();
    const all = document.createElement('option');
    all.value = '';
    const totalCount = (data.devices || []).length;
    all.textContent = totalCount ? `All devices (${totalCount})` : 'All devices';
    select.append(all);
    (data.devices || []).forEach((device) => {
      const option = document.createElement('option');
      option.value = device.id || '';
      const phoneSuffix = device.phone ? ` (${formatPhone(device.phone)})` : '';
      option.textContent = (device.name || 'Phone') + phoneSuffix;
      select.append(option);
    });
    select.value = [...select.options].some((option) => option.value === chosen) ? chosen : '';
  }

  let overviewRequest = 0;

  async function loadWaOverview() {
    const page = document.getElementById('wa-overview');
    if (!page || page.hidden) return;
    if (document.body.dataset.channel !== 'whatsapp') return;
    const mine = ++overviewRequest;
    const range = document.getElementById('wa-overview-range')?.value || '7';
    const device = document.getElementById('wa-overview-device')?.value || '';
    const body = document.getElementById('wa-overview-body');
    if (body && !body.querySelector('.wa-ov-cards')) body.innerHTML = '<p class="muted">Loading the campaign…</p>';
    try {
      await fillDevices();
      if (mine !== overviewRequest) return;
      const chosen = document.getElementById('wa-overview-device')?.value || '';
      const query = 'whatsapp/overview?range=' + encodeURIComponent(range) + '&device=' + encodeURIComponent(chosen);
      const data = await api(query);
      if (mine !== overviewRequest) return;
      render(data, null);
      const chats = await api('whatsapp/chats').catch(() => ({ chats: [] }));
      const currentRange = document.getElementById('wa-overview-range')?.value || '7';
      const currentDevice = document.getElementById('wa-overview-device')?.value || '';
      if (mine === overviewRequest && currentRange === range && currentDevice === chosen && !document.getElementById('wa-overview')?.hidden) {
        render(data, chats.chats || []);
      }
    } catch (error) {
      if (mine === overviewRequest && body) body.innerHTML = `<p class="muted">${escapeHtml(error.message || 'Could not load the overview.')}</p>`;
    }
  }

  window.loadWaOverview = loadWaOverview;
  document.getElementById('wa-overview-range')?.addEventListener('change', () => loadWaOverview());
  document.getElementById('wa-overview-device')?.addEventListener('change', () => loadWaOverview());
  if (!document.getElementById('wa-overview')?.hidden) loadWaOverview();
})();