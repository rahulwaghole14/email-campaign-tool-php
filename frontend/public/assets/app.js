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
function routeParts() {
  const raw = (location.hash || '').replace(/^#/, '');
  const [name, ...rest] = raw.split('/').filter(Boolean);
  return { name: name || '', extra: rest.join('/') };
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
      const current = routeParts();
      if (current.name !== name) {
        history.replaceState(null, '', '#' + name);
      }
    } catch(e) {}
  }

  if (name === 'overview') load?.();
  if (name === 'sheets') window.loadSheets?.();
  if (name === 'inbox') window.loadInbox?.();
  if (name === 'sent') loadSentPage?.();
  if (name === 'mail-accounts') loadMailAccounts?.();
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
    const row = document.createElement('tr'), td = cell(''); td.colSpan = 6; td.className = 'empty';
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

function visibleReplyHtml(html) {
  const doc = new DOMParser().parseFromString(String(html || ''), 'text/html');
  doc.querySelectorAll('.gmail_quote, .gmail_quote_container, .gmail_attr').forEach(node => node.remove());
  while (doc.body.lastChild && doc.body.lastChild.nodeName === 'BR') doc.body.lastChild.remove();
  const text = (doc.body.textContent || '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
  return { text, html: doc.body.innerHTML.trim() };
}

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

function isMimeStub(text) {
  const value = String(text || '').trim();
  if (!value) return true;
  if (/^boundary\s*=\s*"?[A-Za-z0-9_=.\-\/]+"?$/i.test(value)) return true;
  if (/^this is a mime-encapsulated message\.?$/i.test(value)) return true;
  return false;
}

function htmlNoticeText(html) {
  let value = String(html || '');
  if (!value.trim()) return '';
  value = value.replace(/<style[\s\S]*?<\/style>/gi, ' ');
  value = value.replace(/<script[\s\S]*?<\/script>/gi, ' ');
  value = value.replace(/<head[\s\S]*?<\/head>/gi, ' ');
  value = value.replace(/<a\b[^>]*href=["']mailto:([^"']+)["'][^>]*>[\s\S]*?<\/a>/gi, '$1');
  value = value.replace(/<br\s*\/?>/gi, '\n');
  value = value.replace(/<\/(p|div|tr|h\d|li|table)>/gi, '\n');
  value = value.replace(/<[^>]+>/g, ' ');
  const holder = document.createElement('textarea');
  holder.innerHTML = value;
  value = holder.value.replace(/\u00a0/g, ' ');
  value = value.replace(/[ \t]+\n/g, '\n').replace(/[ \t]{2,}/g, ' ').replace(/\n{3,}/g, '\n\n');
  return value.trim();
}

function bounceNoticeText(plain, html) {
  let text = String(plain || '').trim();
  if (isMimeStub(text)) text = htmlNoticeText(html);
  text = text.replace(/([^\s<>]+@[^\s<>]+)\s*<mailto:\1>/gi, '$1');
  text = text.replace(/<mailto:([^>]+)>/gi, '$1');
  text = text.replace(/\n*Original message headers:\s*[\s\S]*$/i, '');
  return text.trim();
}

function failedRecipientFrom(text) {
  const patterns = [
    /Final-Recipient:[^;\n]*;\s*(?:rfc822;)?\s*<?([^\s<>,"']+@[^\s<>,"']+)>?/i,
    /([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})\s*<mailto:/i,
    /mailto:([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i
  ];
  for (const pattern of patterns) {
    const match = text.match(pattern);
    if (match && match[1]) return match[1].replace(/^mailto:/i, '').replace(/[>,.;]+$/, '');
  }
  const addresses = text.match(/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/gi) || [];
  const recipient = addresses.find(address => !/^(postmaster|mailer-daemon|noreply)@/i.test(address));
  return recipient || '';
}

function bounceReasonFrom(text) {
  const quoted = text.match(/returned\s+['"]([^'"]+)['"]/i);
  if (quoted) return quoted[1].replace(/\s+/g, ' ').trim();
  const said = text.match(/said:\s*([\s\S]{0,280}?)(?:\(in reply to|\n\s*\n|$)/i);
  if (said && /\b[45]\d{2}\b/.test(said[1])) {
    return said[1].replace(/\s+/g, ' ').replace(/[.\s]+$/, '').trim();
  }
  const code = text.match(/\b([45]\d{2}\s+[45]\.\d+\.\d+[^\r\n'<]{0,180})/);
  if (code) return code[1].replace(/\s+/g, ' ').trim();
  const diagnostic = text.match(/Diagnostic-Code:[^;]*;\s*([^\r\n]+)/i);
  if (diagnostic) return diagnostic[1].trim();
  const human = text.match(/((?:The email address you entered couldn't be found|mailbox unavailable|mailbox not found|user unknown|No such user)[^\r\n.]*)/i);
  if (human) return human[1].trim();
  return '';
}

function createExpandedMessageElement(msg, senderName, av, initial, dateFormatted, mainBody, quotedText, isCollapsible = false) {
  const el = document.createElement('div');
  el.className = 'gmail-msg-expanded' + (isCollapsible ? ' is-collapsible' : '');
  
  const visible = visibleReplyHtml(msg.html_body || '');
  const richHtml = Boolean(visible.text && /<(img|table|ul|ol)\b/i.test(visible.html));
  const hasPlain = Boolean(mainBody && mainBody.trim());

  const trimmedHtml = quotedText ? `
    <div class="thread-trimmed-wrapper">
      <button type="button" class="thread-trimmed-toggle" title="Show trimmed content">&hellip;</button>
      <div class="thread-trimmed-content" hidden style="display:none">
        <div class="thread-quoted-block">${formatEmailBodyText(formatQuotedHistory(quotedText))}</div>
      </div>
    </div>
  ` : '';

  const formatToggleBtn = (richHtml && hasPlain) ? `
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
  let showingHtml = richHtml;

  function renderBody() {
    bodyContainer.replaceChildren();

    // Friendly Delivery Failure Report Card if this message is a bounce
    const bounceSubject = /undeliver|delivery fail|returned to sender|failure notice/i.test(msg.subject || '');
    if (msg.is_bounce || (msg.status === 'bounced') || bounceSubject) {
      const card = document.createElement('div');
      card.className = 'bounce-report-card';
      
      const text = bounceNoticeText(mainBody, msg.html_body || '');
      const failedRecipient = failedRecipientFrom(text);
      const errorReason = bounceReasonFrom(text);

      card.innerHTML = `
        <div class="bounce-report-banner">
          <div class="bounce-report-icon">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          </div>
          <div class="bounce-report-summary">
            <h4>Email Delivery Failure Notification</h4>
            <p>The recipient's mail server rejected or could not deliver the message sent from your workspace.</p>
          </div>
        </div>
        <div class="bounce-report-meta">
          ${failedRecipient ? `<div class="bounce-meta-row"><span class="bounce-meta-label">Intended Recipient</span><strong class="bounce-meta-val">${escapeHtml(failedRecipient)}</strong></div>` : ''}
          ${errorReason ? `<div class="bounce-meta-row"><span class="bounce-meta-label">Server Diagnostic</span><span class="bounce-meta-val bounce-err-val">${escapeHtml(errorReason)}</span></div>` : ''}
          <div class="bounce-meta-row"><span class="bounce-meta-label">Reported By</span><span class="bounce-meta-val">${escapeHtml(msg.sender_name || msg.sender_email)}</span></div>
        </div>
        <div class="bounce-raw-section">
          <div class="bounce-raw-title">Full Delivery Diagnostics</div>
          <pre class="bounce-raw-pre">${escapeHtml(text || 'No raw diagnostics provided.')}</pre>
        </div>
      `;
      bodyContainer.append(card);
      return;
    }

    if (showingHtml && richHtml) {
      const iframe = document.createElement('iframe');
      iframe.className = 'email-html-frame';
      iframe.sandbox = 'allow-popups allow-popups-to-escape-sandbox allow-same-origin';
      iframe.style.height = '0px';
      iframe.srcdoc = `<!DOCTYPE html><html><head><meta charset="utf-8"><base target="_blank"><style>
        html,body{margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#202124;line-height:1.5;background:transparent;word-break:break-word}
        img{max-width:100%!important;height:auto}
        table{max-width:100%!important}
        a{color:#1a73e8}
      </style></head><body>${visible.html}</body></html>`;

      function resize() {
        try {
          const doc = iframe.contentDocument || iframe.contentWindow.document;
          if (!doc || !doc.body) return;
          iframe.style.height = '0px';
          const h = doc.body.scrollHeight;
          if (h > 0) iframe.style.height = (h + 2) + 'px';
        } catch (e) {}
      }

      iframe.addEventListener('load', resize);
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

    const isBounce = Boolean(email.is_bounce || (thread.length === 1 && thread[0].is_bounce));
    if (badgeEl) {
      if (isBounce) {
        badgeEl.textContent = 'Delivery Failure';
        badgeEl.style.background = '#fce8e6';
        badgeEl.style.color = '#c5221f';
      } else {
        badgeEl.textContent = origin === 'sent' ? 'Sent' : (origin === 'overview' ? 'Queue' : 'Inbox');
        badgeEl.style.background = '';
        badgeEl.style.color = '';
      }
    }

    if (bottomActions) {
      bottomActions.hidden = isBounce;
    }

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

    const recipient = email.recipient || 'Unknown';

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

    let failureHtml = '';
    if (email.status === 'failed') {
      const failedEvent = [...(data.activity || [])].reverse().find(item => item.action === 'failed');
      const reason = String(email.error || failedEvent?.detail || 'Delivery could not be confirmed.').trim();
      failureHtml = `
        <div class="delivery-status-banner error" style="margin-top:18px">
          <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
          <span>This email was not sent. ${escapeHtml(reason)}</span>
        </div>
      `;
    }

    const thread = (data.thread && data.thread.length) ? data.thread : [{
      type: 'outgoing',
      sender_name: 'Me (RSL Desk)',
      sender_email: email.sender_email || '',
      recipient: email.recipient || '',
      body: email.body || '',
      html_body: email.html_body || '',
      snippet: '',
      quoted_history: '',
      created_at: email.sent_at || email.created_at,
      status: email.status,
      subject: email.subject || ''
    }];
    target.replaceChildren();
    const subjectRow = document.createElement('div');
    subjectRow.className = 'gmail-subject-row';
    subjectRow.innerHTML = `<h1 class="gmail-subject-title">${escapeHtml(email.subject || '(No subject)')}</h1>`;
    const stream = document.createElement('div');
    stream.className = 'gmail-messages-stream';
    renderCanvasMessages(thread, stream, recipient, email.subject || '');
    target.append(subjectRow, stream);
    if (failureHtml) {
      const failure = document.createElement('div');
      failure.innerHTML = failureHtml;
      if (failure.firstElementChild) target.append(failure.firstElementChild);
    }
    if (attachmentsHtml) {
      const attachments = document.createElement('div');
      attachments.innerHTML = attachmentsHtml;
      while (attachments.firstChild) target.append(attachments.firstChild);
    }
    if (email.status !== 'failed') {
      const actions = document.createElement('div');
      actions.className = 'gmail-bottom-actions';
      actions.innerHTML = `
        <button type="button" class="gmail-action-pill" data-detail-reply>
          <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5v2.5"/></svg>
          <span>Reply</span>
        </button>
        <button type="button" class="gmail-action-pill" data-detail-forward>
          <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 14 5-5-5-5"/><path d="M20 9H9.5A5.5 5.5 0 0 0 4 14.5V17"/></svg>
          <span>Forward</span>
        </button>
      `;
      const composer = document.createElement('div');
      composer.className = 'gmail-inline-composer';
      composer.hidden = true;
      composer.innerHTML = `
        <div class="inline-composer-header">
          <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5v2.5"/></svg>
          <span>Reply to <strong>${escapeHtml(recipient)}</strong></span>
        </div>
        <textarea rows="5" placeholder="Write your reply..."></textarea>
        <div class="inline-composer-footer">
          <div class="composer-footer-left">
            <button type="button" class="primary detail-reply-send">
              <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
              <span>Send</span>
            </button>
          </div>
          <div class="composer-footer-right">
            <button type="button" class="quiet icon-only detail-reply-discard" title="Discard draft" aria-label="Discard draft">
              <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            </button>
          </div>
        </div>
      `;
      target.append(actions, composer);
      const replyBox = composer.querySelector('textarea');
      actions.querySelector('[data-detail-reply]').addEventListener('click', () => {
        actions.hidden = true;
        composer.hidden = false;
        replyBox.focus();
      });
      composer.querySelector('.detail-reply-discard').addEventListener('click', () => {
        replyBox.value = '';
        composer.hidden = true;
        actions.hidden = false;
      });
      composer.querySelector('.detail-reply-send').addEventListener('click', async event => {
        const button = event.currentTarget;
        const text = replyBox.value.trim();
        if (!text) {
          notice('Please type a reply message before sending.', true);
          replyBox.focus();
          return;
        }
        const orig = email.subject || '';
        button.disabled = true;
        try {
          await api('emails', {
            method: 'POST',
            body: JSON.stringify({
              recipient,
              subject: orig.toLowerCase().startsWith('re:') ? orig : 'Re: ' + orig,
              body: text,
              idempotency_key: crypto.randomUUID(),
              send_now: true
            })
          });
          notice('Reply sent successfully via Hostinger SMTP!');
          await openEmailDetail(id);
        } catch (err) {
          notice('Failed to send reply: ' + err.message, true);
          button.disabled = false;
        }
      });
      actions.querySelector('[data-detail-forward]').addEventListener('click', () => {
        dialog.close();
        view('compose');
        const orig = email.subject || '';
        const subjInput = $('input[name="subject"]');
        if (subjInput) subjInput.value = orig.toLowerCase().startsWith('fwd:') ? orig : 'Fwd: ' + orig;
      });
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

function renderDonutChart(accepted, bounced, pending) {
  const container = $('#overview-donut-container');
  if (!container) return;
  const total = Math.max(0, accepted) + Math.max(0, bounced) + Math.max(0, pending);
  const share = (count) => total > 0 ? (count / total) * 100 : 0;
  const pct = total > 0 ? Math.round(share(accepted) * 10) / 10 : 100;
  const circ = 2 * Math.PI * 40;
  const offset = circ - (Math.min(100, Math.max(0, pct)) / 100) * circ;

  container.innerHTML = `
    <svg width="140" height="140" viewBox="0 0 100 100">
      <circle cx="50" cy="50" r="40" fill="transparent" stroke="#f0f3f0" stroke-width="12"/>
      <circle cx="50" cy="50" r="40" fill="transparent" stroke="#285c43" stroke-width="12"
              stroke-dasharray="${circ}" stroke-dashoffset="${offset}"
              stroke-linecap="round" transform="rotate(-90 50 50)"/>
      <text x="50" y="47" text-anchor="middle" dominant-baseline="middle" font-size="18" font-weight="700" fill="#1f2923">${pct}%</text>
      <text x="50" y="62" text-anchor="middle" dominant-baseline="middle" font-size="8.5" font-weight="700" fill="#66786c">ACCEPTED</text>
    </svg>
  `;

  if ($('#ov-ratio-sent')) $('#ov-ratio-sent').textContent = `${accepted} (${pct}%)`;
  if ($('#ov-ratio-failed')) $('#ov-ratio-failed').textContent = `${bounced} (${share(bounced).toFixed(1)}%)`;
  if ($('#ov-ratio-pending')) $('#ov-ratio-pending').textContent = `${pending} (${share(pending).toFixed(1)}%)`;
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
    const isNameSameAsEmail = !c.from_name || c.from_name.trim().toLowerCase() === (c.from_email || '').trim().toLowerCase();
    const displayName = isNameSameAsEmail ? (c.from_email || 'Contact').split('@')[0] : c.from_name;
    const emailTag = `<span class="dash-contact-email" title="${escapeHtml(c.from_email)}">&lt;${escapeHtml(c.from_email)}&gt;</span>`;
    const av = getAvatarStyle(displayName || c.from_email);
    const initial = (displayName || c.from_email || 'C').trim().charAt(0).toUpperCase();
    const timeFormatted = c.received_at ? new Date(c.received_at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '';
    let cleanSnippet = (c.snippet || '')
      .replace(/<style\b[^>]*>.*?<\/style>/gis, '')
      .replace(/<script\b[^>]*>.*?<\/script>/gis, '')
      .replace(/\{[^}]*\}/g, '')
      .replace(/(?:\.|\n|\r|^|\s)\s*On\s+[A-Za-z]+,\s+[A-Za-z0-9,:\s]+wrote:.*$/is, '.')
      .replace(/(?:\.|\n|\r|^|\s)\s*On\s+(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun).*?wrote:.*$/is, '.')
      .replace(/\s*>\s+.*$/s, '')
      .replace(/^[a-z0-9_\-,\s]+;\s*/i, '')
      .trim();
    if (!cleanSnippet || cleanSnippet === '.') cleanSnippet = 'Message received';

    item.innerHTML = `
      <div class="dash-contact-left">
        <div class="dash-contact-avatar" style="background:${av.bg};color:${av.text}">${initial}</div>
        <div class="dash-contact-info">
          <div class="dash-contact-name">${escapeHtml(displayName)} ${emailTag}</div>
          <div class="dash-contact-snippet" title="${escapeHtml(cleanSnippet)}">${escapeHtml(cleanSnippet)}</div>
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

    if ($('#ov-subtitle') && currentSession?.user) {
      if (currentSession.user.role === 'member') {
        $('#ov-subtitle').textContent = `Viewing email operations & metrics for ${currentSession.user.email}`;
      } else {
        $('#ov-subtitle').textContent = 'Real-time metrics, engagement volume, and deliverability status';
      }
    }

    const counts = data.counts || {};
    const bounces = counts.bounces ?? 0;
    const failed = counts.failed ?? 0;
    const pending = (counts.pending ?? 0) + (counts.processing ?? 0);
    const replies = counts.inbox_replies ?? counts.inbox_total ?? 0;
    const week = data.week_totals || {};

    if ($('#ov-stat-sent')) $('#ov-stat-sent').textContent = counts.sent ?? 0;
    if ($('#ov-stat-inbound')) $('#ov-stat-inbound').textContent = replies;
    if ($('#ov-stat-inbox-badge')) $('#ov-stat-inbox-badge').textContent = `● ${counts.client_unread ?? counts.inbox_unread ?? 0} unread`;
    if ($('#ov-stat-response-rate')) $('#ov-stat-response-rate').textContent = `${counts.response_rate ?? 0}%`;
    if ($('#ov-stat-response-label')) {
      const replied = counts.replied_recipients ?? 0;
      const recipients = counts.sent_recipients ?? 0;
      $('#ov-stat-response-label').textContent = recipients > 0
        ? `${replied} of ${recipients} recipients replied`
        : 'No sent recipients yet';
    }
    if ($('#ov-stat-delivery-label')) $('#ov-stat-delivery-label').textContent = `${counts.delivery_rate ?? 100}% accepted by Hostinger`;

    const healthRate = counts.health_rate ?? counts.delivery_rate ?? 100;
    if ($('#ov-stat-health')) $('#ov-stat-health').textContent = `${healthRate}%`;
    if ($('#ov-stat-health-badge')) {
      const faults = bounces + failed;
      $('#ov-stat-health-badge').textContent = faults === 0 ? 'No bounce notices' : `${bounces} bounce notices`;
      const badgeWrap = $('#ov-stat-health-badge').closest('.dash-badge');
      if (badgeWrap) badgeWrap.className = faults === 0 ? 'dash-badge green' : 'dash-badge red';
    }

    if ($('#overview-sync-status')) {
      const synced = data.last_sync ? new Date(data.last_sync) : null;
      const label = synced && !Number.isNaN(synced.getTime())
        ? `Last inbox sync ${synced.toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}`
        : 'Inbox has not been synced from this desk yet';
      $('#overview-sync-status').innerHTML = `
        <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#34a853"></span>
        <span>${label}</span>
      `;
    }
    if ($('#ov-peak-window') && data.peak_window) {
      $('#ov-peak-window').textContent = data.peak_window;
    }

    if ($('#ov-legend-sent')) $('#ov-legend-sent').textContent = `Sent these 7 days (${week.sent ?? 0})`;
    if ($('#ov-legend-received')) $('#ov-legend-received').textContent = `Client replies these 7 days (${week.received ?? 0})`;

    renderTrafficChart(data.traffic_7d || []);
    renderDonutChart(counts.sent ?? 0, bounces + failed, pending);
    renderHotContacts(data.hot_contacts || []);

    // Infrastructure Status
    if (data.system_health) {
      if ($('#ov-health-smtp')) $('#ov-health-smtp').textContent = `${data.system_health.smtp_host} · ${data.system_health.smtp_user} · Active`;
      if ($('#ov-health-imap')) $('#ov-health-imap').textContent = `${data.system_health.imap_host} · Synced · ${data.system_health.imap_total} stored emails`;
      if ($('#ov-health-sheets')) $('#ov-health-sheets').textContent = `${data.system_health.sheets_count} Sheets connected · Live sync active`;
      if ($('#ov-health-db')) $('#ov-health-db').textContent = `${pending} waiting to send`;
    }

    const summaryTitle = $('#ov-health-summary-title');
    const summarySub = $('#ov-health-summary-sub');
    if (summaryTitle && summarySub) {
      if (bounces + failed === 0) {
        summaryTitle.textContent = 'Pipeline fully operational';
        summarySub.textContent = 'Mail is leaving Hostinger and no bounce notices are stored.';
      } else {
        summaryTitle.textContent = 'Bounce notices need review';
        summarySub.textContent = `${bounces} bounce notices are in the inbox. Hostinger accepted ${counts.sent ?? 0} sends.`;
      }
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

      // Load mail accounts early
      await loadMailAccounts();

      const route = routeParts();
      const savedView = route.name || localStorage.getItem('rsl_active_view') || 'overview';
      if (!route.name && savedView === 'sheets') {
        const savedSheet = localStorage.getItem('rsl_open_sheet');
        if (savedSheet) history.replaceState(null, '', '#sheets/' + savedSheet);
      }

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
$('#login-form').addEventListener('submit', async event => {
  event.preventDefault();
  const button = event.target.querySelector('.primary');
  button.disabled = true;
  try {
    const fd = new FormData(event.target);
    const payload = { password: fd.get('password') };
    const emailVal = fd.get('email');
    if (emailVal && emailVal.trim()) {
      payload.email = emailVal.trim();
    }
    await api('login', { method: 'POST', body: JSON.stringify(payload) });
    event.target.reset();
    $('#notice').hidden = true;
    await init();
  } catch(e) {
    notice(e.message, true);
  } finally {
    button.disabled = false;
  }
});

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

$('#compose-form').addEventListener('input', () => {
  const error = $('#compose-form-error');
  if (error) error.hidden = true;
});

$('#compose-form').addEventListener('submit', async event => {
  event.preventDefault();
  const form = event.target;
  const error = $('#compose-form-error');
  const missing = [
    [form.recipient, 'a recipient'],
    [form.subject, 'a subject'],
    [form.body, 'a message'],
  ].filter(([field]) => !field.value.trim());
  if (missing.length) {
    if (error) {
      const labels = missing.map(([, label]) => label);
      const list = labels.length === 1 ? labels[0] : labels.slice(0, -1).join(', ') + ' and ' + labels.at(-1);
      error.textContent = 'Add ' + list + ' before sending.';
      error.hidden = false;
    }
    missing[0][0].focus();
    return;
  }
  if (error) error.hidden = true;
  const button = form.querySelector('#compose-submit-btn') || form.querySelector('.primary');
  const label = button ? (button.querySelector('span') || button) : null;
  const originalText = label ? label.textContent : 'Send email';
  if (button) button.disabled = true;
  if (label) label.textContent = 'Sending…';

  try {
    const input = Object.fromEntries(new FormData(event.target));
    if (currentSession?.user?.role === 'member' && currentSession?.user?.assigned_account_id) {
      input.mail_account_id = currentSession.user.assigned_account_id;
    } else if (input.mail_account_id) {
      input.mail_account_id = Number(input.mail_account_id);
    }
    input.idempotency_key = requestKey;
    input.send_now = true;
    if (currentAttachments.length) {
      input.attachments = currentAttachments;
    }
    const result = await api('emails', {method:'POST', body:JSON.stringify(input)});
    requestKey = crypto.randomUUID();
    event.target.reset();
    populateComposeSender(currentMailAccounts);
    currentAttachments = [];
    renderComposeAttachments();

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
  populateComposeSender(currentMailAccounts);
  currentAttachments = [];
  renderComposeAttachments();
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
  });
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
    const mailAccountId = $('#sent-account-filter')?.value || '';
    const params = new URLSearchParams({ page: sentPage, page_size: size, search, status: 'sent' });
    if (mailAccountId) params.set('mail_account_id', mailAccountId);
    const from = sentRangeStart($('#sent-date-filter')?.value || '');
    if (from) params.set('from', from);
    const data = await api('emails?' + params);
    if (request !== sentRequest) return;
    const p = data.pagination;
    sentPage = p.page; sentPages = p.pages;
    renderSentRows(data.emails);
    const rangeText = p.total ? `${p.from}–${p.to} of ${p.total} sent emails` : '0 sent emails';
    if (rangeEl) rangeEl.textContent = rangeText;
    if ($('#sent-page')) $('#sent-page').textContent = `Page ${p.page} of ${p.pages}`;
    const isFirst = p.page <= 1;
    const isLast = p.page >= p.pages;
    if (prevBtn) prevBtn.disabled = isFirst;
    if (nextBtn) nextBtn.disabled = isLast;
  } catch(e) {
    if (request === sentRequest) {
      if (rangeEl) rangeEl.textContent = 'Could not load sent emails.';
    }
  }
}

function renderSentRows(list) {
  const tbody = $('#sent-rows');
  if (!tbody) return;
  const mailboxCount = [...($('#sent-account-filter')?.options || [])].filter(option => option.value).length;
  const showVia = !$('#sent-account-filter')?.value && mailboxCount > 1;
  const viaHead = $('#sent-via-head');
  if (viaHead) viaHead.hidden = !showVia;
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
        </div>
      </div>
    `;

    // Sent via mailbox cell
    const senderTd = document.createElement('td');
    senderTd.className = 'sent-cell-sender';
    const senderLabel = email.sender_name || email.sender_email || 'Default Mailbox';
    senderTd.innerHTML = `
      <span class="inbox-mailbox-badge" title="${escapeHtml(email.sender_email || '')}" style="margin:0;max-width:140px;display:inline-block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
        ${escapeHtml(senderLabel)}
      </span>
    `;

    // Subject & preview snippet (Gmail style)
    const subjectTd = document.createElement('td');
    subjectTd.className = 'sent-cell-subject';
    const snippet = (email.snippet || '').replace(/\s+/g, ' ').trim();
    const threadPill = Number(email.thread_count) > 1 ? `<span class="thread-count-badge">${email.thread_count}</span>` : '';
    subjectTd.innerHTML = `
      <div class="sent-subject-block">
        <div class="sent-subject-line">
          <span class="inbox-subject-text">${escapeHtml(email.subject || '(No subject)')}</span>
          ${threadPill}
        </div>
        ${snippet ? `<div class="sent-snippet">${escapeHtml(snippet)}</div>` : ''}
      </div>
    `;

    // Dispatched date cell
    const dateTd = document.createElement('td');
    dateTd.className = 'sent-cell-date';
    dateTd.textContent = new Date(email.sent_at || email.created_at).toLocaleString([], {
      month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
    });

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

    tr.append(recipientTd, subjectTd, dateTd, actionTd);
    if (showVia) tr.insertBefore(senderTd, subjectTd);
    tbody.append(tr);
  }
  if (!list.length) {
    const tr = document.createElement('tr');
    const td = cell(''); td.colSpan = showVia ? 5 : 4; td.className = 'empty';
    td.innerHTML = '<div class="empty-icon"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg></div><h3>No sent emails found</h3><p>Emails you send from Google Sheets or manual compose will appear here.</p>';
    tr.append(td); tbody.append(tr);
  }
}

function sentRangeStart(preset) {
  if (!preset) return '';
  const start = new Date();
  start.setHours(0, 0, 0, 0);
  if (preset === '7') start.setDate(start.getDate() - 6);
  if (preset === '30') start.setDate(start.getDate() - 29);
  return start.toISOString();
}

$('#sent-account-filter')?.addEventListener('change', () => {
  sentPage = 1;
  loadSentPage().catch(e => notice(e.message, true));
});
$('#sent-date-filter')?.addEventListener('change', () => {
  sentPage = 1;
  loadSentPage().catch(e => notice(e.message, true));
});

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
  const name = routeParts().name || 'overview';
  if (document.getElementById(name)) view(name);
});

/* ==========================================================================
   MAIL ACCOUNTS & MULTI-ACCOUNT MANAGEMENT
   ========================================================================== */
let currentMailAccounts = [];

window.getActiveSenderAccount = function() {
  const confirmSelect = $('#send-confirm-sender-select');
  if (confirmSelect && confirmSelect.value) {
    const found = currentMailAccounts.find(a => String(a.id) === String(confirmSelect.value));
    if (found) return found;
  }
  const sheetSelect = $('#sheet-sender-select');
  if (sheetSelect && sheetSelect.value) {
    const found = currentMailAccounts.find(a => String(a.id) === String(sheetSelect.value));
    if (found) return found;
  }
  const senderSelect = $('#compose-sender');
  if (senderSelect && senderSelect.value) {
    const found = currentMailAccounts.find(a => String(a.id) === String(senderSelect.value));
    if (found) return found;
  }
  return currentMailAccounts[0] || null;
};

async function loadMailAccounts() {
  try {
    const res = await api('mail-accounts');
    currentMailAccounts = Array.isArray(res) ? res : (res.accounts || []);
    if (currentMailAccounts.length === 0) {
      currentMailAccounts = [{ id: 1, name: 'RSL Communications', email: 'ray@rslsolution.com', is_default: 1 }];
    }
    window.currentMailAccounts = currentMailAccounts;
    renderMailAccountsConfig(currentMailAccounts);
    populateAccountDropdowns(currentMailAccounts);
    if (window.setInboxAccountOptions) {
      window.setInboxAccountOptions(currentMailAccounts);
    }
    const badge = $('#mailboxes-count-badge');
    if (badge) badge.textContent = currentMailAccounts.length;
  } catch (err) {
    console.error('Failed to load mail accounts:', err);
  }
}

function populateAccountDropdowns(accounts) {
  // 1. Compose Sender Select
  const composeSelect = $('#compose-sender');
  const lockedWrap = $('#compose-sender-locked');
  if (lockedWrap) lockedWrap.hidden = true;

  if (composeSelect) {
    const curVal = composeSelect.value;
    composeSelect.replaceChildren();
    composeSelect.hidden = false;

    for (let i = 0; i < accounts.length; i++) {
      const acc = accounts[i];
      const opt = document.createElement('option');
      opt.value = acc.id;
      opt.textContent = acc.name ? `${acc.name} <${acc.email}>` : acc.email;
      if (curVal && String(curVal) === String(acc.id)) {
        opt.selected = true;
      } else if (!curVal && i === 0) {
        opt.selected = true;
      }
      composeSelect.append(opt);
    }
  }

  // 2. Sent emails Account Filter Select
  const sentSelect = $('#sent-account-filter');
  if (sentSelect) {
    const curVal = sentSelect.value;
    sentSelect.replaceChildren();
    const allOpt = document.createElement('option');
    allOpt.value = '';
    allOpt.textContent = 'All Mailboxes';
    sentSelect.append(allOpt);

    for (const acc of accounts) {
      const opt = document.createElement('option');
      opt.value = acc.id;
      opt.textContent = acc.name ? `${acc.name} (${acc.email})` : acc.email;
      if (curVal && String(curVal) === String(acc.id)) opt.selected = true;
      sentSelect.append(opt);
    }
  }

  // 3. Sheet Workspace Sender Select
  const sheetSelect = $('#sheet-sender-select');
  if (sheetSelect) {
    const curVal = sheetSelect.value || composeSelect?.value;
    sheetSelect.replaceChildren();
    for (let i = 0; i < accounts.length; i++) {
      const acc = accounts[i];
      const opt = document.createElement('option');
      opt.value = acc.id;
      opt.textContent = acc.name ? `${acc.name} (${acc.email})` : acc.email;
      if (curVal && String(curVal) === String(acc.id)) opt.selected = true;
      else if (!curVal && i === 0) opt.selected = true;
      sheetSelect.append(opt);
    }
    if (!sheetSelect.dataset.synced) {
      sheetSelect.dataset.synced = 'true';
      sheetSelect.addEventListener('change', () => {
        if (composeSelect) composeSelect.value = sheetSelect.value;
        const confirmSel = $('#send-confirm-sender-select');
        if (confirmSel) confirmSel.value = sheetSelect.value;
      });
    }
  }

  // 4. Send Confirm Dialog Sender Select
  const confirmSelect = $('#send-confirm-sender-select');
  if (confirmSelect) {
    const curVal = confirmSelect.value || composeSelect?.value;
    confirmSelect.replaceChildren();
    for (let i = 0; i < accounts.length; i++) {
      const acc = accounts[i];
      const opt = document.createElement('option');
      opt.value = acc.id;
      opt.textContent = acc.name ? `${acc.name} (${acc.email})` : acc.email;
      if (curVal && String(curVal) === String(acc.id)) opt.selected = true;
      else if (!curVal && i === 0) opt.selected = true;
      confirmSelect.append(opt);
    }
    if (!confirmSelect.dataset.synced) {
      confirmSelect.dataset.synced = 'true';
      confirmSelect.addEventListener('change', () => {
        if (composeSelect) composeSelect.value = confirmSelect.value;
        const shSel = $('#sheet-sender-select');
        if (shSel) shSel.value = confirmSelect.value;
      });
    }
  }

  // Sync compose select change back
  if (composeSelect && !composeSelect.dataset.synced) {
    composeSelect.dataset.synced = 'true';
    composeSelect.addEventListener('change', () => {
      const shSel = $('#sheet-sender-select');
      if (shSel) shSel.value = composeSelect.value;
      const confirmSel = $('#send-confirm-sender-select');
      if (confirmSel) confirmSel.value = composeSelect.value;
    });
  }
}

function renderMailAccountsConfig(accounts) {
  const tbody = $('#mailboxes-table-body');
  if (!tbody) return;
  tbody.replaceChildren();

  if (!accounts || accounts.length === 0) {
    tbody.innerHTML = '<tr><td colspan="5" class="muted" style="text-align:center;padding:24px">No mail accounts found. Click "Connect Mailbox" to add one.</td></tr>';
    return;
  }

  const canManage = true;

  for (const acc of accounts) {
    const tr = document.createElement('tr');
    
    // Account cell
    const tdAccount = document.createElement('td');
    tdAccount.innerHTML = `
      <div style="display:flex;align-items:center">
        <div>
          <strong style="color:#213129;display:block">${escapeHtml(acc.name || 'Mail Account')}</strong>
          <small class="muted" style="font-size:12px">${escapeHtml(acc.email)}</small>
        </div>
      </div>
    `;

    // SMTP cell
    const tdSmtp = document.createElement('td');
    tdSmtp.innerHTML = `<span style="font-family:monospace;font-size:12px">${escapeHtml(acc.smtp_host)}:${acc.smtp_port}</span> <span class="dash-badge green" style="font-size:10px">SSL</span>`;

    // IMAP cell
    const tdImap = document.createElement('td');
    tdImap.innerHTML = `<span style="font-family:monospace;font-size:12px">${escapeHtml(acc.imap_host)}:${acc.imap_port}</span> <span class="dash-badge green" style="font-size:10px">SSL</span>`;

    // Status cell
    const tdStatus = document.createElement('td');
    const statusClass = acc.status === 'active' ? 'green' : 'amber';
    tdStatus.innerHTML = `<span class="dash-badge ${statusClass}">${escapeHtml(acc.status.toUpperCase())}</span>`;

    // Actions cell
    const tdActions = document.createElement('td');
    tdActions.style.textAlign = 'right';
    tdActions.style.paddingRight = '16px';
    const group = document.createElement('div');
    group.className = 'table-btn-group';

    // Test button
    const testBtn = document.createElement('button');
    testBtn.type = 'button';
    testBtn.className = 'quiet';
    testBtn.textContent = 'Test';
    testBtn.title = 'Test connection to Hostinger SMTP & IMAP';
    testBtn.addEventListener('click', async () => {
      testBtn.disabled = true;
      testBtn.textContent = 'Testing…';
      try {
        const res = await api('mail-accounts/test', {
          method: 'POST',
          body: JSON.stringify({ id: acc.id })
        });
        notice(`✓ Connection verified: SMTP (${res.smtp}) & IMAP (${res.imap}) online.`);
      } catch (err) {
        notice(`Connection test failed: ${err.message}`, true);
      } finally {
        testBtn.disabled = false;
        testBtn.textContent = 'Test';
      }
    });
    group.append(testBtn);

    if (canManage) {
      // Edit button
      const editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.className = 'quiet';
      editBtn.textContent = 'Edit';
      editBtn.addEventListener('click', () => openMailboxModal(acc));
      group.append(editBtn);

      // Delete button (available for ALL accounts dynamically)
      const delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'quiet';
      delBtn.style.color = '#c5221f';
      delBtn.textContent = 'Delete';
      delBtn.addEventListener('click', async () => {
        if (!confirm(`Are you sure you want to disconnect mailbox "${acc.email}"?`)) return;
        try {
          await api(`mail-accounts/${acc.id}`, { method: 'DELETE' });
          notice(`Mailbox ${acc.email} disconnected.`);
          await loadMailAccounts();
        } catch (err) {
          notice(err.message, true);
        }
      });
      group.append(delBtn);
    }

    tdActions.append(group);
    tr.append(tdAccount, tdSmtp, tdImap, tdStatus, tdActions);
    tbody.append(tr);
  }
}

function openMailboxModal(acc = null) {
  const modal = $('#mailbox-modal');
  const title = $('#mailbox-modal-title');
  const banner = $('#mb-test-result');
  if (!modal) return;

  $('#mailbox-form').reset();
  if (banner) { banner.hidden = true; banner.textContent = ''; banner.className = 'modal-test-banner'; }

  if (acc) {
    if (title) title.textContent = 'Edit Mailbox Configuration';
    $('#mailbox-id').value = acc.id;
    $('#mb-name').value = acc.name || '';
    $('#mb-email').value = acc.email || '';
    $('#mb-smtp-host').value = acc.smtp_host || 'smtp.hostinger.com';
    $('#mb-smtp-port').value = acc.smtp_port || 465;
    $('#mb-smtp-user').value = acc.smtp_user || '';
    $('#mb-smtp-pass').value = '';
    $('#mb-same-creds').checked = (acc.smtp_host === acc.imap_host && acc.smtp_user === acc.imap_user);
    $('#mb-imap-host').value = acc.imap_host || 'imap.hostinger.com';
    $('#mb-imap-port').value = acc.imap_port || 993;
    $('#mb-imap-user').value = acc.imap_user || '';
    $('#mb-imap-pass').value = '';
    $('#mb-imap-section').hidden = $('#mb-same-creds').checked;
  } else {
    if (title) title.textContent = 'Connect New Mailbox';
    $('#mailbox-id').value = '';
    $('#mb-name').value = '';
    $('#mb-email').value = '';
    $('#mb-smtp-host').value = 'smtp.hostinger.com';
    $('#mb-smtp-port').value = 465;
    $('#mb-smtp-user').value = '';
    $('#mb-smtp-pass').value = '';
    $('#mb-same-creds').checked = true;
    $('#mb-imap-host').value = 'imap.hostinger.com';
    $('#mb-imap-port').value = 993;
    $('#mb-imap-user').value = '';
    $('#mb-imap-pass').value = '';
    $('#mb-imap-section').hidden = true;
  }

  modal.showModal();
}

// Mailbox Modal Events
$('#btn-add-mailbox')?.addEventListener('click', () => openMailboxModal());
$('#close-mailbox-modal')?.addEventListener('click', () => $('#mailbox-modal')?.close());
$('#btn-cancel-mailbox')?.addEventListener('click', () => $('#mailbox-modal')?.close());

$('#mb-same-creds')?.addEventListener('change', e => {
  const imapSec = $('#mb-imap-section');
  if (imapSec) imapSec.hidden = e.target.checked;
});

$('#btn-test-mailbox')?.addEventListener('click', async () => {
  const banner = $('#mb-test-result');
  const testBtn = $('#btn-test-mailbox');
  if (banner) {
    banner.hidden = false;
    banner.className = 'modal-test-banner testing';
    banner.textContent = 'Testing socket connection to Hostinger SMTP & IMAP…';
  }
  if (testBtn) testBtn.disabled = true;

  try {
    const isSame = $('#mb-same-creds').checked;
    const email = $('#mb-email').value.trim();
    const smtpUser = $('#mb-smtp-user').value.trim() || email;
    const smtpPass = $('#mb-smtp-pass').value;
    const payload = {
      id: $('#mailbox-id').value ? Number($('#mailbox-id').value) : null,
      email: email,
      smtp_host: $('#mb-smtp-host').value.trim(),
      smtp_port: Number($('#mb-smtp-port').value),
      smtp_user: smtpUser,
      smtp_pass: smtpPass,
      imap_host: isSame ? $('#mb-smtp-host').value.trim() : $('#mb-imap-host').value.trim(),
      imap_port: isSame ? 993 : Number($('#mb-imap-port').value),
      imap_user: isSame ? smtpUser : $('#mb-imap-user').value.trim(),
      imap_pass: isSame ? smtpPass : $('#mb-imap-pass').value
    };

    const res = await api('mail-accounts/test', {
      method: 'POST',
      body: JSON.stringify(payload)
    });

    if (banner) {
      banner.className = 'modal-test-banner success';
      banner.textContent = `✓ Success! Hostinger SMTP connected (${res.smtp}) and IMAP connected (${res.imap}).`;
    }
  } catch (err) {
    if (banner) {
      banner.className = 'modal-test-banner error';
      banner.textContent = `✕ Connection test failed: ${err.message}`;
    }
  } finally {
    if (testBtn) testBtn.disabled = false;
  }
});

$('#mailbox-form')?.addEventListener('submit', async event => {
  event.preventDefault();
  const btn = $('#btn-save-mailbox');
  if (btn) btn.disabled = true;

  try {
    const isSame = $('#mb-same-creds').checked;
    const email = $('#mb-email').value.trim();
    const smtpUser = $('#mb-smtp-user').value.trim() || email;
    const smtpPass = $('#mb-smtp-pass').value;
    const idVal = $('#mailbox-id').value;

    const payload = {
      name: $('#mb-name').value.trim(),
      email: email,
      smtp_host: $('#mb-smtp-host').value.trim(),
      smtp_port: Number($('#mb-smtp-port').value),
      smtp_user: smtpUser,
      imap_host: isSame ? $('#mb-smtp-host').value.trim() : $('#mb-imap-host').value.trim(),
      imap_port: isSame ? 993 : Number($('#mb-imap-port').value),
      imap_user: isSame ? smtpUser : $('#mb-imap-user').value.trim()
    };
    if (idVal) payload.id = Number(idVal);
    if (smtpPass) payload.smtp_pass = smtpPass;
    const imapPass = isSame ? smtpPass : $('#mb-imap-pass').value;
    if (imapPass) payload.imap_pass = imapPass;

    await api('mail-accounts', {
      method: 'POST',
      body: JSON.stringify(payload)
    });

    notice(idVal ? `Mailbox ${email} updated successfully.` : `Mailbox ${email} connected successfully!`);
    $('#mailbox-modal')?.close();
    await loadMailAccounts();
  } catch (err) {
    const banner = $('#mb-test-result');
    if (banner) {
      banner.hidden = false;
      banner.className = 'modal-test-banner error';
      banner.textContent = err.message;
    } else {
      notice(err.message, true);
    }
  } finally {
    if (btn) btn.disabled = false;
  }
});

installDeskMenus();
init();

function installDeskMenus() {
  let menu = null;
  let openSelect = null;
  let activeIndex = 0;

  const choices = () => menu ? [...menu.querySelectorAll('button')] : [];

  function closeMenu() {
    menu?.remove();
    menu = null;
    openSelect = null;
  }

  function placeMenu() {
    if (!menu || !openSelect) return;
    const rect = openSelect.getBoundingClientRect();
    const margin = 8;
    menu.style.minWidth = Math.max(rect.width, 180) + 'px';
    menu.style.left = Math.max(margin, rect.left) + 'px';
    menu.style.top = (rect.bottom + 4) + 'px';
    const box = menu.getBoundingClientRect();
    if (box.right > window.innerWidth - margin) {
      menu.style.left = Math.max(margin, window.innerWidth - box.width - margin) + 'px';
    }
    if (box.bottom > window.innerHeight - margin) {
      menu.style.top = Math.max(margin, rect.top - box.height - 4) + 'px';
    }
  }

  function paintActive() {
    choices().forEach((button, index) => {
      button.classList.toggle('is-active', index === activeIndex);
    });
    choices()[activeIndex]?.scrollIntoView({ block: 'nearest' });
  }

  function choose(index) {
    const button = choices()[index];
    const select = openSelect;
    if (!button || !select || button.disabled) return;
    select.value = button.dataset.value ?? '';
    closeMenu();
    select.focus({ preventScroll: true });
    select.dispatchEvent(new Event('input', { bubbles: true }));
    select.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function openMenu(select) {
    closeMenu();
    const options = [...select.options].filter(option => !option.hidden);
    if (!options.length) return;
    const host = select.closest('dialog[open]') || document.body;
    menu = document.createElement('div');
    menu.className = 'desk-menu';
    menu.setAttribute('role', 'listbox');
    openSelect = select;
    let group = '';
    options.forEach(option => {
      const parent = option.parentElement;
      const label = parent && parent.tagName === 'OPTGROUP' ? parent.label : '';
      if (label && label !== group) {
        group = label;
        const heading = document.createElement('div');
        heading.className = 'desk-menu-label';
        heading.textContent = label;
        menu.append(heading);
      }
      const button = document.createElement('button');
      button.type = 'button';
      button.setAttribute('role', 'option');
      button.dataset.value = option.value;
      button.disabled = option.disabled;
      const current = option.index === select.selectedIndex;
      button.classList.toggle('is-current', current);
      button.setAttribute('aria-selected', current ? 'true' : 'false');
      const name = document.createElement('span');
      name.textContent = option.text;
      const mark = document.createElement('span');
      mark.className = 'desk-menu-mark';
      mark.textContent = current ? '✓' : '';
      mark.setAttribute('aria-hidden', 'true');
      button.append(name, mark);
      button.addEventListener('click', () => choose(choices().indexOf(button)));
      menu.append(button);
    });
    host.append(menu);
    activeIndex = Math.max(0, choices().findIndex(button => button.classList.contains('is-current')));
    placeMenu();
    paintActive();
    select.focus({ preventScroll: true });
  }

  document.addEventListener('mousedown', event => {
    const target = event.target instanceof Element ? event.target : null;
    const select = target?.closest('select') || null;
    const inMenu = Boolean(menu && target && menu.contains(target));
    if (menu && !inMenu && select !== openSelect) closeMenu();
    if (!select || select.disabled || select.multiple || Number(select.size) > 1 || event.button !== 0) return;
    event.preventDefault();
    if (openSelect === select) {
      closeMenu();
      return;
    }
    openMenu(select);
  }, true);

  document.addEventListener('keydown', event => {
    const select = event.target instanceof Element ? event.target.closest('select') : null;
    const canOpen = select && !select.disabled && !select.multiple && Number(select.size) <= 1;
    if (!menu && canOpen && (event.key === 'Enter' || event.key === ' ' || event.key === 'F4' || (event.altKey && (event.key === 'ArrowDown' || event.key === 'ArrowUp')))) {
      event.preventDefault();
      openMenu(select);
      return;
    }
    if (!menu) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      const selectToFocus = openSelect;
      closeMenu();
      selectToFocus?.focus({ preventScroll: true });
    } else if (event.key === 'ArrowDown') {
      event.preventDefault();
      const items = choices();
      for (let step = 1; step <= items.length; step += 1) {
        const next = (activeIndex + step) % items.length;
        if (!items[next].disabled) { activeIndex = next; break; }
      }
      paintActive();
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      const items = choices();
      for (let step = 1; step <= items.length; step += 1) {
        const next = (activeIndex - step + items.length) % items.length;
        if (!items[next].disabled) { activeIndex = next; break; }
      }
      paintActive();
    } else if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      choose(activeIndex);
    } else if (event.key === 'Tab') {
      closeMenu();
    }
  }, true);

  window.addEventListener('scroll', event => {
    if (menu && event.target instanceof Node && (event.target === menu || menu.contains(event.target))) return;
    closeMenu();
  }, true);
  window.addEventListener('resize', closeMenu);
}
