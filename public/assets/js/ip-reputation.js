/**
 * IP Reputation list page – summary auto-refresh.
 * (Check-Now is bound once by Monitors.bindIpRepCheckNow in common.js.)
 */
(function () {
  'use strict';

  var API_URL = window.MONITORS_IP_REP_API || '';

  /* Check-Now buttons are bound once by Monitors.bindIpRepCheckNow()
   * (common.js), shared with the detail page. */

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
