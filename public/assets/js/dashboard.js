/**
 * ServMon — Admin dashboard auto-refresh.
 *
 * Depends on: common.js (ServMon namespace)
 */

const SM = window.ServMon;
const CPU_HISTORY_KEY = "servmon:cpuHistory:admin";
const SORTABLE_KEYS = ["cpu", "ram", "disk", "queue"];

let sortState = null;
let cachedServers = [];
let liveStream = null;

SM.restoreCpuHistory(CPU_HISTORY_KEY);

function formatUptime(totalSeconds) {
  const seconds = Math.max(0, Math.trunc(Number(totalSeconds || 0)));
  if (seconds <= 0) return "0H";
  const days = Math.floor(seconds / 86400);
  if (days > 0) return `${days}D`;
  const hours = Math.floor(seconds / 3600);
  if (hours > 0) return `${hours}H`;
  return "<1H";
}

function formatServiceSummary(summary) {
  const up = Number(summary?.up ?? 0);
  const down = Number(summary?.down ?? 0);
  const unknown = Number(summary?.unknown ?? 0);
  return {
    up: Math.max(0, Math.trunc(up)),
    down: Math.max(0, Math.trunc(down)),
    unknown: Math.max(0, Math.trunc(unknown)),
    hasIssue:
      (Number.isFinite(down) && down > 0) ||
      (Number.isFinite(unknown) && unknown > 0),
  };
}

function normalizeServiceSummaryForServerStatus(summary, serverStatus) {
  if (serverStatus !== "down") return summary;
  const total = summary.up + summary.down + summary.unknown;
  if (total <= 0) return summary;
  return {
    up: 0,
    down: total,
    unknown: 0,
    hasIssue: true,
  };
}

function renderServiceSummary(summary) {
  if (summary.hasIssue) {
    const parts = [];
    if (summary.down > 0) {
      parts.push(
        `<span class="text-danger fw-semibold me-2"><i class="ti ti-arrow-down-circle me-1" aria-label="down"></i>${SM.escapeHtml(String(summary.down))}</span>`,
      );
    }
    if (summary.unknown > 0) {
      parts.push(
        `<span class="text-warning fw-semibold"><i class="ti ti-help-circle me-1" aria-label="unknown"></i>${SM.escapeHtml(String(summary.unknown))}</span>`,
      );
    }
    return parts.join("");
  }

  return `<span class="text-success fw-semibold"><i class="ti ti-arrow-up-circle me-1" aria-label="up"></i>${SM.escapeHtml(String(summary.up))}</span>`;
}

function updateAdminSummaryCards(servers) {
  const totalEl = document.querySelector("[data-admin-total]");
  const onlineEl = document.querySelector("[data-admin-online]");
  const downEl = document.querySelector("[data-admin-down]");
  const pendingEl = document.querySelector("[data-admin-pending]");
  if (!totalEl || !onlineEl || !downEl || !pendingEl) return;

  let online = 0;
  let down = 0;
  let pending = 0;
  servers.forEach((s) => {
    if (s.status === "online") online += 1;
    else if (s.status === "down") down += 1;
    else pending += 1;
  });

  totalEl.textContent = String(servers.length);
  onlineEl.textContent = String(online);
  downEl.textContent = String(down);
  pendingEl.textContent = String(pending);
}

function updateStaleHint(text, isError) {
  const staleEl = document.querySelector("[data-dashboard-stale]");
  if (!staleEl) return;
  const labelEl = staleEl.querySelector("[data-dashboard-live-label]");
  const timeEl = staleEl.querySelector("time");
  staleEl.classList.toggle("is-stale", Boolean(isError));
  staleEl.classList.remove("text-danger");
  if (labelEl) labelEl.textContent = isError ? "Offline" : "Live";
  if (timeEl) {
    timeEl.textContent = isError
      ? text.replace(/^Live\s*\|\s*/i, "")
      : text.replace(/^Live\s*\|\s*/i, "");
  } else {
    staleEl.textContent = text;
  }
}

function renderAdminRowCells(s) {
  const serviceSummary = normalizeServiceSummaryForServerStatus(
    formatServiceSummary(s.services_summary || {}),
    String(s.status || ""),
  );
  return `
    <td><span class="table-cell-truncate" title="${SM.escapeHtml(String(s.name ?? ""))}">${SM.escapeHtml(s.name)}</span></td>
    <td>${SM.escapeHtml(s.location ?? "-")}</td>
    <td class="font-mono">${SM.escapeHtml(formatUptime(s.uptime))}</td>
    <td>
      <div class="cpu-cell">
        <div class="cpu-value font-mono ${SM.cpuSeverityClass(s.cpu_load)}" title="${SM.cpuLoadSeverity(s.cpu_load) !== 'ok' ? 'CPU load melebihi ambang' : 'CPU load normal'}">${SM.escapeHtml(Number(s.cpu_load || 0).toFixed(2))}</div>
        ${SM.getCpuSparkline(s)}
      </div>
    </td>
    <td>${SM.renderUsageCell(s.ram_used, s.ram_total, "RAM")}</td>
    <td>${SM.renderUsageCell(s.hdd_used, s.hdd_total, "Disk")}</td>
    <td class="d-none d-xl-table-cell"><code>${SM.escapeHtml(s.panel_profile ?? "generic")}</code></td>
    <td>${renderServiceSummary(serviceSummary)}</td>
    <td>
      <div class="net-line"><i class="ti ti-arrow-down" aria-label="In"></i> <span class="font-mono">${SM.escapeHtml(SM.formatBps(s.network_in_bps))}</span></div>
      <div class="net-line text-secondary"><i class="ti ti-arrow-up" aria-label="Out"></i> <span class="font-mono">${SM.escapeHtml(SM.formatBps(s.network_out_bps))}</span></div>
    </td>
    <td class="font-mono">${SM.escapeHtml(SM.formatMailQueue(s.mail_mta, s.mail_queue_total))}</td>
    <td><span class="badge ${SM.statusClass(s.status)} text-uppercase">${SM.escapeHtml(s.status ?? "pending")}</span></td>
  `;
}

function buildDashboardEmptyRow() {
  const row = document.createElement("tr");
  const manageUrl = window.SERVMON_SERVERS_LIST || "/servers";
  row.innerHTML = `
    <td colspan="11" class="table-empty">
      <div class="table-empty-inner">
        <span>No server metrics available yet.</span>
        <a href="${SM.escapeHtml(manageUrl)}" class="btn btn-sm btn-outline-info mt-2">Manage Servers</a>
      </div>
    </td>`;
  return row;
}

function syncAdminTableRows(tableBody, servers) {
  if (!Array.isArray(servers) || servers.length === 0) {
    tableBody.replaceChildren(buildDashboardEmptyRow());
    applyDashboardStatusFilter();
    return;
  }

  const rowsByKey = new Map();
  tableBody.querySelectorAll("tr[data-server-id]").forEach((row) => {
    rowsByKey.set(row.getAttribute("data-server-id"), row);
  });

  const desiredKeys = [];
  servers.forEach((s) => {
    const key = String(s.id ?? s.name ?? "");
    desiredKeys.push(key);
    let row = rowsByKey.get(key);
    if (!row) {
      row = document.createElement("tr");
      row.setAttribute("data-server-id", key);
      rowsByKey.set(key, row);
    }
    const detailBase = window.SERVMON_ADMIN_DETAIL_BASE || "/servers/";
    row.setAttribute(
      "data-detail-url",
      `${detailBase}${encodeURIComponent(String(s.id ?? ""))}`,
    );
    row.classList.add("dashboard-row-link");
    row.dataset.serverStatus = String(s.status || "pending");
    row.dataset.serverSearch = `${s.name || ""} ${s.host || ""} ${s.location || ""} ${s.panel_profile || ""}`.toLowerCase();
    row.setAttribute("tabindex", "0");
    row.setAttribute("role", "link");
    row.setAttribute(
      "aria-label",
      `Open details for ${String(s.name ?? "server")}`,
    );
    const nextHtml = renderAdminRowCells(s);
    if (row.dataset.renderedHtml !== nextHtml) {
      if (!row.hasChildNodes()) {
        row.innerHTML = nextHtml;
      } else {
        const tempRow = document.createElement("tr");
        tempRow.innerHTML = nextHtml;
        const oldCells = Array.from(row.children);
        const newCells = Array.from(tempRow.children);
        for (let i = 0; i < newCells.length; i++) {
          if (oldCells[i] && oldCells[i].innerHTML !== newCells[i].innerHTML) {
            oldCells[i].innerHTML = newCells[i].innerHTML;
          }
        }
      }
      row.dataset.renderedHtml = nextHtml;
    }
  });

  const domKeys = Array.from(tableBody.querySelectorAll("tr[data-server-id]"))
    .map((row) => row.getAttribute("data-server-id"));
  const orderChanged =
    domKeys.length !== desiredKeys.length ||
    domKeys.some((key, i) => key !== desiredKeys[i]);

  if (orderChanged) {
    const fragment = document.createDocumentFragment();
    desiredKeys.forEach((key) => fragment.appendChild(rowsByKey.get(key)));
    tableBody.replaceChildren(fragment);
  }

  applyDashboardStatusFilter();
}

function applyDashboardStatusFilter() {
  const filter = document.querySelector("[data-dashboard-filter]");
  const searchInput = document.querySelector("[data-dashboard-search]");
  const tableBody = document.querySelector("[data-server-table]");
  const emptyFilterRow = document.querySelector("[data-dashboard-filter-empty]");
  if (!tableBody) return;

  const selectedStatus = String(filter?.value || "all");
  const searchQuery = String(searchInput?.value || "").trim().toLowerCase();

  // Update active state on summary cards
  document.querySelectorAll("[data-summary-filter]").forEach((card) => {
    const cardStatus = card.getAttribute("data-summary-filter") || "all";
    const isActive = cardStatus === selectedStatus;
    card.classList.toggle("is-active-filter", isActive);
    card.setAttribute("aria-pressed", String(isActive));
  });

  const clearBtn = document.querySelector("[data-dashboard-search-clear]");
  if (clearBtn) {
    clearBtn.classList.toggle("is-visible", searchQuery.length > 0);
  }

  let visibleCount = 0;
  const rows = tableBody.querySelectorAll("tr[data-server-id]");
  rows.forEach((row) => {
    const matchesStatus =
      selectedStatus === "all" || row.dataset.serverStatus === selectedStatus;
    const searchData =
      row.dataset.serverSearch || row.textContent.toLowerCase();
    const matchesSearch =
      searchQuery === "" || searchData.includes(searchQuery);

    const isVisible = matchesStatus && matchesSearch;
    row.hidden = !isVisible;
    if (isVisible) visibleCount += 1;
  });

  if (emptyFilterRow) {
    emptyFilterRow.hidden = visibleCount > 0 || rows.length === 0;
  }
}

function getSortValue(s, key) {
  switch (key) {
    case "cpu":
      return Number(s.cpu_load || 0);
    case "ram":
      return Number(s.ram_used_pct || 0);
    case "disk":
      return Number(s.hdd_used_pct || 0);
    case "queue":
      return Number(s.mail_queue_total || 0);
    default:
      return 0;
  }
}

function applyDashboardSort(servers) {
  if (!Array.isArray(servers)) return;
  if (!sortState) {
    servers.sort((a, b) => {
      const sa = SM.cpuLoadSeverity(a.cpu_load);
      const sb = SM.cpuLoadSeverity(b.cpu_load);
      const rankA = sa === "critical" ? 2 : sa === "warn" ? 1 : 0;
      const rankB = sb === "critical" ? 2 : sb === "warn" ? 1 : 0;
      if (rankA !== rankB) return rankB - rankA;
      return String(a.name ?? "").localeCompare(String(b.name ?? ""), undefined, {
        sensitivity: "base",
      });
    });
    return;
  }
  const { key, dir } = sortState;
  const factor = dir === "asc" ? 1 : -1;
  servers.sort((a, b) => {
    const va = getSortValue(a, key);
    const vb = getSortValue(b, key);
    if (va !== vb) return (va - vb) * factor;
    return String(a.name ?? "").localeCompare(String(b.name ?? ""), undefined, {
      sensitivity: "base",
    });
  });
}

function renderSortHeaders() {
  document.querySelectorAll("th[data-sort-key]").forEach((th) => {
    const key = th.getAttribute("data-sort-key");
    const active = sortState && sortState.key === key;
    const dir = active ? sortState.dir : null;
    th.setAttribute(
      "aria-sort",
      active ? (dir === "asc" ? "ascending" : "descending") : "none",
    );
    th.classList.toggle("is-sorted", Boolean(active));
    const icon = th.querySelector(".sort-icon");
    if (icon) {
      icon.classList.remove("ti-arrows-sort", "ti-arrow-up", "ti-arrow-down");
      icon.classList.add(
        dir === "asc"
          ? "ti-arrow-up"
          : dir === "desc"
            ? "ti-arrow-down"
            : "ti-arrows-sort",
      );
    }
  });
}

function syncSortFromUrl() {
  const params = new URLSearchParams(window.location.search);
  const key = String(params.get("sort") || "");
  const dir = String(params.get("dir") || "");
  if (SORTABLE_KEYS.includes(key) && (dir === "asc" || dir === "desc")) {
    sortState = { key, dir };
  }
}

function updateSortUrl() {
  const params = new URLSearchParams(window.location.search);
  if (sortState) {
    params.set("sort", sortState.key);
    params.set("dir", sortState.dir);
  } else {
    params.delete("sort");
    params.delete("dir");
  }
  const qs = params.toString();
  const next = `${window.location.pathname}${qs ? `?${qs}` : ""}${window.location.hash}`;
  window.history.replaceState(null, "", next);
}

function cycleSort(key) {
  if (!sortState || sortState.key !== key) {
    sortState = { key, dir: "desc" };
  } else if (sortState.dir === "desc") {
    sortState = { key, dir: "asc" };
  } else {
    sortState = null;
  }
  renderSortHeaders();
  const tableBody = document.querySelector("[data-server-table]");
  if (tableBody) {
    applyDashboardSort(cachedServers);
    syncAdminTableRows(tableBody, cachedServers);
  }
  updateSortUrl();
}

function wireDashboardRowNavigation() {
  const tableBody = document.querySelector("[data-server-table]");
  if (!tableBody) return;

  tableBody.addEventListener("click", (event) => {
    const row = event.target.closest("tr[data-detail-url]");
    if (!row) return;
    const detailUrl = row.getAttribute("data-detail-url");
    if (!detailUrl) return;
    window.location.href = detailUrl;
  });

  tableBody.addEventListener("keydown", (event) => {
    if (event.key !== "Enter" && event.key !== " ") return;
    const row = event.target.closest("tr[data-detail-url]");
    if (!row) return;
    const detailUrl = row.getAttribute("data-detail-url");
    if (!detailUrl) return;
    event.preventDefault();
    window.location.href = detailUrl;
  });
}

async function refreshServerTable() {
  const tableBody = document.querySelector("[data-server-table]");
  if (!tableBody) return;
  const endpoint =
    window.SERVMON_API_STATUS || "/api/status?include_inactive=1";

  let skeletonTimer = null;
  const tableShell = tableBody.closest(".table-shell");
  const hasRows = tableBody.querySelectorAll("tr[data-server-id]").length > 0;
  if (tableShell && !hasRows) {
    skeletonTimer = setTimeout(
      () => tableShell.classList.add("is-loading"),
      300,
    );
  }
  const endSkeleton = () => {
    if (skeletonTimer !== null) {
      clearTimeout(skeletonTimer);
      skeletonTimer = null;
    }
    if (tableShell) tableShell.classList.remove("is-loading");
  };

  try {
    const response = await fetch(endpoint, {
      headers: { Accept: "application/json" },
      cache: "no-store",
    });
    if (!response.ok) {
      updateStaleHint("Live update unavailable", true);
      return false;
    }
    const servers = await response.json();
    if (!Array.isArray(servers)) {
      updateStaleHint("Live data format invalid", true);
      return false;
    }
    cachedServers = servers;
    applyDashboardSort(cachedServers);

    syncAdminTableRows(tableBody, cachedServers);
    SM.persistCpuHistory(CPU_HISTORY_KEY);

    updateAdminSummaryCards(servers);
    updateStaleHint(`Live | ${new Date().toLocaleTimeString()}`, false);
  } catch (err) {
    console.error(err);
    updateStaleHint("Live update error", true);
    return false;
  } finally {
    endSkeleton();
  }
}

function applyLiveMetric(metric) {
  const sid = String(metric.server_id ?? "");
  if (!sid) return;
  const idx = cachedServers.findIndex((s) => String(s.id) === sid);
  if (idx === -1) return;
  const updated = { ...cachedServers[idx] };
  if (metric.cpu_load != null) updated.cpu_load = Number(metric.cpu_load);
  if (metric.ram_used != null) updated.ram_used = Number(metric.ram_used);
  if (metric.ram_total != null) updated.ram_total = Number(metric.ram_total);
  if (metric.hdd_used != null) updated.hdd_used = Number(metric.hdd_used);
  if (metric.hdd_total != null) updated.hdd_total = Number(metric.hdd_total);
  if (metric.network_in_bps != null)
    updated.network_in_bps = Number(metric.network_in_bps);
  if (metric.network_out_bps != null)
    updated.network_out_bps = Number(metric.network_out_bps);
  if (metric.mail_queue_total != null)
    updated.mail_queue_total = Number(metric.mail_queue_total);
  if (metric.uptime != null) updated.uptime = Number(metric.uptime);
  if (metric.recorded_at) updated.last_seen = metric.recorded_at;
  updated.status = "online";
  cachedServers[idx] = updated;
}

const LIVE_STREAM_RETRY_MS = [2000, 4000, 8000, 16000, 30000, 60000];

let stopPoller = null;
let liveStreamSinceId = 0;

function ensurePollerActive() {
  if (!stopPoller) {
    stopPoller = SM.startPoller(refreshServerTable, { baseMs: 5000, maxMs: 10000 });
  }
}

function pausePoller() {
  if (stopPoller) {
    stopPoller();
    stopPoller = null;
  }
}

function startLiveStream() {
  if (!window.EventSource || !document.querySelector("[data-server-table]")) {
    ensurePollerActive();
    return;
  }
  const url = new URL(
    window.SERVMON_API_STREAM || "/api/stream",
    window.location.origin,
  );
  url.searchParams.set("limit", "50");
  if (liveStreamSinceId > 0) {
    url.searchParams.set("since_id", String(liveStreamSinceId));
  }
  const es = new EventSource(url.toString());
  liveStream = es;
  let retries = 0;
  es.addEventListener("metric", (ev) => {
    try {
      const payload = JSON.parse(ev.data);
      const pid = Number(payload.id) || Number(ev.lastEventId) || 0;
      if (pid > liveStreamSinceId) liveStreamSinceId = pid;
      applyLiveMetric(payload);
      const tableBody = document.querySelector("[data-server-table]");
      if (!tableBody) return;
      applyDashboardSort(cachedServers);
      syncAdminTableRows(tableBody, cachedServers);
      SM.persistCpuHistory(CPU_HISTORY_KEY);
      updateAdminSummaryCards(cachedServers);
      updateStaleHint(`Live | ${new Date().toLocaleTimeString()}`, false);
    } catch (err) {
      console.error(err);
    }
  });
  es.addEventListener("open", () => {
    retries = 0;
    pausePoller();
    updateStaleHint(`Live | ${new Date().toLocaleTimeString()}`, false);
  });
  es.onerror = () => {
    if (liveStream !== es) return;
    es.close();
    liveStream = null;
    ensurePollerActive();
    const delay = LIVE_STREAM_RETRY_MS[Math.min(retries++, LIVE_STREAM_RETRY_MS.length - 1)];
    updateStaleHint(`retry in ${Math.round(delay / 1000)}s`, true);
    const retryLiveStream = () => {
      if (liveStream) return;
      if (document.visibilityState !== "visible") {
        setTimeout(retryLiveStream, delay);
        return;
      }
      startLiveStream();
    };
    setTimeout(retryLiveStream, delay);
  };
}

function syncDashboardFilterFromUrl() {
  const dashboardFilter = document.querySelector("[data-dashboard-filter]");
  if (!dashboardFilter) return;
  const params = new URLSearchParams(window.location.search);
  const status = String(params.get("status") || "");
  const valid = ["all", "online", "down", "pending"].includes(status);
  if (valid) dashboardFilter.value = status;
}

function updateDashboardFilterUrl() {
  const dashboardFilter = document.querySelector("[data-dashboard-filter]");
  if (!dashboardFilter) return;
  const params = new URLSearchParams(window.location.search);
  const status = dashboardFilter.value;
  if (!status || status === "all") {
    params.delete("status");
  } else {
    params.set("status", status);
  }
  const qs = params.toString();
  const next = `${window.location.pathname}${qs ? `?${qs}` : ""}${window.location.hash}`;
  window.history.replaceState(null, "", next);
}

syncSortFromUrl();
syncDashboardFilterFromUrl();
renderSortHeaders();
applyDashboardStatusFilter();
refreshServerTable();
wireDashboardRowNavigation();
document.addEventListener("click", (event) => {
  const trigger = event.target.closest("[data-sort-trigger]");
  if (!trigger) return;
  const key = trigger.getAttribute("data-sort-trigger");
  if (!key) return;
  cycleSort(key);
});
const dashboardFilter = document.querySelector("[data-dashboard-filter]");
if (dashboardFilter) {
  dashboardFilter.addEventListener("change", () => {
    applyDashboardStatusFilter();
    updateDashboardFilterUrl();
  });
}

// Wire interactive KPI Summary Cards
document.querySelectorAll("[data-summary-filter]").forEach((card) => {
  const triggerFilter = () => {
    const targetStatus = card.getAttribute("data-summary-filter") || "all";
    if (dashboardFilter) {
      // Toggle back to "all" if clicking the currently active non-all filter
      if (dashboardFilter.value === targetStatus && targetStatus !== "all") {
        dashboardFilter.value = "all";
      } else {
        dashboardFilter.value = targetStatus;
      }
      applyDashboardStatusFilter();
      updateDashboardFilterUrl();
    }
  };

  card.addEventListener("click", triggerFilter);
  card.addEventListener("keydown", (e) => {
    if (e.key === "Enter" || e.key === " ") {
      e.preventDefault();
      triggerFilter();
    }
  });
});

// Wire Instant Search & Clear Button
const dashboardSearch = document.querySelector("[data-dashboard-search]");
const dashboardSearchClear = document.querySelector("[data-dashboard-search-clear]");
if (dashboardSearch) {
  dashboardSearch.addEventListener("input", () => {
    applyDashboardStatusFilter();
  });
}
if (dashboardSearchClear) {
  dashboardSearchClear.addEventListener("click", () => {
    if (dashboardSearch) {
      dashboardSearch.value = "";
      dashboardSearch.focus();
    }
    applyDashboardStatusFilter();
  });
}

// Wire Reset Filter Button on empty state
document.addEventListener("click", (event) => {
  const resetBtn = event.target.closest("[data-dashboard-filter-reset]");
  if (!resetBtn) return;
  if (dashboardFilter) dashboardFilter.value = "all";
  if (dashboardSearch) dashboardSearch.value = "";
  applyDashboardStatusFilter();
  updateDashboardFilterUrl();
});

ensurePollerActive();
startLiveStream();
