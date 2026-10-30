(() => {
  let inboxPage = 1, inboxPages = 1, inboxRequest = 0, currentDetailId = null, searchTimer = null;
  let inboxCategory = 'primary';
  const $ = selector => document.querySelector(selector);

  const AVATAR_PALETTE = [
    { bg: '#e8f0fe', text: '#1a73e8' }, // Google Blue
    { bg: '#fce8e6', text: '#c5221f' }, // Google Red
    { bg: '#fef7e0', text: '#b06000' }, // Google Amber
    { bg: '#e6f4ea', text: '#137333' }, // Google Green
    { bg: '#f3e8fd', text: '#8430ce' }, // Google Purple
    { bg: '#e0f2f1', text: '#00796b' }, // Google Teal
    { bg: '#fce4ec', text: '#c2185b' }  // Google Pink
  ];

  function getAvatarStyle(str) {
    str = String(str || 'U');
    let hash = 0;
    for (let i = 0; i < str.length; i++) hash = str.charCodeAt(i) + ((hash << 5) - hash);
    return AVATAR_PALETTE[Math.abs(hash) % AVATAR_PALETTE.length];
  }

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
  }

  function formatGmailDate(iso) {
    if (!iso) return '';
    const date = new Date(iso);
    const now = new Date();
    if (date.toDateString() === now.toDateString()) {
      return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
    if (date.getFullYear() === now.getFullYear()) {
      return date.toLocaleDateString([], { month: 'short', day: 'numeric' });
    }
    return date.toLocaleDateString([], { month: 'numeric', day: 'numeric', year: '2-digit' });
  }

  function formatDetailDate(iso) {
    if (!iso) return '';
    const date = new Date(iso);
    const formatted = date.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })
      + ', ' + date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    const diffSec = Math.floor((Date.now() - date.getTime()) / 1000);
    let rel = '';
    if (diffSec < 60) rel = 'just now';
    else if (diffSec < 3600) rel = `${Math.floor(diffSec / 60)}m ago`;
    else if (diffSec < 86400) rel = `${Math.floor(diffSec / 3600)}h ago`;
    else if (diffSec < 172800) rel = 'yesterday';
    else rel = `${Math.floor(diffSec / 86400)}d ago`;
    return `${formatted} (${rel})`;
  }

  function formatQuotedHistory(text) {
    const lines = String(text || '').replace(/\r\n/g, '\n').split('\n').map(line => line.replace(/^(?:\s*>)+ ?/, '').trimEnd());
    while (lines.length && lines[lines.length - 1].trim() === '') lines.pop();
    while (lines.length && lines[0].trim() === '') lines.shift();
    if (!lines.length) return '';
    let headerEnd = -1;
    for (let i = 0; i < lines.length; i++) {
      if (/wrote:\s*$/i.test(lines[i].trim())) { headerEnd = i; break; }
    }
    const header = (headerEnd === -1 ? [] : lines.slice(0, headerEnd + 1)).map(line => line.trim()).filter(Boolean).join(' ');
    const body = headerEnd === -1 ? lines : lines.slice(headerEnd + 1);
    const paragraphs = [];
    let chunk = [];
    const flush = () => {
      if (!chunk.length) return;
      paragraphs.push(chunk.join(' ').replace(/\s+/g, ' ').trim());
      chunk = [];
    };
    for (const line of body) {
      if (line.trim() === '') flush();
      else chunk.push(line.trim());
    }
    flush();
    return [header, ...paragraphs].filter(Boolean).join('\n\n');
  }

  function linkifyText(text) {
    const div = document.createElement('div');
    div.textContent = text;
    let safe = div.innerHTML;
    return safe.replace(/(https?:\/\/[^\s<]+)/g, (url) => {
      const clean = url.replace(/[.,;:)\]]+$/, '');
      const trail = url.substring(clean.length);
      const label = clean.length > 55 ? clean.substring(0, 48) + '…' : clean;
      return `<a href="${clean}" target="_blank" rel="noopener noreferrer" class="email-link">${label}</a>${trail}`;
    });
  }

  function visibleMessageHtml(html) {
    const doc = new DOMParser().parseFromString(String(html || ''), 'text/html');
    doc.querySelectorAll('.gmail_quote, .gmail_quote_container, .gmail_attr').forEach(node => node.remove());
    while (doc.body.lastChild && doc.body.lastChild.nodeName === 'BR') doc.body.lastChild.remove();
    const text = (doc.body.textContent || '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
    return { text, html: doc.body.innerHTML.trim() };
  }

  function messageFrameDocument(html) {
    return `<!DOCTYPE html><html><head><meta charset="utf-8"><base target="_blank"><style>html,body{margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#202124;line-height:1.5;background:transparent;word-break:break-word}img{max-width:100%!important;height:auto}table{max-width:100%!important}a{color:#1a73e8}</style></head><body>${html}</body></html>`;
  }

  function mountMessageFrame(iframe, html) {
    iframe.style.setProperty('border', 'none', 'important');
    const fit = () => {
      try {
        const doc = iframe.contentDocument;
        if (!doc || !doc.body) return;
        iframe.style.height = '0px';
        const h = doc.body.scrollHeight;
        if (h > 0) iframe.style.height = (h + 2) + 'px';
      } catch (e) {}
    };
    iframe.addEventListener('load', fit);
    iframe.srcdoc = messageFrameDocument(html);
    fit();
  }

  function renderThreadStackHtml(thread, counterpartEmail, originalSubject) {
    const frames = {};
    const html = `
      <div class="thread-stack" id="thread-stack-list">
        ${thread.map((msg, idx) => {
          const isLast = idx === thread.length - 1;
          const isOutgoing = msg.type === 'outgoing';
          const senderName = isOutgoing ? 'Me (RSL Desk)' : (msg.sender_name || msg.sender_email);
          const av = getAvatarStyle(senderName);
          const initial = senderName.trim().charAt(0).toUpperCase();
          const dateStr = new Date(msg.created_at).toLocaleString([], {
            month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
          });
          const snippet = (msg.snippet || msg.body || '').replace(/[\r\n\t]+/g, ' ').trim();

          const hasHtml = Boolean(msg.html_body && msg.html_body.trim());
          const mainContent = linkifyText((msg.body || '').trim());
          const quotedText = (msg.quoted_history || '').trim();
          const trimmedToggle = quotedText ? `
            <div class="thread-trimmed-wrapper">
              <button type="button" class="thread-trimmed-toggle" title="Show trimmed content">&hellip;</button>
              <div class="thread-trimmed-content" hidden>
                <div class="thread-quoted-block">${linkifyText(formatQuotedHistory(quotedText))}</div>
              </div>
            </div>
          ` : '';

          const visible = hasHtml ? visibleMessageHtml(msg.html_body) : null;
          let bodyRenderHtml = '';
          if (visible && visible.text) {
            frames[idx] = visible.html;
            bodyRenderHtml = `<div class="thread-html-wrapper"><iframe class="email-html-frame" data-html-idx="${idx}" sandbox="allow-popups allow-popups-to-escape-sandbox allow-same-origin"></iframe></div>`;
          } else {
            bodyRenderHtml = `<div class="thread-body-text">${mainContent || (quotedText ? '<em>(Quoted reply content)</em>' : '<em>(No message content)</em>')}</div>`;
          }

          return `
            <div class="thread-card ${msg.type} ${isLast ? 'is-expanded' : ''}" data-msg-idx="${idx}">
              <div class="thread-card-head">
                <div class="thread-card-meta">
                  <div class="thread-avatar" style="background:${av.bg};color:${av.text}">${initial}</div>
                  <div class="thread-sender-group">
                    <div class="thread-sender-line1">
                      <span class="thread-sender-name">${escapeHtml(senderName)}</span>
                      <span class="thread-pill-tag ${isOutgoing ? 'sent' : 'inbox'}">${isOutgoing ? 'SENT' : 'INBOX'}</span>
                    </div>
                    <div class="thread-to-line">to ${escapeHtml(msg.recipient || '')}</div>
                    <div class="thread-collapsed-snippet">${escapeHtml(snippet)}</div>
                  </div>
                </div>
                <div class="thread-head-right">
                  <span class="thread-date">${dateStr}</span>
                  <svg class="thread-chevron icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                </div>
              </div>
              <div class="thread-card-body">
                ${bodyRenderHtml}
                ${trimmedToggle}
              </div>
            </div>
          `;
        }).join('')}
      </div>
      <div class="thread-inline-reply">
        <div class="thread-inline-reply-header">
          <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5v2.5"/></svg>
          <span>Reply to ${escapeHtml(counterpartEmail)}</span>
        </div>
        <textarea id="thread-reply-textarea" placeholder="Write your reply..."></textarea>
        <div class="thread-inline-reply-footer">
          <button type="button" class="primary" id="thread-reply-send-btn" style="height:34px;padding:0 20px;font-size:12.5px;border-radius:17px">Send reply</button>
        </div>
      </div>
    `;
    renderThreadStackHtml._frames = frames;
    return html;
  }

  function attachThreadEvents(container, counterpartEmail, originalSubject, reloadCallback) {
    const frames = renderThreadStackHtml._frames || {};
    container.querySelectorAll('iframe.email-html-frame[data-html-idx]').forEach(iframe => {
      const html = frames[iframe.dataset.htmlIdx];
      if (html) mountMessageFrame(iframe, html);
    });

    container.querySelectorAll('.thread-card-head').forEach(head => {
      head.addEventListener('click', () => {
        head.closest('.thread-card').classList.toggle('is-expanded');
      });
    });

    container.querySelectorAll('.thread-trimmed-toggle').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const content = btn.nextElementSibling;
        if (content) {
          content.hidden = !content.hidden;
          btn.classList.toggle('active', !content.hidden);
        }
      });
    });

    const toggleAllBtn = container.querySelector('#thread-toggle-all-btn');
    if (toggleAllBtn) {
      toggleAllBtn.addEventListener('click', () => {
        const cards = container.querySelectorAll('.thread-card');
        const allExpanded = Array.from(cards).every(c => c.classList.contains('is-expanded'));
        cards.forEach(c => c.classList.toggle('is-expanded', !allExpanded));
        toggleAllBtn.textContent = allExpanded ? 'Expand all' : 'Collapse all';
      });
    }

    const sendBtn = container.querySelector('#thread-reply-send-btn');
    const textarea = container.querySelector('#thread-reply-textarea');
    if (sendBtn && textarea) {
      sendBtn.addEventListener('click', async () => {
        const body = textarea.value.trim();
        if (!body) {
          notice('Please type a reply message before sending.', true);
          textarea.focus();
          return;
        }
        sendBtn.disabled = true;
        sendBtn.textContent = 'Sending…';
        try {
          const sub = originalSubject || '';
          const reSubject = sub.toLowerCase().startsWith('re:') ? sub : 'Re: ' + sub;
          await api('emails', {
            method: 'POST',
            body: JSON.stringify({
              recipient: counterpartEmail,
              subject: reSubject,
              body: body,
              idempotency_key: crypto.randomUUID(),
              send_now: true
            })
          });
          notice('Reply sent successfully!');
          if (typeof reloadCallback === 'function') {
            await reloadCallback();
          }
        } catch (err) {
          notice('Failed to send reply: ' + err.message, true);
          sendBtn.disabled = false;
          sendBtn.textContent = 'Send reply';
        }
      });
    }
  }

  function updateBadge(count, globalCount) {
    const badge = $('#nav-inbox-badge');
    const headerBadge = $('#inbox-header-badge');
    const overviewCount = $('#count-inbox');
    
    // Global workspace unread badge (sidebar and overview)
    const effectiveGlobal = (globalCount !== undefined && globalCount !== null) ? globalCount : count;
    if (overviewCount) overviewCount.textContent = effectiveGlobal;
    if (badge) {
      if (effectiveGlobal > 0) {
        badge.textContent = effectiveGlobal > 99 ? '99+' : effectiveGlobal;
        badge.hidden = false;
      } else {
        badge.hidden = true;
      }
    }

    // Header badge reflects the currently active mailbox filter
    if (headerBadge) {
      if (count > 0) {
        headerBadge.textContent = count > 99 ? '99+' : count;
        headerBadge.hidden = false;
      } else {
        headerBadge.hidden = true;
      }
    }
  }

  async function loadInbox() {
    const request = ++inboxRequest;
    $('#inbox-prev').disabled = true;
    $('#inbox-next').disabled = true;
    $('#inbox-range').textContent = 'Loading inbox...';

    try {
      const search = $('#inbox-search').value.trim();
      const status = $('#inbox-filter').value;
      const size = $('#inbox-size').value;
      const accountId = $('#inbox-account-filter')?.value || '';
      const params = new URLSearchParams({ page: inboxPage, page_size: size, search, status, account_id: accountId, category: inboxCategory });
      const from = inboxRangeStart($('#inbox-date-filter')?.value || '');
      if (from) params.set('from', from);

      const data = await api('inbox?' + params);
      if (request !== inboxRequest) return;

      const p = data.pagination;
      inboxPage = p.page;
      inboxPages = p.pages;
      updateBadge(data.unread_count, data.global_unread_count);

      // Update category tab counts
      if (data.category_counts) {
        if ($('#inbox-count-primary')) $('#inbox-count-primary').textContent = data.category_counts.primary ?? 0;
        if ($('#inbox-count-bounces')) $('#inbox-count-bounces').textContent = data.category_counts.bounces ?? 0;
        if ($('#inbox-count-all')) $('#inbox-count-all').textContent = data.category_counts.all ?? 0;
      }

      const accountFilterEl = $('#inbox-account-filter');
      const accountPillEl = $('#inbox-account-pill');
      if (accountPillEl && accountFilterEl) {
        const selText = accountFilterEl.options[accountFilterEl.selectedIndex]?.textContent || 'All Mailboxes';
        const label = accountFilterEl.value ? selText.split(' (')[0] : 'All Mailboxes Active';
        accountPillEl.innerHTML = `<span class="status-indicator-dot"></span>${escapeHtml(label)}`;
      }

      const lastSyncEl = $('#inbox-last-sync');
      if (lastSyncEl && !lastSyncEl.textContent) {
        lastSyncEl.textContent = '• Connected & up to date';
      }

      renderInboxRows(data.emails);

      const rangeText = p.total ? `${p.from}–${p.to} of ${p.total} messages` : '0 messages';
      $('#inbox-range').textContent = rangeText;
      $('#inbox-page').textContent = `Page ${p.page} of ${p.pages}`;
      
      const isFirst = p.page <= 1;
      const isLast = p.page >= p.pages;
      $('#inbox-prev').disabled = isFirst;
      $('#inbox-next').disabled = isLast;
    } catch (e) {
      if (request === inboxRequest) {
        $('#inbox-range').textContent = 'Could not load messages. Click Refresh to try again.';
        notice(e.message, true);
      }
    }
  }

  function inboxRangeStart(preset) {
    if (!preset) return '';
    const start = new Date();
    start.setHours(0, 0, 0, 0);
    if (preset === '7') start.setDate(start.getDate() - 6);
    if (preset === '30') start.setDate(start.getDate() - 29);
    return start.toISOString();
  }

  function renderInboxRows(emails) {
    const tbody = $('#inbox-rows');
    const mailboxCount = [...($('#inbox-account-filter')?.options || [])].filter(option => option.value).length;
    const showMailbox = !$('#inbox-account-filter')?.value && mailboxCount > 1;
    const mailboxHead = $('#inbox-mailbox-head');
    if (mailboxHead) mailboxHead.hidden = !showMailbox;
    tbody.replaceChildren();

    for (const email of emails) {
      const tr = document.createElement('tr');
      if (Number(email.is_read) === 0) {
        tr.classList.add('inbox-row-unread');
      }

      // Sender cell with colored Google-style avatar
      const tdFrom = document.createElement('td');
      const senderDisplay = email.from_name || email.from_email || 'Unknown';
      const av = getAvatarStyle(senderDisplay);
      const initial = senderDisplay.trim().charAt(0).toUpperCase();

      const senderEmail = String(email.from_email || '').trim();
      const showAddress = senderEmail && senderEmail.toLowerCase() !== senderDisplay.trim().toLowerCase();
      tdFrom.innerHTML = `
        <div class="inbox-sender-cell">
          <div class="inbox-avatar" style="background:${av.bg};color:${av.text}">${initial}</div>
          <div class="inbox-sender-info">
            <strong class="inbox-sender-name">${escapeHtml(senderDisplay)}</strong>
            ${showAddress ? `<small class="inbox-sender-email">${escapeHtml(senderEmail)}</small>` : ''}
          </div>
        </div>
      `;

      // Mailbox attribution cell (symmetrical to Sent emails)
      const tdMailbox = document.createElement('td');
      tdMailbox.className = 'inbox-cell-mailbox';
      const mailboxName = email.mailbox_name || email.mailbox_email || email.to_email || 'Default Mailbox';
      const mailboxEmail = email.mailbox_email || email.to_email || '';
      tdMailbox.innerHTML = `
        <span class="inbox-mailbox-badge" title="${escapeHtml(mailboxEmail)}" style="margin:0;max-width:140px;display:inline-block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
          ${escapeHtml(mailboxName)}
        </span>
      `;

      // Subject & preview cell (Gmail single-line style)
      const tdSubject = document.createElement('td');
      let cleanSnip = String(email.snippet || '');
      if (cleanSnip.includes('--') || cleanSnip.includes('Content-Type:')) {
        cleanSnip = cleanSnip.replace(/--[A-Za-z0-9_=\-\.\/]+(?:--)?/g, '')
                             .replace(/Content-(?:Type|Transfer-Encoding|ID|Disposition):[^\s]+/gi, '')
                             .replace(/\s+/g, ' ')
                             .trim();
      }
      cleanSnip = cleanSnip.replace(/\s+/g, ' ').trim();
      const threadCountPill = Number(email.thread_count) > 1 ? `<span class="thread-count-badge">${email.thread_count}</span>` : '';
      tdSubject.innerHTML = `
        <div class="sent-subject-block">
          <div class="sent-subject-line">
            <span class="inbox-subject-text">${escapeHtml(email.subject || '(No subject)')}</span>
            ${threadCountPill}
          </div>
          ${cleanSnip ? `<div class="sent-snippet">${escapeHtml(cleanSnip)}</div>` : ''}
        </div>
      `;

      // Date cell
      const tdDate = document.createElement('td');
      tdDate.className = 'inbox-date-cell';
      tdDate.textContent = formatGmailDate(email.received_at);

      // Actions cell
      const tdActions = document.createElement('td');
      tdActions.className = 'inbox-actions';

      const viewBtn = document.createElement('button');
      viewBtn.type = 'button';
      viewBtn.className = 'quiet';
      viewBtn.textContent = email.is_bounce ? 'Inspect' : 'Read';
      viewBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (window.openThreadReader) {
          window.openThreadReader(email.id, 'inbox');
        } else {
          openInboxDetail(email.id);
        }
      });

      const replyBtn = document.createElement('button');
      replyBtn.type = 'button';
      replyBtn.className = 'quiet';
      replyBtn.textContent = 'Reply';
      if (email.is_bounce) {
        replyBtn.hidden = true;
      }
      replyBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        replyToEmail(email);
      });

      tdActions.append(viewBtn);
      if (!email.is_bounce) {
        tdActions.append(replyBtn);
      }
      tr.append(tdFrom, tdSubject, tdDate, tdActions);
      if (showMailbox) tr.insertBefore(tdMailbox, tdSubject);

      // Make entire row clickable like Gmail
      tr.addEventListener('click', (e) => {
        if (!e.target.closest('button, a, input')) {
          if (window.openThreadReader) {
            window.openThreadReader(email.id, 'inbox');
          } else {
            openInboxDetail(email.id);
          }
        }
      });

      tbody.append(tr);
    }

    if (!emails.length) {
      let emptyMsg = 'This mailbox has no matching messages or has not been synced yet.';
      if (inboxCategory === 'bounces') {
        emptyMsg = 'No bounced or undelivered emails found.';
      } else if (inboxCategory === 'primary') {
        emptyMsg = 'No client replies or incoming messages yet.';
      }
      const tr = document.createElement('tr');
      const td = document.createElement('td');
      td.colSpan = showMailbox ? 5 : 4;
      td.className = 'empty';
      td.innerHTML = `
        <div class="empty-icon">
          <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/></svg>
        </div>
        <h3>No emails found</h3>
        <p>${escapeHtml(emptyMsg)}</p>
        <button type="button" class="primary" id="empty-sync-btn">Sync inbox now</button>
      `;
      td.querySelector('#empty-sync-btn')?.addEventListener('click', syncInbox);
      tr.append(td);
      tbody.append(tr);
    }
  }

  async function syncInbox() {
    const syncBtn = $('#inbox-sync');
    const label = syncBtn ? syncBtn.querySelector('span') : null;
    const originalText = label ? label.textContent : 'Sync emails';
    if (syncBtn) {
      syncBtn.disabled = true;
      syncBtn.classList.add('is-syncing');
    }
    if (label) label.textContent = 'Syncing…';

    try {
      const res = await api('inbox/sync', { method: 'POST' });
      notice(`Inbox synced! Received ${res.new_count} new messages.`);
      const lastSyncEl = $('#inbox-last-sync');
      if (lastSyncEl) {
        const timeStr = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        lastSyncEl.textContent = `• Synced at ${timeStr}`;
      }
      inboxPage = 1;
      await loadInbox();
    } catch (e) {
      notice('Failed to sync inbox: ' + e.message, true);
    } finally {
      if (syncBtn) {
        syncBtn.disabled = false;
        syncBtn.classList.remove('is-syncing');
      }
      if (label) label.textContent = originalText;
    }
  }

  async function openInboxDetail(id) {
    if (window.openThreadReader) {
      return window.openThreadReader(id, 'inbox');
    }
    currentDetailId = id;
    const dialog = $('#inbox-detail');
    dialog.replaceChildren();

    const loadingMsg = document.createElement('div');
    loadingMsg.style.padding = '36px';
    loadingMsg.style.textAlign = 'center';
    loadingMsg.style.color = '#5f6368';
    loadingMsg.textContent = 'Loading message...';
    dialog.append(loadingMsg);

    if (!dialog.open) dialog.showModal();

    try {
      const email = await api(`inbox/${id}`);
      dialog.replaceChildren();

      // 1. Gmail Top Toolbar
      const toolbar = document.createElement('div');
      toolbar.className = 'gmail-detail-toolbar';
      toolbar.innerHTML = `
        <div class="gmail-toolbar-left">
          <button type="button" class="gmail-tool-btn" id="gmail-back-btn" title="Back to inbox">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            <span>Back</span>
          </button>
          <button type="button" class="gmail-tool-btn" id="gmail-unread-btn" title="Mark as unread">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.2 8.4c.5.38.8.97.8 1.6v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V10a2 2 0 0 1 .8-1.6l8-6a2 2 0 0 1 2.4 0l8 6Z"/><path d="m22 10-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 10"/></svg>
            <span>Mark unread</span>
          </button>
          <button type="button" class="gmail-tool-btn primary" id="gmail-reply-btn" title="Reply">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5v2.5"/></svg>
            <span>Reply</span>
          </button>
        </div>
        <div class="gmail-toolbar-right">
          <button type="button" class="gmail-tool-btn icon-only" id="gmail-close-btn" title="Close">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
          </button>
        </div>
      `;
      dialog.append(toolbar);

      // 2. Scrollable Message Container
      const scrollArea = document.createElement('div');
      scrollArea.className = 'gmail-detail-scroll';

      // Subject Row with "Inbox" label pill
      const subjectRow = document.createElement('div');
      subjectRow.className = 'gmail-subject-row';
      const threadPill = (email.thread && email.thread.length > 1) ? `<span class="thread-count-badge">${email.thread.length} messages</span>` : '';
      subjectRow.innerHTML = `
        <h2 class="gmail-subject-title">${escapeHtml(email.subject || '(No subject)')}</h2>
        ${threadPill}
        <span class="gmail-label-pill">Inbox</span>
      `;
      scrollArea.append(subjectRow);

      if (email.thread && email.thread.length > 1) {
        // Multi-message conversation thread!
        const threadBox = document.createElement('div');
        const counterpart = email.from_email || 'recipient@example.com';
        threadBox.innerHTML = renderThreadStackHtml(email.thread, counterpart, email.subject);
        scrollArea.append(threadBox);
        attachThreadEvents(threadBox, counterpart, email.subject, async () => {
          await openInboxDetail(id);
          await loadInbox();
        });
      } else {
        // Sender Info Card (Gmail style)
        const senderDisplay = email.from_name || email.from_email || 'Unknown';
        const av = getAvatarStyle(senderDisplay);
        const initial = senderDisplay.trim().charAt(0).toUpperCase();
        const dateFormatted = formatDetailDate(email.received_at);

        const senderCard = document.createElement('div');
        senderCard.className = 'gmail-sender-card';
        senderCard.innerHTML = `
          <div class="gmail-avatar-large" style="background:${av.bg};color:${av.text}">${initial}</div>
          <div class="gmail-sender-meta">
            <div class="gmail-sender-line1">
              <span class="gmail-sender-display">${escapeHtml(senderDisplay)}</span>
              <span class="gmail-sender-address">&lt;${escapeHtml(email.from_email)}&gt;</span>
            </div>
            <div class="gmail-sender-line2">
              <span>to</span>
              <span class="gmail-to-pill">${escapeHtml(email.to_email || 'me')}</span>
            </div>
          </div>
          <div class="gmail-sender-date">${dateFormatted}</div>
        `;
        scrollArea.append(senderCard);

        // 3. View Mode Toggle & Content
        const hasHtml = Boolean(email.html_body && email.html_body.trim());
        let cleanBody = email.body || email.snippet || '(Empty body)';
        cleanBody = cleanBody.replace(/^--[A-Za-z0-9_=\-\.\/]+(?:--)?$/gm, '').replace(/^Content-(?:Type|Transfer-Encoding|ID|Disposition):[^\n]*\n?/gmi, '').trim();

        const plainBox = document.createElement('div');
        plainBox.className = 'gmail-plain-body';
        plainBox.innerHTML = linkifyText(cleanBody);

        if (hasHtml) {
          const iframe = document.createElement('iframe');
          iframe.className = 'email-html-frame';
          iframe.sandbox = 'allow-popups allow-popups-to-escape-sandbox allow-same-origin';
          iframe.srcdoc = `<!DOCTYPE html><html><head><meta charset="utf-8"><base target="_blank"><style>html,body{overflow:hidden}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;margin:0;padding:16px 0;color:#202124;line-height:1.6}img{max-width:100%;height:auto}a{color:#1a73e8}</style></head><body>${email.html_body}</body></html>`;
          iframe.addEventListener('load', () => {
            try {
              const doc = iframe.contentDocument || iframe.contentWindow.document;
              if (doc) {
                const h = Math.max(doc.body.scrollHeight, doc.documentElement.scrollHeight);
                if (h > 0) iframe.style.height = (h + 30) + 'px';
              }
            } catch (e) {}
          });
          scrollArea.append(iframe);
        } else {
          scrollArea.append(plainBox);
        }

        // 4. Gmail Bottom Quick Reply Bar
        const quickReplyBar = document.createElement('div');
        quickReplyBar.className = 'gmail-quick-reply-bar';
        quickReplyBar.innerHTML = `
          <button type="button" class="gmail-reply-chip" id="gmail-bottom-reply-btn">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5v2.5"/></svg>
            <span>Reply</span>
          </button>
          <button type="button" class="gmail-reply-chip" id="gmail-bottom-forward-btn">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 14 5-5-5-5"/><path d="M20 9H9.5A5.5 5.5 0 0 0 4 14.5V17"/></svg>
            <span>Forward</span>
          </button>
        `;
        scrollArea.append(quickReplyBar);
      }
      dialog.append(scrollArea);

      // Wire actions
      const doReply = () => {
        dialog.close();
        replyToEmail(email);
      };
      toolbar.querySelector('#gmail-reply-btn')?.addEventListener('click', doReply);
      scrollArea.querySelector('#gmail-bottom-reply-btn')?.addEventListener('click', doReply);
      scrollArea.querySelector('#gmail-bottom-forward-btn')?.addEventListener('click', () => {
        dialog.close();
        forwardEmail(email);
      });

      const doClose = () => dialog.close();
      toolbar.querySelector('#gmail-back-btn')?.addEventListener('click', doClose);
      toolbar.querySelector('#gmail-close-btn')?.addEventListener('click', doClose);

      toolbar.querySelector('#gmail-unread-btn')?.addEventListener('click', async () => {
        try {
          await api(`inbox/${id}/toggle-read`, { method: 'POST' });
          dialog.close();
          await loadInbox();
        } catch (e) {
          notice(e.message, true);
        }
      });

      // Refresh list to update unread badge
      loadInbox();
    } catch (e) {
      dialog.replaceChildren();
      const err = document.createElement('div');
      err.style.padding = '24px';
      err.innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
          <h3 style="margin:0;color:#c5221f">Could not load email</h3>
          <button type="button" class="gmail-tool-btn" id="gmail-err-close">Close</button>
        </div>
        <p style="color:#5f6368">${escapeHtml(e.message)}</p>
      `;
      err.querySelector('#gmail-err-close')?.addEventListener('click', () => dialog.close());
      dialog.append(err);
    }
  }

  function forwardEmail(email) {
    if (typeof window.view === 'function') {
      window.view('compose');
    }
    const toInput = $('#compose-to');
    const subjectInput = $('input[name="subject"]');
    const bodyInput = $('textarea[name="body"]');
    if (toInput) {
      toInput.value = '';
      setTimeout(() => toInput.focus(), 50);
    }
    if (subjectInput) {
      const orig = email.subject || '';
      subjectInput.value = orig.toLowerCase().startsWith('fwd:') ? orig : 'Fwd: ' + orig;
    }
    if (bodyInput) {
      const sender = email.from_name ? `${email.from_name} <${email.from_email}>` : email.from_email;
      const formattedDate = email.received_at ? new Date(email.received_at).toLocaleString() : 'recently';
      const rawText = (email.body_plain || email.snippet || '').trim();

      bodyInput.value = `\n\n---------- Forwarded message ---------\nFrom: ${sender}\nDate: ${formattedDate}\nSubject: ${email.subject || '(No subject)'}\nTo: ${email.to_email || 'ray@rslsolution.com'}\n\n${rawText}`;
      setTimeout(() => {
        if (toInput) toInput.focus();
      }, 50);
    }
    notice('Forwarding email — enter recipient');
  }

  function replyToEmail(email) {
    const dialog = $('#inbox-detail');
    if (dialog && dialog.open) {
      dialog.close();
    }
    // Switch to compose view
    if (typeof window.view === 'function') {
      window.view('compose');
    }

    const titleEl = $('#compose-title');
    const submitLabel = $('#compose-submit-label');
    if (titleEl) titleEl.textContent = 'Reply to email.';
    if (submitLabel) submitLabel.textContent = 'Send reply';

    const toInput = $('#compose-to');
    const subjectInput = $('input[name="subject"]');
    const bodyInput = $('textarea[name="body"]');

    if (toInput) toInput.value = email.from_email || '';
    if (subjectInput) {
      const orig = email.subject || '';
      subjectInput.value = orig.toLowerCase().startsWith('re:') ? orig : 'Re: ' + orig;
    }
    if (bodyInput) {
      const sender = email.from_name ? `${email.from_name} <${email.from_email}>` : email.from_email;
      const formattedDate = email.received_at ? new Date(email.received_at).toLocaleString() : 'recently';
      const rawText = (email.body_plain || email.snippet || '').trim();
      const quotedBody = rawText ? rawText.split('\n').map(line => `> ${line}`).join('\n') : '';

      bodyInput.value = quotedBody ? `\n\nOn ${formattedDate}, ${sender} wrote:\n${quotedBody}` : '';
      setTimeout(() => {
        bodyInput.focus();
        bodyInput.setSelectionRange(0, 0);
        bodyInput.scrollTop = 0;
      }, 50);
    }
    notice(`Drafting reply to ${email.from_email}`);
  }

  window.replyToEmail = replyToEmail;

  // Event Listeners
  $('#inbox-sync')?.addEventListener('click', syncInbox);
  $('#inbox-refresh')?.addEventListener('click', loadInbox);
  $('#close-inbox-detail')?.addEventListener('click', () => $('#inbox-detail').close());

  $('#inbox-search')?.addEventListener('input', () => {
    clearTimeout(searchTimer);
    inboxPage = 1;
    searchTimer = setTimeout(loadInbox, 300);
  });

  $('#inbox-account-filter')?.addEventListener('change', () => {
    inboxPage = 1;
    loadInbox();
  });

  $('#inbox-filter')?.addEventListener('change', () => {
    inboxPage = 1;
    loadInbox();
  });

  $('#inbox-date-filter')?.addEventListener('change', () => {
    inboxPage = 1;
    loadInbox();
  });

  const savedInboxSize = localStorage.getItem('rsl_inbox_size');
  if (savedInboxSize && $('#inbox-size')) {
    $('#inbox-size').value = savedInboxSize;
  }

  $('#inbox-size')?.addEventListener('change', () => {
    localStorage.setItem('rsl_inbox_size', $('#inbox-size').value);
    inboxPage = 1;
    loadInbox();
  });

  $('#inbox-prev')?.addEventListener('click', () => {
    if (inboxPage > 1) {
      inboxPage--;
      loadInbox();
    }
  });

  $('#inbox-next')?.addEventListener('click', () => {
    if (inboxPage < inboxPages) {
      inboxPage++;
      loadInbox();
    }
  });

  // Category Tabs (Client Replies vs Bounced vs All)
  document.querySelectorAll('.inbox-cat-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const cat = btn.dataset.category;
      if (!cat || cat === inboxCategory) return;
      document.querySelectorAll('.inbox-cat-btn').forEach(b => {
        b.classList.remove('active');
        b.setAttribute('aria-selected', 'false');
      });
      btn.classList.add('active');
      btn.setAttribute('aria-selected', 'true');
      inboxCategory = cat;
      inboxPage = 1;
      loadInbox();
    });
  });

  function setInboxAccountOptions(accounts) {
    const filterEl = $('#inbox-account-filter');
    if (!filterEl) return;
    const currentVal = filterEl.value;
    filterEl.replaceChildren();
    const allOpt = document.createElement('option');
    allOpt.value = '';
    allOpt.textContent = 'All Mailboxes';
    filterEl.append(allOpt);
    for (const acc of (accounts || [])) {
      const opt = document.createElement('option');
      opt.value = acc.id;
      opt.textContent = acc.name ? `${acc.name} (${acc.email})` : acc.email;
      filterEl.append(opt);
    }
    filterEl.disabled = false;
    if (currentVal) {
      filterEl.value = currentVal;
    }
  }

  window.loadInbox = loadInbox;
  window.updateInboxBadge = updateBadge;
  window.renderThreadStackHtml = renderThreadStackHtml;
  window.attachThreadEvents = attachThreadEvents;
  window.setInboxAccountOptions = setInboxAccountOptions;
})();

