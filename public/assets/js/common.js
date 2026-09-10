/**
 * ServMon shared utility functions.
 *
 * Used by both public.js and dashboard.js to avoid code duplication.
 * Exposes functions on the global `ServMon` namespace.
 */
window.ServMon = window.ServMon || {};

(function (ns) {
  "use strict";

  // ── HTML escaping ──────────────────────────────────────────────────
  ns.escapeHtml = function (input) {
    const div = document.createElement("div");
    div.textContent = input ?? "";
    return div.innerHTML;
  };

  // ── Formatting helpers ─────────────────────────────────────────────
  ns.formatBytes = function (bytes) {
    if (!bytes || Number(bytes) <= 0) return "0 B";
    const units = ["B", "KB", "MB", "GB", "TB"];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    const value = bytes / Math.pow(1024, i);
    return `${value.toFixed(i === 0 ? 0 : 2)} ${units[i]}`;
  };

  ns.formatBps = function (bps) {
    const value = Number(bps ?? 0);
    if (!Number.isFinite(value) || value <= 0) return "0 bps";
    const units = ["bps", "Kb", "Mb", "Gb", "Tb"];
    let scaled = value;
    let unitIndex = 0;
    while (scaled >= 1000 && unitIndex < units.length - 1) {
      scaled /= 1000;
      unitIndex += 1;
    }
    return `${scaled.toFixed(unitIndex === 0 ? 0 : 2)} ${units[unitIndex]}`;
  };

  ns.formatMailQueue = function (mailMta, mailQueueTotal) {
    const queue = Number(mailQueueTotal ?? 0);
    return `${Number.isFinite(queue) ? Math.max(0, Math.trunc(queue)) : 0}`;
  };

  // ── Panel brand chip ───────────────────────────────────────────────
  // Mirrors PHP panel_brand_chip(). Brand data comes from
  // window.SERVMON_PANEL_BRANDS (see panel_brands_for_js()).
  ns.panelBrandChip = function (profile, extraClass) {
    const brands = window.SERVMON_PANEL_BRANDS || {};
    const raw = String(profile ?? "");
    const key = raw.toLowerCase().trim();
    const fallback = { slug: "generic", label: key === "" ? "generic" : raw, logo: null, logo_dark: null };
    const brand = Object.prototype.hasOwnProperty.call(brands, key) ? brands[key] : fallback;
    const slug = String((brand && brand.slug) || "generic");
    const label = String((brand && brand.label) || fallback.label);
    const logo = brand && brand.logo ? String(brand.logo) : null;
    const logoDark = brand && brand.logo_dark ? String(brand.logo_dark) : null;
    const cls = `panel-brand panel-${slug}${extraClass ? ` ${extraClass}` : ""}`;
    if (!logo) {
      return `<span class="${ns.escapeHtml(cls)}">${ns.escapeHtml(label)}</span>`;
    }
    let html = `<span class="${ns.escapeHtml(cls)}" title="${ns.escapeHtml(label)}">`;
    const logoCls = logoDark ? "panel-logo panel-logo-day" : "panel-logo";
    html += `<img class="${logoCls}" src="${ns.escapeHtml(logo)}" alt="${ns.escapeHtml(label)} logo" loading="lazy">`;
    if (logoDark) {
      html += `<img class="panel-logo panel-logo-dark" src="${ns.escapeHtml(logoDark)}" alt="" aria-hidden="true" loading="lazy">`;
    }
    return `${html}</span>`;
  };

  // ── Status helpers ─────────────────────────────────────────────────
  ns.statusClass = function (status) {
    if (status === "online") return "badge-online";
    if (status === "down") return "badge-down";
    return "badge-pending";
  };

  ns.usagePercent = function (used, total) {
    const usedNumber = Number(used || 0);
    const totalNumber = Number(total || 0);
    if (!Number.isFinite(totalNumber) || totalNumber <= 0) return 0;
    const pct = (usedNumber / totalNumber) * 100;
    return Math.max(0, Math.min(100, pct));
  };

  ns.usageClass = function (percent) {
    if (percent >= 80) return "is-critical";
    if (percent > 60) return "is-warning";
    return "is-ok";
  };

  // ── CPU load severity (threshold-based) ─────────────────────────────
  ns.cpuThresholds = function () {
    const t = window.SERVMON_CPU_THRESHOLDS || {};
    const warn = Number(t.warn);
    const critical = Number(t.critical);
    return {
      warn: Number.isFinite(warn) && warn > 0 ? warn : 2,
      critical:
        Number.isFinite(critical) && critical > 0 ? Math.max(warn, critical) : 4,
    };
  };

  ns.cpuLoadSeverity = function (load) {
    const { warn, critical } = ns.cpuThresholds();
    const value = Number(load);
    if (!Number.isFinite(value)) return "ok";
    if (value > critical) return "critical";
    if (value > warn) return "warn";
    return "ok";
  };

  ns.cpuSeverityClass = function (load) {
    const severity = ns.cpuLoadSeverity(load);
    if (severity === "critical") return "text-danger";
    if (severity === "warn") return "text-warning";
    return "";
  };

  ns.renderUsageCell = function (used, total, label) {
    const safeUsed = Number.isFinite(Number(used))
      ? Math.max(0, Number(used))
      : 0;
    const safeTotal = Number.isFinite(Number(total))
      ? Math.max(0, Number(total))
      : 0;
    const pct = ns.usagePercent(safeUsed, safeTotal);
    const pctText = pct.toFixed(1);
    return `
      <div class="resource-cell">
        <div class="resource-label">
          <span>${ns.escapeHtml(`${ns.formatBytes(safeUsed)} / ${ns.formatBytes(safeTotal)}`)}</span>
          <span>${ns.escapeHtml(`${pctText}%`)}</span>
        </div>
        <div class="progress resource-progress" role="progressbar" aria-label="${ns.escapeHtml(label)} usage" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${ns.escapeHtml(pctText)}">
          <div class="progress-bar resource-progress-bar ${ns.usageClass(pct)}" style="--target-width:${ns.escapeHtml(pctText)}%"></div>
        </div>
      </div>
    `;
  };

  ns.renderServiceSummary = function (s) {
    const summary = s.services_summary || {};
    let up = Number(summary.up) || 0;
    let down = Number(summary.down) || 0;
    let unknown = Number(summary.unknown) || 0;
    const total = up + down + unknown;
    if ((s.status ?? "") === "down" && total > 0) {
      up = 0;
      down = total;
      unknown = 0;
    }
    if (down > 0 || unknown > 0) {
      let html = "";
      if (down > 0) {
        html += `<span class="service-state text-danger"><i class="ti ti-arrow-down-circle" aria-label="down"></i><span class="font-mono">${ns.escapeHtml(String(down))}</span></span>`;
      }
      if (unknown > 0) {
        html += `<span class="service-state text-warning"><i class="ti ti-help-circle" aria-label="unknown"></i><span class="font-mono">${ns.escapeHtml(String(unknown))}</span></span>`;
      }
      return html;
    }
    return `<span class="service-state text-success"><i class="ti ti-arrow-up-circle" aria-label="up"></i><span class="font-mono">${ns.escapeHtml(String(up))}</span></span>`;
  };

  // Visibility-aware polling with overlap protection and exponential backoff.
  // This keeps background tabs from producing continuous traffic and gives a
  // recovering server some breathing room after repeated failures.
  ns.startPoller = function (task, options = {}) {
    const baseMs = Math.max(5000, Number(options.baseMs) || 15000);
    const maxMs = Math.max(baseMs, Number(options.maxMs) || 120000);
    let delayMs = baseMs;
    let timer = null;
    let running = false;
    let stopped = false;

    const schedule = (delay) => {
      if (stopped) return;
      window.clearTimeout(timer);
      timer = window.setTimeout(async () => {
        if (stopped) return;
        if (document.hidden) {
          schedule(baseMs);
          return;
        }
        if (running) {
          schedule(baseMs);
          return;
        }
        running = true;
        try {
          const result = await task();
          if (result === false) {
            throw new Error("poll request failed");
          }
          delayMs = baseMs;
        } catch (error) {
          console.error(error);
          delayMs = Math.min(maxMs, delayMs * 2);
        } finally {
          running = false;
          schedule(delayMs);
        }
      }, Math.max(0, delay));
    };

    const onVisibilityChange = () => {
      if (!document.hidden) {
        delayMs = baseMs;
        schedule(0);
      }
    };
    document.addEventListener("visibilitychange", onVisibilityChange);
    schedule(0);

    return () => {
      stopped = true;
      window.clearTimeout(timer);
      document.removeEventListener("visibilitychange", onVisibilityChange);
    };
  };

  // ── CPU Sparkline ──────────────────────────────────────────────────

  /** @type {Map<string, {last_seen: string|null, values: number[]}>} */
  const cpuHistory = new Map();

  /**
   * @param {string} storageKey
   */
  ns.restoreCpuHistory = function (storageKey) {
    try {
      const raw = window.sessionStorage.getItem(storageKey);
      if (!raw) return;
      const parsed = JSON.parse(raw);
      if (!parsed || typeof parsed !== "object") return;
      Object.entries(parsed).forEach(([key, value]) => {
        const values = Array.isArray(value?.values)
          ? value.values
              .map((n) => Number(n))
              .filter((n) => Number.isFinite(n))
              .slice(-20)
          : [];
        cpuHistory.set(String(key), {
          last_seen: value?.last_seen ?? null,
          values,
        });
      });
    } catch (err) {
      console.warn("Failed to restore CPU history", err);
    }
  };

  /**
   * @param {string} storageKey
   */
  ns.persistCpuHistory = function (storageKey) {
    try {
      const data = {};
      cpuHistory.forEach((value, key) => {
        data[key] = {
          last_seen: value?.last_seen ?? null,
          values: Array.isArray(value?.values) ? value.values.slice(-20) : [],
        };
      });
      window.sessionStorage.setItem(storageKey, JSON.stringify(data));
    } catch (err) {
      console.warn("Failed to persist CPU history", err);
    }
  };

  ns.getCpuSparkline = function (s) {
    const serverId = String(s.id ?? "");
    const currentLoad = Number(s.cpu_load) || 0;
    const lastSeen = s.last_seen;

    let record = cpuHistory.get(serverId);
    if (!record) {
      record = { last_seen: null, values: [] };
      cpuHistory.set(serverId, record);
    }

    if (record.last_seen !== lastSeen) {
      record.values.push(currentLoad);
      record.last_seen = lastSeen;
      if (record.values.length > 20) {
        record.values.shift();
      }
    }

    const history = record.values;

    if (history.length < 2) {
      const width = 60;
      const height = 18;
      const normalizedMax = Math.max(currentLoad, 5);
      const y =
        height - 2 - (Math.max(currentLoad, 0) / normalizedMax) * (height - 4);
      const clampedY = Number.isFinite(y)
        ? Math.max(2, Math.min(height - 2, y))
        : height - 2;
      return `<svg class="cpu-sparkline" width="${width}" height="${height}" role="img" aria-label="CPU load ${ns.escapeHtml(currentLoad.toFixed(2))}"><title>CPU load ${ns.escapeHtml(currentLoad.toFixed(2))}</title><polyline fill="none" stroke="var(--sv-muted)" stroke-width="1.5" points="0,${clampedY.toFixed(1)} ${width},${clampedY.toFixed(1)}"/></svg>`;
    }

    const maxVal = Math.max(...history, 1.5);
    const width = 60;
    const height = 18;
    const maxPoints = 20;
    const stepX = width / (maxPoints - 1);

    let points = "";
    const startIdx = maxPoints - history.length;

    history.forEach((val, i) => {
      const x = (startIdx + i) * stepX;
      const y = height - 2 - (val / maxVal) * (height - 4);
      points += `${x.toFixed(1)},${y.toFixed(1)} `;
    });

    const latestLoad = history[history.length - 1];
    let strokeColor = "var(--sv-accent)";
    if (latestLoad >= Math.max(0.8 * maxVal, 1.0) && latestLoad > 2.0)
      strokeColor = "var(--sv-warning)";
    if (latestLoad > 5.0) strokeColor = "var(--sv-danger)";

    return `<svg class="cpu-sparkline" width="${width}" height="${height}" role="img" aria-label="CPU load trend, latest ${ns.escapeHtml(latestLoad.toFixed(2))}"><title>CPU load trend, latest ${ns.escapeHtml(latestLoad.toFixed(2))}</title><polyline fill="none" stroke="${strokeColor}" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" points="${points.trim()}"/></svg>`;
  };
  // ── Alert severity normalization ───────────────────────────────────
  // Shared by alerts.js (toast) and notification-center.js (bell list).
  ns.alertSeverity = function (severity) {
    if (severity === "danger" || severity === "warning" || severity === "success") {
      return severity;
    }
    return "info";
  };

  // ── Timestamp / theme helpers ──────────────────────────────────────
  // Shared by detail.js (server charts) and ping-detail.js (ping charts).
  ns.parseTimestampMs = function (ts) {
    if (!ts) return null;
    const text = String(ts).trim();
    const ms = Date.parse(text.includes("T") ? text : text.replace(" ", "T"));
    return Number.isFinite(ms) ? ms : null;
  };

  ns.getThemeColor = function (varName, fallback) {
    const value = getComputedStyle(document.documentElement).getPropertyValue(varName).trim();
    return value || fallback;
  };

  ns.prettyJson = function (raw) {
    try {
      return JSON.stringify(JSON.parse(raw), null, 2);
    } catch (e) {
      return raw;
    }
  };

  // ── Clickable table rows ───────────────────────────────────────────
  // Shared by dashboard.js (server table) and disk_health.php (disk table).
  // Rows carry the target in data-detail-url. Interactive descendants
  // (links, buttons, inputs) are never hijacked.
  ns.wireRowNavigation = function (container) {
    const root = typeof container === "string" ? document.querySelector(container) : container;
    if (!root) return;
    const goToRowDetail = (row) => {
      const detailUrl = row.getAttribute("data-detail-url");
      if (detailUrl) window.location.assign(detailUrl);
    };
    root.addEventListener("click", (event) => {
      if (event.target.closest("a,button,input,select,textarea,label")) return;
      const row = event.target.closest("tr[data-detail-url]");
      if (row) goToRowDetail(row);
    });
    root.addEventListener("keydown", (event) => {
      if (event.key !== "Enter" && event.key !== " ") return;
      const row = event.target.closest("tr[data-detail-url]");
      if (!row) return;
      event.preventDefault();
      goToRowDetail(row);
    });
  };

  // ── IP reputation Check-Now ────────────────────────────────────────
  // Shared by the list page (ip-reputation.js) and the detail page
  // (ip-reputation-detail.js). Bound once here so the two page scripts
  // can never double-fire on the same button.
  ns.bindIpRepCheckNow = function () {
    document.addEventListener("click", (event) => {
      const btn = event.target.closest("[data-ip-rep-check-now]");
      const apiUrl = window.SERVMON_IP_REP_API || "";
      if (!btn || !apiUrl) return;
      const targetId = btn.getAttribute("data-ip-rep-check-now");
      if (!targetId || btn.disabled) return;
      const ip = btn.getAttribute("data-ip") || "";

      btn.disabled = true;
      const origHtml = btn.innerHTML;
      btn.innerHTML = `<i class="ti ti-loader-2 ti-spin me-1"></i>Checking${ip ? ` ${ns.escapeHtml(ip)}` : ""}…`;

      fetch(`${apiUrl}?action=check_now&id=${encodeURIComponent(targetId)}`, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({ _csrf_token: window.SERVMON_CSRF_TOKEN || "" }),
      })
        .then((r) => r.json())
        .then((data) => {
          if (data && data.success) {
            const status = (data.result && data.result.overall_status) || "";
            btn.innerHTML = `<i class="ti ti-check me-1"></i>${status ? ns.escapeHtml(String(status).toUpperCase()) : "Done"}`;
            setTimeout(() => window.location.reload(), 1200);
          } else {
            btn.innerHTML = '<i class="ti ti-alert-triangle me-1"></i>Failed';
            setTimeout(() => {
              btn.innerHTML = origHtml;
              btn.disabled = false;
            }, 3000);
          }
        })
        .catch(() => {
          btn.innerHTML = '<i class="ti ti-alert-triangle me-1"></i>Error';
          setTimeout(() => {
            btn.innerHTML = origHtml;
            btn.disabled = false;
          }, 3000);
        });
    });
  };

  // ── Auto behaviors (replaces per-page inline scripts) ─────────────
  // login.php password toggle, server_setup.php copy-to-clipboard, and the
  // alert/audit JSON detail modals. All guarded by element presence.
  ns.bindAutoBehaviors = function () {
    document.querySelectorAll("[data-password-toggle]").forEach((button) => {
      button.addEventListener("click", () => {
        const input = document.getElementById(button.getAttribute("data-password-toggle"));
        if (!input) return;
        const isPassword = input.type === "password";
        input.type = isPassword ? "text" : "password";
        button.setAttribute("aria-label", isPassword ? "Hide password" : "Show password");
        button.setAttribute("title", isPassword ? "Hide password" : "Show password");
        const icon = button.querySelector("i");
        if (icon) icon.className = isPassword ? "ti ti-eye-off" : "ti ti-eye";
      });
    });

    document.addEventListener("click", async (event) => {
      const btn = event.target.closest("[data-copy-text]");
      if (!btn) return;
      const value = btn.getAttribute("data-copy-text") || "";
      const prev = btn.textContent;
      try {
        await navigator.clipboard.writeText(value);
      } catch (e) {
        const textarea = document.createElement("textarea");
        textarea.value = value;
        textarea.style.position = "fixed";
        textarea.style.opacity = "0";
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand("copy");
        document.body.removeChild(textarea);
      }
      btn.textContent = "Copied";
      window.setTimeout(() => {
        btn.textContent = prev;
      }, 1000);
    });

    const alertModal = document.getElementById("alertDetailModal");
    if (alertModal) {
      alertModal.addEventListener("show.bs.modal", (event) => {
        const btn = event.relatedTarget;
        const title = btn.getAttribute("data-alert-title") || "Alert Details";
        const message = btn.getAttribute("data-alert-message") || "";
        const contextRaw = btn.getAttribute("data-alert-context") || "{}";
        document.getElementById("alertDetailTitle").textContent = title;
        document.getElementById("alertDetailMessage").textContent = message;
        document.getElementById("alertDetailContext").textContent = ns.prettyJson(contextRaw);
      });
    }

    const auditModal = document.getElementById("auditContextModal");
    if (auditModal) {
      auditModal.addEventListener("show.bs.modal", (event) => {
        const btn = event.relatedTarget;
        const contextRaw = btn.getAttribute("data-audit-context") || "{}";
        document.getElementById("auditContextContent").textContent = ns.prettyJson(contextRaw);
      });
    }
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => {
      ns.bindAutoBehaviors();
      ns.bindIpRepCheckNow();
    });
  } else {
    ns.bindAutoBehaviors();
    ns.bindIpRepCheckNow();
  }
})(window.ServMon);
