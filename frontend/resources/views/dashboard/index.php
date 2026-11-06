<?php
$isAuthenticated = !empty($_SESSION['authenticated']);
$isSetupAllowed = empty(getenv('ADMIN_PASSWORD_HASH')) && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>RSL &middot; Email Desk</title><link rel="icon" type="image/svg+xml" href="/assets/favicon.svg?v=1"><link rel="alternate icon" type="image/png" href="/assets/favicon.png?v=1"><link rel="stylesheet" href="/assets/app.css?v=112"><link rel="stylesheet" href="/assets/wa-sheets.css?v=112"><script>try{var raw=location.hash?location.hash.replace(/^#/,''):(localStorage.getItem('rsl_active_view')||'overview');var bits=String(raw).split('/');var v=bits[0].replace(/[^a-z0-9-]/gi,'');var sheetId=(bits[1]||'').replace(/[^a-z0-9-]/gi,'');var channel=localStorage.getItem('rsl_channel')||'email';if(channel!=='whatsapp'&&(v==='wa-device'||v==='wa-settings'||v==='wa-overview'||v==='wa-chats'||v==='wa-sent'||v==='message'||v==='wa-compose'))v='overview';if(channel==='whatsapp'&&v==='wa-compose')v='message';if(channel==='whatsapp'&&(v==='wa-home'||v==='wa-sheets'))v='sheets';if(!location.hash&&v==='sheets'&&!sheetId){if(channel==='whatsapp'){var wid=String(localStorage.getItem('rsl_open_wa_sheet')||'').replace(/[^0-9]/g,'');if(wid)sheetId='wa-'+wid;}else sheetId=String(localStorage.getItem('rsl_open_sheet')||'').replace(/[^a-z0-9-]/gi,'');}if(v){var s=document.createElement('style');s.id='early-view-style';s.textContent='.view{display:none!important}#'+v+'{display:block!important}';document.head.appendChild(s);}var sheetOk=v==='sheets'&&sheetId&&((channel==='whatsapp'&&sheetId.indexOf('wa-')===0)||(channel!=='whatsapp'&&sheetId.indexOf('wa-')!==0));if(sheetOk){var keep=document.createElement('style');keep.id='early-sheet-style';keep.textContent='#sheets-directory{display:none!important}#sheet-preview,#sheet-preview[hidden]{display:block!important}#sheets{padding-top:14px!important}';document.head.appendChild(keep);}}catch(e){}</script><script src="/assets/app.js?v=106" defer></script><script src="/assets/sheets.js?v=108" defer></script><script src="/assets/inbox.js?v=77" defer></script><script src="/assets/wa-sheets.js?v=89" defer></script><script src="/assets/wa-device.js?v=19" defer></script><script src="/assets/wa-sent.js?v=9" defer></script><script src="/assets/wa-chats.js?v=28" defer></script><script src="/assets/wa-overview.js?v=7" defer></script></head>
<body class="<?= $isAuthenticated ? 'is-authenticated' : 'is-unauthenticated' ?>">
<aside><a class="brand" href="/"><img src="/assets/rsl-logo.png?v=52" alt="RSL" class="sidebar-rsl-logo" width="76" height="36"><span>WORKSPACE</span></a><div class="workspace"><span id="desk-title">Email Desk</span><small id="desk-kicker">EMAIL OPERATIONS</small></div><div class="channel-switch" role="tablist" aria-label="Channel"><button type="button" class="channel-btn active" data-channel="email" aria-pressed="true">Email</button><button type="button" class="channel-btn" data-channel="whatsapp" aria-pressed="false">WhatsApp</button></div><div class="sidebar-action-wrap"><button type="button" class="sidebar-compose-btn" data-channel="email" data-view="compose"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14"/></svg><span>Compose</span></button><button type="button" class="sidebar-compose-btn" data-channel="whatsapp" data-view="message" hidden><svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14"/></svg><span>New message</span></button></div><nav aria-label="Main navigation">
<button class="nav active" data-view="overview"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg><span>Overview</span></button>
<button class="nav" data-view="inbox"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 12h-6l-2 3h-4l-2-3H2v7a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-7Z"/><path d="M5.45 5.11 2 12v0h20l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg><span>Inbox</span><span id="nav-inbox-badge" class="badge-count" hidden>0</span></button>
<button class="nav" data-view="sent"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg><span>Sent</span></button>
<button class="nav" data-view="sheets"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M4 9h16M4 15h16M10 9v12"/></svg><span>Sheets</span></button>
<button class="nav" data-channel="whatsapp" data-view="wa-overview"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg><span>Dashboard</span></button>
<button class="nav" data-channel="whatsapp" data-view="wa-chats"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/></svg><span>Chats</span></button>
<button class="nav" data-channel="whatsapp" data-view="wa-sent"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg><span>Sent</span></button>
<button class="nav" data-channel="whatsapp" data-view="sheets"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M4 9h16M4 15h16M10 9v12"/></svg><span>Sheets</span></button>
<button class="nav" data-channel="whatsapp" data-view="wa-device"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/></svg><span>Linked device</span></button>
<button class="nav" data-channel="whatsapp" data-view="wa-settings"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9c.3.7.9 1.2 1.6 1.3H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg><span>Settings</span></button>
<button class="nav" data-view="mail-accounts" id="nav-mail-accounts"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg><span>Mail Accounts</span></button>
<button class="nav" data-view="integrations" id="nav-integrations"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m8 12 8-8M7 15l-2 2a3 3 0 0 0 4 4l4-4M11 7l4-4a3 3 0 0 1 4 4l-2 2M8 8l8 8"/></svg><span>Integrations</span></button></nav><div class="sidebar-foot"><button id="logout" class="sidebar-logout" <?= $isAuthenticated ? '' : 'hidden' ?> title="Sign out of workspace"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg><span>Sign out</span></button><div class="sidebar-platform-label">Email Automation Platform</div></div></aside>
<main>
<div id="notice" role="status" hidden></div>
<section id="setup" <?= ($isSetupAllowed && !$isAuthenticated) ? '' : 'hidden' ?>><div class="auth-box"><div class="auth-brand-badge"><img src="/assets/rsl-logo.png?v=52" alt="RSL Solution" class="auth-rsl-logo" width="120" height="57"><div class="auth-brand-text"><span class="auth-brand-name">WORKSPACE</span><span class="auth-brand-sub">Communication Desk</span></div></div><div class="card login-card"><div class="login-card-head"><div class="eyebrow">FIRST-TIME SETUP</div><h1 class="login-title">Create your administrator</h1><p class="login-desc">Choose your password to access the communication desk. This setup is available only from this computer.</p></div><form id="setup-form"><label>Password<input name="password" type="password" autocomplete="new-password" minlength="6" maxlength="64" required></label><label>Confirm password<input name="confirmation" type="password" autocomplete="new-password" minlength="6" maxlength="64" required></label><p class="muted" style="margin:8px 0 16px;font-size:12px;">Use at least 6 characters. Your password is stored securely as a hash.</p><button class="primary" style="width:100%;min-height:44px;">Create administrator</button></form></div><div class="auth-foot-note">RSL Communication Desk &middot; One workspace. Every conversation.</div></div></section>
<section id="login" <?= (!$isSetupAllowed && !$isAuthenticated) ? '' : 'hidden' ?>><div class="auth-box"><div class="auth-brand-badge"><img src="/assets/rsl-logo.png?v=52" alt="RSL Solution" class="auth-rsl-logo" width="120" height="57"><div class="auth-brand-text"><span class="auth-brand-name">WORKSPACE</span><span class="auth-brand-sub">Communication Desk</span></div></div><div class="card login-card"><div class="login-card-head"><h1 class="login-title">Welcome to your desk</h1><p class="login-desc">Sign in to manage outgoing messages and track email activity.</p></div><form id="login-form"><label><div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:6px;"><span style="font-size:13px;font-weight:600;color:#213129;">Workspace Password</span><button type="button" id="forgot-password" class="auth-text-link">Forgot password?</button></div><input name="password" id="login-password" type="password" autocomplete="current-password" required placeholder="••••••••"></label><button class="primary auth-submit-btn" type="submit" id="login-submit-btn">Sign in &rarr;</button><p id="setup-help" class="muted" hidden style="margin-top:14px;">Set ADMIN_PASSWORD_HASH in backend/.env to enable sign-in.</p></form></div><div class="auth-foot-note">RSL Communication Desk &middot; One workspace. Every conversation.</div></div></section>
<div id="app" <?= $isAuthenticated ? '' : 'hidden' ?>>
<section id="overview" class="view" hidden>
<div class="dash-header">
  <div>
    <div class="eyebrow">EMAIL PERFORMANCE &amp; INTELLIGENCE</div>
    <h1 class="dash-title">Dashboard Overview</h1>
    <p class="muted" id="ov-subtitle">Real-time metrics, engagement volume, and deliverability status</p>
  </div>
  <div class="header-actions">
    <span class="overview-sync-status" id="overview-sync-status" style="font-size:12px;color:#66786c;display:inline-flex;align-items:center;gap:6px">
      <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#34a853"></span>
      <span>Auto-synced</span>
    </span>
    <button type="button" class="quiet" id="overview-sync-btn">
      <svg class="icon sync-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5M19 12a7 7 0 0 0-12-5L4 10M5 12a7 7 0 0 0 12 5l3-3"/></svg>
      <span>Sync Inbox</span>
    </button>
  </div>
</div>

<div class="mode" role="status" hidden>
  <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7v1"/></svg>
  <span id="mode">Test mode enabled &mdash; emails won't be sent.</span>
</div>

<!-- 4 Visual KPI Cards -->
<div class="dash-kpi-grid">
  <div class="card dash-kpi-card" data-view="sent" title="Click to view all sent messages" tabindex="0">
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

  <div class="card dash-kpi-card" data-view="inbox" title="Click to open inbox" tabindex="0">
    <div class="dash-kpi-header">
      <span>INBOUND REPLIES</span>
      <svg class="icon text-muted" viewBox="0 0 24 24"><path d="M21.2 8.4c.5.38.8.97.8 1.6v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V10a2 2 0 0 1 .8-1.6l8-6a2 2 0 0 1 2.4 0l8 6Z"/><path d="m22 10-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 10"/></svg>
    </div>
    <div class="dash-kpi-val" id="ov-stat-inbound">0</div>
    <span class="dash-badge blue">
      <span id="ov-stat-inbox-badge">&#9679; 0 Messages Synced</span>
    </span>
  </div>

  <div class="card dash-kpi-card" data-view="inbox" title="Click to view conversation replies" tabindex="0">
    <div class="dash-kpi-header">
      <span>RESPONSE RATE</span>
      <svg class="icon text-muted" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="m16 12-4-4-4 4M12 16V8"/></svg>
    </div>
    <div class="dash-kpi-val" id="ov-stat-response-rate">0%</div>
    <span class="dash-badge green">
      <span id="ov-stat-response-label">Campaign Reply Engagement</span>
    </span>
  </div>

  <div class="card dash-kpi-card" data-view="integrations" title="Click to view mail server &amp; delivery health" tabindex="0">
    <div class="dash-kpi-header">
      <span>DELIVERY HEALTH</span>
      <svg class="icon text-muted" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
    </div>
    <div class="dash-kpi-val" id="ov-stat-health">100%</div>
    <span class="dash-badge green">
      <span id="ov-stat-health-badge">Zero Bounces &middot; Optimal Health</span>
    </span>
  </div>
</div>

<!-- Main Analytics Grid -->
<div class="dash-analytics-grid">
  <!-- 7-Day Trend Chart -->
  <div class="card dash-chart-card">
    <div class="dash-card-header">
      <div>
        <h2 class="dash-card-title">Email Volume &amp; Response Curve</h2>
        <p class="dash-card-subtitle">Daily comparison of Outgoing Dispatches vs. Inbound Replies</p>
      </div>
      <div class="dash-time-pills" id="ov-range-pills" role="group" aria-label="Chart range">
        <button type="button" class="dash-time-pill active" data-range="7" aria-pressed="true">7 days</button>
        <button type="button" class="dash-time-pill" data-range="15" aria-pressed="false">15 days</button>
        <button type="button" class="dash-time-pill" data-range="30" aria-pressed="false">30 days</button>
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
      <div class="dash-peak" id="ov-peak">
        <span class="dash-peak-kicker">Peak window</span>
        <strong id="ov-peak-window">&mdash;</strong>
        <span class="dash-peak-note" id="ov-peak-note">Last 7 days</span>
      </div>
    </div>
  </div>

  <!-- Delivery Status Donut -->
  <div class="card dash-donut-card">
    <div class="dash-card-header">
      <div>
        <h2 class="dash-card-title">Delivery Status</h2>
        <p class="dash-card-subtitle">Accepted by Hostinger, bounce notices, and mail still queued</p>
      </div>
    </div>

    <div class="dash-donut-wrap">
      <div id="overview-donut-container"></div>

      <div class="dash-ratio-list">
        <div class="dash-ratio-row">
          <span class="dash-ratio-label"><span class="dash-legend-dot" style="background:#285c43"></span>Accepted by Hostinger</span>
          <span class="dash-ratio-val" id="ov-ratio-sent">0 (100%)</span>
        </div>
        <div class="dash-ratio-row">
          <span class="dash-ratio-label"><span class="dash-legend-dot" style="background:#d93025"></span>Bounced after send</span>
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
        <p class="dash-card-subtitle">Mail that still needs an answer. Test receipts stay in Client Replies.</p>
      </div>
      <button type="button" class="quiet" data-view="inbox" style="padding:6px 12px;font-size:12px">View all in Inbox</button>
    </div>

    <div class="dash-contacts-list" id="overview-hot-contacts"></div>
  </div>

  <!-- System Health -->
  <div class="card dash-health-card">
    <div class="dash-card-header">
      <div>
        <h2 class="dash-card-title">System &amp; Pipeline Health</h2>
        <p class="dash-card-subtitle">Real-time status of connected mail services</p>
      </div>
      <button type="button" class="quiet" data-view="integrations" style="padding:6px 12px;font-size:12px;display:inline-flex;align-items:center;gap:4px">
        <span>Configure</span>
        <svg class="icon" viewBox="0 0 24 24" style="width:12px;height:12px" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
      </button>
    </div>

    <div class="dash-health-list">
      <div class="dash-health-item">
        <span class="dash-health-dot online"></span>
        <div class="dash-health-main">
          <div class="dash-health-title-row">
            <span class="dash-health-title">Hostinger SMTP Outgoing</span>
            <span class="dash-health-badge green">SSL 465</span>
          </div>
          <div class="dash-health-meta" id="ov-health-smtp">smtp.hostinger.com:465 &middot; Active</div>
        </div>
      </div>

      <div class="dash-health-item">
        <span class="dash-health-dot online"></span>
        <div class="dash-health-main">
          <div class="dash-health-title-row">
            <span class="dash-health-title">Hostinger IMAP Inbox Polling</span>
            <span class="dash-health-badge green">SSL 993</span>
          </div>
          <div class="dash-health-meta" id="ov-health-imap">imap.hostinger.com:993 &middot; Live sync active</div>
        </div>
      </div>

      <div class="dash-health-item">
        <span class="dash-health-dot online"></span>
        <div class="dash-health-main">
          <div class="dash-health-title-row">
            <span class="dash-health-title">Google Sheets &amp; Excel Hub</span>
            <span class="dash-health-badge blue">Auto-Sync</span>
          </div>
          <div class="dash-health-meta" id="ov-health-sheets">Live sync active &middot; Real-time status sync</div>
        </div>
      </div>

      <div class="dash-health-item">
        <span class="dash-health-dot online"></span>
        <div class="dash-health-main">
          <div class="dash-health-title-row">
            <span class="dash-health-title">MySQL Database &amp; Queue</span>
            <span class="dash-health-badge green">Optimal</span>
          </div>
          <div class="dash-health-meta" id="ov-health-db">0 waiting to send</div>
        </div>
      </div>
    </div>

    <!-- Pinned Operational Summary Panel (Fills the height gap perfectly) -->
    <div class="dash-health-summary-panel">
      <div class="dash-health-summary-header">
        <div class="dash-health-summary-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="icon" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        </div>
        <div>
          <div class="dash-health-summary-title" id="ov-health-summary-title">Pipeline fully operational</div>
          <div class="dash-health-summary-sub" id="ov-health-summary-sub">Mail is leaving Hostinger and no bounce notices are stored.</div>
        </div>
      </div>
      <div class="dash-health-summary-actions">
        <button type="button" class="primary" data-view="integrations" style="width:100%;min-height:38px;font-size:12.5px;font-weight:600;display:inline-flex;align-items:center;justify-content:center;gap:6px">
          <span>Manage Integrations &amp; API Keys</span>
          <svg class="icon" viewBox="0 0 24 24" style="width:14px;height:14px" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
      </div>
    </div>
  </div>
</div>
</section>
<section id="inbox" class="view" hidden>
<div class="title-row inbox-title-row"><div><div class="eyebrow">INCOMING &amp; REPLIES</div><h1 class="inbox-heading">Inbox <span id="inbox-header-badge" class="inbox-heading-badge" hidden>0</span></h1><p class="muted inbox-subheading"><span class="inbox-account-pill" id="inbox-account-pill"><span class="status-indicator-dot"></span>Connected Account</span><span id="inbox-last-sync" class="inbox-last-sync-label"></span></p></div><div class="inbox-header-actions"><button class="primary inbox-sync-btn" id="inbox-sync"><svg class="icon sync-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5M19 12a7 7 0 0 0-12-5L4 10M5 12a7 7 0 0 0 12 5l3-3"/></svg><span>Sync Inbox</span></button></div></div>
<div class="card table-card" style="margin-top:20px"><div class="queue-header">
<div class="inbox-categories-bar" role="tablist" aria-label="Inbox categories">
  <button type="button" class="inbox-cat-btn active" data-category="primary" role="tab" aria-selected="true">
    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
    <span>Client Replies</span>
    <span class="tab-count" id="inbox-count-primary">0</span>
  </button>
  <button type="button" class="inbox-cat-btn" data-category="linkedin" role="tab" aria-selected="false">
    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-4 0v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/></svg>
    <span>LinkedIn</span>
    <span class="tab-count" id="inbox-count-linkedin">0</span>
  </button>
  <button type="button" class="inbox-cat-btn" data-category="bounces" role="tab" aria-selected="false">
    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <span>Bounced / Undelivered</span>
    <span class="tab-count bounce-count" id="inbox-count-bounces">0</span>
  </button>
  <button type="button" class="inbox-cat-btn" data-category="all" role="tab" aria-selected="false">
    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
    <span>All Messages</span>
    <span class="tab-count" id="inbox-count-all">0</span>
  </button>
</div>
<div class="queue-toolbar"><div class="queue-toolbar-left"><div class="search-field"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg><input id="inbox-search" type="search" placeholder="Search mail..." aria-label="Search inbox"></div><select id="inbox-account-filter" aria-label="Filter inbox by mailbox"><option value="">All Mailboxes</option></select><select id="inbox-filter" aria-label="Filter inbox by read status"><option value="">All messages</option><option value="unread">Unread only</option><option value="read">Read only</option></select><select id="inbox-date-filter" aria-label="Filter inbox by date"><option value="">All dates</option><option value="today">Today</option><option value="7">Last 7 days</option><option value="30">Last 30 days</option></select><button class="quiet" id="inbox-refresh"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5M19 12a7 7 0 0 0-12-5L4 10M5 12a7 7 0 0 0 12 5l3-3"/></svg><span>Refresh</span></button></div></div>
</div><div class="table-wrap"><table><thead><tr><th style="width:220px">Sender</th><th id="inbox-mailbox-head" style="width:160px" hidden>Mailbox</th><th>Subject</th><th style="width:110px;text-align:right">Received</th><th style="width:140px;text-align:right;padding-right:18px">Action</th></tr></thead><tbody id="inbox-rows"></tbody></table></div><div class="pagination-bar"><label>Rows per page<select id="inbox-size"><option>10</option><option selected>25</option><option>50</option><option>100</option></select></label><span id="inbox-range" aria-live="polite"></span><div class="pagination-buttons"><button id="inbox-prev" class="quiet">Previous</button><span id="inbox-page"></span><button id="inbox-next" class="quiet">Next</button></div></div></div>
</section>
<section id="sent" class="view" hidden>
<div class="title-row"><div><div class="eyebrow">OUTGOING LOG &amp; DISPATCH</div><h1>Sent</h1><p class="muted">Complete history of all outgoing emails sent from your connected sheets or manual compose.</p></div></div>
<div class="card table-card"><div class="queue-header">
<div class="queue-toolbar"><div class="queue-toolbar-left"><div class="search-field"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg><input id="sent-search" type="search" placeholder="Search sent mail..." aria-label="Search sent emails"></div><select id="sent-account-filter" aria-label="Filter sent emails by mailbox"><option value="">All Mailboxes</option></select><select id="sent-date-filter" aria-label="Filter sent emails by date"><option value="">All dates</option><option value="today">Today</option><option value="7">Last 7 days</option><option value="30">Last 30 days</option></select><button class="quiet" id="sent-refresh"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5M19 12a7 7 0 0 0-12-5L4 10M5 12a7 7 0 0 0 12 5l3-3"/></svg><span>Refresh</span></button></div></div>
</div><div class="table-wrap"><table><thead><tr><th style="width:240px">Recipient</th><th id="sent-via-head" style="width:160px" hidden>Sent via</th><th>Subject</th><th style="width:150px">Dispatched at</th><th style="width:120px;text-align:right;padding-right:18px">Action</th></tr></thead><tbody id="sent-rows"></tbody></table></div><div class="pagination-bar"><label>Rows per page<select id="sent-size"><option>10</option><option selected>25</option><option>50</option><option>100</option></select></label><span id="sent-range" aria-live="polite"></span><div class="pagination-buttons"><button id="sent-prev" class="quiet">Previous</button><span id="sent-page"></span><button id="sent-next" class="quiet">Next</button></div></div></div>
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
<div class="compose-heading"><div><div class="eyebrow" id="compose-eyebrow">NEW DISPATCH</div><h1 id="compose-title">Compose email</h1></div></div>
<form id="compose-form" class="card compose-card" novalidate>
<div class="compose-fields">
<div class="compose-row sender-row email-compose-only" id="compose-sender-row"><span class="compose-label">From</span><div class="compose-sender-wrap"><select id="compose-sender" name="mail_account_id" class="compose-input compose-select" aria-label="Send As Mailbox"></select><div class="compose-sender-locked" id="compose-sender-locked" hidden><span class="sender-badge" id="compose-sender-badge"></span></div></div></div>
<div class="compose-row recipient-row"><span class="compose-label">To</span><input id="compose-to" name="recipient" type="email" required placeholder="recipient@example.com" autocomplete="email" class="compose-input"><div class="recipient-options email-compose-only" aria-label="Additional recipients"><button type="button" class="recipient-toggle" data-recipient="cc" aria-expanded="false" aria-controls="compose-cc-field">Cc</button><button type="button" class="recipient-toggle" data-recipient="bcc" aria-expanded="false" aria-controls="compose-bcc-field">Bcc</button></div></div>
<div class="compose-row optional-row email-compose-only" id="compose-cc-field" hidden><span class="compose-label">Cc</span><input name="cc" disabled placeholder="Separate multiple emails with commas" class="compose-input"></div>
<div class="compose-row optional-row email-compose-only" id="compose-bcc-field" hidden><span class="compose-label">Bcc</span><input name="bcc" disabled placeholder="Separate multiple emails with commas" class="compose-input"></div>
<div class="compose-row subject-row email-compose-only"><input name="subject" maxlength="255" required placeholder="Subject" class="compose-input compose-subject-input"></div>
<div class="compose-body-row"><textarea name="body" rows="10" required maxlength="100000" placeholder="Write your message here..." class="compose-textarea"></textarea></div>
<div id="compose-attachments" class="compose-attachments" hidden></div>
</div>
<p id="compose-form-error" class="compose-form-error" role="alert" hidden></p><div class="form-footer compose-actions"><div class="compose-action-left"><button type="submit" class="primary compose-send-btn" id="compose-submit-btn"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg><span id="compose-submit-label">Send email</span></button><label class="compose-tool-btn email-compose-only" id="compose-attach-btn" title="Attach files" aria-label="Attach files"><input type="file" id="compose-file-input" multiple hidden><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg></label><span id="compose-upload-status" class="compose-upload-status" hidden></span><span id="compose-mode" hidden></span></div><div class="compose-action-right"><button type="reset" class="compose-discard-btn" id="compose-discard-btn" title="Discard draft" aria-label="Discard draft"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M10 11v6M14 11v6"/></svg><span>Discard draft</span></button></div></div>
</form></section>
<section id="sheets" class="view" hidden>
<div id="sheets-directory">
<div class="title-row wa-sheets-head">
  <div class="wa-sheets-head-copy">
    <span class="wa-sheets-head-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="4" y="3" width="16" height="18" rx="2"></rect>
        <path d="M4 9h16M4 15h16M10 9v12"></path>
      </svg>
    </span>
    <div>
      <div class="eyebrow">YOUR DATA SOURCES</div>
      <h1>Your sheets</h1>
      <p class="muted wa-sheets-desc">Upload a spreadsheet or connect Google Sheets to manage your records.</p>
    </div>
  </div>
  <div class="sheets-header-actions">
    <div id="google-status-pill" class="google-status-pill" hidden>
      <span class="status-dot"></span>
      <span id="google-status-text">Google connected</span>
    </div>
    <button type="button" class="quiet" id="google-disconnect" hidden>Disconnect</button>
    <button class="primary" id="google-connect">Connect Google</button>
  </div>
</div>
<div id="google-status" hidden></div>

<div class="card sheet-list">
  <div class="queue-header sheet-list-header">
    <div class="sheet-list-title-wrap">
      <div class="sheet-list-title-row">
        <h2>Saved sheets</h2>
        <span id="saved-sheet-count-badge" class="saved-sheet-count-badge">1 source</span>
      </div>
      <p class="muted">Open a source to enter its full workspace.</p>
    </div>
    <div class="queue-toolbar sheet-list-toolbar">
      <div class="search-field">
        <svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        <input id="saved-sheet-search" type="search" placeholder="Search saved sheets&hellip;" aria-label="Search saved sheets">
      </div>
      <div class="sheet-filter-chips" id="sheet-source-filter-chips">
        <button type="button" class="sheet-filter-chip active" data-type="all">All</button>
        <button type="button" class="sheet-filter-chip" data-type="excel">Excel</button>
        <button type="button" class="sheet-filter-chip" data-type="google">Google</button>
      </div>
      <button id="refresh-sources" class="quiet sheet-refresh-btn" title="Refresh saved sheets">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 0 1 15-6.7L21 8M21 3v5h-5M21 12a9 9 0 0 1-15 6.7L3 16M3 21v-5h5"/></svg>
        <span>Refresh</span>
      </button>
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
        <span class="dropzone-icon-circle" aria-hidden="true">
          <svg class="dropzone-cloud-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/>
            <path d="M12 12v9"/>
            <path d="m16 16-4-4-4 4"/>
          </svg>
        </span>
        <div class="dropzone-text-group">
          <span class="dropzone-title"><strong>Click to upload</strong> or drag and drop</span>
          <span class="dropzone-hint" id="upload-limit">Excel (.xlsx) or CSV, up to 2 MB</span>
        </div>
      </div>
    </form>
  </div>
</div>
<form id="sheet-map-form" class="card sheet-mapping" hidden><div class="mapping-card-header"><div class="eyebrow">SOURCE CONFIGURATION &middot; STEP 2</div><h2>Column mapping</h2><p id="mapping-source-label" class="muted"></p></div><div class="mapping-inputs-group"><div class="mapping-input-col"><label for="sheet-tab">Worksheet tab</label><select id="sheet-tab" required></select><span class="field-hint">Choose which tab in your workbook contains the records</span></div><div class="mapping-input-col"><label for="sheet-header">Header row number</label><input id="sheet-header" type="number" min="1" max="100" value="6" required><span class="field-hint">Row where column titles live (usually 1, or 6 for company templates)</span></div></div><div class="mapping-read-row"><button type="button" id="read-headers" class="primary mapping-read-btn"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21 21-4.3-4.3"/><circle cx="11" cy="11" r="8"/></svg><span>Read &amp; Detect Columns</span></button></div><div id="detected-columns-preview" class="detected-columns-banner" hidden></div><div id="sheet-mappings" class="mapping-sections-wrap"></div><div class="form-footer mapping-footer-bar" id="mapping-actions" hidden><button type="button" class="quiet" id="cancel-mapping-btn">Cancel</button><button id="save-source" class="primary">Save &amp; Open Workspace &rarr;</button></div></form>
</div>
<div class="card sheet-preview" id="sheet-preview" hidden>
  <div class="sheet-workspace-header">
    <div class="sheet-workspace-header-left">
      <button type="button" class="quiet" id="sheet-back-btn" aria-label="Back to your sheets">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m15 18-6-6 6-6"/></svg>
        <span>Back</span>
      </button>
      <div class="sheet-workspace-title-wrap">
        <span id="sheet-preview-icon" class="sheet-preview-badge-icon excel">
          <svg viewBox="0 0 24 24" class="sheet-type-svg"><path fill="#107C41" d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/><path fill="#fff" d="M15.5 17l-2.4-4.5 2.3-4.5h-2.1l-1.3 3.1-1.3-3.1H8.6l2.3 4.5-2.4 4.5h2.1l1.4-3.3 1.4 3.3z"/></svg>
        </span>
        <div class="sheet-workspace-title-col">
          <div class="sheet-workspace-title-line">
            <h1 id="source-title" class="sheet-workspace-title"></h1>
            <span id="sheet-record-count-badge" class="sheet-record-count-badge">
              <span class="status-dot"></span>
              <span id="source-badge-count">0 records</span>
            </span>
          </div>
          <p id="source-summary" class="muted"></p>
          <p id="source-edit-note" class="muted"></p>
        </div>
      </div>
    </div>
    <div class="sheet-header-right">
      <div class="sheet-sender-wrap sheet-email-only">
        <span class="sheet-sender-label">Send from</span>
        <select id="sheet-sender-select" class="sheet-sender-select" aria-label="Select sending mailbox"></select>
      </div>
      <div class="sheet-sender-wrap sheet-wa-only sheet-phone-menu">
        <span class="status-dot pulsing"></span>
        <span class="sheet-sender-label">Send from:</span>
        <button type="button" id="wa-sheet-phone-btn" class="sheet-phone-btn" aria-haspopup="true" aria-expanded="false">
          <span>All ready phones</span>
          <svg class="icon icon-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m6 9 6 6 6-6"/></svg>
        </button>
        <div id="wa-sheet-phones" class="sheet-phone-panel" hidden></div>
      </div>
    </div>
  </div>

  <div class="sheet-schedule sheet-wa-only">
    <div class="sheet-schedule-inputs">
      <div class="sheet-schedule-pill">
        <svg class="icon icon-start" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <label for="wa-sheet-date" class="sheet-schedule-label">Start:</label>
        <input type="date" id="wa-sheet-date" class="sheet-schedule-date" aria-label="Day these rows start">
      </div>
      <div class="sheet-schedule-pill">
        <svg class="icon icon-gap" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <label for="wa-sheet-delay" class="sheet-schedule-label">Gap:</label>
        <select id="wa-sheet-delay" class="sheet-sender-select" aria-label="Minutes between messages from this phone"></select>
      </div>
      <div class="sheet-schedule-divider"></div>
      <p id="wa-sheet-cap" class="sheet-schedule-cap"></p>
    </div>
    <span class="sheet-schedule-tag">Pacing Guard Active</span>
  </div>

  <div class="sheet-search-toolbar">
    <div class="sheet-toolbar-main">
      <div class="search-field">
        <svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        <input id="sheet-search" type="search" placeholder="Search ID, name, phone, or message" aria-label="Search sheet records">
      </div>
      <div class="sheet-filter-line">
        <div class="sheet-filter-tabs" id="sheet-quick-tabs">
          <button type="button" class="sheet-tab-btn active" data-tab-filter="">All records <span class="tab-count" id="count-all-records">0</span></button>
          <button type="button" class="sheet-tab-btn" data-tab-filter="ready">Ready <span class="tab-count" id="count-ready-records">0</span></button>
          <button type="button" class="sheet-tab-btn" data-tab-filter="pending">Pending <span class="tab-count" id="count-pending-records">0</span></button>
          <button type="button" class="sheet-tab-btn" data-tab-filter="sent">Sent <span class="tab-count" id="count-sent-records">0</span></button>
          <button type="button" class="sheet-tab-btn" data-tab-filter="failed">Failed <span class="tab-count" id="count-failed-records">0</span></button>
          <button type="button" class="sheet-tab-btn" data-tab-filter="issues">Needs attention <span class="tab-count" id="count-issues-records">0</span></button>
          <select id="sheet-status" class="sheet-email-only" aria-label="More status filters">
            <option value="">More filters</option>
            <option value="not_queued">Not queued</option>
            <option value="dry_run">Test emails</option>
          </select>
        </div>
      </div>
    </div>
  </div>

  <div class="sheet-controls">
    <div class="sheet-controls-left">
      <span id="sheet-sync-status" class="sheet-sync-pill"><svg viewBox="0 0 24 24" class="icon" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>Live Sync Active</span>
    </div>
    <div class="sheet-controls-right">
      <button id="source-mapping" class="quiet"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg><span>Column mapping</span></button>
      <button type="button" id="sheet-replace" class="quiet" hidden><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg><span>Update file</span></button>
      <input id="sheet-replace-file" type="file" accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" hidden>
      <a id="open-sheet" class="quiet" target="_blank" rel="noopener noreferrer"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg><span>Open in Google Sheets</span></a>
      <button id="refresh-sheet" class="quiet"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 0 1 15-6.7L21 8M21 3v5h-5M21 12a9 9 0 0 1-15 6.7L3 16M3 21v-5h5"/></svg><span>Refresh rows</span></button>
      <button type="button" id="sheet-download" class="quiet"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg><span>Download</span></button>
      <button type="button" class="primary" id="sheet-bulk-send-btn" hidden><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg><span id="sheet-bulk-send-label">Send all ready</span></button>
      <button type="button" id="reset-processing-btn" class="quiet sheet-email-only" title="Unlock emails that got stuck in processing state" hidden><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg><span>Unlock stuck processing</span></button>
    </div>
  </div><script>try{var bits=(location.hash||'').replace(/^#/,'').split('/');var id=bits[0]==='sheets'?(bits[1]||''):'';if(!location.hash&&(localStorage.getItem('rsl_active_view')||'')==='sheets')id=localStorage.getItem('rsl_open_sheet')||'';id=String(id).replace(/[^a-z0-9-]/gi,'');if(id){var saved=JSON.parse(sessionStorage.getItem('rsl_sheet_counts_'+id)||'null');if(saved){var put=function(n,v){var el=document.getElementById(n);if(el&&v!==undefined&&v!==null&&v!=='')el.textContent=v;};put('count-all-records',saved.all);put('count-ready-records',saved.ready);put('count-sent-records',saved.sent);put('count-issues-records',saved.issues);put('source-title',saved.title);put('source-summary',saved.summary);put('source-edit-note',saved.note);put('sheet-bulk-send-label',saved.bulk);put('sheet-range',saved.range);var bulk=document.getElementById('sheet-bulk-send-btn');if(bulk&&saved.bulk)bulk.hidden=false;}}}catch(e){}</script><div id="batch-actions" class="batch-toolbar sheet-email-only" hidden><div class="batch-info-group"><span id="batch-count-label">0 rows selected</span><button type="button" class="batch-link" id="batch-select-all-sheet">Select all ready across sheet</button><button type="button" class="batch-link" id="batch-clear-btn">Clear selection</button></div><button type="button" class="primary" id="batch-send-btn"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg><span>Send selected</span></button></div><div class="table-wrap"><table><thead><tr><th id="sheet-col-check" class="sheet-email-only" style="width:36px;text-align:center"><input type="checkbox" id="sheet-select-all" class="sheet-checkbox" title="Select all valid rows"></th><th id="sheet-col-id">ID / agency</th><th id="sheet-col-extra" hidden>Agency / Name</th><th id="sheet-col-mid">Email</th><th id="sheet-col-next">Subject</th><th id="sheet-col-status">Status</th><th id="sheet-col-actions">Actions</th></tr></thead><tbody id="sheet-rows"></tbody></table></div><div class="sheet-pagination pagination-bar"><label>Rows per page<select id="sheet-size"><option>10</option><option>25</option><option>50</option><option>100</option></select></label><span id="sheet-range" aria-live="polite"></span><button id="sheet-prev" class="quiet">Previous</button><span id="sheet-page"></span><button id="sheet-next" class="quiet">Next</button></div></div>
<dialog id="sheet-editor" aria-labelledby="sheet-editor-title"><form id="sheet-edit-form"><div class="queue-header"><div><h2 id="sheet-editor-title">Edit record</h2><p id="edit-record-id" class="muted"></p></div><button type="button" id="close-editor" class="quiet">Close</button></div><p id="edit-record-note" class="muted">Only changed fields are saved. Keep this sheet free of simultaneous edits while saving.</p><div id="edit-fields"></div><p id="edit-error" role="alert" hidden></p><div class="form-footer"><span id="edit-save-note" class="muted">Changes are saved to this source.</span><button type="submit" class="primary" id="save-record">Save changes</button></div></form></dialog>
</section>

<!-- Mail Accounts View -->
<section id="mail-accounts" class="view" hidden>
<div class="dash-header">
  <div>
    <div class="eyebrow">SENDING &amp; RECEIVING CONFIGURATION</div>
    <h1 class="dash-title">Mail Accounts</h1>
    <p class="muted">Connect and manage SMTP and IMAP email accounts.</p>
  </div>
  <button type="button" class="primary" id="btn-add-mailbox">
    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
    <span>Connect Mailbox</span>
  </button>
</div>
<div class="card config-card" style="margin-top:20px">
  <div class="table-wrap">
    <table class="config-table">
      <thead>
        <tr>
          <th style="width:230px">Account / Sender</th>
          <th>Outgoing (SMTP)</th>
          <th>Incoming (IMAP)</th>
          <th style="width:110px">Status</th>
          <th style="width:180px;text-align:right;padding-right:16px">Actions</th>
        </tr>
      </thead>
      <tbody id="mailboxes-table-body">
        <tr><td colspan="5" class="muted" style="text-align:center;padding:24px">Loading mail accounts...</td></tr>
      </tbody>
    </table>
  </div>
</div>
</section>

<!-- Integrations View -->
<section id="integrations" class="view" hidden>
<div class="dash-header">
  <div>
    <div class="eyebrow">CONNECTED PLATFORMS</div>
    <h1 class="dash-title">Integrations</h1>
    <p class="muted">Active services, data connectors, and system health.</p>
  </div>
</div>
<div class="integration-grid" style="margin-top:20px">
  <article class="card">
    <span class="badge sent">Connected &amp; Live</span>
    <h2>Hostinger SMTP</h2>
    <p>Live outgoing mail via smtp.hostinger.com:465 with SSL/TLS.</p>
  </article>
  <article class="card">
    <span class="badge sent">Connected &amp; Live</span>
    <h2>Hostinger IMAP Inbox</h2>
    <p>Live incoming synchronization via imap.hostinger.com:993.</p>
    <button class="quiet" data-view="inbox">Open Inbox</button>
  </article>
  <article class="card">
    <span class="badge">Available to connect</span>
    <h2>Google Sheets</h2>
    <p>Add sheet links, map columns, and edit records from your workspace.</p>
    <button class="quiet" data-view="sheets">Manage Google Sheets</button>
  </article>
  <article class="card">
    <span class="badge">Disabled</span>
    <h2>Automatic replies</h2>
    <p>Requires rule configuration and human-review workflows before it can be enabled.</p>
  </article>
</div>
</section>

<section id="wa-sheets" class="view" hidden>
  <div id="wa-sheets-directory">
    <div class="title-row">
      <div>
        <div class="eyebrow">WHATSAPP</div>
        <h1>WhatsApp sheets</h1>
        <p class="muted">Map ID, name, phone, and the WhatsApp message. Phone and the message are required. Email is ignored.</p>
      </div>
      <button type="button" class="quiet" id="wa-refresh">Refresh</button>
    </div>
    <div class="card sheet-list">
      <div class="queue-header">
        <div>
          <h2>Saved sheets</h2>
          <p class="muted">These stay on WhatsApp. They are not added to the email queue.</p>
        </div>
      </div>
      <div id="wa-sheet-sources" class="sheet-sources-list"></div>
    </div>
    <div class="sheets-grid">
      <div class="card sheet-action-card">
        <div class="sheet-card-header">
          <div>
            <h2>Upload a spreadsheet</h2>
            <p class="muted">Use the same workbook shape, with Phone and WhatsApp content columns.</p>
          </div>
        </div>
        <input id="wa-sheet-file" type="file" accept=".xlsx,.csv" hidden>
        <button type="button" class="primary" id="wa-upload-btn">Choose file</button>
        <p class="muted" id="wa-upload-limit">Excel (.xlsx) or CSV, up to 5 MB</p>
      </div>
      <div class="card sheet-action-card">
        <div class="sheet-card-header">
          <div>
            <h2>Link a Google Sheet</h2>
            <p class="muted">Read a connected Google Sheet into this WhatsApp desk.</p>
          </div>
        </div>
        <form id="wa-link-form" class="sheet-link-box">
          <div class="sheet-input-group">
            <input id="wa-sheet-url" type="url" required placeholder="https://docs.google.com/spreadsheets/d/..." aria-label="Google Sheet link">
            <button class="primary" type="submit">Read tabs</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <form id="wa-sheet-map" class="card" hidden>
    <div class="queue-header">
      <div>
        <div class="eyebrow">COLUMN MAPPING</div>
        <h2 id="wa-map-title">Workbook</h2>
        <p class="muted">ID and name are optional. Phone keeps the country code already in the cell. Email columns are ignored.</p>
      </div>
    </div>
    <label>Worksheet tab<select id="wa-sheet-tab"></select></label>
    <label>Header row<input id="wa-header" type="number" min="1" max="100" value="1"></label>
    <button type="button" class="quiet" id="wa-read-headers">Read columns</button>
    <div id="wa-map-fields" class="wa-map-grid"></div>
    <div class="form-footer">
      <button type="button" class="quiet" id="wa-map-cancel">Back</button>
      <button type="submit" class="primary" id="wa-map-save" hidden>Save sheet</button>
    </div>
  </form>
  <div id="wa-sheet-preview" hidden>
    <div class="title-row">
      <div>
        <div class="eyebrow">WHATSAPP SHEET</div>
        <h1 id="wa-preview-title">WhatsApp sheet</h1>
        <p class="muted" id="wa-sheet-summary"></p>
      </div>
      <button type="button" class="quiet" id="wa-preview-back">All sheets</button>
    </div>
    <div class="wa-sheet-sender">
      <label>Send from
        <select id="wa-sheet-sender" aria-label="Phone that sends this sheet"></select>
      </label>
      <p id="wa-sheet-sender-note" class="muted"></p>
    </div>
    <div class="card">
      <div class="table-wrap">
        <table>
          <colgroup><col class="wa-col-id"><col class="wa-col-name"><col class="wa-col-phone"><col class="wa-col-message"><col class="wa-col-check"></colgroup>
          <thead><tr><th>Record</th><th>Name</th><th>Phone</th><th>Message</th><th>Check</th></tr></thead>
          <tbody id="wa-sheet-rows"></tbody>
        </table>
      </div>
      <div class="wa-sheet-pager">
        <button type="button" class="quiet" id="wa-sheet-prev">Previous</button>
        <span id="wa-sheet-page"></span>
        <button type="button" class="quiet" id="wa-sheet-next">Next</button>
      </div>
    </div>
  </div>
</section>

<section id="message" class="view" hidden>
  <div class="wa-device-head wa-message-head">
    <div class="wa-device-head-copy">
      <span class="wa-device-head-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
      </span>
      <div>
        <div class="eyebrow" style="margin-bottom: 2px;">WHATSAPP OPERATIONS</div>
        <h1>New message</h1>
        <p class="muted wa-overview-desc">Sends from the linked phone. One number, one message.</p>
      </div>
    </div>
  </div>
  <form id="wa-compose-form" class="card wa-compose-card" novalidate>
    <label class="wa-compose-field-label" for="wa-compose-sender">
      <span class="wa-compose-label-text">Send from (Phone)</span>
      <select id="wa-compose-sender" name="device_id" class="wa-compose-input wa-compose-select" required>
        <option value="">Checking connected phones…</option>
      </select>
    </label>
    <label class="wa-compose-field-label" for="wa-compose-phone">
      <span class="wa-compose-label-text">Phone number</span>
      <input id="wa-compose-phone" name="phone" type="tel" inputmode="tel" placeholder="+91 98765 43210" autocomplete="tel" required class="wa-compose-input">
      <span class="field-hint">Include country code with plus prefix (e.g. +968, +91, +1)</span>
    </label>
    <label class="wa-compose-field-label" for="wa-compose-message">
      <span class="wa-compose-label-text">Message</span>
      <textarea id="wa-compose-message" name="message" rows="7" maxlength="4000" placeholder="Write the WhatsApp message" required class="wa-compose-textarea"></textarea>
    </label>
    <p id="wa-compose-error" class="compose-form-error" role="alert" hidden></p>
    <div class="form-footer wa-compose-footer">
      <span class="wa-compose-foot-hint">Dispatches directly via connected phone</span>
      <button type="submit" class="primary wa-compose-send-btn" id="wa-compose-send">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
        <span>Send message</span>
      </button>
    </div>
  </form>
</section>

<section id="wa-overview" class="view" hidden>
  <div class="wa-device-head wa-overview-head">
    <div class="wa-device-head-copy">
      <span class="wa-device-head-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M3 3v18h18" stroke-linecap="round"/>
          <path d="m7 15 4-4 4 3 6-7" stroke-linecap="round" stroke-linejoin="round"/>
          <circle cx="21" cy="7" r="1.5" fill="currentColor"/>
        </svg>
      </span>
      <div>
        <div class="eyebrow" style="margin-bottom: 2px;">WHATSAPP OPERATIONS</div>
        <h1>Dashboard</h1>
        <p class="muted wa-overview-desc">What this desk handed to the linked phone. Track campaign sends, failures, and unread replies.</p>
      </div>
    </div>
    <div class="wa-device-head-actions wa-overview-filters">
      <select id="wa-overview-device" aria-label="Phone">
        <option value="">All devices</option>
      </select>
      <select id="wa-overview-range" aria-label="Overview range">
        <option value="today">Today</option>
        <option value="7" selected>Last 7 days</option>
        <option value="30">Last 30 days</option>
      </select>
    </div>
  </div>
  <div id="wa-overview-body"></div>
</section>

<section id="wa-chats" class="view" hidden>
  <div class="wa-device-head wa-chats-head">
    <div class="wa-device-head-copy">
      <span class="wa-device-head-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/>
        </svg>
      </span>
      <div>
        <div class="eyebrow" style="margin-bottom: 2px;">WHATSAPP OPERATIONS</div>
        <h1>Chats &amp; Conversations</h1>
        <p class="muted wa-overview-desc">Live two-way WhatsApp messaging with delivery receipts, contact history, and multi-device routing.</p>
      </div>
    </div>
    <div class="wa-device-head-actions">
      <button type="button" class="primary" id="wa-chats-new-msg-btn">
        + New message
      </button>
    </div>
  </div>
  <div class="wa-chat-desk">
    <div class="wa-chat-list">
      <div class="wa-chat-sidebar-top">
        <label class="wa-chat-phone">
          <select id="wa-chat-phone" aria-label="Sending phone">
            <option value="">All linked devices</option>
          </select>
        </label>
        <div class="search-field wa-chat-search-wrap">
          <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg>
          <input id="wa-chat-search" type="search" placeholder="Search name or phone..." aria-label="Search chats">
        </div>
        <div class="wa-chat-filters" role="toolbar" aria-label="Chat filters">
          <button type="button" class="wa-filter is-on" data-filter="all">All <span id="wa-all-total">0</span></button>
          <button type="button" class="wa-filter" data-filter="unread">Unread <span id="wa-unread-total">0</span></button>
          <button type="button" class="wa-filter wa-filter-add" id="wa-new-message" aria-label="New message">+</button>
        </div>
      </div>
      <div id="wa-chat-list" class="wa-chat-items"></div>
    </div>
    <div id="wa-chat-thread" class="wa-chat-thread">
      <div class="wa-chat-empty">
        <p class="wa-empty-title">No chats yet</p>
        <p class="muted">A chat opens here as soon as you send a message.</p>
      </div>
    </div>
  </div>
</section>

<section id="wa-sent" class="view" hidden>
  <div class="wa-device-head wa-sent-head">
    <div class="wa-device-head-copy">
      <span class="wa-device-head-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="m22 2-7 20-4-9-9-4Z"/>
          <path d="M22 2 11 13"/>
        </svg>
      </span>
      <div>
        <div class="eyebrow" style="margin-bottom: 2px;">WHATSAPP OPERATIONS</div>
        <h1>Sent</h1>
        <p class="muted wa-overview-desc">Messages this desk handed to the linked device.</p>
      </div>
    </div>
  </div>
  <div class="card table-card wa-sent-card">
    <div class="wa-sent-toolbar">
      <div class="wa-sent-toolbar-filters">
        <div class="wa-sent-search-box">
          <svg class="icon wa-sent-search-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
          <input id="wa-sent-search" type="search" placeholder="Search phone, contact name, or message..." aria-label="Search WhatsApp sent">
        </div>

        <div class="wa-sent-select-wrap">
          <select id="wa-sent-phone" aria-label="Sending phone" class="wa-sent-select">
            <option value="">All Linked Devices</option>
          </select>
          <svg class="icon wa-sent-select-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
        </div>

        <div class="wa-sent-select-wrap">
          <select id="wa-sent-status" aria-label="Filter by status" class="wa-sent-select">
            <option value="">All Statuses</option>
            <option value="sent">Delivered</option>
            <option value="waiting">Waiting</option>
            <option value="failed">Failed</option>
          </select>
          <svg class="icon wa-sent-select-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
        </div>

        <div class="wa-sent-date-wrap">
          <button type="button" id="wa-sent-date-btn" class="wa-sent-date-btn" aria-haspopup="dialog" aria-expanded="false">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
              <line x1="16" y1="2" x2="16" y2="6"/>
              <line x1="8" y1="2" x2="8" y2="6"/>
              <line x1="3" y1="10" x2="21" y2="10"/>
            </svg>
            <span id="wa-sent-date-btn-text">All Time</span>
            <svg class="icon wa-sent-btn-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
          </button>

          <div id="wa-sent-date-popover" class="wa-sent-date-popover" hidden>
            <div class="wa-sent-popover-head">
              <span class="wa-sent-popover-kicker">QUICK DATE PRESETS</span>
              <button type="button" class="wa-sent-popover-all is-active" id="wa-sent-preset-all">All Time</button>
            </div>
            <div class="wa-sent-preset-grid">
              <button type="button" class="wa-sent-preset-pill" data-preset="today">Today</button>
              <button type="button" class="wa-sent-preset-pill" data-preset="yesterday">Yesterday</button>
              <button type="button" class="wa-sent-preset-pill" data-preset="7">Last 7 Days</button>
              <button type="button" class="wa-sent-preset-pill" data-preset="30">Last 30 Days</button>
            </div>
            <div class="wa-sent-custom-section">
              <span class="wa-sent-popover-kicker">CUSTOM DATE OR RANGE</span>
              <div class="wa-sent-custom-grid">
                <div>
                  <label for="wa-sent-date-from">From Date</label>
                  <input type="date" id="wa-sent-date-from">
                </div>
                <div>
                  <label for="wa-sent-date-to">To Date</label>
                  <input type="date" id="wa-sent-date-to">
                </div>
              </div>
              <div class="wa-sent-custom-actions">
                <button type="button" class="primary wa-sent-apply-custom-btn" id="wa-sent-custom-apply">Set Date Range</button>
                <button type="button" class="quiet wa-sent-reset-custom-btn" id="wa-sent-custom-reset">Reset</button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <button class="wa-sent-refresh-btn" type="button" id="wa-sent-refresh" title="Refresh">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
        <span>Refresh</span>
      </button>
    </div>

    <!-- Active Filter Badge Strip -->
    <div id="wa-sent-filter-strip" class="wa-sent-filter-strip" hidden>
      <div class="wa-sent-chip-list">
        <span class="wa-sent-filter-label">ACTIVE FILTER:</span>
        <span id="wa-sent-chip-date" class="wa-sent-chip" hidden>
          <span id="wa-sent-chip-date-text"></span>
          <button type="button" id="wa-sent-chip-date-close" class="wa-sent-chip-close" aria-label="Clear date filter">&times;</button>
        </span>
        <span id="wa-sent-chip-device" class="wa-sent-chip" hidden>
          <span id="wa-sent-chip-device-text">📱 Whatsapp Campaign</span>
          <button type="button" id="wa-sent-chip-device-close" class="wa-sent-chip-close" aria-label="Clear device filter">&times;</button>
        </span>
        <span id="wa-sent-chip-status" class="wa-sent-chip" hidden>
          <span id="wa-sent-chip-status-text">Delivered</span>
          <button type="button" id="wa-sent-chip-status-close" class="wa-sent-chip-close" aria-label="Clear status filter">&times;</button>
        </span>
      </div>
      <button type="button" id="wa-sent-clear-all" class="wa-sent-clear-all">Clear All</button>
    </div>

    <div class="table-wrap">
      <table class="wa-sent-table">
        <thead>
          <tr>
            <th style="width:240px">RECIPIENT (TO)</th>
            <th style="width:200px">DISPATCHED VIA (FROM)</th>
            <th>MESSAGE PREVIEW</th>
            <th style="width:150px">WHEN SENT</th>
            <th style="width:130px">RECEIPT</th>
            <th style="width:90px; text-align:right">ACTIONS</th>
          </tr>
        </thead>
        <tbody id="wa-sent-rows"></tbody>
      </table>
    </div>

    <div class="pagination-bar wa-sent-pagination">
      <div class="wa-sent-pag-left">
        <label>Rows per page:
          <select id="wa-sent-size">
            <option>10</option>
            <option selected>25</option>
            <option>50</option>
            <option>100</option>
          </select>
        </label>
        <span id="wa-sent-range" aria-live="polite">1–3 of 3 records</span>
      </div>
      <div class="pagination-buttons">
        <span id="wa-sent-page">Page 1 of 1</span>
        <button type="button" id="wa-sent-prev" class="quiet">Previous</button>
        <button type="button" id="wa-sent-next" class="quiet">Next</button>
      </div>
    </div>
  </div>
</section>

<section id="wa-device" class="view" hidden>
  <div class="wa-device-head">
    <div class="wa-device-head-copy">
      <span class="wa-device-head-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="7" y="2" width="10" height="20" rx="2"></rect>
          <path d="M11 18h2"></path>
        </svg>
      </span>
      <div>
        <div class="eyebrow" style="margin-bottom: 2px;">WHATSAPP OPERATIONS</div>
        <h1>Linked Devices</h1>
        <p id="wa-device-state" class="muted wa-overview-desc">Checking phones…</p>
        <p id="wa-device-name" class="wa-device-account" hidden></p>
      </div>
    </div>
    <div class="wa-device-head-actions">
      <p class="wa-device-slots" id="wa-device-slots"><strong id="wa-device-slot-used">0</strong><span>/ <em id="wa-device-slot-max">5</em> slots</span></p>
      <button type="button" class="primary" id="wa-device-add">+ Add device</button>
    </div>
  </div>
  <form id="wa-device-name-form" class="wa-device-name" hidden>
    <input id="wa-device-new-name" type="text" maxlength="80" placeholder="Name this phone" aria-label="Phone name" required>
    <button type="submit" class="primary">Create</button>
    <button type="button" class="quiet" id="wa-device-name-cancel">Cancel</button>
  </form>
  <div class="wa-device-layout">
    <div class="wa-device-list-container">
      <div class="wa-device-list-header">
        <span class="wa-device-list-eyebrow" id="wa-device-list-eyebrow">CONFIGURED PHONES (0)</span>
        <span class="wa-device-list-tip">Click 'Pair' to open live scanner</span>
      </div>
      <div id="wa-device-list" class="wa-device-list"></div>
    </div>
    <div class="wa-device-side-panel">
      <!-- Live Scanner Dock (Shown when pairing) -->
      <div id="wa-device-scan-dock" class="card wa-device-scan-dock" hidden>
        <div class="wa-scan-dock-head">
          <div class="wa-scan-dock-live">
            <span class="status-dot pulsing"></span>
            <span>Live QR Scanner</span>
          </div>
          <button type="button" id="wa-device-scan-close" class="wa-scan-close-btn" aria-label="Close scanner">&times;</button>
        </div>
        <div class="wa-scan-dock-body">
          <h3 id="wa-scan-target-title">Scan to link <span id="wa-scan-target-name">Phone</span></h3>
          <p class="muted wa-scan-sub">Open WhatsApp on your mobile phone to complete setup</p>
          <div class="wa-scan-img-wrap">
            <img id="wa-scan-qr-img" alt="Scan this picture with WhatsApp" src="">
          </div>
          <div class="wa-scan-steps">
            <div class="wa-scan-step-item">
              <span class="wa-step-num">1</span>
              <span>Open <strong>WhatsApp</strong> on your phone</span>
            </div>
            <div class="wa-scan-step-item">
              <span class="wa-step-num">2</span>
              <span>Tap <strong>Menu</strong> or <strong>Settings</strong> &gt; <strong>Linked Devices</strong></span>
            </div>
            <div class="wa-scan-step-item">
              <span class="wa-step-num">3</span>
              <span>Point your phone camera at this QR code</span>
            </div>
          </div>
          <div class="wa-scan-dock-footer">
            <span class="muted wa-scan-refreshing">
              <svg class="icon spinning" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
              <span>Auto-refreshing</span>
            </span>
            <button type="button" class="quiet wa-scan-cancel-btn" id="wa-scan-cancel-btn">Close scanner</button>
          </div>
        </div>
      </div>

      <!-- Overview & Capacity Card (Shown when idle) -->
      <div id="wa-device-info-card" class="card wa-device-info-card">
        <div class="wa-device-capacity-section">
          <div class="wa-capacity-head">
            <span class="wa-capacity-title">
              <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/></svg>
              <span>Slot Usage</span>
            </span>
            <strong id="wa-capacity-label" class="wa-capacity-num">0 of 5 Used</strong>
          </div>
          <div class="wa-capacity-track">
            <div id="wa-capacity-fill" class="wa-capacity-fill" style="width: 0%"></div>
          </div>
          <p id="wa-capacity-desc" class="muted wa-capacity-desc">Loading available slots…</p>
        </div>

        <div class="wa-device-features-section">
          <h4>Multi-Device Features</h4>
          <div class="wa-feature-item">
            <span class="wa-feature-check"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg></span>
            <p><strong>Simultaneous Senders:</strong> Run campaigns across multiple numbers simultaneously.</p>
          </div>
          <div class="wa-feature-item">
            <span class="wa-feature-check"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg></span>
            <p><strong>Inbox Routing:</strong> Incoming replies from each phone appear in the Chats tab.</p>
          </div>
          <div class="wa-feature-item">
            <span class="wa-feature-check"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg></span>
            <p><strong>Persistent Sync:</strong> Devices stay linked even when your phone screen turns off.</p>
          </div>
        </div>

        <div class="wa-device-security-pill">
          <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
          </svg>
          <span>End-to-end encrypted session via official WhatsApp Web protocol.</span>
        </div>
      </div>
    </div>
  </div>
</section>
<dialog id="wa-disconnect-dialog" aria-labelledby="wa-disconnect-title">
  <p id="wa-disconnect-title">Disconnecting this phone&hellip;</p>
</dialog>

<section id="wa-settings" class="view" hidden>
  <div class="wa-device-head wa-settings-head">
    <div class="wa-device-head-copy">
      <span class="wa-device-head-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="3"/>
          <path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9c.3.7.9 1.2 1.6 1.3H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>
        </svg>
      </span>
      <div>
        <div class="eyebrow" style="margin-bottom: 2px;">WHATSAPP OPERATIONS</div>
        <h1>Settings</h1>
        <p class="muted wa-overview-desc">Configure the campaign account used for the linked phone. Changes are saved on this desk.</p>
      </div>
    </div>
  </div>
  <div class="wa-settings-layout">
    <form class="card wa-settings-card" id="wa-settings-form">
      <label>Account name
        <input id="wa-settings-name" name="name" type="text" maxlength="160" required autocomplete="off" placeholder="Whatsapp Campaign">
      </label>
      <label>Base URL
        <input id="wa-settings-base" name="base_url" type="url" required autocomplete="off" placeholder="https://wa.rslsolution.com/backend-php/public" spellcheck="false">
      </label>
      <label>Access token
        <textarea id="wa-settings-token" name="access_token" rows="4" required autocomplete="off" spellcheck="false" placeholder="Paste the access token from the Message API"></textarea>
      </label>
      <div class="wa-settings-footer">
        <p id="wa-settings-note" class="muted">Loading the saved account&hellip;</p>
        <button type="submit" class="primary" id="wa-settings-save">Save account</button>
      </div>
    </form>
    <div class="wa-settings-info-panel">
      <div class="card wa-settings-info-card">
        <div class="wa-settings-info-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22">
            <circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>
          </svg>
        </div>
        <h3 class="wa-settings-info-title">How it works</h3>
        <ul class="wa-settings-info-list">
          <li>The <strong>Account name</strong> is a label shown across the desk to identify this integration.</li>
          <li>The <strong>Base URL</strong> is the public endpoint of your WhatsApp Message API server.</li>
          <li>The <strong>Access token</strong> is used to authenticate outbound API requests from this desk.</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<section id="wa-home" class="view" hidden></section>
</div><footer>RSL Communication Desk <span>One workspace. Every conversation.</span></footer><dialog id="email-detail" aria-labelledby="email-detail-tag"><div class="gmail-detail-toolbar"><div class="gmail-toolbar-left"><span class="email-id-tag" id="email-detail-tag">Email</span><span class="email-delivery-pill" id="email-detail-pill">&#9679; Delivered</span></div><div class="gmail-toolbar-right"><button type="button" class="gmail-tool-btn" id="close-email-detail" aria-label="Close dialog">Close</button></div></div><div class="gmail-detail-scroll"><p id="email-detail-message" class="muted" role="status" hidden></p><div id="email-detail-content"></div></div></dialog><dialog id="inbox-detail" aria-labelledby="inbox-detail-title"><div class="queue-header"><h2 id="inbox-detail-title">Message details</h2><div class="dialog-actions"><button type="button" class="primary" id="inbox-reply-btn"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5v2.5"/></svg>Reply</button><button type="button" class="quiet" id="inbox-toggle-read-btn">Mark unread</button><button type="button" class="quiet" id="close-inbox-detail">Close</button></div></div><p id="inbox-detail-message" class="muted" role="status"></p><div id="inbox-detail-content"></div></dialog><dialog id="reset-dialog" aria-labelledby="reset-title"><div class="reset-dialog-wrap"><div class="queue-header"><h2 id="reset-title">Reset Administrator Password</h2><button type="button" id="close-reset" class="reset-close-btn" aria-label="Close dialog">&times;</button></div><div id="reset-step-1"><p class="muted reset-subtitle">Enter your administrator email address. We'll send a 6-digit verification code to your inbox.</p><form id="reset-request-form"><label>Administrator email<input id="reset-req-email" name="email" type="email" placeholder="e.g. ray@rslsolution.com" required autocomplete="email"></label><p id="reset-req-error" role="alert" class="email-detail-error" hidden></p><div class="form-footer reset-footer"><button class="primary reset-submit-btn" type="submit" id="reset-req-btn">Send verification code &rarr;</button></div></form><div id="reset-local-bypass-wrap" class="reset-bypass-card" hidden><button type="button" class="reset-bypass-btn" id="reset-local-bypass-btn">Running on local computer? Reset directly &rarr;</button></div></div><div id="reset-step-2" hidden><p class="muted reset-subtitle" id="reset-sent-notice">A 6-digit code was sent to your email. Enter it below along with your new password.</p><form id="reset-verify-form"><label>6-Digit verification code<input id="reset-verify-code" name="code" type="text" pattern="[0-9]{6}" maxlength="6" placeholder="123456" required autocomplete="one-time-code"></label><label>New password<input id="reset-verify-password" name="password" type="password" autocomplete="new-password" minlength="6" maxlength="64" required placeholder="At least 6 characters"></label><label>Confirm new password<input id="reset-verify-confirm" name="confirmation" type="password" autocomplete="new-password" minlength="6" maxlength="64" required placeholder="Re-enter password"></label><p id="reset-verify-error" role="alert" class="email-detail-error" hidden></p><div class="form-footer reset-footer"><button type="button" id="reset-back-to-step1" class="reset-back-btn">&larr; Back</button><button class="primary reset-submit-btn" type="submit" id="reset-verify-btn">Save &amp; Sign in &rarr;</button></div></form></div><div id="reset-step-local" hidden><p class="muted reset-subtitle">Local computer access verified. Set a new administrator password directly.</p><form id="reset-local-form"><label>New password<input id="reset-local-password" name="password" type="password" autocomplete="new-password" minlength="6" maxlength="64" required placeholder="At least 6 characters"></label><label>Confirm new password<input id="reset-local-confirm" name="confirmation" type="password" autocomplete="new-password" minlength="6" maxlength="64" required placeholder="Re-enter password"></label><p id="reset-local-error" role="alert" class="email-detail-error" hidden></p><div class="form-footer reset-footer"><button type="button" id="reset-local-back" class="reset-back-btn">&larr; Back</button><button class="primary reset-submit-btn" type="submit">Save &amp; Sign in &rarr;</button></div></form></div></div></dialog><dialog id="batch-progress-dialog" class="batch-progress-dialog" aria-labelledby="batch-progress-title"><div class="batch-modal-wrap"><div class="batch-modal-header"><div class="batch-modal-status-badge"><span class="status-dot pulsing"></span><span id="batch-progress-badge">Dispatched live via SMTP</span></div><h2 id="batch-progress-title">Sending emails&hellip;</h2><p id="batch-progress-subtitle" class="muted">Live SMTP dispatch in progress. Please keep this tab open.</p></div><div class="batch-meter-section"><div class="batch-counter-row"><span class="batch-counter-label" id="batch-counter-text">0 / 0 sent</span><span class="batch-counter-percent" id="batch-counter-percent">0%</span></div><div class="batch-progress-track"><div class="batch-progress-fill" id="batch-progress-bar" style="width: 0%"></div></div></div><div class="batch-stats-grid"><div class="batch-stat-card is-success"><span class="batch-stat-num" id="batch-stat-sent">0</span><span class="batch-stat-lbl">Successfully Sent</span></div><div class="batch-stat-card is-failed"><span class="batch-stat-num" id="batch-stat-failed">0</span><span class="batch-stat-lbl">Failed</span></div><div class="batch-stat-card is-remaining"><span class="batch-stat-num" id="batch-stat-remaining">0</span><span class="batch-stat-lbl">Remaining</span></div></div><div class="batch-log-wrapper"><div class="batch-log-title">Recent Activity</div><div class="batch-log-list" id="batch-log-list"><div class="batch-log-item muted">&#9889; Initializing persistent SMTP connection&hellip;</div></div></div><div class="batch-modal-footer"><button type="button" class="primary batch-done-btn" id="batch-done-btn" hidden>Done &amp; View Records</button><button type="button" class="quiet batch-cancel-btn" id="batch-cancel-btn">Stop Remaining</button></div></div></dialog><dialog id="send-confirm-dialog" class="send-confirm-dialog" aria-labelledby="send-confirm-title"><div class="confirm-modal-wrap"><div class="confirm-modal-top"><div class="confirm-modal-icon-wrap"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg></div><div><h3 id="send-confirm-title" class="confirm-title">Confirm Email Dispatch</h3><p id="send-confirm-desc" class="muted confirm-subtitle">Ready to send via configured SMTP.</p></div></div><div id="send-confirm-preview-box" class="confirm-preview-box"><div class="confirm-preview-row" id="send-confirm-from-row"><span class="confirm-lbl">Send From</span><select id="send-confirm-sender-select" class="confirm-sender-select" aria-label="Confirm sender mailbox"></select></div><div class="confirm-preview-row"><span class="confirm-lbl">Recipient</span><strong id="send-confirm-recip" class="confirm-val"></strong></div><div class="confirm-preview-row"><span class="confirm-lbl">Subject</span><span id="send-confirm-subj" class="confirm-val"></span></div><div class="confirm-preview-row" id="send-confirm-batch-info" hidden><span class="confirm-lbl">Total to send</span><span id="send-confirm-count" class="confirm-val confirm-pill"></span></div></div><div class="confirm-modal-actions"><button type="button" class="quiet" id="send-confirm-cancel-btn">Cancel</button><button type="button" class="primary confirm-send-action-btn" id="send-confirm-ok-btn"><span>Send Email &rarr;</span></button></div></div></dialog>

<!-- Mailbox Modal -->
<dialog id="mailbox-modal" class="config-modal" aria-labelledby="mailbox-modal-title">
  <div class="config-modal-wrap">
    <div class="queue-header">
      <div>
        <h2 id="mailbox-modal-title">Connect Mailbox</h2>
        <p class="muted" id="mailbox-modal-sub">Add an email account for sending and receiving.</p>
      </div>
      <button type="button" class="quiet" id="close-mailbox-modal">&times;</button>
    </div>
    <form id="mailbox-form">
      <input type="hidden" name="id" id="mailbox-id" value="">
      
      <div class="modal-form-grid">
        <label>
          <span>Display Name</span>
          <input name="name" id="mb-name" type="text" required placeholder="Display Name">
        </label>
        <label>
          <span>Email Address</span>
          <input name="email" id="mb-email" type="email" required placeholder="name@example.com">
        </label>
      </div>

      <div class="modal-sub-divider">
        <span>Outgoing Mail (SMTP)</span>
      </div>

      <div class="modal-form-grid three-col">
        <label style="grid-column: span 2">
          <span>SMTP Host</span>
          <input name="smtp_host" id="mb-smtp-host" type="text" required value="smtp.hostinger.com">
        </label>
        <label>
          <span>Port</span>
          <input name="smtp_port" id="mb-smtp-port" type="number" required value="465">
        </label>
      </div>

      <div class="modal-form-grid">
        <label>
          <span>SMTP Username</span>
          <input name="smtp_user" id="mb-smtp-user" type="text" required placeholder="name@example.com">
        </label>
        <label>
          <span>SMTP Password</span>
          <input name="smtp_pass" id="mb-smtp-pass" type="password" placeholder="Leave blank to keep existing password">
        </label>
      </div>

      <div style="margin: 8px 0 14px">
        <label class="modal-checkbox-row">
          <input type="checkbox" id="mb-same-creds" checked>
          <span>Use same host, port &amp; password for incoming IMAP inbox</span>
        </label>
      </div>

      <div id="mb-imap-section" hidden>
        <div class="modal-sub-divider">
          <span>Incoming Mail (IMAP)</span>
        </div>
        <div class="modal-form-grid three-col">
          <label style="grid-column: span 2">
            <span>IMAP Host</span>
            <input name="imap_host" id="mb-imap-host" type="text" value="imap.hostinger.com">
          </label>
          <label>
            <span>Port</span>
            <input name="imap_port" id="mb-imap-port" type="number" value="993">
          </label>
        </div>
        <div class="modal-form-grid">
          <label>
            <span>IMAP Username</span>
            <input name="imap_user" id="mb-imap-user" type="text" placeholder="name@example.com">
          </label>
          <label>
            <span>IMAP Password</span>
            <input name="imap_pass" id="mb-imap-pass" type="password" placeholder="Leave blank to keep existing password">
          </label>
        </div>
      </div>

      <div id="mb-test-result" class="modal-test-banner" hidden></div>

      <div class="form-footer modal-footer-actions">
        <button type="button" class="quiet" id="btn-test-mailbox">
          <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          <span>Test Connection</span>
        </button>
        <div style="margin-left:auto;display:flex;gap:8px">
          <button type="button" class="quiet" id="btn-cancel-mailbox">Cancel</button>
          <button type="submit" class="primary" id="btn-save-mailbox">Save Mailbox</button>
        </div>
      </div>
    </form>
  </div>
</dialog></main></body></html>
