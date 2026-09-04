/**
 * IP Reputation list page – Check Now button + auto-refresh
 */
(function () {
  'use strict';

  var API_URL = window.SERVMON_IP_REP_API || '';

  /* ── Check Now buttons ─────────────────────────────────── */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-ip-rep-check-now]');
    if (!btn || !API_URL) return;

    var targetId = btn.getAttribute('data-ip-rep-check-now');
    var ip = btn.getAttribute('data-ip') || '';
    if (!targetId) return;

    btn.disabled = true;
    var origHtml = btn.innerHTML;
    btn.innerHTML = '<i class="ti ti-loader-2 ti-spin me-1"></i>Checking ' + ip + '…';

    fetch(API_URL + '?action=check_now&id=' + encodeURIComponent(targetId), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ _csrf_token: window.SERVMON_CSRF_TOKEN || '' }),
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          var status = (data.result && data.result.overall_status) || 'unknown';
          btn.innerHTML = '<i class="ti ti-check me-1"></i>' + status.toUpperCase();
          setTimeout(function () { location.reload(); }, 1200);
        } else {
          btn.innerHTML = '<i class="ti ti-alert-triangle me-1"></i>Failed';
          setTimeout(function () { btn.innerHTML = origHtml; btn.disabled = false; }, 3000);
        }
      })
      .catch(function () {
        btn.innerHTML = '<i class="ti ti-alert-triangle me-1"></i>Error';
        setTimeout(function () { btn.innerHTML = origHtml; btn.disabled = false; }, 3000);
      });
  });

  /* ── Auto-refresh (every 30s) ──────────────────────────── */
  if (API_URL) {
    setInterval(function () {
      fetch(API_URL + '?action=list', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data || !data.summary) return;
          // Update summary cards
          var cards = document.querySelectorAll('.summary-card-value');
          if (cards.length >= 4) {
            cards[0].textContent = String(data.summary.total || 0);
            cards[1].textContent = String(data.summary.clean || 0);
            cards[2].textContent = String(data.summary.listed || 0);
            cards[3].textContent = String((data.summary.unknown || 0) + (data.summary.paused || 0));
          }
        })
        .catch(function () { /* silent */ });
    }, 30000);
  }
})();
