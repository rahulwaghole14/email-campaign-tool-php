(() => {
  const fieldOrder = ['id', 'name', 'phone', 'message'];
  const fields = {id:'Record ID', name:'Agency name', phone:'Phone number', message:'WhatsApp content'};
  const aliases = {
    id: ['id', 'record id', 'record_id'],
    name: ['agency name', 'name', 'recipient name'],
    phone: ['phone', 'phone number', 'mobile', 'mobile number', 'whatsapp number'],
    message: ['whatsapp content', 'whatsapp message', 'message', 'msg']
  };
  let uploadId = '', inspectedUrl = '', page = 0, records = [], openId = 0, fillingSender = false;
  const post = (path, data = {}) => api(path, {method:'POST', body:JSON.stringify(data)});
  const element = (tag, text, className) => { const el = document.createElement(tag); if (text !== undefined) el.textContent = text; if (className) el.className = className; return el; };
  const sourceInput = () => uploadId ? {upload_id: uploadId} : {url: inspectedUrl};

  function escapeHtml(str) {
    return String(str || '').replace(/[&<>"']/g, (ch) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  }

  async function busy(button, work) {
    if (button) button.disabled = true;
    try { await work(); } catch (e) { notice(e.message, true); }
    finally { if (button) button.disabled = false; }
  }

  function showDirectory() {
    $('#wa-sheets-directory').hidden = false;
    $('#wa-sheet-map').hidden = true;
    $('#wa-sheet-preview').hidden = true;
  }

  function renderSources(items) {
    const list = $('#wa-sheet-sources');
    if (!list) return;
    list.replaceChildren();
    if (!items.length) {
      list.append(element('div', 'No WhatsApp sheets yet. Upload a workbook to map the phone and message columns.', 'empty'));
      return;
    }
    for (const item of items) {
      const card = element('div', undefined, 'sheet-source-card');
      card.innerHTML = `
        <div class="sheet-source-main">
          <div class="source-icon-badge excel"><svg viewBox="0 0 24 24"><path fill="#107C41" d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/><path fill="#fff" d="M15.5 17l-2.4-4.5 2.3-4.5h-2.1l-1.3 3.1-1.3-3.1H8.6l2.3 4.5-2.4 4.5h2.1l1.4-3.3 1.4 3.3z"/></svg></div>
          <div class="sheet-source-info">
            <strong class="sheet-source-title">${escapeHtml(item.title)}</strong>
            <div class="sheet-source-meta"><span>Tab: <strong>${escapeHtml(item.tab_title || 'First tab')}</strong></span><span class="meta-dot">·</span><span>Header row: <strong>${item.header_row}</strong></span></div>
          </div>
        </div>
        <div class="sheet-source-actions">
          <button type="button" class="sheet-open-btn"><span>Open</span></button>
          <button type="button" class="sheet-delete-btn" aria-label="Remove sheet">Remove</button>
        </div>`;
      card.querySelector('.sheet-open-btn').addEventListener('click', (event) => busy(event.currentTarget, () => openSource(item.id)));
      card.querySelector('.sheet-delete-btn').addEventListener('click', (event) => {
        if (!confirm(`Remove "${item.title}" from WhatsApp sheets?`)) return;
        busy(event.currentTarget, async () => {
          await api(`whatsapp/sheets/${item.id}`, {method:'DELETE'});
          notice('WhatsApp sheet removed.');
          await window.loadWaSheets();
        });
      });
      list.append(card);
    }
  }

  window.loadWaSheets = async () => {
    showDirectory();
    const [status, data] = await Promise.all([api('google/status'), api('whatsapp/sheets')]);
    const limit = status.upload_limit || (5 * 1024 * 1024);
    const limitEl = $('#wa-upload-limit');
    if (limitEl) limitEl.textContent = `Excel (.xlsx) or CSV, up to ${(limit / 1024 / 1024).toFixed(1).replace('.0','')} MB`;
    renderSources(data.sources || []);
  };

  async function displayTabs(data) {
    const select = $('#wa-sheet-tab');
    select.replaceChildren();
    for (const tab of data.sheets || []) {
      const option = element('option', tab.properties.title);
      option.value = tab.properties.sheetId;
      select.append(option);
    }
    $('#wa-sheet-map').hidden = false;
    $('#wa-sheets-directory').hidden = true;
    $('#wa-sheet-preview').hidden = true;
    $('#wa-map-title').textContent = data.properties?.title || 'Workbook';
    $('#wa-map-save').hidden = true;
    $('#wa-map-fields').replaceChildren();
    await readHeaders();
  }

  async function readHeaders() {
    const data = await post('sheets/inspect', {...sourceInput(), sheet_id: Number($('#wa-sheet-tab').value), header_row: Number($('#wa-header').value)});
    const headers = (data.headers || []).map(header => String(header || '').trim()).filter(Boolean);
    const grid = $('#wa-map-fields');
    grid.replaceChildren();
    for (const key of fieldOrder) {
      const required = key === 'phone' || key === 'message';
      const card = element('label', undefined, 'mapping-field-card' + (required ? ' is-required' : ''));
      const headerRow = element('div', undefined, 'mapping-field-header');
      headerRow.append(
        element('span', fields[key], 'mapping-field-title'),
        element('span', required ? 'Required' : 'Optional', required ? 'mapping-badge required' : 'mapping-badge optional')
      );
      const select = element('select');
      select.name = key;
      select.append(element('option', required ? 'Select column…' : 'Not mapped'));
      select.firstChild.value = '';
      for (const header of headers) select.append(new Option(header, header));
      const match = headers.find(header => aliases[key].includes(header.toLowerCase()));
      if (match) select.value = match;
      const sync = () => card.classList.toggle('is-mapped', Boolean(select.value));
      select.addEventListener('change', sync);
      sync();
      card.append(headerRow, select);
      grid.append(card);
    }
    $('#wa-map-save').hidden = false;
  }

  function snippet(text) {
    const flat = String(text || '').replace(/\s+/g, ' ').trim();
    return flat.length > 140 ? flat.slice(0, 137) + '…' : flat;
  }

  function renderRecords() {
    const body = $('#wa-sheet-rows');
    const size = 10;
    const slice = records.slice(page * size, page * size + size);
    body.replaceChildren();
    const ready = records.filter(row => !row.issues?.length).length;
    $('#wa-sheet-summary').textContent = `${records.length} row${records.length === 1 ? '' : 's'} · ${ready} ready`;
    if (!slice.length) {
      const tr = element('tr');
      const td = element('td', 'No rows in this sheet.');
      td.colSpan = 5;
      tr.append(td);
      body.append(tr);
    }
    for (const row of slice) {
      const tr = element('tr');
      const check = element('td', row.issues?.length ? row.issues.join(', ') : (row.skipped_numbers ? `Ready · ${row.skipped_numbers} skipped` : 'Ready'), 'wa-sheet-check ' + (row.issues?.length ? 'is-issue' : 'is-ready'));
      const message = element('td', snippet(row.values.message), 'wa-sheet-message');
      message.title = row.values.message || '';
      tr.append(
        element('td', row.record_id || '—'),
        element('td', row.values.name || '—'),
        element('td', row.values.phone || '—'),
        message,
        check
      );
      body.append(tr);
    }
    const pages = Math.max(1, Math.ceil(records.length / size));
    $('#wa-sheet-page').textContent = `${page + 1} / ${pages}`;
    $('#wa-sheet-prev').disabled = page === 0;
    $('#wa-sheet-next').disabled = page >= pages - 1;
  }

  function senderLabel(device) {
    const name = device.name || 'Phone';
    return device.phone ? `${name} · ${device.phone}` : name;
  }

  async function fillSender(source) {
    const select = $('#wa-sheet-sender');
    const note = $('#wa-sheet-sender-note');
    if (!select) return;
    fillingSender = true;
    const saved = source?.device_id || '';
    select.replaceChildren();
    const blank = element('option', 'Choose a ready phone');
    blank.value = '';
    select.append(blank);
    let roster = {devices: []};
    try { roster = await api('whatsapp/device'); } catch (error) { if (note) note.textContent = error.message; }
    const ready = (roster.devices || []).filter((device) => device.status === 'ready' || device.status === 'connected');
    for (const device of ready) {
      const option = element('option', senderLabel(device));
      option.value = device.id;
      select.append(option);
    }
    const known = (roster.devices || []).find((device) => device.id === saved);
    if (saved && !ready.some((device) => device.id === saved)) {
      const option = element('option', known ? `${senderLabel(known)} (not linked)` : 'Saved phone (not linked)');
      option.value = saved;
      select.append(option);
    }
    select.value = saved;
    if (note) {
      note.textContent = saved && !ready.some((device) => device.id === saved)
        ? 'This phone is not ready, so these rows will not send.'
        : (ready.length ? 'This phone sends the rows in this sheet.' : 'Link a phone on Linked device before choosing one.');
    }
    fillingSender = false;
  }

  async function openSource(id) {
    const data = await api(`whatsapp/sheets/${id}`);
    openId = id;
    records = data.records || [];
    page = 0;
    $('#wa-preview-title').textContent = data.source?.title || 'WhatsApp sheet';
    $('#wa-sheets-directory').hidden = true;
    $('#wa-sheet-map').hidden = true;
    $('#wa-sheet-preview').hidden = false;
    renderRecords();
    await fillSender(data.source || {});
  }

  $('#wa-sheet-file')?.addEventListener('change', () => {
    const file = $('#wa-sheet-file').files?.[0];
    if (file) uploadFile(file);
  });

  async function uploadFile(file) {
    const button = $('#wa-upload-btn');
    await busy(button, async () => {
      notice(`Uploading ${file.name}…`);
      const form = new FormData();
      form.append('file', file);
      const response = await fetch('/api/sheets/upload', {method:'POST', headers:{'X-CSRF-Token': csrf}, body: form});
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Upload failed.');
      uploadId = data.upload_id;
      inspectedUrl = '';
      $('#wa-header').value = 1;
      await displayTabs(data.metadata);
      notice('File uploaded. Check ID, name, phone, and the WhatsApp message.');
    });
    $('#wa-sheet-file').value = '';
  }

  $('#wa-upload-btn')?.addEventListener('click', () => $('#wa-sheet-file').click());
  $('#wa-read-headers')?.addEventListener('click', (event) => busy(event.currentTarget, readHeaders));
  $('#wa-map-cancel')?.addEventListener('click', () => { uploadId = ''; inspectedUrl = ''; window.loadWaSheets(); });
  $('#wa-preview-back')?.addEventListener('click', () => window.loadWaSheets());
  $('#wa-sheet-sender')?.addEventListener('change', (event) => {
    if (!openId || fillingSender) return;
    busy(event.currentTarget, async () => {
      await post(`whatsapp/sheets/${openId}/sender`, {device_id: event.currentTarget.value});
      const data = await api(`whatsapp/sheets/${openId}`);
      await fillSender(data.source || {});
      notice('Sending phone saved for this sheet.');
    });
  });
  $('#wa-sheet-prev')?.addEventListener('click', () => { page -= 1; renderRecords(); });
  $('#wa-sheet-next')?.addEventListener('click', () => { page += 1; renderRecords(); });
  $('#wa-refresh')?.addEventListener('click', () => window.loadWaSheets());

  $('#wa-link-form')?.addEventListener('submit', (event) => {
    event.preventDefault();
    busy(event.submitter, async () => {
      uploadId = '';
      inspectedUrl = $('#wa-sheet-url').value.trim();
      const data = await post('sheets/inspect', {url: inspectedUrl});
      $('#wa-header').value = 1;
      await displayTabs(data);
    });
  });

  $('#wa-map-form')?.addEventListener('submit', (event) => {
    event.preventDefault();
    busy(event.submitter, async () => {
      const mapping = {};
      $('#wa-map-fields').querySelectorAll('select').forEach((select) => { mapping[select.name] = select.value; });
      const saved = await post('whatsapp/sheets', {
        ...sourceInput(),
        sheet_id: Number($('#wa-sheet-tab').value),
        header_row: Number($('#wa-header').value),
        mapping
      });
      notice('WhatsApp sheet saved.');
      await openSource(saved.id);
    });
  });
})();
