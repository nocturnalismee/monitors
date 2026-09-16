/**
 * Monitors — Ping monitors live refresh via AJAX (no page reload).
 *
 * Depends on: common.js (Monitors namespace: escapeHtml, startPoller).
 * Falls back to plain setInterval when the shared poller is unavailable.
 */
(() => {
  "use strict";

  const SM = window.Monitors || {};
  const escapeHtml =
    typeof SM.escapeHtml === "function"
      ? SM.escapeHtml
      : (value) => String(value ?? "");

  const tbody = document.querySelector("[data-ping-tbody]");
  if (!tbody) return;

  const intervalMs = Math.max(
    5000,
    Number(window.MONITORS_PING_REFRESH_MS || 15000),
  );
  const uptimePoints = Math.max(1, Number(tbody.dataset.pingPoints || 30));

  const summaryEls = {};
  document.querySelectorAll("[data-ping-summary]").forEach((el) => {
    summaryEls[el.getAttribute("data-ping-summary")] = el;
  });

  const setText = (el, value) => {
    if (!el) return;
    const text = String(value ?? "");
    if (el.textContent !== text) el.textContent = text;
  };

  const hasActiveFormFocus = () => {
    const el = document.activeElement;
    if (!(el instanceof HTMLElement)) return false;
    const tag = el.tagName.toLowerCase();
    return tag === "input" || tag === "select" || tag === "textarea";
  };

  const hasActiveTerminal = () => {
    const modal = document.getElementById("pingTerminalModal");
    return !!modal && modal.classList.contains("show");
  };

  const hasOpenDropdown = () =>
    !!document.querySelector(".dropdown-menu.show");

  const segmentHtml = (segment) => {
    const status = String(segment?.status ?? "pending");
    const cls =
      status === "up" ? "is-up" : status === "down" ? "is-down" : "is-pending";
    const checkedAt = segment?.checked_at ?? null;
    const title =
      checkedAt !== null && checkedAt !== ""
        ? `Status: ${status.toUpperCase()} | ${checkedAt}`
        : "Status: PENDING";
    return `<span class="ping-uptime-segment ${cls}" title="${escapeHtml(title)}" aria-hidden="true"></span>`;
  };

  const patchRow = (row, data) => {
    const status = String(data.display_status ?? "unknown");

    const badge = row.querySelector('[data-ping-cell="status"]');
    if (badge) {
      const nextClass = `badge ${String(data.badge_class || "badge-pending")} text-uppercase`;
      if (badge.className !== nextClass) badge.className = nextClass;
      if (badge.textContent !== status) badge.textContent = status;
    }

    const percent = row.querySelector('[data-ping-cell="uptime_percent"]');
    if (percent) {
      const value = data.uptime_percent;
      setText(
        percent,
        value === null || value === undefined || value === ""
          ? "-"
          : `${Number(value).toFixed(2)}%`,
      );
    }

    const strip = row.querySelector('[data-ping-cell="uptime_strip"]');
    if (strip) {
      const bars = Array.isArray(data.uptime_bars)
        ? data.uptime_bars.slice(-uptimePoints)
        : [];
      while (bars.length < uptimePoints) {
        bars.unshift({ status: "pending", checked_at: null });
      }
      const nextHtml = bars.map(segmentHtml).join("");
      if (strip.dataset.renderedHtml !== nextHtml) {
        strip.innerHTML = nextHtml;
        strip.dataset.renderedHtml = nextHtml;
      }
    }

    const latency = row.querySelector('[data-ping-cell="latency"]');
    if (latency) {
      const value = data.last_latency_ms;
      setText(
        latency,
        value === null || value === undefined || value === ""
          ? "-"
          : `${Number(value).toFixed(2)} ms`,
      );
    }

    const lastChecked = row.querySelector('[data-ping-cell="last_checked"]');
    if (lastChecked) {
      const value = data.last_checked_at ?? "";
      setText(lastChecked, value === "" ? "-" : String(value));
    }
  };

  const patchSummary = (summary) => {
    if (!summary || typeof summary !== "object") return;
    const num = (value) => {
      const parsed = Number(value ?? 0);
      return Number.isFinite(parsed) ? String(Math.trunc(parsed)) : "0";
    };
    const pending = Number(summary.pending ?? 0);
    const paused = Number(summary.paused ?? 0);
    setText(summaryEls.total, num(summary.total));
    setText(summaryEls.up, num(summary.up));
    setText(summaryEls.down, num(summary.down));
    setText(
      summaryEls.pending_paused,
      String(Math.trunc(pending) + Math.trunc(paused)),
    );
    setText(summaryEls.pending, num(summary.pending));
    setText(summaryEls.paused, num(summary.paused));
  };

  async function refreshPingMonitors() {
    // Skip silently (no backoff): user is interacting with the page.
    if (hasActiveFormFocus() || hasActiveTerminal()) return true;

    const url = new URL(window.location.href);
    url.searchParams.set("format", "json");

    let response;
    try {
      response = await fetch(url.toString(), {
        headers: { Accept: "application/json" },
        cache: "no-store",
      });
    } catch (error) {
      console.error(error);
      return false;
    }
    if (!response.ok) return false;

    let payload;
    try {
      payload = await response.json();
    } catch (error) {
      console.error(error);
      return false;
    }
    if (!payload || !Array.isArray(payload.rows)) return false;

    const incomingIds = payload.rows.map((item) => String(item?.id ?? ""));
    const domRows = Array.from(
      tbody.querySelectorAll("tr[data-monitor-id]"),
    );
    const domIds = domRows.map((row) =>
      row.getAttribute("data-monitor-id"),
    );
    const sameStructure =
      incomingIds.length === domIds.length &&
      incomingIds.every((id, index) => id === domIds[index]);
    if (!sameStructure) {
      // Monitor added/removed: row markup (actions, CSRF forms) only
      // exists server-side, so take one full reload instead of guessing.
      if (hasOpenDropdown()) return true;
      window.location.reload();
      return true;
    }

    const rowsById = new Map();
    payload.rows.forEach((item) => {
      rowsById.set(String(item?.id ?? ""), item);
    });
    domRows.forEach((row) => {
      const data = rowsById.get(row.getAttribute("data-monitor-id"));
      if (data) patchRow(row, data);
    });
    patchSummary(payload.summary);
    return true;
  }

  if (typeof SM.startPoller === "function") {
    SM.startPoller(refreshPingMonitors, {
      baseMs: intervalMs,
      maxMs: 120000,
    });
  } else {
    window.setInterval(() => {
      if (!document.hidden) refreshPingMonitors();
    }, intervalMs);
  }
})();
