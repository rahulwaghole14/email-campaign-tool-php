let csrf = '', emails = [], requestKey = crypto.randomUUID();
let emailPage = 1, emailPages = 1, emailRequest = 0, detailRequest = 0, searchTimer;
const $ = selector => document.querySelector(selector);
function notice(message, error = false) { $('#notice').textContent = message; $('#notice').className = error ? 'error' : ''; $('#notice').hidden = false; }
async function api(path, options = {}) {
  const response = await fetch('/api/' + path, { ...options, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, ...options.headers } });
  const data = await response.json();
  if (!response.ok) throw new Error(data.error || 'Request failed.');
  return data;
}
function view(name) {
  if (!name) name = 'overview';
  const targetView = document.getElementById(name);
  if (!targetView) name = 'overview';

  const earlyStyle = document.getElementById('early-view-style');
  if (earlyStyle) earlyStyle.remove();

  const noticeEl = $('#notice');
  if (noticeEl) noticeEl.hidden = true;

  const activeNav = (name === 'thread-view') ? (window.currentThreadOrigin || 'inbox') : name;
  document.querySelectorAll('.view').forEach(el => el.hidden = el.id !== name);
  document.querySelectorAll('.nav').forEach(el => {
    el.classList.toggle('active', el.dataset.view === activeNav);
    if (el.dataset.view === activeNav) el.setAttribute('aria-current', 'page');
    else el.removeAttribute('aria-current');
  });
  $('.sidebar-compose-btn')?.classList.toggle('active', activeNav === 'compose');

  if (name !== 'thread-view') {
    try {
      localStorage.setItem('rsl_active_view', name);
      if (location.hash !== '#' + name) {
        history.replaceState(null, '', '#' + name);
      }
    } catch(e) {}
  }

  if (name === 'overview') load?.();
  if (name === 'sheets') window.loadSheets?.();
  if (name === 'inbox') window.loadInbox?.();
  if (name === 'sent') loadSentPage?.();
  if (name === 'compose') {
    const titleEl = $('#compose-title');
    const submitLabel = $('#compose-submit-label');
    if (!$('input[name="subject"]')?.value?.startsWith('Re:')) {
      if (titleEl) titleEl.textContent = 'Compose an email';
      if (submitLabel) submitLabel.textContent = 'Send email';
    }
  }
}
window.view = view;
function cell(text) { const td = document.createElement('td'); td.textContent = text; return td; }
function renderEmails() {
  const term = $('#search').value.toLowerCase();
  const rows = emails;
  $('#emails').replaceChildren();
  for (const email of rows) {
    const row = document.createElement('tr');
    row.className = 'queue-row-item';
    row.addEventListener('click', (e) => {
      if (e.target.closest('button, a, input')) return;
      openThreadReader(email.id, 'overview');
    });

    const recipient = email.recipient || 'Unknown';
    const av = getAvatarStyle(recipient);
    const initial = recipient.trim().charAt(0).toUpperCase();

    // Recipient cell with avatar
    const recipientTd = document.createElement('td');
    recipientTd.className = 'sent-cell-recipient';
    recipientTd.innerHTML = `
      <div class="inbox-sender-cell">
        <div class="inbox-avatar" style="background:${av.bg};color:${av.text}">${initial}</div>
        <div class="inbox-sender-info">
          <strong class="inbox-sender-name">${escapeHtml(recipient)}</strong>
          <small class="inbox-sender-email">To: ${escapeHtml(recipient)}</small>
        </div>
      </div>
    `;

    // Subject & preview snippet
    const subjectTd = document.createElement('td');
    subjectTd.className = 'sent-cell-subject';
    const snippet = (email.body || '').replace(/[\r\n\t]+/g, ' ').trim();
    const threadPill = Number(email.thread_count) > 1 ? `<span class="thread-count-badge">${email.thread_count}</span>` : '';
    subjectTd.innerHTML = `
      <div class="inbox-preview-wrap">
        <span class="inbox-subject-text">${escapeHtml(email.subject || '(No subject)')}</span>
        ${threadPill}
        ${snippet ? `<span class="inbox-snippet-separator">&middot;</span><span class="inbox-snippet-text">${escapeHtml(snippet)}</span>` : ''}
      </div>
    `;

    // Created date
    const dateTd = document.createElement('td');
    dateTd.className = 'sent-cell-date';
    dateTd.textContent = new Date(email.created_at).toLocaleString([], {
      month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
    });

    // Status badge
    const statusTd = document.createElement('td');
    statusTd.className = 'sent-cell-status';
    const badge = document.createElement('span');
    const st = email.status || 'pending';
    badge.className = `badge ${st}`;
    badge.textContent = st.charAt(0).toUpperCase() + st.slice(1).replace('_', ' ');
    statusTd.append(badge);

    // Actions
    const actionTd = document.createElement('td');
    actionTd.className = 'sent-cell-action';
    const details = document.createElement('button');
    details.type = 'button';
    details.className = 'sent-btn-details';
    details.textContent = 'View details';
    details.addEventListener('click', (e) => {
      e.stopPropagation();
      openThreadReader(email.id, 'overview');
    });
    actionTd.append(details);

    if (['failed', 'dry_run'].includes(email.status) && email.attempts < 3) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'sent-btn-details';
      button.style.marginLeft = '6px';
      button.textContent = 'Retry';
      button.addEventListener('click', async (e) => {
        e.stopPropagation();
        if (email.status === 'failed' && !confirm('Check the sent mailbox first: a failed SMTP attempt may still have delivered. Queue this message again?')) return;
        button.disabled = true;
        try {
          await api(`emails/${email.id}/retry`, {method:'POST'});
          await load();
          notice('Message queued for retry.');
        } catch(err) {
          notice(err.message, true);
          button.disabled = false;
        }
      });
      actionTd.append(button);
    }

    row.append(recipientTd, subjectTd, dateTd, statusTd, actionTd);
    $('#emails').append(row);
  }
  if (!rows.length) {
    const row = document.createElement('tr'), td = cell(''); td.colSpan = 5; td.className = 'empty';
    td.innerHTML = '<div class="empty-icon"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/></svg></div>';
    const heading = document.createElement('h3'), description = document.createElement('p'), button = document.createElement('button');
    const filtered = term || $('#email-status').value;
    heading.textContent = filtered ? 'No matching emails' : 'No emails queued';
    description.textContent = filtered ? 'Try another search or status.' : 'Compose your first email to get started.';
    button.type = 'button'; button.className = 'quiet'; button.textContent = filtered ? 'Clear filters' : 'Compose email';
    button.addEventListener('click', () => { if (filtered) { $('#search').value = ''; $('#email-status').value = ''; emailPage = 1; loadEmailPage().catch(e => notice(e.message,true)); $('#search').focus(); } else view('compose'); });
    td.append(heading, description, button); row.append(td); $('#emails').append(row);
  }
}
async function loadEmailPage() {
  const request = ++emailRequest;
  $('#email-prev').disabled = true; $('#email-next').disabled = true;
  $('#email-range').textContent = 'Loading emails...';
  try {
    const params = new URLSearchParams({page:emailPage,page_size:$('#email-size').value,search:$('#search').value,status:$('#email-status').value});
    const data = await api('emails?' + params);
    if (request !== emailRequest) return;
    emails = data.emails; const p = data.pagination; emailPage = p.page; emailPages = p.pages;
    renderEmails();
    $('#email-range').textContent = `${p.from}–${p.to} of ${p.total} emails`;
    $('#email-page').textContent = `Page ${p.page} of ${p.pages}`;
    $('#email-prev').disabled = p.page <= 1; $('#email-next').disabled = p.page >= p.pages;
  } catch(e) { if (request === emailRequest) { $('#email-range').textContent = 'Could not load emails. Use Refresh to try again.'; throw e; } }
}
const AVATAR_PALETTE = [
  { bg: '#e8f0fe', text: '#1a73e8' }, // Google Blue
  { bg: '#fce8e6', text: '#c5221f' }, // Google Red
  { bg: '#fef7e0', text: '#b06000' }, // Google Amber
  { bg: '#e6f4ea', text: '#137333' }, // Google Green
  { bg: '#f3e8fd', text: '#7627bb' }, // Google Purple
  { bg: '#e0f2f1', text: '#00695c' }, // Google Teal
  { bg: '#fce4ec', text: '#c2185b' }  // Google Pink
];

function getAvatarStyle(str) {
  str = String(str || 'U');
  let hash = 0;
  for (let i = 0; i < str.length; i++) hash = str.charCodeAt(i) + ((hash << 5) - hash);
  return AVATAR_PALETTE[Math.abs(hash) % AVATAR_PALETTE.length];
}

window.currentThreadOrigin = 'inbox';
window.currentThreadId = null;

function formatEmailBodyText(text) {
  if (!text) return '';
  const escaped = escapeHtml(text);
  return escaped.replace(/(https?:\/\/[^\s<]+)/g, (url) => {
    const clean = url.replace(/[.,;:)\]]+$/, '');
    const trail = url.substring(clean.length);
    const label = clean.length > 55 ? clean.substring(0, 48) + '…' : clean;
    return `<a href="${clean}" target="_blank" rel="noopener noreferrer" style="color:#1a73e8;text-decoration:underline">${label}</a>${trail}`;
  });
}

function createExpandedMessageElement(msg, senderName, av, initial, dateFormatted, mainBody, quotedText, isCollapsible = false) {
  const el = document.createElement('div');
  el.className = 'gmail-msg-expanded' + (isCollapsible ? ' is-collapsible' : '');
  
  const hasHtml = Boolean(msg.html_body && msg.html_body.trim());
  const hasPlain = Boolean(mainBody && mainBody.trim());

  const trimmedHtml = quotedText ? `
    <div class="thread-trimmed-wrapper">
      <button type="button" class="thread-trimmed-toggle" title="Show trimmed content">&hellip;</button>
      <div class="thread-trimmed-content" hidden style="display:none">
        <div class="thread-quoted-block">${formatEmailBodyText(quotedText)}</div>
      </div>
    </div>
  ` : '';

  const formatToggleBtn = (hasHtml && hasPlain) ? `
    <button type="button" class="thread-format-toggle-btn" title="Toggle Plain Text / HTML">View plain text</button>
  ` : '';

  el.innerHTML = `
    <div class="gmail-msg-expanded-header">
      <div class="gmail-msg-expanded-header-left">
        <div class="gmail-msg-expanded-avatar" style="background:${av.bg};color:${av.text}">${initial}</div>
        <div class="gmail-msg-expanded-meta">
          <div class="gmail-msg-meta-line1">
            <span class="gmail-msg-sender-name">${escapeHtml(senderName)}</span>
            <span class="gmail-msg-sender-email">&lt;${escapeHtml(msg.sender_email)}&gt;</span>
          </div>
          <div class="gmail-msg-meta-line2">
            <span>to</span>
            <span class="gmail-msg-to-pill">${escapeHtml(msg.recipient || 'me')}</span>
          </div>
        </div>
      </div>
      <div class="gmail-msg-expanded-header-right">
        ${formatToggleBtn}
        <span>${dateFormatted}</span>
      </div>
    </div>
    <div class="gmail-msg-body">
      <div class="gmail-body-container"></div>
      ${trimmedHtml}
    </div>
  `;

  const bodyContainer = el.querySelector('.gmail-body-container');
  let showingHtml = hasHtml;

  function renderBody() {
    bodyContainer.replaceChildren();
    if (showingHtml && hasHtml) {
      const iframe = document.createElement('iframe');
      iframe.className = 'email-html-frame';
      iframe.sandbox = 'allow-popups allow-popups-to-escape-sandbox allow-same-origin';
      iframe.srcdoc = `<!DOCTYPE html><html><head><meta charset="utf-8"><base target="_blank"><style>
        html,body{margin:0;padding:4px 0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#202124;line-height:1.5;background:transparent;word-break:break-word}
        img{max-width:100%!important;height:auto}
        table{max-width:100%!important}
        a{color:#1a73e8}
      </style></head><body>${msg.html_body}</body></html>`;

      function resize() {
        try {
          const doc = iframe.contentDocument || iframe.contentWindow.document;
          if (doc && doc.body) {
            doc.body.style.margin = '0';
            const h = Math.max(doc.body.scrollHeight, doc.documentElement.scrollHeight, 24);
            iframe.style.height = (h + 12) + 'px';
          }
        } catch (e) {}
      }

      iframe.addEventListener('load', () => {
        resize();
        setTimeout(resize, 200);
        setTimeout(resize, 600);
      });

      bodyContainer.append(iframe);
      el._iframeResize = resize;
    } else if (hasPlain) {
      const plainBox = document.createElement('div');
      plainBox.className = 'gmail-body-text';
      plainBox.innerHTML = formatEmailBodyText(mainBody);
      bodyContainer.append(plainBox);
    } else {
      const emptyBox = document.createElement('div');
      emptyBox.className = 'gmail-body-text';
      emptyBox.style.color = '#5f6368';
      emptyBox.style.fontStyle = 'italic';
      emptyBox.textContent = quotedText ? '(Quoted reply content)' : '(No message content)';
      bodyContainer.append(emptyBox);
    }
  }

  renderBody();

  const toggleBtn = el.querySelector('.thread-format-toggle-btn');
  if (toggleBtn) {
    toggleBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      showingHtml = !showingHtml;
      toggleBtn.textContent = showingHtml ? 'View plain text' : 'View formatted HTML';
      renderBody();
    });
  }

  el.querySelector('.thread-trimmed-toggle')?.addEventListener('click', (e) => {
    e.stopPropagation();
    const content = el.querySelector('.thread-trimmed-content');
    if (content) {
      const isHidden = content.style.display === 'none' || content.hidden;
      content.hidden = !isHidden;
      content.style.display = isHidden ? 'block' : 'none';
      e.target.classList.toggle('active', isHidden);
    }
  });

  return el;
}

function renderCanvasMessages(thread, container, counterpart, originalSubject) {
  container.replaceChildren();

  thread.forEach((msg, idx) => {
    const isLatest = idx === thread.length - 1;
    const isOutgoing = msg.type === 'outgoing';
    const senderName = isOutgoing ? 'Me (RSL Desk)' : (msg.sender_name || msg.sender_email);
    const av = getAvatarStyle(senderName);
    const initial = senderName.trim().charAt(0).toUpperCase();
    const dateFormatted = new Date(msg.created_at).toLocaleDateString([], {
      weekday: 'short', month: 'short', day: 'numeric', year: 'numeric'
    }) + ', ' + new Date(msg.created_at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });

    const snippet = (msg.snippet || msg.body || '').replace(/[\r\n\t]+/g, ' ').trim();
    const mainBody = (msg.body || '').trim();
    const quotedText = (msg.quoted_history || '').trim();

    const msgWrapper = document.createElement('div');
    msgWrapper.className = 'gmail-thread-msg-item';

    if (!isLatest) {
      const collapsedEl = document.createElement('div');
      collapsedEl.className = 'gmail-msg-collapsed';
      collapsedEl.innerHTML = `
        <div class="gmail-msg-collapsed-avatar" style="background:${av.bg};color:${av.text}">${initial}</div>
        <div class="gmail-msg-collapsed-sender">${escapeHtml(senderName)}</div>
        <div class="gmail-msg-collapsed-snippet">${escapeHtml(snippet)}</div>
        <div class="gmail-msg-collapsed-date">${dateFormatted}</div>
      `;

      const expandedEl = createExpandedMessageElement(msg, senderName, av, initial, dateFormatted, mainBody, quotedText, true);
      expandedEl.style.display = 'none';
      expandedEl.hidden = true;

      collapsedEl.addEventListener('click', () => {
        collapsedEl.style.display = 'none';
        collapsedEl.hidden = true;
        expandedEl.style.display = 'block';
        expandedEl.hidden = false;
        if (expandedEl._iframeResize) expandedEl._iframeResize();
      });

      // Clicking expanded header folds it back to single-line row (like Gmail)
      expandedEl.querySelector('.gmail-msg-expanded-header')?.addEventListener('click', (e) => {
        if (e.target.closest('button, a, input, .thread-trimmed-toggle, .thread-format-toggle-btn')) return;
        expandedEl.style.display = 'none';
        expandedEl.hidden = true;
        collapsedEl.style.display = 'flex';
        collapsedEl.hidden = false;
      });

      msgWrapper.append(collapsedEl, expandedEl);
    } else {
      const expandedEl = createExpandedMessageElement(msg, senderName, av, initial, dateFormatted, mainBody, quotedText, false);
      msgWrapper.append(expandedEl);
    }

    container.append(msgWrapper);
  });
}

async function openThreadReader(id, origin = 'inbox') {
  window.currentThreadOrigin = origin;
  window.currentThreadId = id;
  view('thread-view');

  const stream = $('#thread-canvas-stream');
  const subjectEl = $('#thread-canvas-subject');
  const badgeEl = $('#thread-canvas-badge');
  const composer = $('#thread-canvas-composer');
  const bottomActions = $('#thread-canvas-bottom-actions');

  if (composer) composer.hidden = true;
  if (bottomActions) bottomActions.hidden = false;
  if (stream) stream.innerHTML = '<div style="padding:48px 0;text-align:center;color:#5f6368">Loading conversation…</div>';

  if (badgeEl) {
    badgeEl.textContent = origin === 'sent' ? 'Sent' : (origin === 'overview' ? 'Queue' : 'Inbox');
  }

  try {
    const endpoint = origin === 'inbox' ? `inbox/${id}` : `emails/${id}`;
    const res = await api(endpoint);
    const email = res.email || res;
    const thread = res.thread && res.thread.length ? res.thread : [
      {
        id: email.id,
        type: origin === 'inbox' ? 'incoming' : 'outgoing',
        sender_name: origin === 'inbox' ? (email.from_name || email.from_email) : 'Me (RSL Desk)',
        sender_email: origin === 'inbox' ? email.from_email : (email.sender_email || 'ray@rslsolution.com'),
        recipient: origin === 'inbox' ? (email.to_email || 'ray@rslsolution.com') : email.recipient,
        subject: email.subject,
        body: email.body,
        html_body: email.html_body,
        snippet: email.snippet,
        created_at: email.received_at || email.sent_at || email.created_at
      }
    ];

    const subject = email.subject || '(No subject)';
    if (subjectEl) subjectEl.textContent = subject;

    let counterpart = '';
    if (origin === 'inbox') counterpart = email.from_email;
    else counterpart = email.recipient;

    for (let i = thread.length - 1; i >= 0; i--) {
      if (thread[i].type === 'incoming' && thread[i].sender_email) {
        counterpart = thread[i].sender_email;
        break;
      }
    }
    const recipEl = $('#thread-composer-recipient');
    if (recipEl) recipEl.textContent = counterpart;

    renderCanvasMessages(thread, stream, counterpart, subject);

  } catch (err) {
    if (stream) {
      stream.innerHTML = `<div style="padding:48px 0;text-align:center;color:#c5221f">Could not load conversation: ${escapeHtml(err.message)}</div>`;
    }
  }
}
window.openThreadReader = openThreadReader;

// Reader Controls Wiring
$('#thread-back-btn')?.addEventListener('click', () => {
  view(window.currentThreadOrigin || 'inbox');
});

$('#thread-print-btn')?.addEventListener('click', () => {
  window.print();
});

$('#thread-canvas-reply-btn')?.addEventListener('click', () => {
  const composer = $('#thread-canvas-composer');
  const bottomActions = $('#thread-canvas-bottom-actions');
  if (composer) composer.hidden = false;
  if (bottomActions) bottomActions.hidden = true;
  $('#thread-composer-textarea')?.focus();
});

$('#thread-top-reply-btn')?.addEventListener('click', () => {
  const composer = $('#thread-canvas-composer');
  const bottomActions = $('#thread-canvas-bottom-actions');
  if (composer) composer.hidden = false;
  if (bottomActions) bottomActions.hidden = true;
  $('#thread-composer-textarea')?.focus();
});

$('#thread-canvas-forward-btn')?.addEventListener('click', () => {
  view('compose');
  const subjectEl = $('#thread-canvas-subject');
  const sub = subjectEl ? subjectEl.textContent : '';
  const subjInput = $('input[name="subject"]');
  if (subjInput) subjInput.value = sub.toLowerCase().startsWith('fwd:') ? sub : 'Fwd: ' + sub;
});

$('#thread-composer-discard-btn')?.addEventListener('click', () => {
  const composer = $('#thread-canvas-composer');
  const bottomActions = $('#thread-canvas-bottom-actions');
  const textarea = $('#thread-composer-textarea');
  if (textarea) textarea.value = '';
  if (composer) composer.hidden = true;
  if (bottomActions) bottomActions.hidden = false;
});

$('#thread-composer-send-btn')?.addEventListener('click', async () => {
  const textarea = $('#thread-composer-textarea');
  const sendBtn = $('#thread-composer-send-btn');
  const text = textarea ? textarea.value.trim() : '';
  if (!text) {
    notice('Please type a reply message before sending.', true);
    textarea?.focus();
    return;
  }
  const counterpart = $('#thread-composer-recipient')?.textContent || '';
  const subjectEl = $('#thread-canvas-subject');
  const origSubject = subjectEl ? subjectEl.textContent : '';
  const reSubject = origSubject.toLowerCase().startsWith('re:') ? origSubject : 'Re: ' + origSubject;

  sendBtn.disabled = true;
  const originalHtml = sendBtn.innerHTML;
  sendBtn.innerHTML = 'Sending…';

  try {
    await api('emails', {
      method: 'POST',
      body: JSON.stringify({
        recipient: counterpart,
        subject: reSubject,
        body: text,
        idempotency_key: crypto.randomUUID(),
        send_now: true
      })
    });
    notice('Reply sent successfully via Hostinger SMTP!');
    if (textarea) textarea.value = '';
    const composer = $('#thread-canvas-composer');
    const bottomActions = $('#thread-canvas-bottom-actions');
    if (composer) composer.hidden = true;
    if (bottomActions) bottomActions.hidden = false;
    await openThreadReader(window.currentThreadId, window.currentThreadOrigin);
  } catch (err) {
    notice('Failed to send reply: ' + err.message, true);
  } finally {
    sendBtn.disabled = false;
    sendBtn.innerHTML = originalHtml;
  }
});

async function openEmailDetail(id) {
  const request = ++detailRequest, dialog = $('#email-detail');
  const tagEl = $('#email-detail-tag'), pillEl = $('#email-detail-pill');
  if (tagEl) tagEl.textContent = `Email #${id}`;
  if (pillEl) { pillEl.textContent = 'Loading…'; pillEl.className = 'email-delivery-pill'; }
  $('#email-detail-content').replaceChildren();
  const msgEl = $('#email-detail-message');
  if (msgEl) { msgEl.textContent = 'Loading email…'; msgEl.hidden = false; }
  if (!dialog.open) dialog.showModal();

  try {
    const data = await api(`emails/${id}`);
    if (request !== detailRequest || !dialog.open) return;
    const email = data.email, target = $('#email-detail-content');
    if (msgEl) msgEl.hidden = true;

    if (pillEl) {
      if (email.status === 'sent') {
        pillEl.textContent = '● Sent via Hostinger';
        pillEl.className = 'email-delivery-pill sent';
      } else if (email.status === 'failed') {
        pillEl.textContent = '● Delivery Failed';
        pillEl.className = 'email-delivery-pill failed';
      } else if (email.status === 'dry_run') {
        pillEl.textContent = '● Test Dry Run';
        pillEl.className = 'email-delivery-pill dry_run';
      } else {
        pillEl.textContent = `● ${email.status.replaceAll('_', ' ')}`;
        pillEl.className = 'email-delivery-pill pending';
      }
    }

    const date = value => value ? new Date(value).toLocaleString() : 'Not recorded';
    const recipient = email.recipient || 'Unknown';
    const av = getAvatarStyle(recipient);
    const initial = recipient.trim().charAt(0).toUpperCase();

    let attachmentsHtml = '';
    if (email.attachments && email.attachments.length) {
      const atts = typeof email.attachments === 'string' ? JSON.parse(email.attachments) : email.attachments;
      if (Array.isArray(atts) && atts.length) {
        attachmentsHtml = `
          <div class="detail-section-title" style="margin-top:20px">Attachments (${atts.length})</div>
          <div class="email-attachments-list">
            ${atts.map(att => `
              <div class="attachment-chip">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <span class="attachment-name" title="${escapeHtml(att.name || 'File')}">${escapeHtml(att.name || 'File')}</span>
                <span class="attachment-size">${formatBytes(att.size || 0)}</span>
              </div>
            `).join('')}
          </div>
        `;
      }
    }

    let activityHtml = '';
    if (data.activity && data.activity.length) {
      activityHtml = `
        <div class="detail-section-title" style="margin-top:24px">Delivery Timeline</div>
        <div class="delivery-timeline">
          ${data.activity.map((item, idx) => `
            <div class="timeline-step">
              <div class="timeline-marker ${idx === 0 ? 'active' : ''}"></div>
              <div class="timeline-body">
                <div class="timeline-text">${escapeHtml(item.detail)}</div>
                <div class="timeline-date">${date(item.created_at)}</div>
              </div>
            </div>
          `).join('')}
        </div>
      `;
    }

    if (data.thread && data.thread.length > 1) {
      target.innerHTML = `
        <div class="gmail-subject-row">
          <h1 class="gmail-subject-title">${escapeHtml(email.subject || '(No subject)')}</h1>
          <span class="thread-count-badge">${data.thread.length} messages</span>
          <span class="badge ${email.status}">${email.status.toUpperCase()}</span>
        </div>
        ${renderThreadStackHtml(data.thread, recipient, email.subject)}
        ${activityHtml}
      `;
      attachThreadEvents(target, recipient, email.subject, async () => {
        await openEmailDetail(id);
        await loadSentPage?.();
      });
    } else {
      target.innerHTML = `
        <div class="gmail-subject-row">
          <h1 class="gmail-subject-title">${escapeHtml(email.subject || '(No subject)')}</h1>
          <span class="badge ${email.status}">${email.status.toUpperCase()}</span>
        </div>

        <div class="gmail-sender-card">
          <div class="gmail-avatar-large" style="background:${av.bg};color:${av.text}">${initial}</div>
          <div class="gmail-sender-meta">
            <div class="gmail-sender-line1">
              <span class="gmail-sender-display">${escapeHtml(recipient)}</span>
              <span class="gmail-sender-date">${date(email.sent_at || email.created_at)}</span>
            </div>
            <div class="gmail-sender-line2">
              <span>To: <span class="gmail-to-pill">${escapeHtml(recipient)}</span></span>
              ${email.cc ? `<span>Cc: <span class="gmail-to-pill">${escapeHtml(email.cc)}</span></span>` : ''}
              ${email.bcc ? `<span>Bcc: <span class="gmail-to-pill">${escapeHtml(email.bcc)}</span></span>` : ''}
            </div>
          </div>
        </div>

        ${email.status === 'sent' ? `
          <div class="delivery-status-banner success">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
            <span>Accepted by mail server &middot; Dispatched successfully to recipient mailbox.</span>
          </div>
        ` : ''}

        ${email.error ? `
          <div class="delivery-status-banner error">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
            <span>${escapeHtml(email.error)}</span>
          </div>
        ` : ''}

        <div class="detail-section-title" style="margin-top:18px">Message Content</div>
        <div class="gmail-plain-body">${escapeHtml(email.body || '')}</div>

        ${attachmentsHtml}
        ${activityHtml}
      `;
    }
  } catch (e) {
    if (request === detailRequest) {
      if (msgEl) {
        msgEl.textContent = e.message;
        msgEl.hidden = false;
      }
    }
  }
}
window.openEmailDetail = openEmailDetail;
$('#close-email-detail')?.addEventListener('click', () => $('#email-detail').close());
$('#email-detail')?.addEventListener('close', () => { detailRequest++; });
function renderTrafficChart(days) {
  const container = $('#overview-chart-container');
  if (!container) return;
  if (!days || !days.length) {
    container.innerHTML = '<div style="padding:40px;text-align:center;color:#66786c">No traffic data recorded yet.</div>';
    return;
  }

  const maxVal = Math.max(10, ...days.map(d => Math.max(d.sent, d.received)));
  const yMax = Math.ceil(maxVal / 5) * 5;

  const w = 700;
  const h = 220;
  const padLeft = 40;
  const padRight = 20;
  const padTop = 30;
  const padBottom = 40;
  const plotW = w - padLeft - padRight;
  const plotH = h - padTop - padBottom;

  const getX = (idx) => padLeft + (idx / Math.max(1, days.length - 1)) * plotW;
  const getY = (val) => padTop + plotH - (val / yMax) * plotH;

  const sentPoints = days.map((d, i) => `${getX(i)},${getY(d.sent)}`);
  const sentArea = `${getX(0)},${getY(0)} ` + sentPoints.join(' ') + ` ${getX(days.length - 1)},${getY(0)}`;

  const recvPoints = days.map((d, i) => `${getX(i)},${getY(d.received)}`);
  const recvArea = `${getX(0)},${getY(0)} ` + recvPoints.join(' ') + ` ${getX(days.length - 1)},${getY(0)}`;

  const xLabels = days.map((d, i) => {
    const isToday = i === days.length - 1;
    return `<text x="${getX(i)}" y="202" font-size="11" font-weight="${isToday ? '700' : '400'}" fill="${isToday ? '#285c43' : '#8f9e94'}" text-anchor="middle">${isToday ? 'Today' : escapeHtml(d.day)}</text>`;
  }).join('');

  const sentCircles = days.map((d, i) => `<circle cx="${getX(i)}" cy="${getY(d.sent)}" r="4.5" fill="#285c43" stroke="#fff" stroke-width="2"><title>${escapeHtml(d.day)} (${d.date}): ${d.sent} sent</title></circle>`).join('');
  const recvCircles = days.map((d, i) => `<circle cx="${getX(i)}" cy="${getY(d.received)}" r="4.5" fill="#1a73e8" stroke="#fff" stroke-width="2"><title>${escapeHtml(d.day)} (${d.date}): ${d.received} received</title></circle>`).join('');

  container.innerHTML = `
    <svg viewBox="0 0 ${w} ${h}" width="100%" height="100%" preserveAspectRatio="none">
      <defs>
        <linearGradient id="gGradLive" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="#285c43" stop-opacity="0.25"/>
          <stop offset="100%" stop-color="#285c43" stop-opacity="0.0"/>
        </linearGradient>
        <linearGradient id="bGradLive" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="#1a73e8" stop-opacity="0.2"/>
          <stop offset="100%" stop-color="#1a73e8" stop-opacity="0.0"/>
        </linearGradient>
      </defs>

      <!-- Grid lines -->
      <line x1="${padLeft}" y1="${getY(yMax)}" x2="${w - padRight}" y2="${getY(yMax)}" stroke="#f0f3f0" stroke-dasharray="3,3"/>
      <text x="${padLeft - 10}" y="${getY(yMax) + 4}" font-size="10" fill="#8f9e94" text-anchor="end">${yMax}</text>

      <line x1="${padLeft}" y1="${getY(yMax * 0.6)}" x2="${w - padRight}" y2="${getY(yMax * 0.6)}" stroke="#f0f3f0" stroke-dasharray="3,3"/>
      <text x="${padLeft - 10}" y="${getY(yMax * 0.6) + 4}" font-size="10" fill="#8f9e94" text-anchor="end">${Math.round(yMax * 0.6)}</text>

      <line x1="${padLeft}" y1="${getY(yMax * 0.2)}" x2="${w - padRight}" y2="${getY(yMax * 0.2)}" stroke="#f0f3f0" stroke-dasharray="3,3"/>
      <text x="${padLeft - 10}" y="${getY(yMax * 0.2) + 4}" font-size="10" fill="#8f9e94" text-anchor="end">${Math.round(yMax * 0.2)}</text>

      <line x1="${padLeft}" y1="${getY(0)}" x2="${w - padRight}" y2="${getY(0)}" stroke="#edf1ed"/>
      <text x="${padLeft - 10}" y="${getY(0) + 4}" font-size="10" fill="#8f9e94" text-anchor="end">0</text>

      <!-- Area fills -->
      <polygon points="${sentArea}" fill="url(#gGradLive)"/>
      <polyline points="${sentPoints.join(' ')}" fill="none" stroke="#285c43" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>

      <polygon points="${recvArea}" fill="url(#bGradLive)"/>
      <polyline points="${recvPoints.join(' ')}" fill="none" stroke="#1a73e8" stroke-width="2.5" stroke-dasharray="4,3" stroke-linecap="round"/>

      <!-- Data Dots -->
      ${sentCircles}
      ${recvCircles}

      <!-- X-Labels -->
      ${xLabels}
    </svg>
  `;
}

function renderDonutChart(deliveryRate, totalSent, totalFailed, totalPending) {
  const container = $('#overview-donut-container');
  if (!container) return;
  const circ = 2 * Math.PI * 40; // 251.327
  const pct = Math.min(100, Math.max(0, deliveryRate));
  const offset = circ - (pct / 100) * circ;

  container.innerHTML = `
    <svg width="140" height="140" viewBox="0 0 100 100">
      <circle cx="50" cy="50" r="40" fill="transparent" stroke="#f0f3f0" stroke-width="12"/>
      <circle cx="50" cy="50" r="40" fill="transparent" stroke="#285c43" stroke-width="12"
              stroke-dasharray="${circ}" stroke-dashoffset="${offset}"
              stroke-linecap="round" transform="rotate(-90 50 50)"/>
      <text x="50" y="47" text-anchor="middle" dominant-baseline="middle" font-size="18" font-weight="700" fill="#1f2923">${pct}%</text>
      <text x="50" y="62" text-anchor="middle" dominant-baseline="middle" font-size="8.5" font-weight="700" fill="#66786c">DELIVERY</text>
    </svg>
  `;

  if ($('#ov-ratio-sent')) $('#ov-ratio-sent').textContent = `${totalSent} (${pct}%)`;
  if ($('#ov-ratio-failed')) $('#ov-ratio-failed').textContent = `${totalFailed} (${(100 - pct).toFixed(1)}%)`;
  if ($('#ov-ratio-pending')) $('#ov-ratio-pending').textContent = `${totalPending} (0%)`;
}

function renderHotContacts(contacts) {
  const container = $('#overview-hot-contacts');
  if (!container) return;
  container.replaceChildren();

  if (!contacts || !contacts.length) {
    container.innerHTML = '<div style="padding:24px;text-align:center;color:#66786c">No incoming contact replies yet. Dispatched emails and recipient replies will appear here!</div>';
    return;
  }

  for (const c of contacts) {
    const item = document.createElement('div');
    item.className = 'dash-contact-item';
    const av = getAvatarStyle(c.from_name || c.from_email);
    const initial = (c.from_name || c.from_email).trim().charAt(0).toUpperCase();
    const timeFormatted = c.received_at ? new Date(c.received_at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '';

    item.innerHTML = `
      <div class="dash-contact-left">
        <div class="dash-contact-avatar" style="background:${av.bg};color:${av.text}">${initial}</div>
        <div class="dash-contact-info">
          <div class="dash-contact-name">${escapeHtml(c.from_name)} <span class="dash-contact-email">&lt;${escapeHtml(c.from_email)}&gt;</span></div>
          <div class="dash-contact-snippet">${escapeHtml(c.snippet || 'Message received')}</div>
        </div>
      </div>
      <div class="dash-contact-right">
        <span class="dash-contact-pill">${c.thread_count > 1 ? `${c.thread_count} messages` : '1 message'}</span>
        <div class="dash-contact-time">${timeFormatted}</div>
      </div>
    `;

    item.addEventListener('click', () => {
      if (window.openThreadReader) {
        window.openThreadReader(c.id, 'inbox');
      } else {
        view('inbox');
      }
    });

    container.append(item);
  }
}

async function load() {
  try {
    const data = await api('dashboard');

    // KPI Values
    if ($('#ov-stat-sent')) $('#ov-stat-sent').textContent = data.counts.sent ?? 0;
    if ($('#ov-stat-inbound')) $('#ov-stat-inbound').textContent = data.counts.inbox_total ?? 0;
    if ($('#ov-stat-inbox-badge')) $('#ov-stat-inbox-badge').textContent = `● ${data.counts.inbox_unread ?? 0} Unread`;
    if ($('#ov-stat-response-rate')) $('#ov-stat-response-rate').textContent = `${data.counts.response_rate ?? 0}%`;
    if ($('#ov-stat-delivery-label')) $('#ov-stat-delivery-label').textContent = `${data.counts.delivery_rate ?? 100}% Accepted by Hostinger`;
    if ($('#ov-stat-health')) $('#ov-stat-health').textContent = `${data.counts.failed ?? 0} Bounces`;
    if ($('#ov-stat-health-badge')) $('#ov-stat-health-badge').textContent = `${data.counts.failed ?? 0} Delivery Failures`;

    // Legends
    if ($('#ov-legend-sent')) $('#ov-legend-sent').textContent = `Outgoing Sent (${data.counts.sent ?? 0} msgs)`;
    if ($('#ov-legend-received')) $('#ov-legend-received').textContent = `Inbound Replies (${data.counts.inbox_total ?? 0} msgs)`;

    // Render Charts
    renderTrafficChart(data.traffic_7d || []);
    renderDonutChart(data.counts.delivery_rate ?? 100, data.counts.sent ?? 0, data.counts.failed ?? 0, data.counts.pending ?? 0);
    renderHotContacts(data.hot_contacts || []);

    // Infrastructure Status
    if (data.system_health) {
      if ($('#ov-health-smtp')) $('#ov-health-smtp').textContent = `${data.system_health.smtp_host} · ${data.system_health.smtp_user} · Active`;
      if ($('#ov-health-imap')) $('#ov-health-imap').textContent = `${data.system_health.imap_host} · Synced · ${data.system_health.imap_total} stored emails`;
      if ($('#ov-health-sheets')) $('#ov-health-sheets').textContent = `${data.system_health.sheets_count} Sheets connected · Live sync active`;
      if ($('#ov-health-db')) $('#ov-health-db').textContent = `${data.counts.pending ?? 0} pending queue · Latency < 2ms`;
    }

    if (typeof data.inbox_unread !== 'undefined') window.updateInboxBadge?.(data.inbox_unread);
    if ($('#mode')) {
      $('#mode').textContent = data.dry_run ? 'Test mode enabled — emails won’t be sent.' : 'Live sending enabled — queued emails will be sent automatically.';
      const modeWrap = $('#mode').closest('.mode');
      if (modeWrap) {
        modeWrap.hidden = !data.dry_run;
      }
    }
    $('#compose-mode').textContent = data.dry_run ? 'Test mode — emails won’t be sent' : 'Live mode — queued emails will be sent';
    $('#compose-mode').classList.toggle('is-live', !data.dry_run);
  } catch(e) {
    notice('Failed to load dashboard: ' + e.message, true);
  }
}

// Quick overview inbox sync button
$('#overview-sync-btn')?.addEventListener('click', async () => {
  const btn = $('#overview-sync-btn');
  const label = btn.querySelector('span');
  btn.disabled = true;
  if (label) label.textContent = 'Syncing…';
  try {
    const res = await api('inbox/sync', { method: 'POST' });
    notice(`Synced! Received ${res.new_count} new messages.`);
    await load();
  } catch (err) {
    notice('Failed to sync inbox: ' + err.message, true);
  } finally {
    btn.disabled = false;
    if (label) label.textContent = 'Sync Inbox';
  }
});
let currentSession = null;

async function init() {
  try {
    const session = await api('session');
    currentSession = session;
    csrf = session.csrf;
    $('#setup').hidden = !session.setup_allowed || session.authenticated;
    $('#login').hidden = session.authenticated || session.setup_allowed;

    if (session.authenticated) {
      document.body.classList.add('is-authenticated');
      document.body.classList.remove('is-unauthenticated');
      if ($('#logout')) {
        $('#logout').hidden = false;
        $('#logout').style.display = 'inline-flex';
      }

      const activeHash = location.hash ? location.hash.replace(/^#/, '') : '';
      const savedView = activeHash || localStorage.getItem('rsl_active_view') || 'overview';

      // Pre-set view hidden attributes before revealing #app to prevent any FOUC / flash of overview
      document.querySelectorAll('.view').forEach(el => el.hidden = el.id !== savedView);
      document.querySelectorAll('.nav').forEach(el => {
        el.classList.toggle('active', el.dataset.view === savedView);
        if (el.dataset.view === savedView) el.setAttribute('aria-current', 'page');
        else el.removeAttribute('aria-current');
      });

      // Reveal #app with target view already active
      $('#app').hidden = false;

      // Restore saved pagination sizes
      const savedEmailSize = localStorage.getItem('rsl_email_size');
      if (savedEmailSize && $('#email-size')) $('#email-size').value = savedEmailSize;
      const savedSentSize = localStorage.getItem('rsl_sent_size');
      if (savedSentSize && $('#sent-size')) $('#sent-size').value = savedSentSize;

      // Initialize the target view directly
      view(savedView);

      // Fetch dashboard statistics in background without blocking UI
      load().catch(() => {});
    } else {
      document.body.classList.add('is-unauthenticated');
      document.body.classList.remove('is-authenticated');
      if ($('#logout')) {
        $('#logout').hidden = true;
        $('#logout').style.display = 'none';
      }
      $('#app').hidden = true;
    }

    if (session.google_notice) notice(session.google_notice.message, session.google_notice.error);
  } catch (e) {
    notice(e.message, true);
  }
}
$('#setup-form').addEventListener('submit', async event => { event.preventDefault(); const button = event.target.querySelector('.primary'); button.disabled = true; try { await api('setup', {method:'POST', body:JSON.stringify(Object.fromEntries(new FormData(event.target)))}); event.target.reset(); await init(); notice('Administrator created. You are signed in.'); } catch(e) {notice(e.message,true);} finally {button.disabled = false;} });
document.querySelectorAll('[data-view]').forEach(button => button.addEventListener('click', () => view(button.dataset.view)));
$('#login-form').addEventListener('submit', async event => { event.preventDefault(); const button = event.target.querySelector('.primary'); button.disabled = true; try { await api('login', {method:'POST', body:JSON.stringify({password:new FormData(event.target).get('password')})}); event.target.reset(); $('#notice').hidden = true; await init(); } catch(e) {notice(e.message, true);} finally {button.disabled = false;} });

// Password Reset Modal Handlers
$('#forgot-password')?.addEventListener('click', () => {
  if ($('#reset-step-1')) $('#reset-step-1').hidden = false;
  if ($('#reset-step-2')) $('#reset-step-2').hidden = true;
  if ($('#reset-step-local')) $('#reset-step-local').hidden = true;
  if ($('#reset-req-error')) $('#reset-req-error').hidden = true;
  if ($('#reset-verify-error')) $('#reset-verify-error').hidden = true;
  if ($('#reset-local-error')) $('#reset-local-error').hidden = true;
  if ($('#reset-req-email') && currentSession?.admin_email) {
    $('#reset-req-email').value = currentSession.admin_email;
  }
  if ($('#reset-local-bypass-wrap')) {
    $('#reset-local-bypass-wrap').hidden = !currentSession?.is_local;
  }
  $('#reset-dialog').showModal();
});

$('#close-reset')?.addEventListener('click', () => $('#reset-dialog').close());

$('#reset-request-form')?.addEventListener('submit', async event => {
  event.preventDefault();
  const btn = $('#reset-req-btn');
  btn.disabled = true;
  $('#reset-req-error').hidden = true;
  const email = $('#reset-req-email').value.trim();
  try {
    const res = await api('password-reset/request', {
      method: 'POST',
      body: JSON.stringify({ email })
    });
    $('#reset-step-1').hidden = true;
    $('#reset-step-2').hidden = false;
    $('#reset-sent-notice').textContent = res.message || `A 6-digit code was sent to ${email}.`;
    if (res.dev_code && $('#reset-verify-code')) {
      $('#reset-verify-code').value = res.dev_code;
    }
    $('#reset-verify-code')?.focus();
  } catch (e) {
    $('#reset-req-error').textContent = e.message;
    $('#reset-req-error').hidden = false;
  } finally {
    btn.disabled = false;
  }
});

$('#reset-back-to-step1')?.addEventListener('click', () => {
  $('#reset-step-1').hidden = false;
  $('#reset-step-2').hidden = true;
  $('#reset-step-local').hidden = true;
});

$('#reset-verify-form')?.addEventListener('submit', async event => {
  event.preventDefault();
  const btn = $('#reset-verify-btn');
  btn.disabled = true;
  $('#reset-verify-error').hidden = true;
  const email = $('#reset-req-email').value.trim();
  const code = $('#reset-verify-code').value.trim();
  const password = $('#reset-verify-password').value;
  const confirmation = $('#reset-verify-confirm').value;
  try {
    await api('password-reset/verify', {
      method: 'POST',
      body: JSON.stringify({ email, code, password, confirmation })
    });
    $('#reset-dialog').close();
    event.target.reset();
    $('#notice').hidden = true;
    await init();
    notice('Password updated successfully. You are signed in.');
  } catch (e) {
    $('#reset-verify-error').textContent = e.message;
    $('#reset-verify-error').hidden = false;
  } finally {
    btn.disabled = false;
  }
});

$('#reset-local-bypass-btn')?.addEventListener('click', () => {
  $('#reset-step-1').hidden = true;
  $('#reset-step-local').hidden = false;
});

$('#reset-local-back')?.addEventListener('click', () => {
  $('#reset-step-1').hidden = false;
  $('#reset-step-local').hidden = true;
});

$('#reset-local-form')?.addEventListener('submit', async event => {
  event.preventDefault();
  const btn = event.target.querySelector('.primary');
  btn.disabled = true;
  $('#reset-local-error').hidden = true;
  const password = $('#reset-local-password').value;
  const confirmation = $('#reset-local-confirm').value;
  try {
    await api('reset-password', {
      method: 'POST',
      body: JSON.stringify({ password, confirmation })
    });
    $('#reset-dialog').close();
    event.target.reset();
    $('#notice').hidden = true;
    await init();
    notice('Password updated. You are signed in.');
  } catch (e) {
    $('#reset-local-error').textContent = e.message;
    $('#reset-local-error').hidden = false;
  } finally {
    btn.disabled = false;
  }
});

$('#logout').addEventListener('click', async () => { try {await api('logout', {method:'POST'}); location.reload();} catch(e) {notice(e.message,true);} });

let currentAttachments = [];

function formatBytes(bytes) {
  if (!bytes || bytes === 0) return '0 B';
  const k = 1024, sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

function escapeHtml(str) {
  if (!str) return '';
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

function renderComposeAttachments() {
  const container = $('#compose-attachments');
  if (!container) return;
  if (!currentAttachments.length) {
    container.hidden = true;
    container.innerHTML = '';
    return;
  }
  container.hidden = false;
  container.innerHTML = '';
  currentAttachments.forEach((att, idx) => {
    const chip = document.createElement('div');
    chip.className = 'attachment-chip';
    chip.innerHTML = `
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
        <polyline points="14 2 14 8 20 8"/>
      </svg>
      <span class="attachment-name" title="${escapeHtml(att.name)}">${escapeHtml(att.name)}</span>
      <span class="attachment-size">${formatBytes(att.size)}</span>
      <button type="button" class="attachment-remove-btn" title="Remove attachment" aria-label="Remove">&times;</button>
    `;
    chip.querySelector('.attachment-remove-btn').addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      currentAttachments.splice(idx, 1);
      renderComposeAttachments();
    });
    container.append(chip);
  });
}

async function handleFileUpload(files) {
  if (!files || !files.length) return;
  const maxBytes = 25 * 1024 * 1024;
  const statusEl = $('#compose-upload-status');
  for (const file of files) {
    if (file.size > maxBytes) {
      notice(`"${file.name}" exceeds the maximum allowed file size of 25 MB.`, true);
      continue;
    }
    try {
      if (statusEl) {
        statusEl.textContent = `Attaching ${file.name}…`;
        statusEl.hidden = false;
      }
      const formData = new FormData();
      formData.append('file', file);
      const res = await fetch('/api/attachments', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrf },
        body: formData
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Upload failed');
      currentAttachments.push(data);
      renderComposeAttachments();
    } catch (err) {
      notice(`Failed to attach ${file.name}: ${err.message}`, true);
    } finally {
      if (statusEl) statusEl.hidden = true;
    }
  }
  const fileInput = $('#compose-file-input');
  if (fileInput) fileInput.value = '';
}

$('#compose-file-input')?.addEventListener('change', e => {
  handleFileUpload(e.target.files);
});

// Drag and drop support on compose card
const composeCard = $('#compose-form')?.closest('.card') || $('#compose-form');
if (composeCard) {
  ['dragenter', 'dragover'].forEach(name => {
    composeCard.addEventListener(name, e => {
      e.preventDefault();
      composeCard.style.outline = '2px dashed #1a73e8';
      composeCard.style.outlineOffset = '-4px';
    });
  });
  ['dragleave', 'drop'].forEach(name => {
    composeCard.addEventListener(name, e => {
      e.preventDefault();
      composeCard.style.outline = 'none';
    });
  });
  composeCard.addEventListener('drop', e => {
    e.preventDefault();
    composeCard.style.outline = 'none';
    if (e.dataTransfer?.files?.length) {
      handleFileUpload(e.dataTransfer.files);
    }
  });
}

$('#compose-form').addEventListener('submit', async event => {
  event.preventDefault();
  const button = event.target.querySelector('#compose-submit-btn') || event.target.querySelector('.primary');
  const label = button ? (button.querySelector('span') || button) : null;
  const originalText = label ? label.textContent : 'Send email';
  if (button) button.disabled = true;
  if (label) label.textContent = 'Sending…';

  try {
    const input = Object.fromEntries(new FormData(event.target));
    input.idempotency_key = requestKey;
    input.send_now = true;
    if (currentAttachments.length) {
      input.attachments = currentAttachments;
    }
    const result = await api('emails', {method:'POST', body:JSON.stringify(input)});
    requestKey = crypto.randomUUID();
    event.target.reset();
    currentAttachments = [];
    renderComposeAttachments();
    resetRecipientStatus();

    const titleEl = $('#compose-title');
    const submitLabel = $('#compose-submit-label');
    if (titleEl) titleEl.textContent = 'Compose an email';
    if (submitLabel) submitLabel.textContent = 'Send email';

    notice(`Email #${result.id} sent successfully!`);
    view('sent');
    await loadSentPage?.();
  } catch(e) {
    notice(e.message, true);
  } finally {
    if (button) button.disabled = false;
    if (label) label.textContent = originalText;
  }
});
$('.compose-discard-btn')?.addEventListener('click', () => {
  $('#compose-form').reset();
  currentAttachments = [];
  renderComposeAttachments();
  resetRecipientStatus();
  notice('Draft discarded.');
  view('inbox');
});
$('#search')?.addEventListener('input', () => { clearTimeout(searchTimer); emailRequest++; emailPage = 1; searchTimer = setTimeout(() => loadEmailPage().catch(e => notice(e.message,true)),250); });
$('#email-status')?.addEventListener('change', () => { emailPage = 1; loadEmailPage().catch(e => notice(e.message,true)); });
$('#email-size')?.addEventListener('change', () => {
  localStorage.setItem('rsl_email_size', $('#email-size').value);
  emailPage = 1;
  loadEmailPage().catch(e => notice(e.message, true));
});
$('#email-prev')?.addEventListener('click', () => { if (emailPage > 1) { emailPage--; loadEmailPage().catch(e => notice(e.message,true)); } });
$('#email-next')?.addEventListener('click', () => { if (emailPage < emailPages) { emailPage++; loadEmailPage().catch(e => notice(e.message,true)); } });
document.querySelectorAll('[data-recipient]').forEach(button => {
  const field = document.getElementById(button.getAttribute('aria-controls'));
  const input = field.querySelector('input');
  button.addEventListener('click', () => {
    const expanded = button.getAttribute('aria-expanded') !== 'true';
    // Keep populated recipients visible so they cannot be sent unnoticed or discarded.
    if (!expanded && input.value.trim()) { input.focus(); return; }
    button.setAttribute('aria-expanded', String(expanded));
    field.hidden = !expanded; input.disabled = !expanded;
    if (expanded) input.focus();
  });
  $('#compose-form').addEventListener('reset', () => {
    button.setAttribute('aria-expanded', 'false'); field.hidden = true; input.disabled = true;
    currentAttachments = []; renderComposeAttachments();
    resetRecipientStatus();
  });
});

// Live recipient email & MX domain validation in Compose
const composeToInput = $('#compose-to');
const composeRecipientStatus = $('#compose-recipient-status');
let recipientValidationTimer = null;
let recipientValidationController = null;

function resetRecipientStatus() {
  if (recipientValidationTimer) {
    clearTimeout(recipientValidationTimer);
    recipientValidationTimer = null;
  }
  if (recipientValidationController) {
    recipientValidationController.abort();
    recipientValidationController = null;
  }
  if (composeRecipientStatus) {
    composeRecipientStatus.hidden = true;
    composeRecipientStatus.textContent = '';
    composeRecipientStatus.className = 'compose-recipient-status';
    composeRecipientStatus.removeAttribute('title');
  }
}

async function validateComposeRecipient(rawEmail) {
  const email = (rawEmail || '').trim();
  if (!email || !email.includes('@')) {
    resetRecipientStatus();
    return;
  }

  const parts = email.split('@');
  const domain = parts[parts.length - 1] || '';

  // Wait until user has at least started a domain with a dot, e.g. "user@domain.com"
  if (!domain.includes('.') || domain.split('.').pop().length < 2) {
    resetRecipientStatus();
    return;
  }

  if (recipientValidationController) {
    recipientValidationController.abort();
  }
  recipientValidationController = new AbortController();

  if (composeRecipientStatus) {
    composeRecipientStatus.hidden = false;
    composeRecipientStatus.className = 'compose-recipient-status is-checking';
    composeRecipientStatus.textContent = 'Verifying domain…';
    composeRecipientStatus.title = 'Checking MX DNS records…';
  }

  try {
    const res = await fetch(`/api/validate-recipient?email=${encodeURIComponent(email)}`, {
      signal: recipientValidationController.signal,
      headers: { 'Accept': 'application/json' }
    });
    if (!res.ok && res.status !== 400) {
      throw new Error('Validation request failed');
    }
    const data = await res.json();
    if (!composeRecipientStatus || composeToInput?.value.trim() !== email) return;

    if (data.valid) {
      composeRecipientStatus.hidden = false;
      composeRecipientStatus.className = 'compose-recipient-status is-valid';
      composeRecipientStatus.textContent = `✓ Mail server active (@${data.domain})`;
      composeRecipientStatus.title = `MX records verified on ${data.domain}`;
    } else {
      composeRecipientStatus.hidden = false;
      composeRecipientStatus.className = 'compose-recipient-status is-invalid';
      composeRecipientStatus.textContent = `⚠️ ${data.error || 'Invalid email or domain'}`;
      composeRecipientStatus.title = data.error || 'Invalid recipient email';
    }
  } catch (err) {
    if (err.name === 'AbortError') return;
    if (composeRecipientStatus && composeToInput?.value.trim() === email) {
      composeRecipientStatus.hidden = true;
    }
  }
}

composeToInput?.addEventListener('input', () => {
  if (recipientValidationTimer) clearTimeout(recipientValidationTimer);
  const val = composeToInput.value.trim();
  if (!val || !val.includes('@')) {
    resetRecipientStatus();
    return;
  }
  recipientValidationTimer = setTimeout(() => {
    validateComposeRecipient(val);
  }, 350);
});

composeToInput?.addEventListener('blur', () => {
  if (recipientValidationTimer) clearTimeout(recipientValidationTimer);
  const val = composeToInput.value.trim();
  if (val && val.includes('@')) {
    validateComposeRecipient(val);
  }
});
$('#refresh')?.addEventListener('click', async () => {
  const button = $('#refresh'), label = button.querySelector('span');
  button.disabled = true; label.textContent = 'Refreshing…';
  try { await load(); } catch(e) { notice(e.message,true); }
  finally { button.disabled = false; label.textContent = 'Refresh'; }
});
// Add keyboard-accessible visibility controls to every password field.
document.querySelectorAll('input[type="password"]').forEach((input, index) => {
  input.id ||= `password-${index}`;
  const wrapper = document.createElement('span');
  wrapper.className = 'password-field';
  input.before(wrapper);
  wrapper.append(input);
  const toggle = document.createElement('button');
  toggle.type = 'button';
  toggle.className = 'password-toggle';
  toggle.setAttribute('aria-controls', input.id);
  toggle.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="eye-slash" d="m3 3 18 18"/></svg>';
  function updateVisibility(visible) {
    input.type = visible ? 'text' : 'password';
    toggle.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
    toggle.title = visible ? 'Hide password' : 'Show password';
    toggle.setAttribute('aria-pressed', String(visible));
    toggle.classList.toggle('is-visible', visible);
  }
  updateVisibility(false);
  toggle.addEventListener('click', () => updateVisibility(input.type === 'password'));
  input.form?.addEventListener('reset', () => updateVisibility(false));
  wrapper.append(toggle);
});

let sentPage = 1, sentPages = 1, sentRequest = 0, sentSearchTimer;
async function loadSentPage() {
  const request = ++sentRequest;
  const prevBtn = $('#sent-prev'), nextBtn = $('#sent-next'), rangeEl = $('#sent-range');
  if (prevBtn) prevBtn.disabled = true;
  if (nextBtn) nextBtn.disabled = true;
  if (rangeEl) rangeEl.textContent = 'Loading sent emails...';
  try {
    const size = $('#sent-size')?.value || 25;
    const search = $('#sent-search')?.value.trim() || '';
    const params = new URLSearchParams({ page: sentPage, page_size: size, search, status: 'sent' });
    const data = await api('emails?' + params);
    if (request !== sentRequest) return;
    const p = data.pagination;
    sentPage = p.page; sentPages = p.pages;
    renderSentRows(data.emails);
    if (rangeEl) rangeEl.textContent = `${p.from}–${p.to} of ${p.total} sent emails`;
    if ($('#sent-page')) $('#sent-page').textContent = `Page ${p.page} of ${p.pages}`;
    if (prevBtn) prevBtn.disabled = p.page <= 1;
    if (nextBtn) nextBtn.disabled = p.page >= p.pages;
  } catch(e) {
    if (request === sentRequest && rangeEl) {
      rangeEl.textContent = 'Could not load sent emails.';
    }
  }
}

function renderSentRows(list) {
  const tbody = $('#sent-rows');
  if (!tbody) return;
  tbody.replaceChildren();
  for (const email of list) {
    const tr = document.createElement('tr');
    tr.className = 'sent-row-item';
    tr.addEventListener('click', (e) => {
      if (e.target.closest('button, a, input')) return;
      openThreadReader(email.id, 'sent');
    });

    const recipient = email.recipient || 'Unknown';
    const av = getAvatarStyle(recipient);
    const initial = recipient.trim().charAt(0).toUpperCase();

    // Recipient cell with Google avatar circle
    const recipientTd = document.createElement('td');
    recipientTd.className = 'sent-cell-recipient';
    recipientTd.innerHTML = `
      <div class="inbox-sender-cell">
        <div class="inbox-avatar" style="background:${av.bg};color:${av.text}">${initial}</div>
        <div class="inbox-sender-info">
          <strong class="inbox-sender-name">${escapeHtml(recipient)}</strong>
          <small class="inbox-sender-email">To: ${escapeHtml(recipient)}</small>
        </div>
      </div>
    `;

    // Subject & preview snippet (Gmail style)
    const subjectTd = document.createElement('td');
    subjectTd.className = 'sent-cell-subject';
    const snippet = (email.body || '').replace(/[\r\n\t]+/g, ' ').trim();
    const threadPill = Number(email.thread_count) > 1 ? `<span class="thread-count-badge">${email.thread_count}</span>` : '';
    subjectTd.innerHTML = `
      <div class="inbox-preview-wrap">
        <span class="inbox-subject-text">${escapeHtml(email.subject || '(No subject)')}</span>
        ${threadPill}
        ${snippet ? `<span class="inbox-snippet-separator">&middot;</span><span class="inbox-snippet-text">${escapeHtml(snippet)}</span>` : ''}
      </div>
    `;

    // Dispatched date cell
    const dateTd = document.createElement('td');
    dateTd.className = 'sent-cell-date';
    dateTd.textContent = new Date(email.sent_at || email.created_at).toLocaleString([], {
      month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
    });

    // Status cell
    const statusTd = document.createElement('td');
    statusTd.className = 'sent-cell-status';
    const badge = document.createElement('span');
    const st = email.status || 'sent';
    badge.className = `badge ${st}`;
    badge.textContent = st.charAt(0).toUpperCase() + st.slice(1).replace('_', ' ');
    statusTd.append(badge);

    // Action cell
    const actionTd = document.createElement('td');
    actionTd.className = 'sent-cell-action';
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'sent-btn-details';
    btn.textContent = 'View details';
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      openThreadReader(email.id, 'sent');
    });
    actionTd.append(btn);

    tr.append(recipientTd, subjectTd, dateTd, statusTd, actionTd);
    tbody.append(tr);
  }
  if (!list.length) {
    const tr = document.createElement('tr');
    const td = cell(''); td.colSpan = 5; td.className = 'empty';
    td.innerHTML = '<div class="empty-icon"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg></div><h3>No sent emails found</h3><p>Emails you send from Google Sheets or manual compose will appear here.</p>';
    tr.append(td); tbody.append(tr);
  }
}

$('#sent-search')?.addEventListener('input', () => {
  clearTimeout(sentSearchTimer);
  sentSearchTimer = setTimeout(() => { sentPage = 1; loadSentPage().catch(e => notice(e.message, true)); }, 250);
});
$('#sent-size')?.addEventListener('change', () => {
  localStorage.setItem('rsl_sent_size', $('#sent-size').value);
  sentPage = 1;
  loadSentPage().catch(e => notice(e.message, true));
});
$('#sent-prev')?.addEventListener('click', () => { sentPage--; loadSentPage().catch(e => notice(e.message, true)); });
$('#sent-next')?.addEventListener('click', () => { sentPage++; loadSentPage().catch(e => notice(e.message, true)); });
$('#sent-refresh')?.addEventListener('click', async () => {
  const btn = $('#sent-refresh'), label = btn.querySelector('span');
  btn.disabled = true; if (label) label.textContent = 'Refreshing...';
  try { await loadSentPage(); } catch(e) { notice(e.message, true); }
  finally { btn.disabled = false; if (label) label.textContent = 'Refresh'; }
});

window.addEventListener('hashchange', () => {
  const hash = location.hash ? location.hash.replace(/^#/, '') : 'overview';
  if (document.getElementById(hash)) view(hash);
});

init();
