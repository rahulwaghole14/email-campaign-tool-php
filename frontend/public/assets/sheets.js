(() => {
  const fields = {id:'Record ID',name:'Agency / recipient name',email:'Email',subject:'Email subject',body:'Email content',link:'Drive link',cc:'CC',bcc:'BCC',enabled:'Send Enabled'};
  const aliases = {id:['id','record_id'], name:['agency name','recipient_name'],email:['email','recipient_email'],subject:['email subject','subject'],body:['email content','message'],link:['drive_link','drive link'],cc:['cc'],bcc:['bcc'],enabled:['send enabled','send_enabled']};
  let inspectedUrl = '', source = null, records = [], page = 0, editing = null, mappingId = null, savedMapping = null, openedId = null;
  let uploadId = null;
  let uploadLimit = 5 * 1024 * 1024;
  let allSources = [];
  const selectedIds = new Set();
  const isUpload = item => item?.spreadsheet_id?.startsWith('upload:');
  const sourceInput = () => uploadId ? {upload_id:uploadId} : {url:inspectedUrl};
  const post = (path, data = {}) => api(path, {method:'POST',body:JSON.stringify(data)});
  const element = (tag, text, className) => { const el = document.createElement(tag); if (text !== undefined) el.textContent = text; if (className) el.className = className; return el; };
  const isRowValid = row => !row.issues?.length && row.send_status !== 'sent' && (!('enabled' in row.values) || row.values.enabled.trim().toUpperCase() === 'YES');

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
            </div>
            <div class="sheet-source-meta">
              <span>Tab: <strong>${item.sheet_id === 0 ? 'First tab' : (String(item.sheet_id).length > 5 ? 'Tab ID: ' + item.sheet_id : 'Tab #' + item.sheet_id)}</strong></span>
              <span class="meta-dot">·</span>
              <span>Header row: <strong>${item.header_row}</strong></span>
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
            await api(`sheets/${item.id}`, { method: 'DELETE' });
            notice(`Sheet source "${item.title}" removed.`);
            await window.loadSheets();
          });
        }
      });

      list.append(card);
    }
  }

  window.loadSheets = async () => {
    try {
      const [status, data] = await Promise.all([api('google/status'),api('sheets')]);
      uploadLimit = status.upload_limit || uploadLimit;
      allSources = data.sources || [];
      window.googleStatusConnected = status.connected;

      const limitEl = $('#upload-limit');
      if (limitEl) limitEl.textContent = `Excel (.xlsx) or CSV, up to ${(uploadLimit / 1024 / 1024).toFixed(1).replace('.0','')} MB`;

      const statusPill = $('#google-status-pill');
      if (statusPill) {
        statusPill.hidden = !status.connected;
        statusPill.style.display = status.connected ? 'inline-flex' : 'none';
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

      if (!openedId) { $('#sheets-directory').hidden = false; $('#sheet-preview').hidden = true; }
      if (!status.connected) { if (!isUpload(source)) { $('#sheet-preview').hidden = true; $('#sheets-directory').hidden = false; } if (!uploadId) $('#sheet-map-form').hidden = true; }
    } catch(e) {notice(e.message,true);}
  };

  $('#saved-sheet-search')?.addEventListener('input', e => {
    const term = e.target.value.toLowerCase().trim();
    if (!term) {
      renderSourcesList(allSources, window.googleStatusConnected);
      return;
    }
    const filtered = allSources.filter(s => (s.title || '').toLowerCase().includes(term));
    renderSourcesList(filtered, window.googleStatusConnected);
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
      $('#sheet-header').value = file.name.toLowerCase().endsWith('.csv') ? 1 : 6;
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

  function clearMapping() { $('#sheet-mappings').replaceChildren(); $('#save-source').hidden = true; }
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
    for (const [key,label] of Object.entries(fields)) {
      const wrapper = element('label',label + (['id','email','subject','body'].includes(key) ? ' *' : ''));
      const select = element('select'); select.name = key; select.required = ['id','email','subject','body'].includes(key);
      const empty = element('option',select.required ? 'Select column' : 'Not mapped'); empty.value = ''; select.append(empty);
      for (const header of data.headers.filter(Boolean)) { const option = element('option',header); option.value = header; select.append(option); }
      select.value = savedMapping?.[key] || data.headers.find(header => aliases[key].includes(header.toLowerCase())) || '';
      if (mappingId && key === 'id') select.disabled = true;
      wrapper.append(select); $('#sheet-mappings').append(wrapper);
    }
    $('#save-source').hidden = false;
  }
  $('#read-headers').addEventListener('click', event => busy(event.currentTarget, readHeaders));

  $('#sheet-map-form').addEventListener('submit', event => { event.preventDefault(); busy(event.submitter, async () => {
    const mapping = {}; $('#sheet-mappings').querySelectorAll('select').forEach(select => mapping[select.name] = select.value);
    const data = await post(mappingId ? `sheets/${mappingId}/mapping` : 'sheets', {...sourceInput(),sheet_id:Number($('#sheet-tab').value),header_row:Number($('#sheet-header').value),mapping});
    $('#sheet-map-form').hidden = true; await window.loadSheets(); await openSource(data.id); notice('Sheet source saved.');
  }); });

  function updateBatchBar() {
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
      } else if (readyCount > 0) {
        bulkBtn.hidden = false;
        bulkLabel.textContent = `Send All ${readyCount} Ready`;
      } else {
        bulkBtn.hidden = true;
      }
    }
  }

  async function openSource(id, preserveSelections = false) {
    const sameSource = Number(source?.id) === Number(id);
    openedId = Number(id);
    if (!preserveSelections) selectedIds.clear();
    const noticeEl = $('#notice');
    if (noticeEl) noticeEl.hidden = true;
    const data = await api(`sheets/${id}`);
    if (openedId !== Number(id)) return;
    source = data.source; records = data.records;
    if (!sameSource) { page = 0; $('#sheet-search').value = ''; $('#sheet-status').value = ''; }
    $('#source-title').textContent = `${source.title} / ${data.tab.title}`;
    $('#source-summary').textContent = `${records.length} records · Header row ${source.header_row}`;
    $('#open-sheet').href = `https://docs.google.com/spreadsheets/d/${source.spreadsheet_id}/edit#gid=${source.sheet_id}`;
    $('#open-sheet').hidden = isUpload(source);
    $('#source-edit-note').textContent = isUpload(source) ? 'Edits save to the uploaded copy in this application. Your original file and existing queued emails stay unchanged.' : 'Edits save to Google Sheets. Already queued or sent emails keep their original content.';
    $('#sheets-directory').hidden = true;
    $('#sheet-preview').hidden = false;
    window.scrollTo({top: 0, behavior: 'smooth'});
    renderRows();
    updateBatchBar();
  }

  $('#sheet-back-btn')?.addEventListener('click', () => {
    $('#sheet-preview').hidden = true;
    $('#sheets-directory').hidden = false;
    source = null;
    openedId = null;
    selectedIds.clear();
    window.scrollTo({top: 0, behavior: 'smooth'});
  });

  function renderRows() {
    const size = Number($('#sheet-size').value), term = $('#sheet-search').value.trim().toLowerCase(), filter = $('#sheet-status').value;
    const filtered = records.filter(row => {
      const matchesTerm = [row.record_id,row.values.name,row.values.email,row.values.subject].some(value => String(value || '').toLowerCase().includes(term));
      if (!matchesTerm) return false;
      if (!filter) return true;
      if (filter === 'issues') return row.issues.length > 0;
      if (filter === 'ready') return isRowValid(row);
      return row.send_status === filter;
    });

    const pages = Math.max(1,Math.ceil(filtered.length / size)); page = Math.max(0,Math.min(page,pages - 1));
    const pageRecords = filtered.slice(page * size, (page + 1) * size);
    $('#sheet-rows').replaceChildren();

    // Update Quick Filter Tab counters and active state
    const allCount = records.length;
    const readyCount = records.filter(isRowValid).length;
    const sentCount = records.filter(r => r.send_status === 'sent').length;
    const issuesCount = records.filter(r => r.issues && r.issues.length > 0).length;

    const cAll = $('#count-all-records'), cReady = $('#count-ready-records'), cSent = $('#count-sent-records'), cIssues = $('#count-issues-records');
    if (cAll) cAll.textContent = allCount;
    if (cReady) cReady.textContent = readyCount;
    if (cSent) cSent.textContent = sentCount;
    if (cIssues) cIssues.textContent = issuesCount;

    document.querySelectorAll('.sheet-tab-btn').forEach(btn => {
      btn.classList.toggle('active', (btn.dataset.tabFilter || '') === filter);
    });

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

      // ID / Agency cell with tight compact spacing
      const id = element('td', undefined, 'cell-id-agency-td');
      const idWrap = element('div', undefined, 'cell-id-agency');
      const idVal = element('span', String(row.record_id || 'Missing ID'), 'sheet-id-val');
      const agencyVal = element('span', String(row.values.name || ''), 'sheet-agency-val');
      idWrap.append(idVal, agencyVal);
      id.append(idWrap);

      const cleanEmail = String(row.values.email || '').replace(/^[`'"\s\u00a0\u200b]+|[`'"\s\u00a0\u200b]+$/g, '');
      const emailCell = element('td', cleanEmail, 'cell-email');
      const subjectCell = element('td', String(row.values.subject || '—'), 'cell-subject');

      // Status badges
      const status = element('td', undefined, 'cell-status');
      const statusWrap = element('div', undefined, 'cell-status-wrap');
      const statusBadge = element('span', row.send_status.replaceAll('_',' '), `badge ${row.send_status}`);
      statusWrap.append(statusBadge);
      if (row.issues.length) {
        for (const issue of row.issues) {
          statusWrap.append(element('span', issue, 'badge issue'));
        }
      } else if (row.send_status === 'not_queued') {
        statusWrap.append(element('span', 'Ready', 'badge ready'));
      }
      status.append(statusWrap);

      // Actions
      const actions = element('td', undefined, 'sheet-row-actions');
      const edit = element('button', 'Edit', 'sheet-btn-action');
      edit.disabled = !row.editable;
      edit.addEventListener('click', () => editRow(row));
      actions.append(edit);

      if (row.email_id) {
        const details = element('button', 'View email', 'sheet-btn-action');
        details.addEventListener('click', () => window.openEmailDetail(row.email_id));
        actions.append(details);
      }

      const queue = element('button', 'Send email', 'sheet-btn-action btn-send-single');
      queue.disabled = !validToSend;
      queue.addEventListener('click', () => busy(queue, async () => {
        if (!confirm(`Send this email now to ${cleanEmail}?\nSubject: ${row.values.subject}`)) return;
        const data = await post(`sheets/${source.id}/send`, {record_id: row.record_id, version: row.version});
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
  }

  // Select all checkbox handler
  $('#sheet-select-all')?.addEventListener('change', event => {
    const checked = event.target.checked;
    const size = Number($('#sheet-size').value), term = $('#sheet-search').value.trim().toLowerCase(), filter = $('#sheet-status').value;
    const filtered = records.filter(row => {
      const matchesTerm = [row.record_id,row.values.name,row.values.email,row.values.subject].some(value => String(value || '').toLowerCase().includes(term));
      if (!matchesTerm) return false;
      if (!filter) return true;
      if (filter === 'issues') return row.issues.length > 0;
      if (filter === 'ready') return isRowValid(row);
      return row.send_status === filter;
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

  // High-speed Batch / Bulk send handler (Persistent SMTP Connection & Single-Pass Verification)
  async function performBatchSend(ids) {
    if (!ids || !ids.length) return;
    if (!confirm(`Send ${ids.length} email${ids.length === 1 ? '' : 's'} now directly via Hostinger SMTP?`)) return;
    const btn = $('#batch-send-btn') || $('#sheet-bulk-send-btn');
    const origHtml = btn ? btn.innerHTML : '';
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = `<span>⚡ Dispatching ${ids.length} emails…</span>`;
    }
    notice(`Dispatching ${ids.length} email${ids.length === 1 ? '' : 's'} via persistent Hostinger SMTP connection…`);
    try {
      const res = await post(`sheets/${source.id}/batch-send`, { record_ids: ids });
      selectedIds.clear();
      notice(`⚡ Bulk dispatch complete! ${res.sent} email${res.sent === 1 ? '' : 's'} sent successfully${res.failed ? ` (${res.failed} failed/skipped)` : ''}.`);
    } catch (err) {
      notice(`Batch dispatch failed: ${err.message}`, true);
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = origHtml;
      }
      await openSource(source.id, true);
      if (typeof window.load === 'function') await window.load();
      updateBatchBar();
    }
  }

  $('#batch-send-btn')?.addEventListener('click', () => {
    performBatchSend(Array.from(selectedIds));
  });

  $('#sheet-bulk-send-btn')?.addEventListener('click', () => {
    if (selectedIds.size > 0) {
      performBatchSend(Array.from(selectedIds));
    } else {
      const readyIds = (records || []).filter(isRowValid).map(r => r.record_id);
      performBatchSend(readyIds);
    }
  });

  document.querySelectorAll('.sheet-tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const tabFilter = btn.dataset.tabFilter || '';
      $('#sheet-status').value = tabFilter;
      page = 0;
      renderRows();
    });
  });

  const savedSheetSize = localStorage.getItem('rsl_sheet_size');
  if (savedSheetSize && $('#sheet-size')) $('#sheet-size').value = savedSheetSize;

  for (const [selector,event] of [['#sheet-search','input'],['#sheet-status','change'],['#sheet-size','change']]) $(selector).addEventListener(event, () => {
    if (selector === '#sheet-size') localStorage.setItem('rsl_sheet_size', $('#sheet-size').value);
    page = 0; renderRows();
  });
  $('#sheet-prev').addEventListener('click', () => {page--;renderRows();}); $('#sheet-next').addEventListener('click', () => {page++;renderRows();});
  $('#refresh-sheet').addEventListener('click', event => busy(event.currentTarget, () => openSource(source.id, true)));

  $('#source-mapping').addEventListener('click', event => busy(event.currentTarget, async () => {
    mappingId = source.id; savedMapping = source.mapping;
    uploadId = isUpload(source) ? source.spreadsheet_id : null;
    $('#sheet-url').value = isUpload(source) ? '' : `https://docs.google.com/spreadsheets/d/${source.spreadsheet_id}/edit`;
    $('#sheet-header').value = source.header_row;
    await readTabs($('#sheet-url').value, source.sheet_id); $('#sheet-tab').disabled = true; await readHeaders(); $('#sheet-map-form').scrollIntoView({block:'start'});
  }));

  function editRow(row) {
    editing = {row,sourceId:source.id,uploaded:isUpload(source)}; $('#edit-fields').replaceChildren(); $('#edit-error').hidden = true;
    $('#edit-save-note').textContent = isUpload(source) ? 'Changes save to your uploaded copy in this application.' : 'Changes save to the original Google Sheet.';
    $('#edit-record-id').textContent = `Record ${row.record_id} · Row ${row.row}`;
    for (const [key,value] of Object.entries(row.values)) {
      if (key === 'id') continue;
      const label = element('label',fields[key]); const input = element(key === 'body' ? 'textarea' : 'input');
      input.name = key; input.value = value; input.disabled = row.formulas.includes(key);
      if (key === 'body') input.rows = 5;
      label.append(input); if (input.disabled) label.append(element('small','Formula: edit in the original spreadsheet.')); $('#edit-fields').append(label);
    }
    $('#sheet-editor').showModal();
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

      await post(`sheets/${editing.sourceId}/edit`,{record_id:editing.row.record_id,version:editing.row.version,changes});
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
      const data = await api(`sheets/${source.id}`);
      if (openedId !== Number(source.id)) return;
      records = data.records; source = data.source;
      renderRows();
      const statusPill = $('#sheet-sync-status');
      if (statusPill) {
        statusPill.innerHTML = `<svg viewBox="0 0 24 24" class="icon" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>Synced with Google`;
        setTimeout(() => {
          if (statusPill) statusPill.innerHTML = `<svg viewBox="0 0 24 24" class="icon" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>Live Sync Active`;
        }, 3000);
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