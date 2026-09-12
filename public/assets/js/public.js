/**
 * ServMon — Public status page auto-refresh.
 *
 * Depends on: common.js (ServMon namespace)
 */

// var (not const): alerts.js aliases the same name on shared pages.
var SM = window.SM ?? window.ServMon;
const CPU_HISTORY_KEY = "servmon:cpuHistory:public";

SM.restoreCpuHistory(CPU_HISTORY_KEY);

const SORTABLE_KEYS = ["cpu", "ram", "disk", "queue"];
let sortState = null;
let cachedServers = [];

function getSortValue(s, key) {
  let value = 0;
  if (key === "cpu") value = Number(s.cpu_load) || 0;
  else if (key === "ram") value = Number(s.ram_used_pct) || 0;
  else if (key === "disk") value = Number(s.hdd_used_pct) || 0;
  else if (key === "queue") value = Number(s.mail_queue_total) || 0;
  return { value, name: String(s.name ?? "") };
}

function applyPublicSort(servers) {
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
    const byValue = (va.value - vb.value) * factor;
    if (byValue !== 0) return byValue;
    return va.name.localeCompare(vb.name, undefined, { sensitivity: "base" });
  });
}

function renderSortHeaders() {
  document.querySelectorAll("th[data-sort-key]").forEach((th) => {
    const key = th.getAttribute("data-sort-key");
    const isSorted = sortState && sortState.key === key;
    const dir = isSorted ? sortState.dir : null;
    th.setAttribute("aria-sort", dir === "asc" ? "ascending" : dir === "desc" ? "descending" : "none");
    th.classList.toggle("is-sorted", isSorted);
    const icon = th.querySelector(".sort-icon");
    if (icon) {
      icon.className = `ti sort-icon ${dir === "asc" ? "ti-arrow-up" : dir === "desc" ? "ti-arrow-down" : "ti-arrows-sort"}`;
    }
  });
}

function syncSortFromUrl() {
  const params = new URLSearchParams(window.location.search);
  const key = params.get("sort");
  const dir = params.get("dir");
  if (key && SORTABLE_KEYS.includes(key) && (dir === "asc" || dir === "desc")) {
    sortState = { key, dir };
  }
}

function updateSortUrl() {
  const url = new URL(window.location.href);
  if (sortState) {
    url.searchParams.set("sort", sortState.key);
    url.searchParams.set("dir", sortState.dir);
  } else {
    url.searchParams.delete("sort");
    url.searchParams.delete("dir");
  }
  window.history.replaceState(null, "", url);
}

function parseSortValue(value) {
  if (!value || value === "default") return null;
  const [key, dir] = String(value).split("-");
  if (SORTABLE_KEYS.includes(key) && (dir === "asc" || dir === "desc")) {
    return { key, dir };
  }
  return null;
}

function syncSortSelect() {
  const select = document.querySelector("[data-public-sort-select]");
  if (!select) return;
  select.value = sortState ? `${sortState.key}-${sortState.dir}` : "default";
}

function initSortSelect() {
  const select = document.querySelector("[data-public-sort-select]");
  if (!select) return;
  select.addEventListener("change", () => {
    const parsed = parseSortValue(select.value);
    setSort(parsed ? parsed.key : null, parsed ? parsed.dir : null);
  });
}

function setSort(key, dir) {
  sortState = key ? { key, dir } : null;
  renderSortHeaders();
  applyPublicSort(cachedServers);
  const tableBody = document.querySelector("[data-public-server-table]");
  if (tableBody) syncPublicTableRows(tableBody, cachedServers);
  updateSortUrl();
  syncSortSelect();
}

function cycleSort(key) {
  if (!sortState || sortState.key !== key) {
    setSort(key, "desc");
  } else if (sortState.dir === "desc") {
    setSort(key, "asc");
  } else {
    setSort(null, null);
  }
}

function formatUptime(totalSeconds) {
  const seconds = Math.max(0, Math.trunc(Number(totalSeconds || 0)));
  if (seconds <= 0) return "0D";
  const days = Math.floor(seconds / 86400);
  if (days > 0) return `${days}D`;
  const hours = Math.floor(seconds / 3600);
  if (hours > 0) return `${hours}h`;
  const minutes = Math.max(1, Math.floor(seconds / 60));
  return `${minutes}m`;
}

function updateSummaryCards(servers) {
  const totalEl = document.querySelector("[data-public-total]");
  const onlineEl = document.querySelector("[data-public-online]");
  const downEl = document.querySelector("[data-public-down]");
  const pendingEl = document.querySelector("[data-public-pending]");
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

function renderPublicRowCells(s) {
  return `
    <td data-label="Name"><span class="table-cell-truncate" title="${SM.escapeHtml(String(s.name ?? ""))}">${SM.escapeHtml(s.name)}</span></td>
    <td data-label="Location"><span class="public-location">${SM.escapeHtml(s.location ?? "-")}</span></td>
    <td data-label="Status"><span class="badge ${SM.statusClass(s.status)} text-uppercase">${SM.escapeHtml(s.status ?? "pending")}</span></td>
    <td class="font-mono" data-label="Uptime">${SM.escapeHtml(formatUptime(s.uptime))}</td>
    <td data-label="RAM">${SM.renderUsageCell(s.ram_used, s.ram_total, "RAM")}</td>
    <td data-label="Disk">${SM.renderUsageCell(s.hdd_used, s.hdd_total, "Disk")}</td>
    <td data-label="CPU">
      <div class="cpu-cell">
        <div class="cpu-value font-mono ${SM.cpuSeverityClass(s.cpu_load)}" title="${SM.cpuLoadSeverity(s.cpu_load) !== 'ok' ? 'CPU load exceeds threshold' : 'CPU load normal'}">${SM.escapeHtml(Number(s.cpu_load || 0).toFixed(2))}</div>
        ${SM.getCpuSparkline(s)}
      </div>
    </td>
    <td data-label="Panel">${SM.panelBrandChip(s.panel_profile, "public-panel-chip")}</td>
    <td data-label="Services"><div class="service-summary">${SM.renderServiceSummary(s)}</div></td>
    <td data-label="NET">
      <div class="net-line"><i class="ti ti-arrow-down" aria-label="In"></i><span class="font-mono">${SM.escapeHtml(SM.formatBps(s.network_in_bps))}</span></div>
      <div class="net-line text-secondary"><i class="ti ti-arrow-up" aria-label="Out"></i><span class="font-mono">${SM.escapeHtml(SM.formatBps(s.network_out_bps))}</span></div>
    </td>
    <td class="font-mono" data-label="Queue">${SM.escapeHtml(SM.formatMailQueue(s.mail_mta, s.mail_queue_total))}</td>
  `;
}

function syncPublicTableRows(tableBody, servers) {
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
    const nextHtml = renderPublicRowCells(s);
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
}

async function refreshPublicStatusTable() {
  const tableBody = document.querySelector("[data-public-server-table]");
  if (!tableBody) return;

  const endpoint = window.SERVMON_API_STATUS || "/api/status";
  try {
    const response = await fetch(endpoint, {
      headers: { Accept: "application/json" },
      cache: "no-store",
    });
    if (!response.ok) return false;

    const servers = await response.json();
    if (!Array.isArray(servers)) return false;
    cachedServers = servers;
    applyPublicSort(cachedServers);

    syncPublicTableRows(tableBody, cachedServers);
    SM.persistCpuHistory(CPU_HISTORY_KEY);

    updateSummaryCards(cachedServers);
  } catch (err) {
    console.error(err);
    return false;
  }
}

syncSortFromUrl();
renderSortHeaders();
syncSortSelect();
initSortSelect();
refreshPublicStatusTable();
document.addEventListener("click", (event) => {
  const button = event.target.closest("[data-sort-trigger]");
  if (!button) return;
  cycleSort(button.getAttribute("data-sort-trigger"));
});
SM.startPoller(refreshPublicStatusTable, { baseMs: 15000, maxMs: 120000 });
