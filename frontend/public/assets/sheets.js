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
  const waAliases = {id:['id','record id','record_id'],name:['agency name','name'],phone:['phone','phone number','mobile','whatsapp number'],message:['whatsapp content','whatsapp message']};
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
  const isNotSent = row => canAddress(row) && (row.send_status === 'not_queued' || !row.send_status);
  const isPendingRow = row => canAddress(row) && (row.send_status === 'pending' || row.send_status === 'processing');
  const isFailedRow = row => canAddress(row) && row.send_status === 'failed';
  const isRowValid = isNotSent;
  const pendingSendIds = () => (records || []).filter(row => canAddress(row) && row.send_status === 'pending').map(row => row.record_id);
  const failedSendIds = () => (records || []).filter(isFailedRow).map(row => row.record_id);
  const rowMatchesFilter = (row, filter) => {
    if (!filter) return true;
    if (filter === 'issues') return row.issues.length > 0;
    if (filter === 'ready') return isNotSent(row);
    if (filter === 'pending') return isPendingRow(row);
    if (filter === 'failed') return isFailedRow(row);
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
    if (idHead) idHead.textContent = wa ? 'ID' : 'ID / agency';
    if (extra) {
      extra.textContent = wa ? 'Agency / Name' : 'Email';
      extra.hidden = !wa;
    }
    if (mid) mid.textContent = wa ? 'Phone' : 'Email';
    if (next) next.textContent = wa ? 'Message' : 'Subject';
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
      $('#sheet-header').value = (file.name.toLowerCase().endsWith('.csv') || sheetMode() === 'whatsapp') ? 1 : 6;
      displayTabs(data.metadata);
      $('#sheet-map-form').scrollIntoView({ behavior: 'smooth', block: 'center' });
      notice('File uploaded! Select tab and header row, then map the columns.');
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
    $('#sheet-mappings').replaceChildren();
    const saveBtn = $('#save-source');
    if (saveBtn) saveBtn.hidden = true;
    const actionsBar = $('#mapping-actions');
    if (actionsBar) actionsBar.hidden = true;
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
    $('#sheet-map-form').hidden = false; clearMapping();
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
            <span><strong>${headers.length} columns detected</strong> from Row ${Number($('#sheet-header').value)}</span>
          </div>
          <span class="detected-columns-hint">Verify the auto-mapped fields below or adjust any column</span>
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

      const autoValue = savedMapping?.[key] || headers.find(header => (headerAliases[key] || []).includes(header.toLowerCase())) || '';
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

    const reqSection = element('div', undefined, 'mapping-section');
    reqSection.innerHTML = `<div class="mapping-section-title"><span class="section-pill required">1</span> ${wa ? 'Phone and WhatsApp message' : 'Mandatory Email Fields (Required to dispatch)'}</div><div class="mapping-section-grid"></div>`;
    const reqGrid = reqSection.querySelector('.mapping-section-grid');
    requiredKeys.forEach(key => reqGrid.append(createFieldCard(key, labels[key], true)));

    const optSection = element('div', undefined, 'mapping-section');
    optSection.innerHTML = `<div class="mapping-section-title"><span class="section-pill optional">2</span> ${wa ? 'Optional fields (Record ID, agency name)' : 'Optional Parameters (Name, Drive link, CC, BCC, Send Enabled)'}</div><div class="mapping-section-grid"></div>`;
    const optGrid = optSection.querySelector('.mapping-section-grid');
    optionalKeys.forEach(key => optGrid.append(createFieldCard(key, labels[key], false)));

    const sections = [reqSection, optSection];
    if (followKeys.length) {
      const followSection = element('div', undefined, 'mapping-section');
      followSection.innerHTML = `<div class="mapping-section-title"><span class="section-pill optional">3</span> Follow-up mails (subject and message for each step)</div><div class="mapping-section-grid"></div>`;
      const followGrid = followSection.querySelector('.mapping-section-grid');
      followKeys.forEach(key => followGrid.append(createFieldCard(key, labels[key], false)));
      sections.push(followSection);
    }
    $('#sheet-mappings').append(...sections);

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
      if (waBar) waBar.hidden = true;
      const readyRecords = (records || []).filter(r => !r.issues?.length);
      const readyCount = readyRecords.length;
      if (waBulk && waBulkLabel) {
        if (readyCount > 0) {
          waBulk.hidden = false;
          waBulk.style.display = 'inline-flex';
          waBulkLabel.textContent = `Send all ${readyCount} ready`;
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
    }
  }

  let fillingSchedule = false;

  function paintSchedule(sheet) {
    const scheduleEl = document.querySelector('#sheet-preview .sheet-schedule');
    if (scheduleEl) scheduleEl.hidden = sheetMode() !== 'whatsapp';
    const date = $('#wa-sheet-date');
    const delay = $('#wa-sheet-delay');
    if (!date || !delay || sheetMode() !== 'whatsapp') return;
    fillingSchedule = true;
    if (!delay.options.length) {
      const blank = element('option', 'Minutes');
      blank.value = '';
      delay.append(blank);
      for (let minutes = 1; minutes <= 30; minutes += 1) {
        const option = element('option', `${minutes} minute${minutes === 1 ? '' : 's'}`);
        option.value = String(minutes);
        delay.append(option);
      }
    }
    date.value = sheet?.start_date || '';
    delay.value = sheet?.delay_minutes ? String(sheet.delay_minutes) : '';
    fillingSchedule = false;
    paintCap();
  }

  function paintCap() {
    const cap = $('#wa-sheet-cap');
    if (!cap || sheetMode() !== 'whatsapp') return;
    const ready = (records || []).filter((row) => !row.issues?.length).length;
    const count = chosenPhoneIds().length;
    const daily = count * 20;
    const days = ready && daily ? Math.ceil(ready / daily) : 0;

    if (!count) {
      cap.innerHTML = '<span class="wa-cap-stat">⚡ <strong>0 msgs / day</strong></span> &middot; <span>Pick a ready phone to schedule dispatch</span>';
      cap.title = 'Pick a ready phone to schedule dispatch';
      return;
    }

    const dispatchText = `${ready} rows dispatch across <strong>${days || 1} days</strong>`;
    cap.innerHTML = `<span class="wa-cap-stat">⚡ <strong>${daily} msgs / day / phone</strong></span> &middot; <span>${dispatchText}</span> &middot; <span class="wa-cap-note">Country code preserved</span>`;
    cap.title = `${daily} msgs / day / phone · ${ready} rows dispatch across ${days || 1} days · Country code preserved`;
  }

  async function saveSchedule() {
    if (!source?.id || fillingSchedule || sheetMode() !== 'whatsapp') return;
    const startDate = $('#wa-sheet-date')?.value || '';
    const delayRaw = $('#wa-sheet-delay')?.value || '';
    const delayMinutes = delayRaw === '' ? null : Number(delayRaw);
    await post(sheetApi(`${source.id}/schedule`), {start_date: startDate, delay_minutes: delayMinutes});
    source.start_date = startDate || null;
    source.delay_minutes = delayMinutes;
    paintCap();
    notice('Schedule saved for the ready phones.');
  }

  $('#wa-sheet-date')?.addEventListener('change', (event) => {
    if (fillingSchedule || !source?.id) return;
    busy(event.currentTarget, saveSchedule);
  });
  $('#wa-sheet-delay')?.addEventListener('change', (event) => {
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
    const sentCount = records.filter(r => r.send_status === 'sent').length;
    const issuesCount = records.filter(r => r.issues && r.issues.length > 0).length;

    const cAll = $('#count-all-records'), cReady = $('#count-ready-records'), cPending = $('#count-pending-records'), cFailed = $('#count-failed-records'), cSent = $('#count-sent-records'), cIssues = $('#count-issues-records');
    if (cAll) cAll.textContent = allCount;
    if (cReady) cReady.textContent = readyCount;
    if (cPending) cPending.textContent = pendingCount;
    if (cFailed) cFailed.textContent = failedCount;
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
      const chkCell = element('td'); chkCell.style.textAlign = 'center';
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

      // ID / Agency cell in one line
      const id = element('td', undefined, 'cell-id-agency-td');
      const idWrap = element('div', undefined, 'cell-id-agency');
      const idVal = element('span', String(row.record_id || 'Missing ID'), 'sheet-id-val');
      const agencyName = String(row.values.name || '').trim();
      if (agencyName) {
        const dot = element('span', '·', 'sheet-id-dot');
        const agencyVal = element('span', agencyName, 'sheet-agency-val');
        idWrap.append(idVal, dot, agencyVal);
      } else {
        idWrap.append(idVal);
      }
      id.append(idWrap);

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
      } else if (row.send_status === 'not_queued') {
        statusWrap.append(element('span', 'Not sent', 'badge ready'));
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
        const idCell = element('td', String(row.record_id || '—'), 'cell-wa-id');
        const nameCell = element('td', String(row.values.name || '—'), 'cell-wa-name');
        nameCell.title = String(row.values.name || '');
        const phoneCell = element('td', String(row.values.phone || '—'), 'cell-wa-phone');
        const messageCell = element('td', String(row.values.message || '—'), 'cell-wa-message');
        messageCell.title = String(row.values.message || '');
        tr.append(idCell, nameCell, phoneCell, messageCell, status, actions);
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

      tr.append(chkCell, id, emailCell, subjectCell, status, actions);
      $('#sheet-rows').append(tr);
    }

    if (!filtered.length) {
      const row = element('tr'); const td = cell(records.length ? 'No records match your filters.' : 'No records below the header row.');
      td.colSpan = 6; td.style.textAlign = 'center'; row.append(td); $('#sheet-rows').append(row);
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

  $('#batch-send-btn')?.addEventListener('click', () => {
    performBatchSend(Array.from(selectedIds));
  });

  $('#sheet-bulk-send-btn')?.addEventListener('click', async () => {
    if (sheetMode() === 'whatsapp') {
      const readyRecords = (records || []).filter(r => !r.issues?.length);
      const readyCount = readyRecords.length;
      const phoneCount = chosenPhoneIds().length;
      if (!phoneCount) {
        notice('Please pick at least one ready phone in "Send from".', true);
        return;
      }
      if (!readyCount) {
        notice('No ready records to dispatch.', true);
        return;
      }
      await saveSchedule();
      notice(`Campaign ready: ${readyCount} rows scheduled for WhatsApp dispatch.`);
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
    const response = await fetch('/api/sheets/' + source.id + '/download', { credentials: 'same-origin' });
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
      const response = await fetch('/api/sheets/' + source.id + '/replace', { method: 'POST', headers: { 'X-CSRF-Token': csrf }, body: form });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.error || 'Could not update this sheet.');
      await openSource(source.id, true);
      notice('File updated on this sheet. Sent mail is unchanged. Open Column mapping to map the follow-up columns.');
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