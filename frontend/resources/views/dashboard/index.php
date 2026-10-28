<?php
$isAuthenticated = !empty($_SESSION['authenticated']);
$isSetupAllowed = empty(getenv('ADMIN_PASSWORD_HASH')) && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>RSL · Communication Desk</title><link rel="stylesheet" href="/assets/app.css?v=42"><script>try{var v=location.hash?location.hash.replace(/^#/,''):(localStorage.getItem('rsl_active_view')||'overview');if(v){var s=document.createElement('style');s.id='early-view-style';s.textContent='.view{display:none!important}#'+v+'{display:block!important}';document.head.appendChild(s);}}catch(e){}</script><script src="/assets/app.js?v=42" defer></script><script src="/assets/sheets.js?v=33" defer></script><script src="/assets/inbox.js?v=33" defer></script></head>
<body class="<?= $isAuthenticated ? 'is-authenticated' : 'is-unauthenticated' ?>">
<aside><a class="brand" href="/"><img src="/assets/rsl-logo.svg" alt="RSL" class="sidebar-rsl-logo" width="76" height="38"><span>WORKSPACE</span></a><div class="workspace">Communication Desk<small>EMAIL OPERATIONS</small></div><nav aria-label="Main navigation">
<button class="nav active" data-view="overview"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg><span>Overview</span></button>
<button class="nav" data-view="inbox"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 12h-6l-2 3h-4l-2-3H2v7a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-7Z"/><path d="M5.45 5.11 2 12v0h20l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg><span>Inbox</span><span id="nav-inbox-badge" class="badge-count" hidden>0</span></button>
<button class="nav" data-view="sent"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg><span>Sent emails</span></button>
<button class="nav" data-view="compose"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20H4V4h10M16 3l5 5-10 10H6v-5Z"/></svg><span>Compose email</span></button>
<button class="nav" data-view="sheets"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M4 9h16M4 15h16M10 9v12"/></svg><span>Sheets</span></button>
<button class="nav" data-view="integrations"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m8 12 8-8M7 15l-2 2a3 3 0 0 0 4 4l4-4M11 7l4-4a3 3 0 0 1 4 4l-2 2M8 8l8 8"/></svg><span>Integrations</span></button></nav><div class="sidebar-foot"><button id="logout" class="sidebar-logout" <?= $isAuthenticated ? '' : 'hidden' ?> title="Sign out of workspace"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg><span>Sign out</span></button><div class="sidebar-platform-label">Email Automation Platform</div></div></aside>
<main>
<div id="notice" role="status" hidden></div>
<section id="setup" <?= ($isSetupAllowed && !$isAuthenticated) ? '' : 'hidden' ?>><div class="auth-box"><div class="auth-brand-badge"><img src="/assets/rsl-logo.svg" alt="RSL Solution" class="auth-rsl-logo" width="120" height="60"><div class="auth-brand-text"><span class="auth-brand-name">WORKSPACE</span><span class="auth-brand-sub">Communication Desk</span></div></div><div class="card login-card"><div class="login-card-head"><div class="eyebrow">FIRST-TIME SETUP</div><h1 class="login-title">Create your administrator</h1><p class="login-desc">Choose your password to access the communication desk. This setup is available only from this computer.</p></div><form id="setup-form"><label>Password<input name="password" type="password" autocomplete="new-password" minlength="6" maxlength="64" required></label><label>Confirm password<input name="confirmation" type="password" autocomplete="new-password" minlength="6" maxlength="64" required></label><p class="muted" style="margin:8px 0 16px;font-size:12px;">Use at least 6 characters. Your password is stored securely as a hash.</p><button class="primary" style="width:100%;min-height:44px;">Create administrator</button></form></div><div class="auth-foot-note">RSL Communication Desk · One workspace. Every conversation.</div></div></section>
<section id="login" <?= (!$isSetupAllowed && !$isAuthenticated) ? '' : 'hidden' ?>><div class="auth-box"><div class="auth-brand-badge"><img src="/assets/rsl-logo.svg" alt="RSL Solution" class="auth-rsl-logo" width="120" height="60"><div class="auth-brand-text"><span class="auth-brand-name">WORKSPACE</span><span class="auth-brand-sub">Communication Desk</span></div></div><div class="card login-card"><div class="login-card-head"><h1 class="login-title">Welcome to your desk</h1><p class="login-desc">Sign in to manage outgoing messages and track email activity.</p></div><form id="login-form"><label><div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px;"><span style="font-size:13px;font-weight:600;color:#213129;">Administrator password</span><button type="button" id="forgot-password" class="auth-text-link">Forgot password?</button></div><input name="password" id="login-password" type="password" autocomplete="current-password" required placeholder="••••••••"></label><button class="primary auth-submit-btn" type="submit" id="login-submit-btn">Sign in to workspace →</button><p id="setup-help" class="muted" hidden style="margin-top:14px;">Set ADMIN_PASSWORD_HASH in backend/.env to enable sign-in.</p></form></div><div class="auth-foot-note">RSL Communication Desk · One workspace. Every conversation.</div></div></section>
<div id="app" <?= $isAuthenticated ? '' : 'hidden' ?>>
<section id="overview" class="view" hidden>
<div class="dash-header">
  <div>
    <div class="eyebrow">COMMUNICATION PERFORMANCE &amp; INTELLIGENCE</div>
    <h1 class="dash-title">Dashboard Overview</h1>
    <p class="muted">Real-time metrics, engagement volume, and deliverability status</p>
  </div>
  <div class="header-actions">
    <button type="button" class="quiet" id="overview-sync-btn">
      <svg class="icon sync-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5M19 12a7 7 0 0 0-12-5L4 10M5 12a7 7 0 0 0 12 5l3-3"/></svg>
      <span>Sync Inbox</span>
    </button>
    <button class="primary" data-view="compose">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
      <span>Compose email</span>
    </button>
  </div>
</div>

<div class="mode" role="status" hidden>
  <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7v1"/></svg>
  <span id="mode">Test mode enabled — emails won't be sent.</span>
</div>

<!-- 4 Visual KPI Cards -->
<div class="dash-kpi-grid">
  <div class="card dash-kpi-card">
    <div class="dash-kpi-header">
      <span>DELIVERED OUTGOING</span>
      <svg class="icon text-muted" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
    </div>
    <div class="dash-kpi-val" id="ov-stat-sent">0</div>
    <span class="dash-badge green">
      <svg style="width:10px;height:10px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="18 15 12 9 6 15"/></svg>
      <span id="ov-stat-delivery-label">100% Accepted by Hostinger</span>
    </span>
  </div>

  <div class="card dash-kpi-card">
    <div class="dash-kpi-header">
      <span>INBOUND REPLIES</span>
      <svg class="icon text-muted" viewBox="0 0 24 24"><path d="M21.2 8.4c.5.38.8.97.8 1.6v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V10a2 2 0 0 1 .8-1.6l8-6a2 2 0 0 1 2.4 0l8 6Z"/><path d="m22 10-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 10"/></svg>
    </div>
    <div class="dash-kpi-val" id="ov-stat-inbound">0</div>
    <span class="dash-badge blue">
      <span id="ov-stat-inbox-badge">● 0 Messages Synced</span>
    </span>
  </div>

  <div class="card dash-kpi-card">
    <div class="dash-kpi-header">
      <span>RESPONSE RATE</span>
      <svg class="icon text-muted" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="m16 12-4-4-4 4M12 16V8"/></svg>
    </div>
    <div class="dash-kpi-val" id="ov-stat-response-rate">0%</div>
    <span class="dash-badge green">
      <span>Active Engagement Rate</span>
    </span>
  </div>

  <div class="card dash-kpi-card">
    <div class="dash-kpi-header">
      <span>DELIVERY HEALTH</span>
      <svg class="icon text-muted" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
    </div>
    <div class="dash-kpi-val" id="ov-stat-health">0 Bounces</div>
    <span class="dash-badge green">
      <span id="ov-stat-health-badge">0 Delivery Failures</span>
    </span>
  </div>
</div>

<!-- Main Analytics Grid -->
<div class="dash-analytics-grid">
  <!-- 7-Day Trend Chart -->
  <div class="card dash-chart-card">
    <div class="dash-card-header">
      <div>
        <h2 class="dash-card-title">Communication Volume &amp; Response Curve</h2>
        <p class="dash-card-subtitle">Daily comparison of Outgoing Dispatches vs. Inbound Replies</p>
      </div>
      <div class="dash-time-pills">
        <button type="button" class="dash-time-pill active">Last 7 Days</button>
      </div>
    </div>

    <div class="dash-chart-container" id="overview-chart-container"></div>

    <div class="dash-chart-legend">
      <div class="dash-legend-item">
        <span class="dash-legend-dot" style="background:#285c43"></span>
        <span id="ov-legend-sent">Outgoing Sent</span>
      </div>
      <div class="dash-legend-item">
        <span class="dash-legend-dot" style="background:#1a73e8"></span>
        <span id="ov-legend-received">Inbound Replies</span>
      </div>
      <div class="dash-legend-item" style="margin-left:auto">
        <span>Peak communication window: <strong>3:00 PM – 5:00 PM</strong></span>
      </div>
    </div>
  </div>

  <!-- Delivery Status Donut -->
  <div class="card dash-donut-card">
    <div class="dash-card-header">
      <div>
        <h2 class="dash-card-title">Delivery Status</h2>
        <p class="dash-card-subtitle">Hostinger SMTP success ratio</p>
      </div>
    </div>

    <div class="dash-donut-wrap">
      <div id="overview-donut-container"></div>

      <div class="dash-ratio-list">
        <div class="dash-ratio-row">
          <span class="dash-ratio-label"><span class="dash-legend-dot" style="background:#285c43"></span>Accepted &amp; Delivered</span>
          <span class="dash-ratio-val" id="ov-ratio-sent">0 (100%)</span>
        </div>
        <div class="dash-ratio-row">
          <span class="dash-ratio-label"><span class="dash-legend-dot" style="background:#d93025"></span>Bounces / Rejected</span>
          <span class="dash-ratio-val" id="ov-ratio-failed">0 (0%)</span>
        </div>
        <div class="dash-ratio-row">
          <span class="dash-ratio-label"><span class="dash-legend-dot" style="background:#f9ab00"></span>Queued / Pending</span>
          <span class="dash-ratio-val" id="ov-ratio-pending">0 (0%)</span>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Bottom Grid -->
<div class="dash-bottom-grid">
  <!-- Active Hot Contacts -->
  <div class="card">
    <div class="dash-card-header">
      <div>
        <h2 class="dash-card-title">Hot Conversations &amp; Top Engaged Leads</h2>
        <p class="dash-card-subtitle">Contacts who replied and have active conversation threads</p>
      </div>
      <button type="button" class="quiet" data-view="inbox" style="padding:6px 12px;font-size:12px">View all in Inbox</button>
    </div>

    <div class="dash-contacts-list" id="overview-hot-contacts"></div>
  </div>

  <!-- System Health -->
  <div class="card">
    <div class="dash-card-header">
      <div>
        <h2 class="dash-card-title">System &amp; Pipeline Health</h2>
        <p class="dash-card-subtitle">Real-time status of connected mail services</p>
      </div>
    </div>

    <div class="dash-health-list">
      <div class="dash-health-item">
        <span class="dash-health-dot online"></span>
        <div>
          <div class="dash-health-title">Hostinger SMTP Outgoing</div>
          <div class="dash-health-meta" id="ov-health-smtp">smtp.hostinger.com:465 · Active</div>
        </div>
      </div>

      <div class="dash-health-item">
        <span class="dash-health-dot online"></span>
        <div>
          <div class="dash-health-title">Hostinger IMAP Inbox Polling</div>
          <div class="dash-health-meta" id="ov-health-imap">imap.hostinger.com:993 · Live sync active</div>
        </div>
      </div>

      <div class="dash-health-item">
        <span class="dash-health-dot online"></span>
        <div>
          <div class="dash-health-title">Google Sheets &amp; Excel Hub</div>
          <div class="dash-health-meta" id="ov-health-sheets">Live sync active · Real-time status sync</div>
        </div>
      </div>

      <div class="dash-health-item">
        <span class="dash-health-dot online"></span>
        <div>
          <div class="dash-health-title">MySQL Database &amp; Queue Worker</div>
          <div class="dash-health-meta" id="ov-health-db">0 pending queue · Latency &lt; 2ms</div>
        </div>
      </div>
    </div>
  </div>
</div>
</section>
<section id="inbox" class="view" hidden>
<div class="title-row inbox-title-row"><div><div class="eyebrow">INCOMING &amp; REPLIES</div><h1 class="inbox-heading">Inbox <span id="inbox-header-badge" class="inbox-heading-badge" hidden>0</span></h1><p class="muted inbox-subheading"><span class="inbox-account-pill" id="inbox-account-pill"><span class="status-indicator-dot"></span>Connected Account</span><span id="inbox-last-sync" class="inbox-last-sync-label"></span></p></div><div class="inbox-header-actions"><button class="primary inbox-sync-btn" id="inbox-sync"><svg class="icon sync-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5M19 12a7 7 0 0 0-12-5L4 10M5 12a7 7 0 0 0 12 5l3-3"/></svg><span>Sync Inbox</span></button></div></div>
<div class="card table-card" style="margin-top:20px"><div class="queue-header"><div class="section-title"><div><h2>Incoming messages</h2><p class="muted">Click any message to read full content or send a reply</p></div></div>
<div class="queue-toolbar"><div class="search-field"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg><input id="inbox-search" type="search" placeholder="Search mail..." aria-label="Search inbox"></div><select id="inbox-filter" aria-label="Filter inbox by read status"><option value="">All messages</option><option value="unread">Unread only</option><option value="read">Read only</option></select><button class="quiet" id="inbox-refresh"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5M19 12a7 7 0 0 0-12-5L4 10M5 12a7 7 0 0 0 12 5l3-3"/></svg><span>Refresh</span></button></div>
</div><div class="table-wrap"><table><thead><tr><th style="width:220px">Sender</th><th>Subject &amp; preview</th><th style="width:95px;text-align:right">Received</th><th style="width:180px;text-align:right;padding-right:18px">Action</th></tr></thead><tbody id="inbox-rows"></tbody></table></div><div class="pagination-bar"><label>Rows per page<select id="inbox-size"><option>10</option><option selected>25</option><option>50</option><option>100</option></select></label><span id="inbox-range" aria-live="polite"></span><div class="pagination-buttons"><button id="inbox-prev" class="quiet">Previous</button><span id="inbox-page"></span><button id="inbox-next" class="quiet">Next</button></div></div></div>
</section>
<section id="sent" class="view" hidden>
<div class="title-row"><div><div class="eyebrow">OUTGOING LOG &amp; DISPATCH</div><h1>Sent emails</h1><p class="muted">Complete history of all outgoing emails sent from your connected sheets or manual compose.</p></div><button class="primary" data-view="compose"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Compose email</button></div>
<div class="card table-card"><div class="queue-header"><div class="section-title"><div><h2>Delivered messages</h2><p class="muted">Check delivery status, recipient details, and exact dispatch timestamps</p></div></div>
<div class="queue-toolbar"><div class="search-field"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg><input id="sent-search" type="search" placeholder="Search sent mail..." aria-label="Search sent emails"></div><button class="quiet" id="sent-refresh"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5M19 12a7 7 0 0 0-12-5L4 10M5 12a7 7 0 0 0 12 5l3-3"/></svg><span>Refresh</span></button></div>
</div><div class="table-wrap"><table><thead><tr><th style="width:230px">Recipient</th><th>Subject &amp; preview</th><th style="width:140px">Dispatched at</th><th style="width:90px">Status</th><th style="width:130px;text-align:right;padding-right:18px">Action</th></tr></thead><tbody id="sent-rows"></tbody></table></div><div class="pagination-bar"><label>Rows per page<select id="sent-size"><option>10</option><option selected>25</option><option>50</option><option>100</option></select></label><span id="sent-range" aria-live="polite"></span><div class="pagination-buttons"><button id="sent-prev" class="quiet">Previous</button><span id="sent-page"></span><button id="sent-next" class="quiet">Next</button></div></div></div>
</section>
<section id="thread-view" class="view" hidden>
  <div class="gmail-reader-toolbar">
    <div class="gmail-toolbar-left">
      <button type="button" class="gmail-tool-btn" id="thread-back-btn" title="Back to list">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        <span>Back</span>
      </button>
      <div class="gmail-toolbar-divider"></div>
      <button type="button" class="gmail-tool-btn icon-only" id="thread-unread-btn" title="Mark as unread">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.2 8.4c.5.38.8.97.8 1.6v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V10a2 2 0 0 1 .8-1.6l8-6a2 2 0 0 1 2.4 0l8 6Z"/><path d="m22 10-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 10"/></svg>
      </button>
    </div>
    <div class="gmail-toolbar-right">
      <button type="button" class="gmail-tool-btn icon-only" id="thread-print-btn" title="Print conversation">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      </button>
    </div>
  </div>

  <div class="card gmail-reader-canvas">
    <div class="gmail-canvas-header">
      <div class="gmail-canvas-title-row">
        <h1 id="thread-canvas-subject" class="gmail-canvas-subject"></h1>
        <span id="thread-canvas-badge" class="gmail-tag-pill">Inbox</span>
      </div>
    </div>

    <!-- Messages stream -->
    <div id="thread-canvas-stream" class="gmail-canvas-stream"></div>

    <!-- Bottom Action Pills -->
    <div class="gmail-bottom-actions" id="thread-canvas-bottom-actions">
      <button type="button" class="gmail-action-pill" id="thread-canvas-reply-btn">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5v2.5"/></svg>
        <span>Reply</span>
      </button>
      <button type="button" class="gmail-action-pill" id="thread-canvas-forward-btn">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 14 5-5-5-5"/><path d="M20 9H9.5A5.5 5.5 0 0 0 4 14.5V17"/></svg>
        <span>Forward</span>
      </button>
    </div>

    <!-- Inline Reply Composer -->
    <div id="thread-canvas-composer" class="gmail-inline-composer" hidden>
      <div class="inline-composer-header">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5v2.5"/></svg>
        <span>Reply to <strong id="thread-composer-recipient"></strong></span>
      </div>
      <textarea id="thread-composer-textarea" rows="6" placeholder="Write your reply..."></textarea>
      <div class="inline-composer-footer">
        <div class="composer-footer-left">
          <button type="button" class="primary" id="thread-composer-send-btn">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
            <span>Send</span>
          </button>
        </div>
        <div class="composer-footer-right">
          <button type="button" class="quiet icon-only" id="thread-composer-discard-btn" title="Discard draft">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
          </button>
        </div>
      </div>
    </div>
  </div>
</section>
<section id="compose" class="view" hidden>
<div class="compose-heading"><div><div class="eyebrow">NEW DISPATCH</div><h1 id="compose-title">Compose email</h1><p class="muted compose-subheading" id="compose-subtitle"><span class="inbox-account-pill" id="compose-account-pill"><span class="status-indicator-dot"></span>Connected SMTP</span><span class="subheading-sep">·</span><span>Direct SMTP dispatch</span></p></div></div>
<form id="compose-form" class="card compose-card">
<div class="compose-fields">
<div class="compose-row recipient-row"><span class="compose-label">To</span><input id="compose-to" name="recipient" type="email" required placeholder="recipient@example.com" autocomplete="email" class="compose-input"><div class="recipient-options" aria-label="Additional recipients"><button type="button" class="recipient-toggle" data-recipient="cc" aria-expanded="false" aria-controls="compose-cc-field">Cc</button><button type="button" class="recipient-toggle" data-recipient="bcc" aria-expanded="false" aria-controls="compose-bcc-field">Bcc</button></div></div>
<div class="compose-row optional-row" id="compose-cc-field" hidden><span class="compose-label">Cc</span><input name="cc" disabled placeholder="Separate multiple emails with commas" class="compose-input"></div>
<div class="compose-row optional-row" id="compose-bcc-field" hidden><span class="compose-label">Bcc</span><input name="bcc" disabled placeholder="Separate multiple emails with commas" class="compose-input"></div>
<div class="compose-row subject-row"><input name="subject" maxlength="255" required placeholder="Subject" class="compose-input compose-subject-input"></div>
<div class="compose-body-row"><textarea name="body" rows="10" required maxlength="100000" placeholder="Write your message here..." class="compose-textarea"></textarea></div>
<div id="compose-attachments" class="compose-attachments" hidden></div>
</div>
<div class="form-footer compose-actions"><div class="compose-action-left"><button type="submit" class="primary compose-send-btn" id="compose-submit-btn"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg><span id="compose-submit-label">Send email</span></button><label class="compose-tool-btn" id="compose-attach-btn" title="Attach files" aria-label="Attach files"><input type="file" id="compose-file-input" multiple hidden><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg></label><span id="compose-upload-status" class="compose-upload-status" hidden></span><span id="compose-mode" hidden></span></div><div class="compose-action-right"><button type="reset" class="compose-discard-btn" id="compose-discard-btn" title="Discard draft" aria-label="Discard draft"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M10 11v6M14 11v6"/></svg></button></div></div>
</form></section>
<section id="sheets" class="view" hidden>
<div id="sheets-directory">
<div class="title-row">
  <div>
    <div class="eyebrow">YOUR DATA SOURCES</div>
    <h1>Your sheets</h1>
    <p class="muted">Upload a spreadsheet or connect Google Sheets to manage your records.</p>
  </div>
  <div class="sheets-header-actions">
    <div id="google-status-pill" class="google-status-pill" hidden>
      <span class="status-dot"></span>
      <span id="google-status-text">Google connected</span>
      <button type="button" class="google-disconnect-link" id="google-disconnect">Disconnect</button>
    </div>
    <button class="primary" id="google-connect">Connect Google</button>
  </div>
</div>
<div id="google-status" hidden></div>

<div class="card sheet-list">
  <div class="queue-header">
    <div>
      <h2>Saved sheets</h2>
      <p class="muted">Open a source to enter its full workspace.</p>
    </div>
    <div class="queue-toolbar">
      <div class="search-field">
        <svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        <input id="saved-sheet-search" type="search" placeholder="Search saved sheets…" aria-label="Search saved sheets">
      </div>
      <button id="refresh-sources" class="quiet">Refresh</button>
    </div>
  </div>
  <div id="sheet-sources" class="sheet-sources-list"></div>
</div>

<div class="sheets-grid">
  <div class="card sheet-action-card">
    <div class="sheet-card-header">
      <div class="source-icon-badge google">
        <svg viewBox="0 0 24 24" class="sheet-type-svg"><path fill="#0F9D58" d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/><path fill="#fff" d="M9 7h6v2H9zm0 4h6v2H9zm0 4h6v2H9z"/></svg>
      </div>
      <div>
        <h2>Link a Google Sheet</h2>
        <p class="muted">Connect live Google Sheets spreadsheets with real-time sync.</p>
      </div>
    </div>
    <form id="sheet-link-form" class="sheet-link-box">
      <div class="sheet-input-group">
        <input id="sheet-url" type="url" required placeholder="https://docs.google.com/spreadsheets/d/..." class="sheet-url-input" aria-label="Google Sheet link">
        <button class="primary" type="submit" id="sheet-link-btn">Read tabs</button>
      </div>
      <div class="sheet-link-hint">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
        <span>Paste any Google Sheet URL. Tabs and columns will be detected automatically.</span>
      </div>
    </form>
  </div>

  <div class="card sheet-action-card">
    <div class="sheet-card-header">
      <div class="source-icon-badge excel">
        <svg viewBox="0 0 24 24" class="sheet-type-svg"><path fill="#107C41" d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/><path fill="#fff" d="M15.5 17l-2.4-4.5 2.3-4.5h-2.1l-1.3 3.1-1.3-3.1H8.6l2.3 4.5-2.4 4.5h2.1l1.4-3.3 1.4 3.3z"/></svg>
      </div>
      <div>
        <h2>Upload a spreadsheet</h2>
        <p class="muted">Directly import Excel (.xlsx) or CSV without Google connection.</p>
      </div>
    </div>
    <form id="sheet-upload-form" class="sheet-dropzone-form">
      <input id="sheet-file" type="file" accept=".xlsx,.csv" hidden>
      <div class="dropzone-box" id="dropzone-box">
        <svg class="dropzone-cloud-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
          <path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/>
          <path d="M12 12v9"/>
          <path d="m16 16-4-4-4 4"/>
        </svg>
        <div class="dropzone-text-group">
          <span class="dropzone-title"><strong>Click to upload</strong> or drag and drop</span>
          <span class="dropzone-hint" id="upload-limit">Excel (.xlsx) or CSV up to 5 MB</span>
        </div>
      </div>
    </form>
  </div>
</div>
<form id="sheet-map-form" class="card sheet-mapping" hidden><h2>Column mapping</h2><p id="mapping-source-label" class="muted"></p><div class="columns"><label>Tab<select id="sheet-tab" required></select></label><label>Header row<input id="sheet-header" type="number" min="1" max="100" value="6" required></label></div><button type="button" id="read-headers" class="quiet">Read columns</button><div id="sheet-mappings" class="mapping-grid"></div><button id="save-source" class="primary" hidden>Save source</button></form>
</div>
<div class="card sheet-preview" id="sheet-preview" hidden><div class="sheet-workspace-header"><button type="button" class="quiet" id="sheet-back-btn"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>All sheets</button><div class="sheet-workspace-title-wrap"><h1 id="source-title" class="sheet-workspace-title"></h1><p id="source-summary" class="muted"></p></div><div class="sheet-controls"><span id="sheet-sync-status" class="sheet-sync-pill"><svg viewBox="0 0 24 24" class="icon" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>Live Sync Active</span><button id="source-mapping" class="quiet">Column mapping</button><a id="open-sheet" class="quiet" target="_blank" rel="noopener noreferrer">Open in Google Sheets</a><button id="refresh-sheet" class="quiet">Refresh rows</button><button type="button" class="primary" id="sheet-bulk-send-btn" hidden><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg><span id="sheet-bulk-send-label">Send All Ready</span></button></div></div><p id="source-edit-note" class="muted"></p><div class="sheet-filter-tabs" id="sheet-quick-tabs"><button type="button" class="sheet-tab-btn active" data-tab-filter="">All records <span class="tab-count" id="count-all-records">0</span></button><button type="button" class="sheet-tab-btn" data-tab-filter="ready">Ready to send <span class="tab-count" id="count-ready-records">0</span></button><button type="button" class="sheet-tab-btn" data-tab-filter="sent">Sent emails <span class="tab-count" id="count-sent-records">0</span></button><button type="button" class="sheet-tab-btn" data-tab-filter="issues">Needs attention <span class="tab-count" id="count-issues-records">0</span></button></div><div class="sheet-search-toolbar"><div class="search-field"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg><input id="sheet-search" type="search" placeholder="Search ID, agency, email, or subject" aria-label="Search sheet records"></div><select id="sheet-status" aria-label="Filter sheet records"><option value="">All records</option><option value="issues">Needs attention</option><option value="ready">Ready to send</option><option value="not_queued">Not queued</option><option value="pending">Pending</option><option value="sent">Sent</option><option value="failed">Failed</option><option value="dry_run">Test emails</option></select></div><div id="batch-actions" class="batch-toolbar" hidden><div class="batch-info-group"><span id="batch-count-label">0 rows selected</span><button type="button" class="batch-link" id="batch-select-all-sheet">Select all ready across sheet</button><button type="button" class="batch-link" id="batch-clear-btn">Clear selection</button></div><button type="button" class="primary" id="batch-send-btn"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg><span>Send selected</span></button></div><div class="table-wrap"><table><thead><tr><th style="width:36px;text-align:center"><input type="checkbox" id="sheet-select-all" class="sheet-checkbox" title="Select all valid rows"></th><th>ID / agency</th><th>Email</th><th>Subject</th><th>Status</th><th>Actions</th></tr></thead><tbody id="sheet-rows"></tbody></table></div><div class="sheet-pagination pagination-bar"><label>Rows per page<select id="sheet-size"><option>10</option><option>25</option><option>50</option><option>100</option></select></label><span id="sheet-range" aria-live="polite"></span><button id="sheet-prev" class="quiet">Previous</button><span id="sheet-page"></span><button id="sheet-next" class="quiet">Next</button></div></div>
<dialog id="sheet-editor" aria-labelledby="sheet-editor-title"><form id="sheet-edit-form"><div class="queue-header"><div><h2 id="sheet-editor-title">Edit record</h2><p id="edit-record-id" class="muted"></p></div><button type="button" id="close-editor" class="quiet">Close</button></div><p class="muted">Only changed fields are saved. Keep this sheet free of simultaneous edits while saving.</p><div id="edit-fields"></div><p id="edit-error" role="alert" hidden></p><div class="form-footer"><span id="edit-save-note" class="muted">Changes are saved to this source.</span><button type="submit" class="primary">Save changes</button></div></form></dialog>
</section><section id="integrations" class="view" hidden><div class="eyebrow">CONNECTED SERVICES</div><h1>Connect your workflow.</h1><p class="muted">Active and available integrations in your platform.</p><div class="integration-grid"><article class="card"><span class="badge sent">Connected &amp; Live</span><h2>Hostinger SMTP</h2><p>Live outgoing mail via smtp.hostinger.com:465.</p></article><article class="card"><span class="badge sent">Connected &amp; Live</span><h2>Hostinger IMAP Inbox</h2><p>Live incoming synchronization via imap.hostinger.com:993.</p><button class="quiet" data-view="inbox">Open Inbox</button></article><article class="card"><span class="badge">Available to connect</span><h2>Google Sheets</h2><p>Add sheet links, map columns, and edit records from your workspace.</p><button class="quiet" data-view="sheets">Manage Google Sheets</button></article><article class="card"><span class="badge">Disabled</span><h2>Automatic replies</h2><p>Requires rule configuration and human-review workflows before it can be enabled.</p></article></div></section>
</div><footer>RSL Communication Desk <span>One workspace. Every conversation.</span></footer><dialog id="email-detail" aria-labelledby="email-detail-tag"><div class="gmail-detail-toolbar"><div class="gmail-toolbar-left"><span class="email-id-tag" id="email-detail-tag">Email</span><span class="email-delivery-pill" id="email-detail-pill">● Delivered</span></div><div class="gmail-toolbar-right"><button type="button" class="gmail-tool-btn" id="close-email-detail" aria-label="Close dialog">Close</button></div></div><div class="gmail-detail-scroll"><p id="email-detail-message" class="muted" role="status" hidden></p><div id="email-detail-content"></div></div></dialog><dialog id="inbox-detail" aria-labelledby="inbox-detail-title"><div class="queue-header"><h2 id="inbox-detail-title">Message details</h2><div class="dialog-actions"><button type="button" class="primary" id="inbox-reply-btn"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5v2.5"/></svg>Reply</button><button type="button" class="quiet" id="inbox-toggle-read-btn">Mark unread</button><button type="button" class="quiet" id="close-inbox-detail">Close</button></div></div><p id="inbox-detail-message" class="muted" role="status"></p><div id="inbox-detail-content"></div></dialog><dialog id="reset-dialog" aria-labelledby="reset-title"><div class="reset-dialog-wrap"><div class="queue-header"><h2 id="reset-title">Reset Administrator Password</h2><button type="button" id="close-reset" class="reset-close-btn" aria-label="Close dialog">✕</button></div><div id="reset-step-1"><p class="muted reset-subtitle">Enter your administrator email address. We'll send a 6-digit verification code to your inbox.</p><form id="reset-request-form"><label>Administrator email<input id="reset-req-email" name="email" type="email" placeholder="e.g. ray@rslsolution.com" required autocomplete="email"></label><p id="reset-req-error" role="alert" class="email-detail-error" hidden></p><div class="form-footer reset-footer"><button class="primary reset-submit-btn" type="submit" id="reset-req-btn">Send verification code →</button></div></form><div id="reset-local-bypass-wrap" class="reset-bypass-card" hidden><button type="button" class="reset-bypass-btn" id="reset-local-bypass-btn">Running on local computer? Reset directly →</button></div></div><div id="reset-step-2" hidden><p class="muted reset-subtitle" id="reset-sent-notice">A 6-digit code was sent to your email. Enter it below along with your new password.</p><form id="reset-verify-form"><label>6-Digit verification code<input id="reset-verify-code" name="code" type="text" pattern="[0-9]{6}" maxlength="6" placeholder="123456" required autocomplete="one-time-code"></label><label>New password<input id="reset-verify-password" name="password" type="password" autocomplete="new-password" minlength="6" maxlength="64" required placeholder="At least 6 characters"></label><label>Confirm new password<input id="reset-verify-confirm" name="confirmation" type="password" autocomplete="new-password" minlength="6" maxlength="64" required placeholder="Re-enter password"></label><p id="reset-verify-error" role="alert" class="email-detail-error" hidden></p><div class="form-footer reset-footer"><button type="button" id="reset-back-to-step1" class="reset-back-btn">← Back</button><button class="primary reset-submit-btn" type="submit" id="reset-verify-btn">Save &amp; Sign in →</button></div></form></div><div id="reset-step-local" hidden><p class="muted reset-subtitle">Local computer access verified. Set a new administrator password directly.</p><form id="reset-local-form"><label>New password<input id="reset-local-password" name="password" type="password" autocomplete="new-password" minlength="6" maxlength="64" required placeholder="At least 6 characters"></label><label>Confirm new password<input id="reset-local-confirm" name="confirmation" type="password" autocomplete="new-password" minlength="6" maxlength="64" required placeholder="Re-enter password"></label><p id="reset-local-error" role="alert" class="email-detail-error" hidden></p><div class="form-footer reset-footer"><button type="button" id="reset-local-back" class="reset-back-btn">← Back</button><button class="primary reset-submit-btn" type="submit">Save &amp; Sign in →</button></div></form></div></div></dialog></main></body></html>
