(function () {
  const root = document.querySelector('[data-notification-center]');
  if (!root || !window.SERVMON_API_ALERTS) return;

  const toggle = root.querySelector('[data-notification-toggle]');
  const panel = root.querySelector('[data-notification-panel]');
  const list = root.querySelector('[data-notification-list]');
  const count = root.querySelector('[data-notification-count]');
  const summary = root.querySelector('[data-notification-summary]');
  const readAll = root.querySelector('[data-notification-read-all]');

  // Viewers are read-only: hide mutation controls (server also enforces admin-only).
  const canMutateAlerts = window.SERVMON_USER_ROLE === 'admin';
  if (!canMutateAlerts && readAll) readAll.style.display = 'none';

  function escapeHtml(value) {
    const node = document.createElement('div');
    node.textContent = value ?? '';
    return node.innerHTML;
  }

  function severityClass(severity) {
    return ['danger', 'warning', 'success'].includes(severity) ? severity : 'info';
  }

  function render(data) {
    const alerts = Array.isArray(data.alerts) ? data.alerts : [];
    const unread = Number(data.unread_count || 0);
    count.textContent = unread > 99 ? '99+' : String(unread);
    count.classList.toggle('d-none', unread < 1);
    summary.textContent = unread ? `${unread} active alert${unread === 1 ? '' : 's'}` : 'All clear';
    readAll.disabled = unread < 1;

    if (!alerts.length) {
      list.innerHTML = '<div class="notification-empty"><i class="ti ti-circle-check" aria-hidden="true"></i><span>No new alerts</span></div>';
      return;
    }

    list.innerHTML = alerts.map((alert) => {
      const severity = severityClass(alert.severity);
      const ackButton = canMutateAlerts
        ? `<button type="button" class="btn btn-sm btn-icon notification-dismiss" data-notification-ack="${Number(alert.id)}" title="Mark as read" aria-label="Mark ${escapeHtml(alert.title || 'alert')} as read"><i class="ti ti-check" aria-hidden="true"></i></button>`
        : '';
      return `<article class="notification-item notification-item-${severity}">
        <div class="notification-item-icon"><i class="ti ti-${severity === 'danger' ? 'alert-triangle' : severity === 'warning' ? 'alert-circle' : 'info-circle'}" aria-hidden="true"></i></div>
        <div class="notification-item-body">
          <strong>${escapeHtml(alert.title || 'Alert')}</strong>
          <p>${escapeHtml(alert.message || '')}</p>
          <small>${escapeHtml(alert.created_at || '')}</small>
        </div>
        ${ackButton}
      </article>`;
    }).join('');
  }

  async function load() {
    try {
      const response = await fetch(`${window.SERVMON_API_ALERTS}?recent=1&limit=8`, { headers: { Accept: 'application/json' } });
      if (response.ok) render(await response.json());
    } catch (error) {
      summary.textContent = 'Unable to load alerts';
    }
  }

  async function acknowledge(action, alertId) {
    const body = new URLSearchParams({ action, _csrf_token: window.SERVMON_CSRF_TOKEN || '' });
    if (alertId) body.set('alert_id', String(alertId));
    const response = await fetch(window.SERVMON_API_ALERTS, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
      body,
    });
    if (!response.ok) throw new Error('Unable to update alert');
    await load();
    window.dispatchEvent(new CustomEvent('servmon:alerts-updated'));
  }

  let lastFocused = null;

  function isOpen() {
    return !panel.classList.contains('d-none');
  }

  function openPanel() {
    lastFocused = document.activeElement;
    panel.classList.remove('d-none');
    toggle.setAttribute('aria-expanded', 'true');
    panel.focus({ preventScroll: true });
    load();
  }

  function closePanel() {
    if (!isOpen()) return;
    panel.classList.add('d-none');
    toggle.setAttribute('aria-expanded', 'false');
    if (lastFocused && typeof lastFocused.focus === 'function') {
      lastFocused.focus();
    }
  }

  function getFocusable() {
    return Array.from(
      panel.querySelectorAll('button, a[href], input, select, textarea, [tabindex]:not([tabindex="-1"])')
    ).filter((el) => !el.disabled && el.offsetParent !== null);
  }

  function trapFocus(event) {
    if (event.key !== 'Tab' || !isOpen()) return;
    const focusable = getFocusable();
    if (focusable.length === 0) {
      event.preventDefault();
      return;
    }
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  toggle.addEventListener('click', () => {
    if (isOpen()) {
      closePanel();
    } else {
      openPanel();
    }
  });

  document.addEventListener('click', (event) => {
    if (!root.contains(event.target)) {
      closePanel();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && isOpen()) {
      closePanel();
      event.stopPropagation();
    }
    trapFocus(event);
  });

  root.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-notification-ack]');
    if (!button) return;
    button.disabled = true;
    try { await acknowledge('acknowledge', button.dataset.notificationAck); } catch (error) { button.disabled = false; }
  });

  readAll.addEventListener('click', async () => {
    readAll.disabled = true;
    try { await acknowledge('acknowledge_all'); } catch (error) { readAll.disabled = false; }
  });

  load();
  const stopNotif = ServMon.startPoller(load, {baseMs:30000, maxMs:120000});
  window.servmonNotificationStop = stopNotif;
})();
