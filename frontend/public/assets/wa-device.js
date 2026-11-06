(() => {
  let busy = false;
  let pairing = '';
  let pairingQr = '';
  let opening = '';
  let latest = { devices: [], slots: 5 };

  function escapeHtml(value) {
    return String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function stateLabel(status) {
    if (status === 'ready' || status === 'connected') return 'Ready';
    if (status === 'blocked') return 'Blocked';
    return 'Not linked';
  }

  function paint(data) {
    const list = document.getElementById('wa-device-list');
    const state = document.getElementById('wa-device-state');
    if (!list || !state) return;
    latest = data;
    const devices = data.devices || [];
    const readyCount = devices.filter((d) => d.status === 'ready' || d.status === 'connected').length;
    state.className = 'muted wa-overview-desc';
    state.innerHTML = devices.length
      ? `<span class="wa-live-dot-text"><span class="status-dot ${readyCount ? '' : 'is-muted'}"></span>${devices.length} ${devices.length === 1 ? 'phone configured' : 'phones configured'}</span> · <span>${readyCount} active &amp; ready</span>`
      : 'No phones yet. Add one to scan.';
    
    const listEyebrow = document.getElementById('wa-device-list-eyebrow');
    if (listEyebrow) {
      listEyebrow.textContent = `CONFIGURED PHONES (${devices.length})`;
    }

    const used = document.getElementById('wa-device-slot-used');
    const maxSlots = document.getElementById('wa-device-slot-max');
    const addBtn = document.getElementById('wa-device-add');
    const slots = Number(data.slots) > 0 ? Number(data.slots) : 5;
    if (used) used.textContent = String(devices.length);
    if (maxSlots) maxSlots.textContent = String(slots);
    if (addBtn) {
      const isFull = devices.length >= slots;
      addBtn.disabled = isFull;
      addBtn.title = isFull ? 'All slots in use. Disconnect or delete a phone to add more.' : 'Add another device';
    }

    const capLabel = document.getElementById('wa-capacity-label');
    const capFill = document.getElementById('wa-capacity-fill');
    const capDesc = document.getElementById('wa-capacity-desc');
    if (capLabel) capLabel.textContent = `${devices.length} of ${slots} Used`;
    if (capFill) {
      const pct = Math.min(100, Math.round((devices.length / slots) * 100));
      capFill.style.width = pct + '%';
    }
    if (capDesc) {
      const spare = Math.max(0, slots - devices.length);
      capDesc.textContent = spare > 0
        ? `${spare} spare slot${spare === 1 ? '' : 's'} remaining for additional campaign routing.`
        : 'All available slots currently in use.';
    }

    if (pairing && devices.some((device) => device.id === pairing && (device.status === 'ready' || device.status === 'connected'))) {
      pairing = '';
      pairingQr = '';
      opening = '';
    }

    list.replaceChildren();
    for (const device of devices) {
      const ready = device.status === 'ready' || device.status === 'connected';
      const isThisPairing = device.id === pairing && Boolean(pairingQr);
      const isOpening = device.id === opening && !pairingQr;
      let subText = 'Not linked yet · Standby';
      if (device.status === 'blocked') {
        subText = 'Blocked';
      } else if (isThisPairing) {
        subText = 'Scanning live QR · Link from your phone';
      } else if (device.name && device.name.toLowerCase().includes('campaign')) {
        subText = 'Default account name · Ready to link';
      }

      const row = document.createElement('article');
      row.className = 'wa-device-card' + (ready ? ' is-ready' : '') + (isThisPairing ? ' is-active-pairing' : '');
      row.innerHTML = `
        <div class="wa-device-card-left">
          <div class="wa-device-avatar ${ready ? 'is-ready' : ''}">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="7" y="2" width="10" height="20" rx="2"></rect>
              <path d="M11 18h2"></path>
            </svg>
          </div>
          <div class="wa-device-info">
            <div class="wa-device-title-row">
              <strong>${escapeHtml(device.name || 'Phone')}</strong>
              <span class="wa-device-pill is-${escapeHtml(device.status || 'disconnected')}">
                ${ready ? '<span class="status-dot"></span>' : ''}
                ${stateLabel(device.status)}
              </span>
            </div>
            ${ready && device.phone ? `<p class="wa-device-phone-line">${escapeHtml(device.phone)}</p>` : ''}
            <p class="wa-device-sub ${ready ? 'is-phone' : 'muted'}">
              ${ready ? 'Primary sender · Campaign dispatch active' : escapeHtml(subText)}
            </p>
          </div>
        </div>
        <div class="wa-device-row-actions"></div>
      `;
      const actions = row.querySelector('.wa-device-row-actions');
      if (!ready && device.status !== 'blocked' && device.id) {
        const pair = document.createElement('button');
        pair.type = 'button';
        if (isThisPairing) {
          pair.className = 'wa-device-pairing-btn';
          pair.innerHTML = '+ Pair &amp; Scan QR';
        } else {
          pair.className = 'quiet wa-device-pair-btn';
          pair.textContent = isOpening ? 'Opening…' : 'Pair';
        }
        pair.disabled = isOpening;
        pair.addEventListener('click', () => openScan(device.id, device.name));
        actions.append(pair);
      }
      if (ready && device.id) {
        const drop = document.createElement('button');
        drop.type = 'button';
        drop.className = 'quiet wa-device-disconnect-btn';
        drop.textContent = 'Disconnect';
        drop.addEventListener('click', () => disconnect(device));
        actions.append(drop);
      }
      list.append(row);
    }

    // Update Right-Hand Side Dock
    const scanDock = document.getElementById('wa-device-scan-dock');
    const infoCard = document.getElementById('wa-device-info-card');
    const scanTargetName = document.getElementById('wa-scan-target-name');
    const scanQrImg = document.getElementById('wa-scan-qr-img');

    if (pairing && pairingQr) {
      const targetDevice = devices.find((d) => d.id === pairing);
      const targetName = targetDevice?.name || 'Phone';
      if (scanTargetName) scanTargetName.textContent = targetName;
      if (scanQrImg) scanQrImg.src = pairingQr;
      if (scanDock) scanDock.hidden = false;
      if (infoCard) infoCard.hidden = true;
    } else {
      if (scanDock) scanDock.hidden = true;
      if (infoCard) infoCard.hidden = false;
    }
  }

  function paintOne(data) {
    if (data.status === 'ready' || data.status === 'connected') {
      pairingQr = '';
      return;
    }
    if (data.qr) pairingQr = data.qr;
  }

  async function loadWaDevice() {
    const page = document.getElementById('wa-device');
    const state = document.getElementById('wa-device-state');
    if (!page || page.hidden || !state || busy) return;
    if (document.body.dataset.channel !== 'whatsapp') return;
    busy = true;
    try {
      if (pairing) paintOne(await api('whatsapp/device/picture', { method: 'POST', body: JSON.stringify({ id: pairing }) }));
      paint(await api('whatsapp/device'));
    } catch (error) {
      state.className = 'wa-device-state is-issue';
      state.textContent = error.message;
    } finally {
      busy = false;
    }
  }

  function closeScan() {
    pairing = '';
    pairingQr = '';
    opening = '';
    loadWaDevice();
  }

  async function openScan(id, name) {
    const state = document.getElementById('wa-device-state');
    if (busy) return;
    busy = true;
    opening = id || '';
    pairing = id || '';
    pairingQr = '';
    paint(latest);
    try {
      const body = id ? { id } : { name: name || '' };
      const data = await api('whatsapp/device/picture', { method: 'POST', body: JSON.stringify(body) });
      if (!id) pairing = data.id || pairing;
      opening = '';
      paintOne(data);
      paint(latest);
      paint(await api('whatsapp/device'));
    } catch (error) {
      opening = '';
      pairing = '';
      if (state) {
        state.className = 'wa-device-state is-issue';
        state.textContent = error.message;
      }
      paint(latest);
    } finally {
      busy = false;
    }
  }

  async function disconnect(device) {
    const state = document.getElementById('wa-device-state');
    if (busy) return;
    const label = device.phone || device.name || 'this phone';
    if (!confirm('Disconnect ' + label + '? The other phones stay linked.')) return;
    busy = true;
    try {
      if (pairing === device.id) pairing = '';
      paint(await api('whatsapp/device/disconnect', { method: 'POST', body: JSON.stringify({ id: device.id }) }));
    } catch (error) {
      if (state) {
        state.className = 'wa-device-state is-issue';
        state.textContent = error.message;
      }
    } finally {
      busy = false;
    }
  }

  function fillSettings(data) {
    const name = document.getElementById('wa-settings-name');
    const base = document.getElementById('wa-settings-base');
    const token = document.getElementById('wa-settings-token');
    if (name) name.value = data.name || '';
    if (base) base.value = data.base_url || '';
    if (token) token.value = data.access_token || '';
  }

  async function loadWaSettings() {
    const page = document.getElementById('wa-settings');
    const note = document.getElementById('wa-settings-note');
    if (!page || page.hidden) return;
    try {
      fillSettings(await api('whatsapp/settings'));
      if (note) {
        note.className = 'muted';
        note.textContent = 'This account is saved on the desk.';
      }
    } catch (error) {
      if (note) {
        note.className = 'wa-device-state is-issue';
        note.textContent = error.message;
      }
    }
  }

  window.loadWaDevice = loadWaDevice;
  window.loadWaSettings = loadWaSettings;
  document.getElementById('wa-settings-form')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const note = document.getElementById('wa-settings-note');
    const button = document.getElementById('wa-settings-save');
    if (button) button.disabled = true;
    try {
      fillSettings(await api('whatsapp/settings', {
        method: 'POST',
        body: JSON.stringify({
          name: document.getElementById('wa-settings-name')?.value || '',
          base_url: document.getElementById('wa-settings-base')?.value || '',
          access_token: document.getElementById('wa-settings-token')?.value || '',
        }),
      }));
      if (note) {
        note.className = 'muted';
        note.textContent = 'Account saved. The linked phone uses these values.';
      }
    } catch (error) {
      if (note) {
        note.className = 'wa-device-state is-issue';
        note.textContent = error.message;
      }
    } finally {
      if (button) button.disabled = false;
    }
  });
  document.getElementById('wa-device-add')?.addEventListener('click', () => {
    const form = document.getElementById('wa-device-name-form');
    const input = document.getElementById('wa-device-new-name');
    if (form) form.hidden = false;
    if (input) input.focus();
  });
  document.getElementById('wa-device-name-cancel')?.addEventListener('click', () => {
    const form = document.getElementById('wa-device-name-form');
    if (form) form.hidden = true;
  });
  document.getElementById('wa-device-name-form')?.addEventListener('submit', (event) => {
    event.preventDefault();
    const input = document.getElementById('wa-device-new-name');
    const name = input?.value.trim() || '';
    if (!name) return;
    const form = document.getElementById('wa-device-name-form');
    if (form) form.hidden = true;
    if (input) input.value = '';
    openScan('', name);
  });
  document.getElementById('wa-device-scan-close')?.addEventListener('click', closeScan);
  document.getElementById('wa-scan-cancel-btn')?.addEventListener('click', closeScan);
  setInterval(() => loadWaDevice(), 5000);
})();
