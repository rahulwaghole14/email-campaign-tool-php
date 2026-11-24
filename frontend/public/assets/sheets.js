(() => {
  const earlySheet = document.getElementById('early-sheet-style');
  if (earlySheet) {
    const dir = document.getElementById('sheets-directory');
    const preview = document.getElementById('sheet-preview');
    if (dir) dir.hidden = true;
    if (preview) preview.hidden = false;
    const sheetsSec = document.getElementById('sheets');
    if (sheetsSec) sheetsSec.classList.add('in-preview');
    earlySheet.remove();
  }
  const fields = {id:'Record ID',name:'Agency / recipient name',email:'Email',subject:'Email subject',body:'Email content',link:'Drive link',cc:'CC',bcc:'BCC',enabled:'Send Enabled',followup1_subject:'Follow-up 1 subject',followup1_body:'Follow-up 1 message',followup2_subject:'Follow-up 2 subject',followup2_body:'Follow-up 2 message'};
  const aliases = {id:['id','record_id'], name:['agency name','recipient_name'],email:['email','recipient_email'],subject:['email subject','subject'],body:['email content','message'],link:['drive_link','drive link'],cc:['cc'],bcc:['bcc'],enabled:['send enabled','send_enabled'],followup1_subject:['follow-up subject 1','follow-up email 1','followup email 1'],followup1_body:['follow-up content 1','follow-up message 1','follow-up email content 1'],followup2_subject:['follow-up subject 2','follow-up email 2','followup email 2'],followup2_body:['follow-up content 2','follow-up message 2','follow-up email content 2']};
  const waFields = {id:'Record ID',name:'Agency name',phone:'Phone number',message:'WhatsApp content'};
  // Message aliases: WhatsApp-specific names first, then plain "Message" (shared email+WA sheets).
  const waAliases = {id:['id','record id','record_id'],name:['agency name','name'],phone:['phone','phone number','mobile','whatsapp number'],message:['whatsapp content','whatsapp message','message','content']};
  const sheetMode = () => document.body.dataset.channel === 'whatsapp' ? 'whatsapp' : 'email';
  const activeFields = () => sheetMode() === 'whatsapp' ? waFields : fields;
  const activeAliases = () => sheetMode() === 'whatsapp' ? waAliases : aliases;
  const sheetApi = (action = '') => {
    const root = sheetMode() === 'whatsapp' ? 'whatsapp/sheets' : 'sheets';
    return action ? `${root}/${String(action).replace(/^\//, '')}` : root;
  };
  const openSheetKey = () => sheetMode() === 'whatsapp' ? 'rsl_open_wa_sheet' : 'rsl_open_sheet';
  const rowHaystack = row => {
    const values = row.values || {};
    const parts = sheetMode() === 'whatsapp'
      ? [row.record_id, values.name, values.phone, values.message]
      : [row.record_id, values.name, values.email, values.subject];
    return parts.map(value => String(value || '').toLowerCase());
  };
  let sheetChannel = sheetMode();
  let inspectedUrl = '', source = null, records = [], page = 0, editing = null, mappingId = null, savedMapping = null, openedId = null, sheetFilter = '';
  let uploadId = null;
  let uploadLimit = 10 * 1024 * 1024;
  let allSources = [];
  const selectedIds = new Set();
  const isUpload = item => item?.spreadsheet_id?.startsWith('upload:');
  const sourceInput = () => uploadId ? {upload_id:uploadId} : {url:inspectedUrl};
  const post = (path, data = {}) => api(path, {method:'POST',body:JSON.stringify(data)});
  const element = (tag, text, className) => { const el = document.createElement(tag); if (text !== undefined) el.textContent = text; if (className) el.className = className; return el; };
  const canAddress = row => !row.issues?.length && (!('enabled' in row.values) || String(row.values.enabled || '').trim().toUpperCase() === 'YES');

  // WhatsApp Skip persistence in sessionStorage
  const getWaSkippedKey = sourceId => 'rsl_wa_skipped_' + String(sourceId || '');
  const loadWaSkipped = sourceId => {
    try {
      const raw = sessionStorage.getItem(getWaSkippedKey(sourceId));
      return new Set(raw ? JSON.parse(raw) : []);
    } catch {
      return new Set();
    }
  };
  const saveWaSkipped = (sourceId, set) => {
    try {
      sessionStorage.setItem(getWaSkippedKey(sourceId), JSON.stringify(Array.from(set)));
    } catch {}
  };
  let waSkippedIds = new Set();
  const isRowSkipped = recordId => waSkippedIds.has(String(recordId));

  // WhatsApp: Ready includes never-queued rows and follow-ups whose mapped message differs from the last send, excluding user-skipped rows.
  const isWaQueueable = row => canAddress(row) && (
    row.can_queue === true
    || (row.can_queue == null && (row.send_status === 'not_queued' || !row.send_status))
  );
  const isNotSent = row => sheetMode() === 'whatsapp'
    ? (isWaQueueable(row) && !isRowSkipped(row.record_id))
    : (canAddress(row) && (row.send_status === 'not_queued' || !row.send_status));
  const isPendingRow = row => canAddress(row) && (row.send_status === 'pending' || row.send_status === 'processing');
  const isNoWaError = row => /not registered on whatsapp/i.test(String(row.send_error || row.error || ''));
  const isFailedRow = row => canAddress(row) && row.send_status === 'failed' && !isNoWaError(row);
  const isNoWaRow = row => canAddress(row) && row.send_status === 'failed' && isNoWaError(row);
  const waWasSent = row => row.send_status === 'sent' || row.send_status === 'failed';
  const isRowValid = isNotSent;
  const pendingSendIds = () => (records || []).filter(row => canAddress(row) && row.send_status === 'pending').map(row => row.record_id);
  const failedSendIds = () => (records || []).filter(isFailedRow).map(row => row.record_id);
  const rowMatchesFilter = (row, filter) => {
    if (!filter) return true;
    if (filter === 'issues') return row.issues.length > 0;
    if (filter === 'ready') return isNotSent(row);
    if (filter === 'pending') return isPendingRow(row);
    if (filter === 'failed') return isFailedRow(row);
    if (filter === 'nowa') return isNoWaRow(row);
    return row.send_status === filter;
  };
  const syncPill = (label) => {
    const pill = $('#sheet-sync-status');
    if (!pill) return;
    pill.innerHTML = `<svg viewBox="0 0 24 24" class="icon" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>${label}`;
  };

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
  }

  async function busy(button, work) { button.disabled = true; try { await work(); } catch(e) { notice(e.message,true); } finally { button.disabled = false; } }

  function renderSourcesList(items, isGoogleConnected) {
    const list = $('#sheet-sources');
    if (!list) return;
    list.replaceChildren();
    const countBadge = document.getElementById('saved-sheet-count-badge');
    if (countBadge) {
      countBadge.textContent = items.length === 1 ? '1 source' : `${items.length} sources`;
    }
    if (!items.length) {
      list.innerHTML = `<div class="empty" style="padding:28px 16px;text-align:center"><p class="muted">No sheets found. Add a Google Sheet link or upload a spreadsheet below.</p></div>`;
      return;
    }
    for (const item of items) {
      const card = document.createElement('div');
      card.className = 'sheet-source-card';
      const isUp = isUpload(item);
      const iconHtml = isUp
        ? `<div class="source-icon-badge excel"><svg viewBox="0 0 24 24"><path fill="#107C41" d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/><path fill="#fff" d="M15.5 17l-2.4-4.5 2.3-4.5h-2.1l-1.3 3.1-1.3-3.1H8.6l2.3 4.5-2.4 4.5h2.1l1.4-3.3 1.4 3.3z"/></svg></div>`
        : `<div class="source-icon-badge google"><svg viewBox="0 0 24 24"><path fill="#0F9D58" d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/><path fill="#fff" d="M9 7h6v2H9zm0 4h6v2H9zm0 4h6v2H9z"/></svg></div>`;

      const badgeHtml = isUp
        ? `<span class="badge">Excel / CSV Upload</span>`
        : `<span class="badge badge-google">Google Sheet</span>`;

      card.innerHTML = `
        <div class="sheet-source-main">
          ${iconHtml}
          <div class="sheet-source-info">
            <div class="sheet-source-title-row">
              <strong class="sheet-source-title" title="${escapeHtml(item.title)}">${escapeHtml(item.title)}</strong>
              ${badgeHtml}
              ${sheetMode() === 'whatsapp' ? '<span class="badge badge-ready">• Ready</span>' : ''}
            </div>
            <div class="sheet-source-meta">
              <span>Tab: <strong>${escapeHtml(item.tab_title || (item.sheet_id === 0 ? 'First tab' : (String(item.sheet_id).length > 5 ? 'Tab ID: ' + item.sheet_id : 'Tab #' + item.sheet_id)))}</strong></span>
              <span class="meta-dot">·</span>
              <span>Header row: <strong>${item.header_row}</strong></span>
              ${sheetMode() === 'whatsapp' ? '<span class="meta-dot">·</span><span class="sheet-meta-ready">Mapped to Phone &amp; Message</span>' : ''}
            </div>
          </div>
        </div>
        <div class="sheet-source-actions">
          <button type="button" class="sheet-open-btn" data-id="${item.id}">
            <span>Open workspace</span>
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
          </button>
          <button type="button" class="sheet-delete-btn" data-id="${item.id}" title="Remove sheet source" aria-label="Remove sheet source">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M10 11v6M14 11v6"/></svg>
          </button>
        </div>
      `;

      const openBtn = card.querySelector('.sheet-open-btn');
      openBtn.disabled = !isGoogleConnected && !isUp;
      openBtn.addEventListener('click', () => busy(openBtn, () => openSource(item.id)));

      const deleteBtn = card.querySelector('.sheet-delete-btn');
      deleteBtn.addEventListener('click', async (e) => {
        e.stopPropagation();
        if (confirm(`Remove "${item.title}" from your saved sheets?`)) {
          busy(deleteBtn, async () => {
            await api(sheetApi(item.id), { method: 'DELETE' });
            notice(`Sheet source "${item.title}" removed.`);
            await window.loadSheets();
          });
        }
      });

      list.append(card);
    }
  }

  function applySheetChrome() {
    const wa = sheetMode() === 'whatsapp';
    const title = document.querySelector('#sheets .title-row h1');
    const intro = document.querySelector('#sheets .title-row p');
    const eyebrow = document.querySelector('#sheets .title-row .eyebrow');
    const headIcon = document.querySelector('#sheets .wa-sheets-head-icon');
    if (headIcon) headIcon.style.display = wa ? 'flex' : 'none';
    if (title) title.textContent = wa ? 'WhatsApp sheets' : 'Your sheets';
    if (intro) {
      if (wa) {
        const count = allSources.length;
        const countTxt = count === 0 ? 'No active sheets' : (count === 1 ? '1 active sheet' : `${count} active sheets`);
        intro.innerHTML = `<span class="wa-live-dot-text"><span class="status-dot"></span><span id="wa-sheet-count-text">${countTxt}</span></span> &middot; <span>Sync with live Google Sheets or Excel</span> &middot; <span>Write-back enabled</span>`;
      } else {
        intro.textContent = 'Upload a spreadsheet or connect Google Sheets to manage your records.';
      }
    }
    if (eyebrow) eyebrow.textContent = wa ? 'WHATSAPP DATA SOURCES' : 'YOUR DATA SOURCES';
    const search = $('#sheet-search');
    if (search) search.placeholder = wa ? 'Search ID, name, phone, or message' : 'Search ID, agency, email, or subject';
    const idHead = $('#sheet-col-id');
    const mid = $('#sheet-col-mid');
    const next = $('#sheet-col-next');
    const extra = $('#sheet-col-extra');
    const devCol = $('#sheet-col-device');
    if (idHead) idHead.textContent = 'ID';
    if (extra) {
      extra.textContent = 'Agency / Name';
      extra.hidden = false;
    }
    if (mid) mid.textContent = wa ? 'Phone' : 'Email';
    if (next) next.textContent = wa ? 'Message' : 'Subject';
    if (devCol) devCol.hidden = !wa;
    const readyLabel = document.querySelector('#sheet-quick-tabs [data-tab-filter="ready"]');
    if (readyLabel && readyLabel.firstChild) readyLabel.firstChild.nodeValue = wa ? 'Ready ' : 'Not sent ';
  }

  window.loadSheets = async () => {
    try {
      const mode = sheetMode();
      if (mode !== sheetChannel) {
        source = null;
        openedId = null;
        records = [];
        selectedIds.clear();
        sheetFilter = '';
        $('#sheet-preview').hidden = true;
        $('#sheets-directory').hidden = false;
        document.getElementById('sheets')?.classList.remove('in-preview');
        sheetChannel = mode;
      }
      applySheetChrome();
      const [status, data] = await Promise.all([api('google/status'), api(sheetApi())]);
      uploadLimit = status.upload_limit || uploadLimit;
      allSources = data.sources || [];
      window.googleStatusConnected = status.connected;

      const countEl = document.getElementById('wa-sheet-count-text');
      if (countEl && sheetMode() === 'whatsapp') {
        const count = allSources.length;
        countEl.textContent = count === 0 ? 'No active sheets' : (count === 1 ? '1 active sheet' : `${count} active sheets`);
      }

      const limitEl = $('#upload-limit');
      if (limitEl) limitEl.textContent = `Excel (.xlsx) or CSV, up to ${(uploadLimit / 1024 / 1024).toFixed(1).replace('.0','')} MB`;

      const statusPill = $('#google-status-pill');
      if (statusPill) {
        statusPill.hidden = !status.connected;
        statusPill.style.display = status.connected ? 'inline-flex' : 'none';
      }
      const disconnectBtn = $('#google-disconnect');
      if (disconnectBtn) {
        disconnectBtn.hidden = !status.connected;
        disconnectBtn.style.display = status.connected ? 'inline-flex' : 'none';
      }

      const connectBtn = $('#google-connect');
      if (connectBtn) {
        connectBtn.hidden = status.connected;
        connectBtn.style.display = status.connected ? 'none' : 'inline-flex';
        connectBtn.textContent = 'Connect Google';
      }

      const linkBtn = $('#sheet-link-btn');
      if (linkBtn) linkBtn.disabled = !status.connected;

      renderSourcesList(allSources, status.connected);
      updateFilterChipCounts();

      const routeRaw = (location.hash || '').replace(/^#/, '');
      const routeBits = routeRaw.split('/');
      const hashToken = routeBits[0] === 'sheets' ? (routeBits[1] || '') : '';
      const hashId = sheetMode() === 'whatsapp'
        ? (hashToken.startsWith('wa-') ? hashToken.slice(3) : '')
        : (hashToken.startsWith('wa-') ? '' : hashToken);
      if (hashToken && !hashId) history.replaceState(null, '', '#sheets');
      const routeId = (hashId && allSources.some(item => String(item.id) === String(hashId)))
        ? hashId
        : (localStorage.getItem(openSheetKey()) || '');
      const savedSource = routeId ? allSources.find(item => String(item.id) === String(routeId)) : null;
      if (savedSource && Number(openedId) !== Number(savedSource.id)) {
        try {
          await openSource(savedSource.id);
        } catch (err) {
          localStorage.removeItem(openSheetKey());
          openedId = null;
          $('#sheets-directory').hidden = false;
          $('#sheet-preview').hidden = true;
          document.getElementById('sheets')?.classList.remove('in-preview');
          throw err;
        }
      } else if (!openedId) {
        $('#sheets-directory').hidden = false;
        $('#sheet-preview').hidden = true;
        document.getElementById('sheets')?.classList.remove('in-preview');
      }
      if (!status.connected) { if (!isUpload(source)) { $('#sheet-preview').hidden = true; $('#sheets-directory').hidden = false; document.getElementById('sheets')?.classList.remove('in-preview'); } if (!uploadId && !mappingId) $('#sheet-map-form').hidden = true; }
    } catch(e) {notice(e.message,true);}
  };

  let sourceFilterType = 'all';

  function applySourceFilters() {
    const searchInput = $('#saved-sheet-search');
    const term = searchInput ? searchInput.value.toLowerCase().trim() : '';
    let filtered = allSources;
    if (sourceFilterType === 'excel') {
      filtered = filtered.filter(s => isUpload(s));
    } else if (sourceFilterType === 'google') {
      filtered = filtered.filter(s => !isUpload(s));
    }
    if (term) {
      filtered = filtered.filter(s => (s.title || '').toLowerCase().includes(term));
    }
    renderSourcesList(filtered, window.googleStatusConnected);
    updateFilterChipCounts();
  }

  function updateFilterChipCounts() {
    const total = allSources.length;
    const excelCount = allSources.filter(s => isUpload(s)).length;
    const googleCount = allSources.filter(s => !isUpload(s)).length;
    const chips = document.querySelectorAll('#sheet-source-filter-chips .sheet-filter-chip');
    chips.forEach(chip => {
      const type = chip.dataset.type;
      if (type === 'all') chip.textContent = `All (${total})`;
      else if (type === 'excel') chip.textContent = `Excel (${excelCount})`;
      else if (type === 'google') chip.textContent = `Google (${googleCount})`;
    });
  }

  document.querySelectorAll('#sheet-source-filter-chips .sheet-filter-chip').forEach(chip => {
    chip.addEventListener('click', () => {
      document.querySelectorAll('#sheet-source-filter-chips .sheet-filter-chip').forEach(c => c.classList.remove('active'));
      chip.classList.add('active');
      sourceFilterType = chip.dataset.type || 'all';
      applySourceFilters();
    });
  });

  $('#saved-sheet-search')?.addEventListener('input', () => {
    applySourceFilters();
  });

  const dropzone = $('#dropzone-box');
  const fileInput = $('#sheet-file');

  dropzone?.addEventListener('click', () => fileInput?.click());

  fileInput?.addEventListener('change', () => {
    if (fileInput.files?.length) {
      processUpload(fileInput.files[0]);
    }
  });

  ['dragenter', 'dragover'].forEach(name => {
    dropzone?.addEventListener(name, e => {
      e.preventDefault();
      dropzone.classList.add('dropzone-active');
    });
  });

  ['dragleave', 'drop'].forEach(name => {
    dropzone?.addEventListener(name, e => {
      e.preventDefault();
      dropzone.classList.remove('dropzone-active');
    });
  });

  dropzone?.addEventListener('drop', e => {
    e.preventDefault();
    dropzone.classList.remove('dropzone-active');
    if (e.dataTransfer?.files?.length) {
      processUpload(e.dataTransfer.files[0]);
    }
  });

  async function processUpload(file) {
    if (!file) return;
    if (file.size > uploadLimit) {
      notice(`Choose an Excel or CSV file within the ${(uploadLimit / 1024 / 1024).toFixed(1)} MB upload limit.`, true);
      return;
    }
    const dropzoneBox = $('#dropzone-box');
    try {
      if (dropzoneBox) dropzoneBox.style.opacity = '0.5';
      notice(`Uploading ${file.name}…`);
      const form = new FormData();
      form.append('file', file);
      const response = await fetch('/api/sheets/upload', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrf },
        body: form
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Upload failed.');
      uploadId = data.upload_id;
      mappingId = null;
      savedMapping = null;
      $('#sheet-tab').disabled = false;
      // WhatsApp/CSV sheets almost always start at row 1; some email company templates put titles lower.
      $('#sheet-header').value = (file.name.toLowerCase().endsWith('.csv') || sheetMode() === 'whatsapp') ? 1 : 6;
      displayTabs(data.metadata);
      $('#sheet-map-form').scrollIntoView({ behavior: 'smooth', block: 'center' });
      notice('File uploaded. Confirm the title row (usually 1), then Read & Detect Columns.');
    } catch (err) {
      notice(err.message, true);
    } finally {
      if (dropzoneBox) dropzoneBox.style.opacity = '1';
      if (fileInput) fileInput.value = '';
    }
  }

  $('#google-connect').addEventListener('click', event => busy(event.currentTarget, async () => { const data = await post('google/connect'); location.assign(data.url); }));
  $('#google-disconnect').addEventListener('click', event => busy(event.currentTarget, async () => { await post('google/disconnect'); await window.loadSheets(); notice('Google disconnected. Saved source settings are retained.'); }));
  $('#refresh-sources').addEventListener('click', window.loadSheets);

  function clearMapping() {
    const form = $('#sheet-map-form');
    if (form) form.classList.add('is-awaiting-detect');
    const mappings = $('#sheet-mappings');
    if (mappings) {
      mappings.replaceChildren();
      mappings.hidden = true;
    }
    const saveBtn = $('#save-source');
    if (saveBtn) saveBtn.hidden = true;
    const actionsBar = $('#mapping-actions');
    if (actionsBar) actionsBar.hidden = true;
    const nextHint = $('#mapping-next-hint');
    if (nextHint) nextHint.hidden = false;
    const previewBanner = $('#detected-columns-preview');
    if (previewBanner) {
      previewBanner.hidden = true;
      previewBanner.replaceChildren();
    }
  }
  $('#sheet-url').addEventListener('input', () => { $('#sheet-map-form').hidden = true; clearMapping(); mappingId = null; savedMapping = null; uploadId = null; });
  $('#sheet-tab').addEventListener('change', clearMapping); $('#sheet-header').addEventListener('input', clearMapping);

  async function readTabs(url, selectedTab = null) {
    inspectedUrl = url;
    const data = await post('sheets/inspect', sourceInput());
    displayTabs(data, selectedTab);
  }

  function displayTabs(data, selectedTab = null) {
    $('#sheet-tab').replaceChildren();
    for (const tab of data.sheets || []) { const option = element('option',tab.properties.title); option.value = tab.properties.sheetId; $('#sheet-tab').append(option); }
    if (selectedTab !== null) $('#sheet-tab').value = selectedTab;
    const mapForm = $('#sheet-map-form');
    if (mapForm) {
      mapForm.hidden = false;
      mapForm.classList.add('is-awaiting-detect');
    }
    clearMapping();
    $('#mapping-source-label').textContent = `Map columns for ${data.properties.title} (${uploadId ? 'uploaded copy' : 'Google Sheet'})`;
  }

  $('#sheet-link-form').addEventListener('submit', event => { event.preventDefault(); busy(event.submitter, async () => { uploadId = null; mappingId = null; savedMapping = null; $('#sheet-tab').disabled = false; await readTabs($('#sheet-url').value); }); });
  $('#sheet-upload-form').addEventListener('submit', event => {
    event.preventDefault();
    const file = $('#sheet-file').files[0];
    if (file) processUpload(file);
    else $('#sheet-file').click();
  });

  async function readHeaders() {
    const data = await post('sheets/inspect', {...sourceInput(),sheet_id:Number($('#sheet-tab').value),header_row:Number($('#sheet-header').value)});
    clearMapping();

    const headers = (data.headers || []).filter(Boolean);
    const previewBanner = $('#detected-columns-preview');
    if (previewBanner) {
      previewBanner.hidden = false;
      previewBanner.innerHTML = `
        <div class="detected-columns-header">
          <div class="detected-columns-summary">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
            <span><strong>${headers.length} columns detected</strong> from title row ${Number($('#sheet-header').value)}</span>
          </div>
          <span class="detected-columns-hint">Check the mapped fields below, then save</span>
        </div>
        <div class="detected-columns-chips">
          ${headers.map(h => `<span class="col-chip">${escapeHtml(h)}</span>`).join('')}
        </div>
      `;
    }

    const wa = sheetMode() === 'whatsapp';
    const labels = activeFields();
    const headerAliases = activeAliases();
    const requiredKeys = wa ? ['phone', 'message'] : ['id', 'email', 'subject', 'body'];
    const optionalKeys = wa ? ['id', 'name'] : ['name', 'link', 'cc', 'bcc', 'enabled'];
    const followKeys = wa ? [] : ['followup1_subject', 'followup1_body', 'followup2_subject', 'followup2_body'];

    const createFieldCard = (key, label, isRequired) => {
      const card = element('div', undefined, 'mapping-field-card' + (isRequired ? ' is-required' : ''));
      const headerRow = element('div', undefined, 'mapping-field-header');
      const labelText = element('span', label, 'mapping-field-title');
      const badge = element('span', isRequired ? 'Required' : 'Optional', isRequired ? 'mapping-badge required' : 'mapping-badge optional');
      headerRow.append(labelText, badge);

      const select = element('select');
      select.name = key;
      select.required = isRequired;
      const empty = element('option', isRequired ? 'Select column…' : 'Not mapped (optional)');
      empty.value = '';
      select.append(empty);

      for (const header of headers) {
        const option = element('option', header);
        option.value = header;
        select.append(option);
      }

      // Prefer alias order (e.g. "WhatsApp content" before plain "Message" on mixed sheets).
      let autoValue = savedMapping?.[key] || '';
      if (!autoValue) {
        for (const alias of (headerAliases[key] || [])) {
          const hit = headers.find(header => header.toLowerCase() === alias);
          if (hit) { autoValue = hit; break; }
        }
      }
      select.value = autoValue;
      if (mappingId && key === 'id') select.disabled = true;

      const syncStatus = () => {
        card.classList.toggle('is-mapped', Boolean(select.value));
      };
      select.addEventListener('change', syncStatus);
      syncStatus();

      card.append(headerRow, select);
      return card;
    };

    const sections = [];
    if (wa) {
      // One row: required Phone/Message + optional ID/Name (wide screens).
      const waSection = element('div', undefined, 'mapping-section');
      waSection.innerHTML = `<div class="mapping-section-title"><span class="section-pill required">1</span> Map columns (Phone &amp; message required)</div><div class="mapping-section-grid mapping-section-grid--wa"></div>`;
      const waGrid = waSection.querySelector('.mapping-section-grid');
      requiredKeys.forEach(key => waGrid.append(createFieldCard(key, labels[key], true)));
      optionalKeys.forEach(key => waGrid.append(createFieldCard(key, labels[key], false)));
      sections.push(waSection);
    } else {
      const reqSection = element('div', undefined, 'mapping-section');
      reqSection.innerHTML = `<div class="mapping-section-title"><span class="section-pill required">1</span> Mandatory Email Fields (Required to dispatch)</div><div class="mapping-section-grid"></div>`;
      const reqGrid = reqSection.querySelector('.mapping-section-grid');
      requiredKeys.forEach(key => reqGrid.append(createFieldCard(key, labels[key], true)));

      const optSection = element('div', undefined, 'mapping-section');
      optSection.innerHTML = `<div class="mapping-section-title"><span class="section-pill optional">2</span> Optional Parameters (Name, Drive link, CC, BCC, Send Enabled)</div><div class="mapping-section-grid"></div>`;
      const optGrid = optSection.querySelector('.mapping-section-grid');
      optionalKeys.forEach(key => optGrid.append(createFieldCard(key, labels[key], false)));
      sections.push(reqSection, optSection);
    }
    if (followKeys.length) {
      const followSection = element('div', undefined, 'mapping-section');
      followSection.innerHTML = `<div class="mapping-section-title"><span class="section-pill optional">${wa ? '2' : '3'}</span> Follow-up mails (subject and message for each step)</div><div class="mapping-section-grid"></div>`;
      const followGrid = followSection.querySelector('.mapping-section-grid');
      followKeys.forEach(key => followGrid.append(createFieldCard(key, labels[key], false)));
      sections.push(followSection);
    }
    $('#sheet-mappings').append(...sections);

    const form = $('#sheet-map-form');
    if (form) form.classList.remove('is-awaiting-detect');
    const mappingsWrap = $('#sheet-mappings');
    if (mappingsWrap) mappingsWrap.hidden = false;
    const nextHint = $('#mapping-next-hint');
    if (nextHint) nextHint.hidden = true;
    const actionsBar = $('#mapping-actions');
    if (actionsBar) actionsBar.hidden = false;
    const saveBtn = $('#save-source');
    if (saveBtn) saveBtn.hidden = false;
  }
  $('#read-headers').addEventListener('click', event => busy(event.currentTarget, readHeaders));

  $('#cancel-mapping-btn')?.addEventListener('click', () => {
    $('#sheet-map-form').hidden = true;
    clearMapping();
    mappingId = null;
    savedMapping = null;
    uploadId = null;
    $('#sheets-directory').hidden = false;
  });

  $('#sheet-map-form').addEventListener('submit', event => { event.preventDefault(); busy(event.submitter, async () => {
    const mapping = {}; $('#sheet-mappings').querySelectorAll('select').forEach(select => mapping[select.name] = select.value);
    const data = await post(mappingId ? sheetApi(`${mappingId}/mapping`) : sheetApi(), {...sourceInput(),sheet_id:Number($('#sheet-tab').value),header_row:Number($('#sheet-header').value),mapping});
    $('#sheet-map-form').hidden = true; await window.loadSheets(); await openSource(data.id); notice('Sheet source saved.');
  }); });

  function updateBatchBar() {
    if (sheetMode() === 'whatsapp') {
      const waBar = $('#batch-actions');
      const waBulk = $('#sheet-bulk-send-btn');
      const waBulkLabel = $('#sheet-bulk-send-label');
      const waSelected = selectedIds.size;
      if (waBar) {
        waBar.hidden = waSelected === 0;
        const waCountLabel = $('#batch-count-label');
        if (waCountLabel) waCountLabel.textContent = `${waSelected} record${waSelected === 1 ? '' : 's'} selected`;
        const waBatchSpan = $('#batch-send-btn span') || $('#batch-send-btn');
        if (waBatchSpan) waBatchSpan.textContent = `Send ${waSelected} selected message${waSelected === 1 ? '' : 's'}`;
      }
      const readyCount = (records || []).filter(isNotSent).length;
      const waitingCount = (records || []).filter(isPendingRow).length;
      const doneCount = (records || []).filter(waWasSent).length;
      if (waBulk && waBulkLabel) {
        if (readyCount > 0 || waitingCount > 0 || doneCount > 0) {
          waBulk.hidden = false;
          waBulk.style.display = 'inline-flex';
          waBulkLabel.textContent = readyCount > 0 ? `Send all ${readyCount} ready` : waitingCount > 0 ? `View sending (${waitingCount} waiting)` : 'View send status';
        } else {
          waBulk.hidden = true;
          waBulk.style.display = 'none';
        }
      }
      rememberSheetChrome();
      return;
    }
    const bar = $('#batch-actions');
    const count = selectedIds.size;
    const readyRecords = (records || []).filter(isRowValid);
    const readyCount = readyRecords.length;

    if (bar) {
      bar.hidden = count === 0;
      const countLabel = $('#batch-count-label');
      if (countLabel) countLabel.textContent = `${count} record${count === 1 ? '' : 's'} selected`;
      const batchBtnSpan = $('#batch-send-btn span') || $('#batch-send-btn');
      if (batchBtnSpan) batchBtnSpan.textContent = `Send ${count} selected email${count === 1 ? '' : 's'}`;
    }

    const bulkBtn = $('#sheet-bulk-send-btn');
    const bulkLabel = $('#sheet-bulk-send-label');
    if (bulkBtn && bulkLabel) {
      if (count > 0) {
        bulkBtn.hidden = false;
        bulkLabel.textContent = `Send ${count} Selected`;
      } else if ((sheetFilter === 'pending' || sheetFilter === '' || sheetFilter === 'ready') && pendingSendIds().length > 0 && (sheetFilter === 'pending' || readyCount === 0)) {
        bulkBtn.hidden = false;
        bulkLabel.textContent = `Send ${pendingSendIds().length} pending`;
      } else if ((sheetFilter === 'failed' || ((sheetFilter === '' || sheetFilter === 'ready') && readyCount === 0 && pendingSendIds().length === 0)) && failedSendIds().length > 0) {
        bulkBtn.hidden = false;
        bulkLabel.textContent = `Send ${failedSendIds().length} failed again`;
      } else if (readyCount > 0 && sheetFilter !== 'pending' && sheetFilter !== 'failed' && sheetFilter !== 'sent' && sheetFilter !== 'issues') {
        bulkBtn.hidden = false;
        bulkLabel.textContent = `Send all ${readyCount} not sent`;
      } else {
        bulkBtn.hidden = true;
      }
    }
    rememberSheetChrome();
  }

  let fillingWaSender = false;

  function sheetDeviceIds(sheet) {
    if (Array.isArray(sheet?.device_ids) && sheet.device_ids.length) return sheet.device_ids.filter(Boolean);
    return sheet?.device_id ? [sheet.device_id] : [];
  }

  function phoneBoxes() {
    return [...document.querySelectorAll('#wa-sheet-phones input[data-phone-id]')];
  }

  function chosenPhoneIds() {
    return phoneBoxes().filter((box) => box.checked).map((box) => box.value);
  }

  function phoneButtonText() {
    const button = $('#wa-sheet-phone-btn');
    const boxes = phoneBoxes();
    const checked = boxes.filter((box) => box.checked);
    if (!button) return;
    let label = 'Choose phones';
    if (!boxes.length) label = 'No phone is ready';
    else if (checked.length === boxes.length) label = boxes.length === 1 ? 'All ready phones (1 active)' : `All ready phones (${boxes.length} active)`;
    else if (checked.length === 1) label = checked[0].dataset.label || '1 phone';
    else if (checked.length) label = `${checked.length} phones`;
    button.innerHTML = `<span>${escapeHtml(label)}</span><svg class="icon icon-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m6 9 6 6 6-6"/></svg>`;
  }

  async function fillWaSender(sheet) {
    const box = $('#wa-sheet-phones');
    if (!box || sheetMode() !== 'whatsapp') return;
    fillingWaSender = true;
    box.replaceChildren();
    let roster = {devices: []};
    try { roster = await api('whatsapp/device'); } catch (error) { notice(error.message, true); }
    const ready = (roster.devices || []).filter((device) => device.status === 'ready' || device.status === 'connected');
    const labelFor = (device) => device.phone ? `${device.name} · ${device.phone}` : (device.name || 'Phone');
    const useAll = (sheet?.sender_mode || 'all') !== 'pick';
    const saved = new Set(sheetDeviceIds(sheet));
    const allLabel = element('label', undefined, 'sheet-phone-option');
    const allInput = element('input');
    allInput.type = 'checkbox';
    allInput.id = 'wa-sheet-phone-all';
    allLabel.append(allInput, element('span', 'All ready phones'));
    box.append(allLabel);
    if (!ready.length) box.append(element('p', 'Pair a phone on Linked device.', 'sheet-phone-empty'));
    for (const device of ready) {
      const name = labelFor(device);
      const row = element('label', undefined, 'sheet-phone-option');
      const input = element('input');
      input.type = 'checkbox';
      input.value = device.id;
      input.dataset.phoneId = device.id;
      input.dataset.label = name;
      input.checked = useAll || saved.has(device.id);
      row.append(input, element('span', name));
      box.append(row);
    }
    const boxes = phoneBoxes();
    allInput.checked = boxes.length > 0 && boxes.every((item) => item.checked);
    fillingWaSender = false;
    phoneButtonText();
  }

  async function savePhoneChoice() {
    const boxes = phoneBoxes();
    const checked = boxes.filter((box) => box.checked).map((box) => box.value);
    const mode = boxes.length && checked.length === boxes.length ? 'all' : 'pick';
    if (mode === 'pick' && !checked.length) throw new Error('Choose at least one phone.');
    await post(sheetApi(`${source.id}/sender`), {sender_mode: mode, device_ids: mode === 'all' ? [] : checked});
    source.sender_mode = mode;
    source.device_ids = mode === 'all' ? [] : checked;
    source.device_id = checked[0] || null;
    phoneButtonText();
    paintCap();
    notice(mode === 'all'
      ? 'This sheet will send from every ready phone.'
      : `This sheet will send from ${checked.length} phone${checked.length === 1 ? '' : 's'}.`);
  }

  $('#wa-sheet-phone-btn')?.addEventListener('click', () => {
    const panel = $('#wa-sheet-phones');
    const button = $('#wa-sheet-phone-btn');
    if (!panel || !button) return;
    panel.hidden = !panel.hidden;
    button.setAttribute('aria-expanded', String(!panel.hidden));
  });
  document.addEventListener('click', (event) => {
    const menu = document.querySelector('.sheet-phone-menu');
    const panel = $('#wa-sheet-phones');
    if (!menu || !panel || menu.contains(event.target)) return;
    panel.hidden = true;
    $('#wa-sheet-phone-btn')?.setAttribute('aria-expanded', 'false');
  });
  $('#wa-sheet-phones')?.addEventListener('change', (event) => {
    if (fillingWaSender || !source?.id || sheetMode() !== 'whatsapp') return;
    const boxes = phoneBoxes();
    const all = $('#wa-sheet-phone-all');
    if (event.target === all) {
      boxes.forEach((box) => { box.checked = true; });
      all.checked = true;
    } else if (!chosenPhoneIds().length) {
      event.target.checked = true;
    }
    if (all) all.checked = boxes.length > 0 && boxes.every((box) => box.checked);
    const noticeEl = $('#notice');
    if (noticeEl) noticeEl.hidden = true;
    phoneButtonText();
    paintCap();
    busy($('#wa-sheet-phone-btn'), savePhoneChoice);
  });

  async function openSource(id, preserveSelections = false) {
    const sameSource = Number(source?.id) === Number(id);
    openedId = Number(id);
    if (!preserveSelections) selectedIds.clear();
    const noticeEl = $('#notice');
    if (noticeEl) noticeEl.hidden = true;
    const data = await api(sheetApi(id));
    if (openedId !== Number(id)) return;
    source = data.source; records = data.records;
    waSkippedIds = loadWaSkipped(id);
    if (!sameSource) {
      page = 0;
      sheetFilter = '';
      const search = $('#sheet-search');
      const status = $('#sheet-status');
      if (search) search.value = '';
      if (status) status.value = '';
    }
    const tabTitle = data.tab?.title || 'Sheet';
    $('#source-title').textContent = tabTitle;
    const fileName = source.title && source.title !== tabTitle ? source.title : '';
    const iconEl = $('#sheet-preview-icon');
    if (iconEl) {
      if (isUpload(source)) {
        iconEl.className = 'sheet-preview-badge-icon excel';
        iconEl.innerHTML = '<svg viewBox="0 0 24 24" class="sheet-type-svg"><path fill="#107C41" d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/><path fill="#fff" d="M15.5 17l-2.4-4.5 2.3-4.5h-2.1l-1.3 3.1-1.3-3.1H8.6l2.3 4.5-2.4 4.5h2.1l1.4-3.3 1.4 3.3z"/></svg>';
      } else {
        iconEl.className = 'sheet-preview-badge-icon google';
        iconEl.innerHTML = '<svg viewBox="0 0 24 24" class="sheet-type-svg"><path fill="#0F9D58" d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/><path fill="#fff" d="M9 7h6v2H9zm0 4h6v2H9zm0 4h6v2H9z"/></svg>';
      }
    }
    const countBadge = $('#source-badge-count');
    if (countBadge) countBadge.textContent = `${records.length} records`;
    if (sheetMode() === 'whatsapp') {
      const parts = [fileName, `Header row ${source.header_row}`];
      parts.push(isUpload(source) ? 'Edits save to uploaded copy' : 'Edits save to Google Sheets');
      $('#source-summary').textContent = parts.filter(Boolean).join(' · ');
      $('#source-edit-note').textContent = '';
      $('#source-edit-note').style.display = 'none';
      syncPill(isUpload(source) ? 'Uploaded file (Excel)' : 'Live Sync Active');
    } else {
      $('#source-summary').textContent = [fileName, `${records.length} records`, `Header row ${source.header_row}`].filter(Boolean).join(' · ');
      $('#source-edit-note').textContent = isUpload(source)
        ? 'Edits save to the uploaded copy in this application. Your original file and existing queued emails stay unchanged.'
        : 'Edits save to Google Sheets. Already queued or sent emails keep their original content.';
      $('#source-edit-note').style.display = '';
      syncPill(isUpload(source) ? 'Uploaded file' : 'Live Sync Active');
    }
    $('#open-sheet').href = `https://docs.google.com/spreadsheets/d/${source.spreadsheet_id}/edit#gid=${source.sheet_id}`;
    $('#open-sheet').hidden = isUpload(source);
    const replaceBtn = $('#sheet-replace');
    if (replaceBtn) replaceBtn.hidden = !isUpload(source);
    const mappingBtn = $('#source-mapping');
    if (mappingBtn) mappingBtn.hidden = false;
    $('#sheets-directory').hidden = true;
    $('#sheet-preview').hidden = false;
    document.getElementById('sheets')?.classList.add('in-preview');
    try {
      localStorage.setItem(openSheetKey(), String(id));
      const nextHash = '#sheets/' + (sheetMode() === 'whatsapp' ? 'wa-' : '') + id;
      if (location.hash !== nextHash) history.replaceState(null, '', nextHash);
    } catch (e) {}
    window.scrollTo({top: 0, behavior: 'smooth'});
    renderRows();
    updateBatchBar();
    const isWa = sheetMode() === 'whatsapp';
    document.querySelectorAll('#sheet-preview .sheet-wa-only').forEach(el => { el.hidden = !isWa; });
    document.querySelectorAll('#sheet-preview .sheet-email-only').forEach(el => { el.hidden = isWa; });
    if (isWa) {
      await fillWaSender(source);
      paintSchedule(source);
      if (records.some(isPendingRow)) startWaPump();
    }
  }

  let fillingSchedule = false;

  function fillGapSelect(select, blankLabel) {
    if (!select || select.options.length) return;
    const blank = element('option', blankLabel);
    blank.value = '';
    select.append(blank);
    for (let minutes = 1; minutes <= 30; minutes += 1) {
      const option = element('option', String(minutes));
      option.value = String(minutes);
      select.append(option);
    }
  }

  function paintSchedule(sheet) {
    const scheduleEl = document.querySelector('#sheet-preview .sheet-schedule');
    if (scheduleEl) scheduleEl.hidden = sheetMode() !== 'whatsapp';
    const date = $('#wa-sheet-date');
    const delay = $('#wa-sheet-delay');
    const delayMax = $('#wa-sheet-delay-max');
    if (!date || !delay || sheetMode() !== 'whatsapp') return;
    fillingSchedule = true;
    fillGapSelect(delay, 'Min');
    fillGapSelect(delayMax, 'Max');
    date.value = sheet?.start_date || '';
    const time = $('#wa-sheet-time');
    if (time) time.value = sheet?.start_time || '10:00';
    const minGap = sheet?.delay_minutes ? String(sheet.delay_minutes) : '';
    delay.value = minGap;
    if (delayMax) delayMax.value = sheet?.delay_max_minutes ? String(sheet.delay_max_minutes) : minGap;
    const daily = $('#wa-sheet-daily');
    if (daily) daily.value = String(sheet?.daily_limit || 20);
    fillingSchedule = false;
    paintCap();
  }

  function paintCap() {
    const cap = $('#wa-sheet-cap');
    if (!cap || sheetMode() !== 'whatsapp') return;
    const ready = (records || []).filter(isNotSent).length;
    const count = chosenPhoneIds().length;
    const limit = Number($('#wa-sheet-daily')?.value) || 20;
    const daily = count * limit;
    const days = ready && daily ? Math.ceil(ready / daily) : 0;

    if (!count) {
      cap.innerHTML = '<span class="wa-cap-stat">⚡ <strong>0 msgs / day</strong></span> &middot; <span>Pick a ready phone to schedule dispatch</span>';
      cap.title = 'Pick a ready phone to schedule dispatch';
      return;
    }

    const perPhone = count === 1 ? `${limit} per device per day` : `${limit} per device per day × ${count} devices`;
    const dispatchText = `${ready} rows need <strong>${days || 1} day${(days || 1) === 1 ? '' : 's'}</strong>`;
    cap.innerHTML = `<span class="wa-cap-stat">⚡ <strong>${daily} msgs / day</strong></span> &middot; <span>${perPhone}</span> &middot; <span>${dispatchText}</span>`;
    cap.title = `${daily} msgs / day · ${perPhone} · ${ready} rows need ${days || 1} day${(days || 1) === 1 ? '' : 's'}`;
  }

  async function saveSchedule() {
    if (!source?.id || fillingSchedule || sheetMode() !== 'whatsapp') return;
    const startDate = $('#wa-sheet-date')?.value || '';
    const delayRaw = $('#wa-sheet-delay')?.value || '';
    const delayMinutes = delayRaw === '' ? null : Number(delayRaw);
    const delayMaxRaw = $('#wa-sheet-delay-max')?.value || '';
    let delayMaxMinutes = delayMaxRaw === '' ? null : Number(delayMaxRaw);
    if (delayMinutes !== null && delayMaxMinutes === null) delayMaxMinutes = delayMinutes;
    if (delayMinutes !== null && delayMaxMinutes !== null && delayMaxMinutes < delayMinutes) {
      notice('The longest gap must be at least the shortest gap.', true);
      return;
    }
    const startTime = $('#wa-sheet-time')?.value || '';
    const dailyRaw = $('#wa-sheet-daily')?.value || '';
    const dailyLimit = dailyRaw === '' ? null : Number(dailyRaw);
    const saved = await post(sheetApi(`${source.id}/schedule`), {
      start_date: startDate,
      start_time: startTime,
      delay_minutes: delayMinutes,
      delay_max_minutes: delayMaxMinutes,
      daily_limit: dailyLimit,
    });
    source.start_date = startDate || null;
    source.start_time = startTime || null;
    source.delay_minutes = delayMinutes;
    source.delay_max_minutes = delayMaxMinutes;
    if (dailyLimit !== null) source.daily_limit = dailyLimit;
    paintCap();
    if (Number(saved?.moved) > 0) {
      notice('Waiting messages updated with the new random gap.');
    } else {
      notice('Schedule saved for the ready phones.');
    }
  }

  $('#wa-sheet-date')?.addEventListener('change', (event) => {
    if (fillingSchedule || !source?.id) return;
    busy(event.currentTarget, saveSchedule);
  });
  $('#wa-sheet-delay')?.addEventListener('change', (event) => {
    if (fillingSchedule || !source?.id) return;
    const min = Number($('#wa-sheet-delay')?.value || 0);
    const maxEl = $('#wa-sheet-delay-max');
    if (maxEl && min && (!maxEl.value || Number(maxEl.value) < min)) maxEl.value = String(min);
    busy(event.currentTarget, saveSchedule);
  });
  $('#wa-sheet-delay-max')?.addEventListener('change', (event) => {
    if (fillingSchedule || !source?.id) return;
    busy(event.currentTarget, saveSchedule);
  });
  $('#wa-sheet-daily')?.addEventListener('change', (event) => {
    if (fillingSchedule || !source?.id) return;
    busy(event.currentTarget, saveSchedule);
  });
  $('#wa-sheet-time')?.addEventListener('change', (event) => {
    if (fillingSchedule || !source?.id) return;
    busy(event.currentTarget, saveSchedule);
  });

  $('#sheet-back-btn')?.addEventListener('click', () => {
    $('#sheet-preview').hidden = true;
    $('#sheets-directory').hidden = false;
    document.getElementById('sheets')?.classList.remove('in-preview');
    source = null;
    openedId = null;
    selectedIds.clear();
    try {
      localStorage.removeItem(openSheetKey());
      if ((location.hash || '').startsWith('#sheets/')) history.replaceState(null, '', '#sheets');
    } catch (e) {}
    window.scrollTo({top: 0, behavior: 'smooth'});
  });

  function renderRows() {
    const sizeEl = $('#sheet-size'), searchEl = $('#sheet-search');
    const size = Number(sizeEl ? sizeEl.value : 10), term = (searchEl ? searchEl.value : '').trim().toLowerCase(), filter = sheetFilter;
    const filtered = records.filter(row => {
      return rowHaystack(row).some(value => value.includes(term)) && rowMatchesFilter(row, filter);
    });

    const pages = Math.max(1,Math.ceil(filtered.length / size)); page = Math.max(0,Math.min(page,pages - 1));
    const pageRecords = filtered.slice(page * size, (page + 1) * size);
    $('#sheet-rows').replaceChildren();

    // Update Quick Filter Tab counters and active state
    const allCount = records.length;
    const readyCount = records.filter(isNotSent).length;
    const pendingCount = records.filter(isPendingRow).length;
    const failedCount = records.filter(isFailedRow).length;
    const nowaCount = records.filter(isNoWaRow).length;
    const sentCount = records.filter(r => r.send_status === 'sent').length;
    const issuesCount = records.filter(r => r.issues && r.issues.length > 0).length;

    const cAll = $('#count-all-records'), cReady = $('#count-ready-records'), cPending = $('#count-pending-records'), cFailed = $('#count-failed-records'), cNowa = $('#count-nowa-records'), cSent = $('#count-sent-records'), cIssues = $('#count-issues-records');
    if (cAll) cAll.textContent = allCount;
    if (cReady) cReady.textContent = readyCount;
    if (cPending) cPending.textContent = pendingCount;
    if (cFailed) cFailed.textContent = failedCount;
    if (cNowa) cNowa.textContent = nowaCount;
    if (cSent) cSent.textContent = sentCount;
    if (cIssues) cIssues.textContent = issuesCount;

    const hasStuckProcessing = records.some(r => r.send_status === 'processing');
    const resetBtn = $('#reset-processing-btn');
    if (resetBtn) resetBtn.hidden = !hasStuckProcessing;

    document.querySelectorAll('.sheet-tab-btn').forEach(btn => {
      btn.classList.toggle('active', (btn.dataset.tabFilter || '') === filter);
    });
    updateBatchBar();

    const validPageRecords = pageRecords.filter(isRowValid);
    const selectAll = $('#sheet-select-all');
    if (selectAll) {
      selectAll.checked = validPageRecords.length > 0 && validPageRecords.every(r => selectedIds.has(r.record_id));
      selectAll.disabled = validPageRecords.length === 0;
    }

    for (const row of pageRecords) {
      const tr = element('tr');
      const validToSend = isRowValid(row);

      // Checkbox cell
      const chkCell = element('td', undefined, 'cell-check'); chkCell.style.textAlign = 'center';
      const chk = element('input', undefined, 'sheet-checkbox');
      chk.type = 'checkbox'; chk.disabled = !validToSend;
      chk.checked = selectedIds.has(row.record_id);
      chk.title = validToSend ? 'Select for batch sending' : (row.issues.join('; ') || 'Cannot send');
      chk.addEventListener('change', () => {
        if (chk.checked) selectedIds.add(row.record_id);
        else selectedIds.delete(row.record_id);
        updateBatchBar();
        if (selectAll) selectAll.checked = validPageRecords.length > 0 && validPageRecords.every(r => selectedIds.has(r.record_id));
      });
      chkCell.append(chk);

      // ID and agency each get their own column, like the WhatsApp sheet
      const sheetIdCell = element('td', String(row.record_id || 'Missing ID'), 'cell-sheet-id');
      const agencyName = String(row.values.name || '').trim();
      const sheetAgencyCell = element('td', agencyName || '—', 'cell-sheet-agency');
      sheetAgencyCell.title = agencyName;

      const cleanEmail = String(row.values.email || '').replace(/^[`'"\s\u00a0\u200b]+|[`'"\s\u00a0\u200b]+$/g, '');
      const emailCell = element('td', cleanEmail, 'cell-email');
      const subjectCell = element('td', String(row.values.subject || '—'), 'cell-subject');

      // Status badges
      const status = element('td', undefined, 'cell-status');
      const statusWrap = element('div', undefined, 'cell-status-wrap');
      if (row.issues.length) {
        for (const issue of row.issues) {
          statusWrap.append(element('span', issue, 'badge issue'));
        }
      } else if (sheetMode() === 'whatsapp' && row.can_queue === true) {
        const label = (row.send_status === 'sent' || row.send_status === 'failed') ? 'Ready again' : 'Not sent';
        statusWrap.append(element('span', label, 'badge ready'));
      } else if (row.send_status === 'not_queued') {
        statusWrap.append(element('span', 'Not sent', 'badge ready'));
      } else if (sheetMode() === 'whatsapp' && ['pending', 'sent', 'failed'].includes(row.send_status)) {
        const nowa = row.send_status === 'failed' && isNoWaError(row);
        const wave = Number(row.send_wave || 1);
        const label = row.send_status === 'failed'
          ? (nowa ? 'Not on WhatsApp' : 'Failed')
          : (row.send_label || (row.send_status === 'pending'
            ? (wave > 1 ? `Follow-up ${wave - 1} waiting` : 'Message waiting')
            : (wave > 1 ? `Follow-up ${wave - 1} sent` : 'Message sent')));
        const badge = element('span', label, `badge ${nowa ? 'nowa' : row.send_status}`);
        if (row.send_status === 'failed' && (row.send_error || row.error)) badge.title = String(row.send_error || row.error);
        else if (row.send_kind === 'follow_up') badge.title = `Latest delivery is follow-up ${Math.max(1, wave - 1)}`;
        else if (row.send_status === 'sent') badge.title = 'Latest delivery is the first message';
        statusWrap.append(badge);
      } else {
        statusWrap.append(element('span', row.send_status.replaceAll('_',' '), `badge ${row.send_status}`));
      }
      status.append(statusWrap);

      // Actions
      const actions = element('td', undefined, 'sheet-row-actions');
      const edit = element('button', 'Edit', 'sheet-btn-action');
      edit.disabled = !row.editable;
      edit.addEventListener('click', () => editRow(row));
      actions.append(edit);

      if (sheetMode() === 'whatsapp') {
        const statusBtn = element('button', 'Status', 'sheet-btn-action');
        statusBtn.title = 'See what happened to this row';
        statusBtn.addEventListener('click', () => busy(statusBtn, () => waShowRow(row)));
        actions.append(statusBtn);

        const skipped = isRowSkipped(row.record_id);
        const skipBtn = element('button', skipped ? 'Include' : 'Skip', skipped ? 'sheet-btn-action wa-btn-include' : 'sheet-btn-action wa-btn-skip');
        skipBtn.title = skipped ? 'Include this row in bulk send' : 'Skip this row from bulk send';
        skipBtn.addEventListener('click', () => {
          const idStr = String(row.record_id);
          if (waSkippedIds.has(idStr)) {
            waSkippedIds.delete(idStr);
          } else {
            waSkippedIds.add(idStr);
            selectedIds.delete(idStr);
          }
          saveWaSkipped(source?.id, waSkippedIds);
          renderRows();
          updateBatchBar();
        });
        actions.append(skipBtn);

        const idCell = element('td', String(row.record_id || '—'), 'cell-wa-id');
        const nameCell = element('td', String(row.values.name || '—'), 'cell-wa-name');
        nameCell.title = String(row.values.name || '');
        const phoneCell = element('td', String(row.values.phone || '—'), 'cell-wa-phone');
        const messageCell = element('td', String(row.values.message || '—'), 'cell-wa-message');
        messageCell.title = String(row.values.message || '');

        const deviceCell = element('td', undefined, 'cell-wa-device');
        const sendStatus = row.send_status || 'not_queued';
        if (sendStatus === 'not_queued') {
          if (row.sticky_device_name) {
            const badge = element('span', row.sticky_device_name, 'badge-wa-device badge-wa-sticky');
            badge.title = `Sticky owner: locked to ${row.sticky_device_name}`;
            deviceCell.append(badge);
          } else {
            const badge = element('span', 'Free pick', 'badge-wa-device badge-wa-freepick');
            badge.title = 'First-time number: assigned among ready phones at queue time';
            deviceCell.append(badge);
          }
        } else {
          const assigned = row.send_device_name || 'Assigned';
          if (row.device_same === true) {
            const badge = element('span', `${assigned} · same`, 'badge-wa-device badge-wa-same');
            badge.title = 'Sent from original sticky phone';
            deviceCell.append(badge);
          } else if (row.device_same === false) {
            const badge = element('span', `${assigned} · changed`, 'badge-wa-device badge-wa-changed');
            badge.title = 'Device differed from previous sticky owner';
            deviceCell.append(badge);
          } else {
            const badge = element('span', assigned, 'badge-wa-device');
            badge.title = `Assigned phone: ${assigned}`;
            deviceCell.append(badge);
          }
        }

        if (skipped) {
          tr.classList.add('wa-row-skipped');
          const chk = chkCell.querySelector('input');
          if (chk) {
            chk.disabled = true;
            chk.checked = false;
          }
        }

        tr.append(chkCell, idCell, nameCell, phoneCell, messageCell, deviceCell, status, actions);
        $('#sheet-rows').append(tr);
        continue;
      }

      if (row.email_id) {
        const details = element('button', 'View email', 'sheet-btn-action');
        details.addEventListener('click', () => window.openEmailDetail(row.email_id));
        actions.append(details);
      }

      const queue = element('button', 'Send email', 'sheet-btn-action btn-send-single');
      queue.disabled = !validToSend;
      queue.addEventListener('click', () => busy(queue, async () => {
        const confirmed = await confirmSendDialog({
          title: 'Send 1 Email via SMTP',
          desc: 'This email will be dispatched immediately to the recipient.',
          recipient: cleanEmail,
          subject: row.values.subject || '(No subject)',
          count: 1
        });
        if (!confirmed) return;
        const activeSender = window.getActiveSenderAccount ? window.getActiveSenderAccount() : null;
        const sendPayload = {record_id: row.record_id, version: row.version};
        if (activeSender && activeSender.id) {
          sendPayload.mail_account_id = activeSender.id;
        }
        const data = await post(`sheets/${source.id}/send`, sendPayload);
        await openSource(source.id, true);
        await load();
        await window.openEmailDetail(data.id);
        notice(data.status === 'sent' ? `Email #${data.id} was sent.` : `Email #${data.id} completed in ${data.status.replaceAll('_',' ')} mode.`);
      }));
      actions.append(queue);

      tr.append(chkCell, sheetIdCell, sheetAgencyCell, emailCell, subjectCell, status, actions);
      $('#sheet-rows').append(tr);
    }

    if (!filtered.length) {
      const row = element('tr'); const td = cell(records.length ? 'No records match your filters.' : 'No records below the header row.');
      td.colSpan = sheetMode() === 'whatsapp' ? 8 : 7; td.style.textAlign = 'center'; row.append(td); $('#sheet-rows').append(row);
    }
    $('#sheet-page').textContent = `Page ${page + 1} of ${pages}`;
    $('#sheet-range').textContent = `${filtered.length ? page * size + 1 : 0}–${Math.min((page + 1) * size,filtered.length)} of ${filtered.length} records`;
    $('#sheet-prev').disabled = page === 0; $('#sheet-next').disabled = page + 1 >= pages;
    rememberSheetChrome();
  }

  function rememberSheetChrome() {
    if (!openedId || !records.length) return;
    try {
      sessionStorage.setItem('rsl_sheet_counts_' + openedId, JSON.stringify({
        all: $('#count-all-records')?.textContent || '',
        ready: $('#count-ready-records')?.textContent || '',
        sent: $('#count-sent-records')?.textContent || '',
        issues: $('#count-issues-records')?.textContent || '',
        title: $('#source-title')?.textContent || '',
        summary: $('#source-summary')?.textContent || '',
        note: $('#source-edit-note')?.textContent || '',
        bulk: $('#sheet-bulk-send-label')?.textContent || '',
        range: $('#sheet-range')?.textContent || ''
      }));
    } catch (e) {}
  }

  // Select all checkbox handler
  $('#sheet-select-all')?.addEventListener('change', event => {
    const checked = event.target.checked;
    const sizeEl = $('#sheet-size'), searchEl = $('#sheet-search');
    const size = Number(sizeEl ? sizeEl.value : 10), term = (searchEl ? searchEl.value : '').trim().toLowerCase(), filter = sheetFilter;
    const filtered = records.filter(row => {
      return rowHaystack(row).some(value => value.includes(term)) && rowMatchesFilter(row, filter);
    });
    const pageRecords = filtered.slice(page * size, (page + 1) * size);
    for (const r of pageRecords) {
      if (isRowValid(r)) {
        if (checked) selectedIds.add(r.record_id);
        else selectedIds.delete(r.record_id);
      }
    }
    renderRows();
    updateBatchBar();
  });

  // Select all ready records across the entire spreadsheet
  $('#batch-select-all-sheet')?.addEventListener('click', () => {
    const readyRecords = (records || []).filter(isRowValid);
    for (const r of readyRecords) selectedIds.add(r.record_id);
    renderRows();
    updateBatchBar();
    notice(`Selected all ${readyRecords.length} ready records across the sheet.`);
  });

  $('#batch-clear-btn')?.addEventListener('click', () => {
    selectedIds.clear();
    renderRows();
    updateBatchBar();
  });

  function confirmSendDialog({ title, desc, recipient, subject, count }) {
    return new Promise(resolve => {
      const dialog = $('#send-confirm-dialog');
      const titleEl = $('#send-confirm-title');
      const descEl = $('#send-confirm-desc');
      const recipEl = $('#send-confirm-recip');
      const subjEl = $('#send-confirm-subj');
      const batchInfo = $('#send-confirm-batch-info');
      const countEl = $('#send-confirm-count');
      const okBtn = $('#send-confirm-ok-btn');
      const cancelBtn = $('#send-confirm-cancel-btn');

      if (!dialog || typeof dialog.showModal !== 'function') {
        resolve(confirm(desc || 'Send email now via configured SMTP?'));
        return;
      }

      if (titleEl) titleEl.textContent = title || 'Confirm Email Dispatch';
      if (descEl) descEl.textContent = desc || 'Ready to send via configured SMTP.';

          const accounts = (window.currentMailAccounts && window.currentMailAccounts.length > 0)
            ? window.currentMailAccounts
        : [{ id: 1, name: 'RSL Communications', email: 'ray@rslsolution.com' }];
      const confirmSelect = $('#send-confirm-sender-select');
      if (confirmSelect) {
        // Always repopulate so the dropdown is never blank when the dialog opens
        const prevVal = confirmSelect.value;
          confirmSelect.replaceChildren();
          for (const acc of accounts) {
            const opt = document.createElement('option');
          opt.value = String(acc.id);
            opt.textContent = acc.name ? `${acc.name} (${acc.email})` : acc.email;
            confirmSelect.append(opt);
          }
        // Restore previous selection or use the active sender
        const activeSender = window.getActiveSenderAccount ? window.getActiveSenderAccount() : null;
        const preferredId = (activeSender && activeSender.id) ? String(activeSender.id) : prevVal;
        if (preferredId) confirmSelect.value = preferredId;
        if (!confirmSelect.value && confirmSelect.options.length > 0) confirmSelect.selectedIndex = 0;
        }
      const activeSender = window.getActiveSenderAccount ? window.getActiveSenderAccount() : null;
      const fromEl = $('#send-confirm-from');
      if (fromEl) {
        fromEl.textContent = activeSender ? (activeSender.name ? `${activeSender.name} <${activeSender.email}>` : activeSender.email) : 'ray@rslsolution.com';
      }

      if (recipient) {
        recipEl.parentElement.hidden = false;
        recipEl.textContent = recipient;
      } else {
        recipEl.parentElement.hidden = true;
      }

      if (subject) {
        subjEl.parentElement.hidden = false;
        subjEl.textContent = subject;
      } else {
        subjEl.parentElement.hidden = true;
      }

      if (count && count > 1) {
        batchInfo.hidden = false;
        countEl.textContent = `${count} verified records`;
      } else {
        batchInfo.hidden = true;
      }

      let settled = false;
      const cleanup = () => {
        okBtn?.removeEventListener('click', onOk);
        cancelBtn?.removeEventListener('click', onCancel);
        dialog.removeEventListener('close', onClose);
      };

      const onOk = () => {
        if (settled) return;
        settled = true;
        cleanup();
        dialog.close();
        resolve(true);
      };

      const onCancel = () => {
        if (settled) return;
        settled = true;
        cleanup();
        dialog.close();
        resolve(false);
      };

      const onClose = () => {
        if (settled) return;
        settled = true;
        cleanup();
        resolve(false);
      };

      okBtn?.addEventListener('click', onOk, { once: true });
      cancelBtn?.addEventListener('click', onCancel, { once: true });
      dialog.addEventListener('close', onClose, { once: true });

      try {
        dialog.showModal();
      } catch (e) {
        resolve(confirm(desc || 'Send email now via Hostinger SMTP?'));
      }
    });
  }

  // Live Batch / Bulk send handler with real-time modal progress & counter
  let batchCancelRequested = false;

  async function performBatchSend(ids) {
    if (!ids || !ids.length) return;
    const total = ids.length;
    const isSingle = total === 1;
    const targetRow = isSingle ? records?.find(r => r.record_id === ids[0]) : null;
    const confirmed = await confirmSendDialog({
      title: isSingle ? 'Send 1 Email via SMTP' : `Send ${total} Emails via SMTP`,
      desc: isSingle
        ? 'Send this email now directly to the recipient via configured SMTP.'
        : `Ready to dispatch ${total} verified emails directly via persistent SMTP connection.`,
      count: total,
      recipient: isSingle ? (targetRow?.values?.email || '1 recipient') : `${total} ready recipients`,
      subject: isSingle ? (targetRow?.values?.subject || 'From sheet template') : `Batch dispatch of ${total} records`
    });
    if (!confirmed) return;

    const modal = $('#batch-progress-dialog');
    const titleEl = $('#batch-progress-title');
    const subtitleEl = $('#batch-progress-subtitle');
    const badgeEl = $('#batch-progress-badge');
    const counterText = $('#batch-counter-text');
    const counterPercent = $('#batch-counter-percent');
    const progressBar = $('#batch-progress-bar');
    const statSent = $('#batch-stat-sent');
    const statFailed = $('#batch-stat-failed');
    const statRemaining = $('#batch-stat-remaining');
    const logList = $('#batch-log-list');
    const doneBtn = $('#batch-done-btn');
    const cancelBtn = $('#batch-cancel-btn');

    let sent = 0;
    let failed = 0;
    let already = 0;
    let pending = 0;
    let remaining = total;
    batchCancelRequested = false;

    if (titleEl) titleEl.textContent = `Dispatching ${total} email${total === 1 ? '' : 's'}…`;
    if (subtitleEl) subtitleEl.textContent = 'Live SMTP dispatch in progress. Please keep this tab open.';
    if (badgeEl) badgeEl.textContent = 'Dispatched live via SMTP';
    if (counterText) counterText.textContent = `0 / ${total} sent`;
    if (counterPercent) counterPercent.textContent = '0%';
    if (progressBar) progressBar.style.width = '0%';
    if (statSent) statSent.textContent = '0';
    if (statFailed) statFailed.textContent = '0';
    if (statRemaining) statRemaining.textContent = String(total);
    if (logList) logList.innerHTML = `<div class="batch-log-item muted">⚡ Starting dispatch for ${total} records…</div>`;
    if (doneBtn) doneBtn.hidden = true;
    if (cancelBtn) {
      cancelBtn.hidden = false;
      cancelBtn.disabled = false;
      cancelBtn.textContent = 'Stop Remaining';
    }

    if (modal && typeof modal.showModal === 'function') {
      try { modal.showModal(); } catch(e) {}
    }

    const chunkSize = 5; // Safe micro-chunks for instant UI updates & 0 Hostinger timeouts
    const chunks = [];
    for (let i = 0; i < ids.length; i += chunkSize) {
      chunks.push(ids.slice(i, i + chunkSize));
    }

    const addLog = (msg, type = 'success') => {
      if (!logList) return;
      const item = document.createElement('div');
      item.className = `batch-log-item ${type}`;
      item.textContent = msg;
      logList.prepend(item);
      if (logList.children.length > 25) logList.lastElementChild?.remove();
    };

    const outcomeLine = () => {
      const parts = [`${sent} sent`];
      if (pending) parts.push(`${pending} still pending`);
      if (failed) parts.push(`${failed} failed`);
      if (already) parts.push(`${already} already sent`);
      return parts.join(', ') + '.';
    };
    const updateUI = () => {
      const processed = sent + failed + already + pending;
      const pct = Math.min(100, Math.round((processed / total) * 100));
      if (counterText) counterText.textContent = `${sent} / ${total} sent`;
      if (counterPercent) counterPercent.textContent = `${pct}%`;
      if (progressBar) progressBar.style.width = `${pct}%`;
      if (statSent) statSent.textContent = String(sent);
      if (statFailed) statFailed.textContent = String(failed);
      if (statRemaining) statRemaining.textContent = String(remaining);
    };

    const handleCancel = () => {
      batchCancelRequested = true;
      if (cancelBtn) {
        cancelBtn.disabled = true;
        cancelBtn.textContent = 'Stopping…';
      }
      addLog('⚠️ Stop requested. Finishing active request…', 'error');
    };
    cancelBtn?.addEventListener('click', handleCancel, { once: true });

    for (let i = 0; i < chunks.length; i++) {
      if (batchCancelRequested) {
        addLog(`🛑 Stopped by user. ${remaining} records remaining unqueued.`, 'error');
        break;
      }
      const chunk = chunks[i];
      try {
        const activeSender = window.getActiveSenderAccount ? window.getActiveSenderAccount() : null;
        const batchPayload = { record_ids: chunk };
        if (activeSender && activeSender.id) {
          batchPayload.mail_account_id = activeSender.id;
        }
        const res = await post(`sheets/${source.id}/batch-send`, batchPayload);
        sent += (res.sent || 0);
        failed += (res.failed || 0);
        already += (res.already_sent || 0);
        pending += (res.pending || 0);
        remaining = Math.max(0, total - (sent + failed + already + pending));
        const bits = [`${res.sent || 0} sent`];
        if (res.pending) bits.push(`${res.pending} still pending`);
        if (res.failed) bits.push(`${res.failed} failed`);
        if (res.already_sent) bits.push(`${res.already_sent} already sent`);
        addLog(`✓ Batch ${i + 1}/${chunks.length}: ${bits.join(', ')}`);
      } catch (err) {
        pending += chunk.length;
        remaining = Math.max(0, total - (sent + failed + already + pending));
        addLog(`❌ Batch ${i + 1} error: ${err.message}`, 'error');
      }
      updateUI();
    }

    // Finalize modal
    if (titleEl) titleEl.textContent = batchCancelRequested ? 'Batch Dispatch Paused' : '🎉 Dispatch Complete!';
    if (subtitleEl) subtitleEl.textContent = outcomeLine();
    if (badgeEl) badgeEl.textContent = batchCancelRequested ? 'Stopped' : '✓ Completed';
    if (cancelBtn) cancelBtn.hidden = true;
    if (doneBtn) doneBtn.hidden = false;

    selectedIds.clear();
    await openSource(source.id, true);
    if (typeof window.load === 'function') await window.load();
    updateBatchBar();
  }

  $('#batch-done-btn')?.addEventListener('click', () => {
    const modal = $('#batch-progress-dialog');
    if (modal && typeof modal.close === 'function') modal.close();
  });

  $('#batch-send-btn')?.addEventListener('click', (event) => {
    if (sheetMode() === 'whatsapp') {
      const waIds = Array.from(selectedIds);
      if (!waIds.length) return;
      busy(event.currentTarget, () => waConfirmSend(waIds));
      return;
    }
    performBatchSend(Array.from(selectedIds));
  });

  const waWhen = (iso) => iso ? new Date(iso).toLocaleString('en-IN', {day: '2-digit', month: 'short', hour: 'numeric', minute: '2-digit'}) : '—';
  const waDay = (date) => new Date(`${date}T00:00:00`).toLocaleDateString('en-IN', {day: '2-digit', month: 'short', year: 'numeric'});
  let waProgressTimer = null;
  let waPumpTimer = null;

  function waDialog() {
    let dialog = $('#wa-send-dialog');
    if (dialog) return dialog;
    dialog = element('dialog', undefined, 'wa-send-dialog');
    dialog.id = 'wa-send-dialog';
    dialog.setAttribute('aria-labelledby', 'wa-send-title');
    dialog.innerHTML = '<div class="wa-send-head"><h2 id="wa-send-title"></h2><p id="wa-send-sub"></p></div><div id="wa-send-body"></div><p id="wa-send-note" class="wa-send-note"></p><div class="wa-send-actions"><button type="button" class="wa-send-secondary" id="wa-send-pause" hidden>Pause sending</button><button type="button" class="wa-send-secondary" id="wa-send-cancel">Cancel</button><button type="button" class="wa-send-primary" id="wa-send-ok">Send</button></div>';
    dialog.addEventListener('close', () => { clearInterval(waProgressTimer); waProgressTimer = null; $('#wa-send-pause').hidden = true; });
    document.body.append(dialog);
    return dialog;
  }

  function waPlanBody(plan) {
    const rows = plan.phones.map((phone) => `<tr><td>${escapeHtml(phone.name)}</td><td class="num">${phone.count}</td><td>${escapeHtml(waWhen(phone.first_at))}</td><td>${escapeHtml(waWhen(phone.last_at))}</td></tr>`).join('');
    const days = plan.days.map((day) => `<li><span>${escapeHtml(waDay(day.date))}</span><strong>${day.count}</strong></li>`).join('');
    return `<table class="wa-send-table"><thead><tr><th>Phone</th><th class="num">Rows</th><th>First</th><th>Last</th></tr></thead><tbody>${rows}</tbody></table>`
      + `<div class="wa-send-days"><h3>By day</h3><ul>${days}</ul></div>`;
  }

  function waProgressBody(progress) {
    const rows = progress.phones.map((phone) => `<tr><td>${escapeHtml(phone.name || 'Phone')}</td><td class="num">${phone.sent}</td><td class="num">${phone.waiting}</td><td class="num">${phone.failed}</td><td>${phone.waiting ? escapeHtml(waWhen(phone.next_at)) : 'Done'}</td></tr>`).join('');
    const log = progress.log.map((item) => {
      const who = escapeHtml(item.name || item.phone);
      const status = item.status === 'sent' ? 'Sent' : `Failed${item.error ? ` · ${escapeHtml(item.error)}` : ''}`;
      return `<li class="${item.status}"><span>${who}</span><span>${escapeHtml(item.from || '')}</span><span>${status}</span><span>${escapeHtml(waWhen(item.at))}</span></li>`;
    }).join('');
    const pct = progress.total ? Math.round(((progress.sent + progress.failed) / progress.total) * 100) : 0;
    return `<div class="wa-send-stats"><div><strong>${progress.sent}</strong><span>Sent</span></div><div><strong>${progress.waiting}</strong><span>Waiting</span></div><div><strong>${progress.failed}</strong><span>Failed</span></div><div><strong>${progress.total}</strong><span>Total</span></div></div>`
      + `<div class="wa-send-bar"><span style="width:${pct}%"></span></div>`
      + `<table class="wa-send-table"><thead><tr><th>Phone</th><th class="num">Sent</th><th class="num">Waiting</th><th class="num">Failed</th><th>Next</th></tr></thead><tbody>${rows}</tbody></table>`
      + (log ? `<ul class="wa-send-log">${log}</ul>` : '');
  }

  async function waShowProgress(sourceId) {
    const dialog = waDialog();
    const paint = async () => {
      const progress = await api(sheetApi(`${sourceId}/progress`));
      $('#wa-send-title').textContent = progress.waiting ? `Sending ${progress.total} rows` : `${progress.sent} of ${progress.total} sent`;
      $('#wa-send-sub').textContent = progress.waiting ? `${progress.waiting} waiting for their time.` : 'Every row from this sheet has gone out.';
      $('#wa-send-body').innerHTML = waProgressBody(progress);
      $('#wa-send-note').textContent = progress.waiting ? 'You can close this. Keep the desk open and reopen it from "View sending".' : '';
      return progress;
    };
    $('#wa-send-cancel').hidden = true;
    const ok = $('#wa-send-ok');
    ok.textContent = 'Close';
    ok.disabled = false;
    ok.onclick = () => dialog.close();
    if (!dialog.open) dialog.showModal();
    await paint();
    clearInterval(waProgressTimer);
    let lastDone = -1;
    waProgressTimer = setInterval(async () => {
      try {
        const progress = await paint();
        const done = progress.sent + progress.failed;
        if (done !== lastDone && Number(source?.id) === Number(sourceId)) {
          if (lastDone !== -1) await openSource(sourceId, true);
          lastDone = done;
        }
      } catch (e) {}
    }, 10000);
  }

  const waTime = (iso) => iso ? new Date(iso).toLocaleTimeString('en-IN', {hour: 'numeric', minute: '2-digit'}) : '—';
  const waLeft = (iso) => {
    const mins = Math.max(0, Math.round((new Date(iso).getTime() - Date.now()) / 60000));
    if (mins < 1) return 'less than a minute';
    const hours = Math.floor(mins / 60);
    const rest = mins % 60;
    return hours ? `${hours} h${rest ? ` ${rest} min` : ''}` : `${mins} min`;
  };
  let waTodayTab = 'upcoming';

  function waTodayBody(today) {
    const planned = today.sent + today.left;
    const pct = planned ? Math.round((today.sent / planned) * 100) : 0;
    const maxDay = Math.max(1, ...(today.days || []).map((day) => day.count));
    const callouts = [];
    if (today.paused) callouts.push(['warn', 'Sending is paused for this sheet. Nothing from this sheet goes out until you press Resume. Other sheets keep their own pause state.']);
    if (today.held) callouts.push(['danger', `${today.held} number${today.held === 1 ? ' is' : 's are'} held and will not be sent. Their format has no country code the phone service can read, so a message could reach the wrong person. Fix ${today.held === 1 ? 'it' : 'them'} in the sheet.`]);
    if (today.overdue) callouts.push(['warn', `${today.overdue} message${today.overdue === 1 ? ' is' : 's are'} late. Sending runs only while this page is open and the phone is connected, so keep it open.`]);
    if (today.retrying) callouts.push(['info', `${today.retrying} message${today.retrying === 1 ? ' is' : 's are'} trying again because the phone service did not answer.`]);
    if (today.no_whatsapp) callouts.push(['info', `${today.no_whatsapp} number${today.no_whatsapp === 1 ? ' is' : 's are'} not on WhatsApp. Sending to ${today.no_whatsapp === 1 ? 'it' : 'them'} failed, so ${today.no_whatsapp === 1 ? 'it is' : 'they are'} not retried. Check the Not on WhatsApp tab; many are landlines.`]);

    const facts = [];
    if (today.left) {
      facts.push(['Next message', waTime(today.next_at)]);
      facts.push(['Last today', `${waTime(today.finish_today_at)} (about ${waLeft(today.finish_today_at)} from now)`]);
    } else {
      facts.push(['Today', today.sent ? 'Nothing else is due today.' : 'Nothing is scheduled for today.']);
    }
    if (today.later) facts.push(['All done', `${waWhen(today.all_done_at)}, with ${today.later} more on later days`]);

    const lists = {
      upcoming: {label: `Coming up · ${today.left}`, items: today.upcoming, empty: 'Nothing is waiting for today.', state: (item) => item.attempts ? `Try ${item.attempts + 1}` : 'Waiting'},
      sent: {label: `Sent today · ${today.sent}`, items: today.sent_list, empty: 'Nothing has gone out yet today.', state: () => 'Sent'},
      failed: {label: `Failed · ${today.failed}`, items: today.failed_list, empty: 'No failed messages.', state: (item) => (/not registered on whatsapp/i.test(String(item.error || '')) ? 'Not on WhatsApp' : `Failed${item.error ? ` · ${item.error}` : ''}`)},
      nowa: {label: `Not on WhatsApp · ${today.no_whatsapp || 0}`, items: today.no_whatsapp_list || [], empty: 'Every number tried so far has WhatsApp.', state: () => 'Not on WhatsApp'},
    };
    if (!lists[waTodayTab]) waTodayTab = 'upcoming';
    const tabs = Object.entries(lists).map(([key, list]) => `<button type="button" data-wa-tab="${key}" class="${key === waTodayTab ? 'active' : ''}">${escapeHtml(list.label)}</button>`).join('');
    const current = lists[waTodayTab];
    const kind = waTodayTab === 'failed' || waTodayTab === 'nowa' ? 'failed' : waTodayTab === 'sent' ? 'sent' : '';
    const items = current.items.length
      ? `<div class="wa-today-list"><table class="wa-send-table wa-today-items"><thead><tr><th>Time</th><th>Agency</th><th>Sent from</th><th>Status</th></tr></thead><tbody>${current.items.map((item) => `<tr class="${kind}"><td>${escapeHtml(waTime(item.at))}</td><td>${escapeHtml(item.name || item.phone)}</td><td>${escapeHtml(item.from || '')}</td><td>${escapeHtml(current.state(item))}</td></tr>`).join('')}</tbody></table></div>`
      : `<p class="wa-today-empty">${escapeHtml(current.empty)}</p>`;

    const days = (today.days || []).length
      ? `<section class="wa-today-section"><h3>Day by day</h3><ul class="wa-today-days">${today.days.map((day) => {
          const isToday = day.date === today.date;
          const width = Math.round((day.count / maxDay) * 100);
          return `<li class="${isToday ? 'is-today' : ''}" title="${escapeHtml(waTime(day.first_at))} to ${escapeHtml(waTime(day.last_at))}"><span>${escapeHtml(isToday ? 'Today' : waDay(day.date))}</span><i><b style="width:${width}%"></b></i><strong>${day.count}</strong></li>`;
        }).join('')}</ul></section>`
      : '';

    const phones = today.phones.length
      ? `<section class="wa-today-section"><h3>Phones</h3><table class="wa-send-table wa-today-phones"><thead><tr><th>Phone</th><th>Sent today</th><th class="num">Left today</th><th>Next</th><th>Last today</th></tr></thead><tbody>${today.phones.map((phone) => {
          const width = Math.min(100, Math.round((phone.sent / Math.max(1, phone.cap)) * 100));
          return `<tr><td>${escapeHtml(phone.name || 'Phone')}</td><td><div class="wa-cap"><span style="width:${width}%"></span></div><small>${phone.sent} of ${phone.cap}</small></td><td class="num">${phone.left}</td><td>${phone.left ? escapeHtml(waTime(phone.next_at)) : '—'}</td><td>${phone.left ? escapeHtml(waTime(phone.last_at)) : '—'}</td></tr>`;
        }).join('')}</tbody></table></section>`
      : '';

    return `<div class="wa-today">`
      + `<div class="wa-send-stats wa-today-cards"><div><strong>${today.sent}</strong><span>Sent today</span></div><div><strong>${today.left}</strong><span>Left today</span></div><div><strong>${today.failed}</strong><span>Failed</span></div><div><strong>${today.later}</strong><span>Later days</span></div></div>`
      + (planned ? `<div class="wa-today-progress"><div class="wa-send-bar"><span style="width:${pct}%"></span></div><small>${pct}% of today's plan sent</small></div>` : '')
      + callouts.map(([level, text]) => `<p class="wa-today-call ${level}">${escapeHtml(text)}</p>`).join('')
      + `<dl class="wa-today-facts">${facts.map(([label, value]) => `<div><dt>${escapeHtml(label)}</dt><dd>${escapeHtml(value)}</dd></div>`).join('')}</dl>`
      + days
      + phones
      + `<section class="wa-today-section"><h3>Messages</h3><div class="wa-today-tabs">${tabs}</div>${items}</section>`
      + `</div>`;
  }

  async function waShowToday() {
    const dialog = waDialog();
    const cancel = $('#wa-send-cancel');
    const ok = $('#wa-send-ok');
    const sheetId = source?.id ? Number(source.id) : null;
    const paint = async () => {
      // Opened from a sheet → only that sheet's queue. No open sheet → every sheet.
      const today = await api(sheetId ? sheetApi(`${sheetId}/today`) : 'whatsapp/sheets/today');
      const day = new Date(`${today.date}T00:00:00`).toLocaleDateString('en-IN', {weekday: 'short', day: '2-digit', month: 'short'});
      const sheetName = String(today.sheet || source?.title || '').trim();
      $('#wa-send-title').textContent = sheetName ? `Sending status · ${sheetName}` : 'Sending status';
      $('#wa-send-sub').textContent = `${day} · ${today.sent} sent today, ${today.left} still to go today, ${today.later} on later days.`;
      $('#wa-send-body').innerHTML = waTodayBody(today);
      $('#wa-send-note').textContent = 'This updates by itself every 10 seconds.';
      cancel.hidden = today.failed === 0;
      cancel.disabled = false;
      cancel.textContent = `Retry ${today.failed} failed`;
      const pause = $('#wa-send-pause');
      pause.hidden = false;
      pause.disabled = false;
      pause.textContent = today.paused ? 'Resume this sheet' : 'Pause this sheet';
      pause.onclick = async () => {
        if (!sheetId) {
          notice('Open a sheet before pausing or resuming.', true);
          return;
        }
        const next = !today.paused;
        pause.disabled = true;
        try {
          await post(sheetApi(`${sheetId}/pause`), {paused: next});
          notice(next ? 'This sheet is paused. Its queued messages stay waiting.' : 'This sheet resumed. Other sheets stay as they were.');
          if (!next) startWaPump(true);
          await paint();
        } catch (error) {
          notice(error.message, true);
          pause.disabled = false;
        }
      };
      return today;
    };
    $('#wa-send-body').onclick = (event) => {
      const tab = event.target.closest?.('[data-wa-tab]');
      if (!tab) return;
      waTodayTab = tab.dataset.waTab;
      paint().catch(() => {});
    };
    cancel.onclick = async () => {
      cancel.disabled = true;
      try {
        const payload = sheetId ? {source_id: sheetId} : {};
        const result = await post('whatsapp/sheets/retry-failed', payload);
        notice(result.queued ? `${result.queued} failed message${result.queued === 1 ? ' is' : 's are'} back in the queue.` : 'No failed messages to retry.');
        startWaPump(true);
        await paint();
      } catch (error) {
        notice(error.message, true);
        cancel.disabled = false;
      }
    };
    ok.textContent = 'Close';
    ok.disabled = false;
    ok.onclick = () => dialog.close();
    if (!dialog.open) dialog.showModal();
    clearInterval(waProgressTimer);
    await paint();
    waProgressTimer = setInterval(() => { paint().catch(() => {}); }, 10000);
  }

  $('#wa-today-btn')?.addEventListener('click', (event) => busy(event.currentTarget, waShowToday));

  async function waShowRow(row) {
    const dialog = waDialog();
    const info = await post(sheetApi(`${source.id}/row`), {record_id: String(row.record_id || '')});
    const name = String(row.values?.name || '').trim() || 'Row';
    $('#wa-send-title').textContent = `${info.label} · ${name}`;
    $('#wa-send-sub').textContent = `Record ${row.record_id || '—'}`;
    const stickyPhone = info.sticky_phone || row.sticky_device_name;
    const willSend = (!info.from) ? (row.sticky_device_name || (row.send_status === 'not_queued' ? 'Free pick' : '')) : '';
    const facts = [
      ['Phone', info.phone || row.values?.phone],
      ['Sent from', info.from],
      ['Sticky phone', stickyPhone],
      ['Will send from', willSend],
      ['Queued', info.queued_at ? waWhen(info.queued_at) : ''],
      ['Scheduled for', info.due_at ? waWhen(info.due_at) : ''],
      ['Sent at', info.sent_at ? waWhen(info.sent_at) : ''],
      ['Tries', info.queued_at ? `${info.attempts} so far` : ''],
    ].filter(([, value]) => value);
    const table = `<table class="wa-send-table"><tbody>${facts.map(([label, value]) => `<tr><th>${escapeHtml(label)}</th><td>${escapeHtml(value)}</td></tr>`).join('')}</tbody></table>`;
    const reason = info.error ? `<p class="wa-today-warn">Phone service said: ${escapeHtml(info.error)}</p>` : '';
    $('#wa-send-body').onclick = null;
    $('#wa-send-body').innerHTML = `<p class="wa-row-detail">${escapeHtml(info.detail)}</p>${reason}${table}`;
    $('#wa-send-note').textContent = '';
    const cancel = $('#wa-send-cancel');
    cancel.hidden = true;
    cancel.onclick = null;
    const ok = $('#wa-send-ok');
    ok.textContent = 'Close';
    ok.disabled = false;
    ok.onclick = () => dialog.close();
    clearInterval(waProgressTimer);
    if (!dialog.open) dialog.showModal();
  }

  async function waConfirmSend(recordIds = null) {
    if (!source?.id) return;
    if (!recordIds) {
      if (waSkippedIds.size > 0) {
        recordIds = (records || []).filter(isNotSent).map(r => r.record_id);
      }
    } else {
      recordIds = recordIds.filter(id => !isRowSkipped(id));
    }
    if (recordIds && !recordIds.length) {
      notice('Select at least one row first (skipped rows are excluded).', true);
      return;
    }
    if (!chosenPhoneIds().length) {
      notice('Pick at least one ready phone in "Send from".', true);
      return;
    }
    if (!$('#wa-sheet-delay')?.value || !$('#wa-sheet-delay-max')?.value) {
      notice('Choose the shortest and longest gap between messages first.', true);
      return;
    }
    await saveSchedule();
    const sourceId = source.id;
    const scope = recordIds ? {record_ids: recordIds} : {};
    const plan = await post(sheetApi(`${sourceId}/plan`), scope);
    const dialog = waDialog();
    const phoneWord = plan.phones.length === 1 ? 'phone' : 'phones';
    const gapMin = Number(plan.gap_min || plan.gap || 0);
    const gapMax = Number(plan.gap_max || plan.gap || 0);
    const gapText = gapMin === gapMax
      ? `${gapMin} minute${gapMin === 1 ? '' : 's'} between messages on each phone`
      : `random ${gapMin}–${gapMax} minutes between messages on each phone`;
    $('#wa-send-title').textContent = `Send ${plan.total} ${recordIds ? 'selected ' : ''}rows from ${plan.phones.length} ${phoneWord}`;
    $('#wa-send-sub').textContent = `Starts ${waWhen(plan.start_at)} · ${gapText} · ${plan.daily} a day per phone`;
    $('#wa-send-body').innerHTML = waPlanBody(plan);
    const leftOut = [plan.skipped ? `${plan.skipped} row${plan.skipped === 1 ? ' is' : 's are'} already waiting or sent and ${plan.skipped === 1 ? 'is' : 'are'} left out.` : '', plan.unsafe ? `${plan.unsafe} number${plan.unsafe === 1 ? ' has' : 's have'} no country code the phone service can read, so ${plan.unsafe === 1 ? 'it is' : 'they are'} not sent. Fix in the sheet.` : ''].filter(Boolean);
    $('#wa-send-note').textContent = leftOut.join(' ');
    const cancel = $('#wa-send-cancel');
    cancel.hidden = false;
    cancel.onclick = () => dialog.close();
    const ok = $('#wa-send-ok');
    ok.textContent = `Send ${plan.total}`;
    ok.disabled = false;
    ok.onclick = async () => {
      ok.disabled = true;
      cancel.hidden = true;
      ok.textContent = 'Scheduling…';
      try {
        const queued = await post(sheetApi(`${sourceId}/queue`), scope);
        const phoneWord = queued.phones.length === 1 ? 'phone' : 'phones';
        notice(
          queued.resumed
            ? `${queued.queued} rows are waiting to send from ${queued.phones.length} ${phoneWord}. This sheet was paused, so it was resumed.`
            : `${queued.queued} rows are waiting to send from ${queued.phones.length} ${phoneWord}.`
        );
        if (recordIds) selectedIds.clear();
        await openSource(sourceId, true);
        startWaPump(true);
        await waShowToday();
      } catch (error) {
        dialog.close();
        notice(error.message, true);
      }
    };
    dialog.showModal();
  }

  async function pumpWaQueue() {
    try {
      const result = await post('whatsapp/sheets/run');
      if (!result.waiting) {
        clearInterval(waPumpTimer);
        waPumpTimer = null;
      }
    } catch (e) {}
  }

  function startWaPump(now = false) {
    if (!waPumpTimer) waPumpTimer = setInterval(pumpWaQueue, 20000);
    if (now) pumpWaQueue();
  }

  if (sheetMode() === 'whatsapp') startWaPump(true);

  $('#sheet-bulk-send-btn')?.addEventListener('click', async (event) => {
    if (sheetMode() === 'whatsapp') {
      const button = event.currentTarget;
      if (!(records || []).some(isNotSent) && (records || []).some((row) => isPendingRow(row) || waWasSent(row))) {
        busy(button, waShowToday);
        return;
      }
      busy(button, waConfirmSend);
      return;
    }
    if (selectedIds.size > 0) {
      performBatchSend(Array.from(selectedIds));
      return;
    }
    if (sheetFilter === 'pending' || ((sheetFilter === '' || sheetFilter === 'ready') && pendingSendIds().length > 0 && (records || []).filter(isNotSent).length === 0)) {
      performBatchSend(pendingSendIds());
      return;
    }
    if (sheetFilter === 'failed' || ((sheetFilter === '' || sheetFilter === 'ready') && failedSendIds().length > 0 && pendingSendIds().length === 0 && (records || []).filter(isNotSent).length === 0)) {
      performBatchSend(failedSendIds());
      return;
    }
    performBatchSend((records || []).filter(isNotSent).map(row => row.record_id));
  });

  document.querySelectorAll('.sheet-tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      sheetFilter = btn.dataset.tabFilter || '';
      const status = $('#sheet-status');
      if (status) status.value = '';
      page = 0;
      renderRows();
    });
  });

  const savedSheetSize = localStorage.getItem('rsl_sheet_size');
  if (savedSheetSize && $('#sheet-size')) $('#sheet-size').value = savedSheetSize;

  for (const [selector,event] of [['#sheet-search','input'],['#sheet-status','change'],['#sheet-size','change']]) $(selector)?.addEventListener(event, () => {
    if (selector === '#sheet-size') localStorage.setItem('rsl_sheet_size', $('#sheet-size').value);
    if (selector === '#sheet-status') sheetFilter = $('#sheet-status').value;
    page = 0; renderRows();
  });
  $('#sheet-prev').addEventListener('click', () => {page--;renderRows();}); $('#sheet-next').addEventListener('click', () => {page++;renderRows();});
  $('#refresh-sheet').addEventListener('click', event => busy(event.currentTarget, () => openSource(source.id, true)));
  $('#sheet-download')?.addEventListener('click', event => busy(event.currentTarget, async () => {
    if (!source?.id) return;
    const response = await fetch('/api/' + sheetApi(`${source.id}/download`), { credentials: 'same-origin' });
    if (!response.ok) {
      let message = 'Could not download this sheet.';
      try { message = (await response.json()).error || message; } catch (e) {}
      throw new Error(message);
    }
    const blob = await response.blob();
    const name = (response.headers.get('Content-Disposition') || '').match(/filename="([^"]+)"/)?.[1] || 'sheet-status.csv';
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = name;
    link.click();
    URL.revokeObjectURL(link.href);
  }));
  $('#reset-processing-btn')?.addEventListener('click', event => busy(event.currentTarget, async () => {
    if (!source || !source.id) return;
    const res = await post(`sheets/${source.id}/reset-processing`);
    await openSource(source.id, true);
    notice(res.recovered > 0 ? `Reset ${res.recovered} email(s) from processing back to ready.` : 'All processing emails have been unlocked.');
  }));

  $('#sheet-replace')?.addEventListener('click', () => $('#sheet-replace-file')?.click());
  $('#sheet-replace-file')?.addEventListener('change', event => {
    const file = event.currentTarget.files?.[0];
    event.currentTarget.value = '';
    if (!file || !source?.id) return;
    if (file.size > uploadLimit) {
      notice(`Choose an Excel or CSV file within the ${(uploadLimit / 1024 / 1024).toFixed(1)} MB upload limit.`, true);
      return;
    }
    busy($('#sheet-replace'), async () => {
      const form = new FormData();
      form.append('file', file);
      const response = await fetch('/api/' + sheetApi(`${source.id}/replace`), { method: 'POST', headers: { 'X-CSRF-Token': csrf }, body: form });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.error || 'Could not update this sheet.');
      await openSource(source.id, true);
      notice(sheetMode() === 'whatsapp'
        ? 'File updated on this sheet. Already queued or sent WhatsApp messages stay as they were. Open Column mapping if the columns changed.'
        : 'File updated on this sheet. Sent mail is unchanged. Open Column mapping to map the follow-up columns.');
    });
  });

  $('#source-mapping').addEventListener('click', event => busy(event.currentTarget, async () => {
    if (!source?.id) return;
    const form = $('#sheet-map-form');
    const preview = $('#sheet-preview');
    if (form && preview) preview.prepend(form);
    mappingId = source.id; savedMapping = source.mapping;
    uploadId = isUpload(source) ? source.spreadsheet_id : null;
    $('#sheet-url').value = isUpload(source) ? '' : `https://docs.google.com/spreadsheets/d/${source.spreadsheet_id}/edit`;
    $('#sheet-header').value = source.header_row;
    await readTabs($('#sheet-url').value, source.sheet_id); $('#sheet-tab').disabled = true; await readHeaders();
    form?.scrollIntoView({block:'start'});
  }));

  function editRow(row) {
    editing = {row,sourceId:source.id,uploaded:isUpload(source)}; $('#edit-fields').replaceChildren(); $('#edit-error').hidden = true;
    const where = isUpload(source) ? 'the uploaded copy' : 'the sheet';
    $('#edit-save-note').textContent = isUpload(source) ? 'Changes save to your uploaded copy in this application.' : 'Changes save to the original Google Sheet.';
    const locked = row.send_status === 'sent' || row.send_status === 'dry_run' || row.send_status === 'pending' || row.send_status === 'processing';
    const recordNote = $('#edit-record-note');
    recordNote.classList.toggle('is-locked', locked);
    if (row.send_status === 'sent' || row.send_status === 'dry_run') recordNote.textContent = `This email was already sent. Saving updates ${where} only. The recipient keeps the original message.`;
    else if (row.send_status === 'pending' || row.send_status === 'processing') recordNote.textContent = `This email is already queued. Saving updates ${where} only. The queued message stays as it was.`;
    else recordNote.textContent = 'Only changed fields are saved. Keep this sheet free of simultaneous edits while saving.';
    $('#edit-record-id').textContent = `Record ${row.record_id} · Row ${row.row}`;
    for (const [key,value] of Object.entries(row.values)) {
      if (key === 'id' || key === 'email') continue;
      const label = element('label', activeFields()[key] || key); const input = element(key === 'body' || key === 'message' ? 'textarea' : 'input');
      input.name = key; input.value = value; input.disabled = (row.formulas || []).includes(key);
      if (key === 'body' || key === 'message') input.addEventListener('input', () => fitEditorBody(input));
      label.append(input); if (input.disabled) label.append(element('small','Formula: edit in the original spreadsheet.')); $('#edit-fields').append(label);
    }
    $('#sheet-editor').showModal();
    $('#edit-fields').querySelectorAll('textarea').forEach(fitEditorBody);
  }
  function fitEditorBody(input) {
    input.style.height = 'auto';
    input.style.height = `${Math.max(input.scrollHeight, 140)}px`;
  }
  $('#close-editor').addEventListener('click', () => $('#sheet-editor').close());

  $('#sheet-edit-form').addEventListener('submit', async event => {
    event.preventDefault(); const button = event.submitter; button.disabled = true; $('#edit-error').hidden = true;
    try {
      const changes = {}; for (const [key,value] of new FormData(event.target)) if (value !== editing.row.values[key]) changes[key] = value;
      if (!Object.keys(changes).length) { $('#sheet-editor').close(); return; }

      // Optimistic update in memory and UI
      Object.assign(editing.row.values, changes);
      renderRows();

      await post(sheetApi(`${editing.sourceId}/edit`),{record_id:editing.row.record_id,version:editing.row.version,changes});
      $('#sheet-editor').close();
      await openSource(editing.sourceId, true);
      notice(editing.uploaded ? 'Changes saved to the uploaded copy.' : 'Changes saved to Google Sheets.');
    } catch(e) {
      $('#edit-error').textContent = e.message;
      $('#edit-error').hidden = false;
      await openSource(editing.sourceId, true);
    }
    finally {button.disabled = false;}
  });

  // Silent background sync on tab focus and 30s timer
  let isSilentlySyncing = false;
  async function silentSync() {
    if (!source?.id || isSilentlySyncing) return;
    if ($('#sheet-editor').open) return; // Do not interrupt modal editing
    try {
      isSilentlySyncing = true;
      const data = await api(sheetApi(source.id));
      if (openedId !== Number(source.id)) return;
      records = data.records; source = data.source;
      renderRows();
      if (!isUpload(source)) {
        syncPill('Synced with Google');
        setTimeout(() => { if (!isUpload(source)) syncPill('Live Sync Active'); }, 3000);
      }
    } catch (_) {}
    finally { isSilentlySyncing = false; }
  }

  window.addEventListener('focus', () => {
    if ($('#sheets')?.offsetParent !== null) silentSync();
  });
  setInterval(() => {
    if ($('#sheets')?.offsetParent !== null) silentSync();
  }, 30000);
})();