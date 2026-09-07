const servmonCharts = {
  ram: null,
  disk: null,
  cpu: null,
  network: null,
};

let servmonHistoryRequest = null;
let servmonHistorySeq = 0;
let servmonLastRows = [];
let servmonChartEngineRetryTimer = null;
let servmonResizeBound = false;
let servmonChartsPaused = false;

function updateDetailLiveState(isLive) {
  const state = document.querySelector("[data-detail-live-state]");
  if (!state) return;
  const dot = state.querySelector(".detail-live-dot");
  state.classList.toggle("is-stale", !isLive);
  if (dot) dot.classList.toggle("is-stale", !isLive);
  state.lastChild.textContent = isLive
    ? `Live ${new Date().toLocaleTimeString()}`
    : "Update unavailable";
}

function hasEcharts() {
  return typeof window !== "undefined" && typeof window.echarts !== "undefined";
}

function areChartsPaused() {
  return servmonChartsPaused;
}

function setChartPaused(paused) {
  servmonChartsPaused = paused;
  document.querySelectorAll("[data-chart-pause]").forEach((button) => {
    button.setAttribute("aria-pressed", String(paused));
    button.setAttribute("title", paused ? "Resume updates" : "Pause updates");
    button.setAttribute("aria-label", paused ? "Resume live chart updates" : "Pause live chart updates");
    const icon = button.querySelector("i");
    if (icon) icon.className = paused ? "ti ti-player-play" : "ti ti-player-pause";
  });
}

function showChartSkeletons() {
  document.querySelectorAll("[data-chart-skeleton]").forEach((el) => el.classList.add("is-active"));
}

function hideChartSkeletons() {
  document.querySelectorAll("[data-chart-skeleton]").forEach((el) => el.classList.remove("is-active"));
}

function updateChartSummaries(rows) {
  if (!Array.isArray(rows) || rows.length === 0) return;
  const last = rows[rows.length - 1];
  const summaries = {
    ram: `RAM used: ${formatBytes(last.ram_used)}`,
    disk: `Disk used: ${formatBytes(last.hdd_used)}`,
    cpu: `CPU load: ${toNumber(last.cpu_load, 0).toFixed(2)}`,
    network: `Network in ${formatBps(last.network_in_bps)}, out ${formatBps(last.network_out_bps)}`,
  };
  Object.entries(summaries).forEach(([key, text]) => {
    const el = document.querySelector(`[data-chart-summary="${key}"]`);
    if (el) el.textContent = text;
  });
}

function toNumber(value, fallback = 0) {
  const n = Number(value);
  return Number.isFinite(n) ? n : fallback;
}

function parseTimestampMs(ts) {
  if (!ts) return null;
  const text = String(ts).trim();
  const ms = Date.parse(text.includes("T") ? text : text.replace(" ", "T"));
  return Number.isFinite(ms) ? ms : null;
}

function formatBytes(bytes, decimals = 2) {
  const n = toNumber(bytes, 0);
  if (n <= 0) return "0 B";
  if (n < 1) return `${n.toFixed(decimals)} B`;
  const units = ["B", "KB", "MB", "GB", "TB", "PB"];
  const i = Math.min(units.length - 1, Math.floor(Math.log(n) / Math.log(1024)));
  const value = n / Math.pow(1024, i);
  return `${value.toFixed(i === 0 ? 0 : decimals)} ${units[i]}`;
}

function formatBps(bps, decimals = 2) {
  const n = toNumber(bps, 0);
  if (n <= 0) return "0 bps";
  if (n < 1) return `${n.toFixed(decimals)} bps`;
  const units = ["bps", "Kbps", "Mbps", "Gbps", "Tbps"];
  const i = Math.min(units.length - 1, Math.floor(Math.log(n) / Math.log(1000)));
  const value = n / Math.pow(1000, i);
  return `${value.toFixed(i === 0 ? 0 : decimals)} ${units[i]}`;
}

function formatTimeTick(ts) {
  const ms = toNumber(ts, NaN);
  if (!Number.isFinite(ms)) return "";
  const d = new Date(ms);
  const hh = String(d.getHours()).padStart(2, "0");
  const mm = String(d.getMinutes()).padStart(2, "0");
  // Show the date on day change so ambiguous labels like "4" never appear.
  if (d.getHours() === 0 && d.getMinutes() === 0) {
    const dd = String(d.getDate()).padStart(2, "0");
    const mon = String(d.getMonth() + 1).padStart(2, "0");
    return `${dd}/${mon} ${hh}:${mm}`;
  }
  return `${hh}:${mm}`;
}

function getThemeColor(varName, fallback) {
  const value = getComputedStyle(document.documentElement).getPropertyValue(varName).trim();
  return value || fallback;
}

function getChartPalette() {
  const theme = document.documentElement.getAttribute("data-bs-theme") || "dark";
  const isLight = theme === "light";
  return {
    text: getThemeColor("--sv-text", isLight ? "#0f172a" : "#e5e7eb"),
    textMuted: getThemeColor("--sv-muted", isLight ? "#475569" : "#94a3b8"),
    grid: isLight ? "rgba(15,23,42,0.08)" : "rgba(148,163,184,0.14)",
    axis: isLight ? "rgba(15,23,42,0.18)" : "rgba(148,163,184,0.22)",
    tooltipBg: getThemeColor("--sv-surface-2", isLight ? "#f1f5f9" : "#1f2937"),
    tooltipBorder: getThemeColor("--sv-border", isLight ? "#cbd5e1" : "#334155"),
    tooltipText: getThemeColor("--sv-text", isLight ? "#0f172a" : "#e5e7eb"),
    series1: getThemeColor("--sv-chart-1", "#38bdf8"),
    series2: getThemeColor("--sv-chart-2", "#f59e0b"),
    series3: getThemeColor("--sv-chart-3", "#ef4444"),
    series4: getThemeColor("--sv-chart-4", "#22c55e"),
    series5: getThemeColor("--sv-chart-5", "#a78bfa"),
  };
}

function downsample(rows, maxPoints = 720) {
  if (!Array.isArray(rows) || rows.length <= maxPoints) return rows;
  const step = Math.ceil(rows.length / maxPoints);
  return rows.filter((_, i) => i % step === 0 || i === rows.length - 1);
}

function normalizeRows(payload) {
  if (!Array.isArray(payload)) return [];
  const rows = payload.map((row) => {
    const recorded_at = String(row.recorded_at || "");
    return {
      recorded_at,
      ts: parseTimestampMs(recorded_at),
      ram_used: toNumber(row.ram_used, 0),
      hdd_used: toNumber(row.hdd_used, 0),
      cpu_load: toNumber(row.cpu_load, 0),
      network_in_bps: toNumber(row.network_in_bps, 0),
      network_out_bps: toNumber(row.network_out_bps, 0),
    };
  });
  rows.sort((a, b) => {
    if (a.ts === null && b.ts === null) return 0;
    if (a.ts === null) return -1;
    if (b.ts === null) return 1;
    return a.ts - b.ts;
  });
  return downsample(rows, 720);
}

function formatServerDetailUptime(totalSeconds) {
  const seconds = Math.max(0, Math.trunc(Number(totalSeconds || 0)));
  if (seconds <= 0) return "0H";
  const days = Math.floor(seconds / 86400);
  if (days > 0) return `${days}D`;
  const hours = Math.floor(seconds / 3600);
  if (hours > 0) return `${hours}H`;
  return "<1H";
}

function updateServerDetailRing(selector, valueSelector, used, total, label) {
  const ring = document.querySelector(selector);
  const valueEl = document.querySelector(valueSelector);
  const metaEl = document.querySelector(label === "RAM" ? "[data-server-ram-meta]" : "[data-server-disk-meta]");
  const totalValue = Math.max(0, Number(total || 0));
  const usedValue = Math.max(0, Number(used || 0));
  const pct = totalValue > 0 ? Math.max(0, Math.min(100, (usedValue / totalValue) * 100)) : 0;
  if (ring) {
    ring.style.setProperty("--sv-pct", pct.toFixed(1));
    ring.classList.remove("usage-ring-ok", "usage-ring-warning", "usage-ring-critical");
    ring.classList.add(pct >= 80 ? "usage-ring-critical" : pct > 60 ? "usage-ring-warning" : "usage-ring-ok");
    ring.setAttribute("aria-label", `${label} usage ${pct.toFixed(1)} percent`);
  }
  if (valueEl) valueEl.textContent = `${pct.toFixed(1)}%`;
  if (metaEl) metaEl.textContent = `${formatBytes(usedValue)} / ${formatBytes(totalValue)}`;
}

function updateServerDetailServices(services) {
  const tbody = document.querySelector("[data-server-services]");
  if (!tbody || !Array.isArray(services)) return;
  if (services.length === 0) {
    tbody.innerHTML = '<tr><td colspan="5" class="table-empty">No service data yet.</td></tr>';
    return;
  }
  const escape = window.ServMon?.escapeHtml || ((value) => String(value ?? ""));
  tbody.innerHTML = services.map((service) => {
    const status = String(service.status || "unknown").toLowerCase();
    const badge = status === "up" ? "badge-online" : status === "down" ? "badge-down" : "badge-pending";
    return `<tr>
      <td>${escape(service.group || "-")}</td>
      <td>${escape(service.service_key || "-")}</td>
      <td><code>${escape(service.unit_name || "-")}</code></td>
      <td><span class="badge ${badge} text-uppercase">${escape(status)}</span></td>
      <td>${escape(service.updated_at || "-")}</td>
    </tr>`;
  }).join("");
}

async function refreshServerDetailStatus() {
  const endpoint = window.SERVMON_SERVER_STATUS_ENDPOINT;
  if (!endpoint) {
    updateDetailLiveState(false);
    return false;
  }
  try {
    const response = await fetch(`${endpoint}&_t=${Date.now()}`, {
      headers: { Accept: "application/json" },
      cache: "no-store",
    });
    if (!response.ok) {
      updateDetailLiveState(false);
      return false;
    }
    const data = await response.json();
    if (!data || typeof data !== "object") return false;

    const setText = (selector, value) => {
      const element = document.querySelector(selector);
      if (element) element.textContent = String(value ?? "-");
    };
    setText("[data-server-uptime]", formatServerDetailUptime(data.uptime));
    setText("[data-server-cpu]", toNumber(data.cpu_load, 0).toFixed(2));
    setText("[data-server-mail-queue]", `${Math.max(0, Number(data.mail_queue_total || 0))} emails`);
    setText("[data-server-mail-meta]", `${data.mail_mta || "none"} | Live`);
    setText("[data-server-last-seen]", data.last_seen || "-");
    setText("[data-server-state]", String(data.status || "unknown").toUpperCase());
    updateServerDetailRing("[data-server-ram-ring]", "[data-server-ram-value]", data.ram_used, data.ram_total, "RAM");
    updateServerDetailRing("[data-server-disk-ring]", "[data-server-disk-value]", data.hdd_used, data.hdd_total, "Disk");
    updateServerDetailServices(data.services);
    updateDetailLiveState(true);
    return true;
  } catch (error) {
    console.error(error);
    updateDetailLiveState(false);
    return false;
  }
}

function updateCpuSummary(rows) {
  const highEl = document.getElementById("cpuLoadHigh");
  const lowEl = document.getElementById("cpuLoadLow");
  const dailyAvgEl = document.getElementById("cpuLoadDailyAvg");
  if (!highEl || !lowEl || !dailyAvgEl) return;

  if (!Array.isArray(rows) || rows.length === 0) {
    highEl.textContent = "0.00";
    lowEl.textContent = "0.00";
    dailyAvgEl.textContent = "0.00";
    return;
  }

  const latestRow = rows[rows.length - 1];
  const latestDateKey =
    latestRow && latestRow.ts !== null
      ? new Date(latestRow.ts).toLocaleDateString("sv-SE")
      : String(latestRow?.recorded_at || "").slice(0, 10);

  const dayRows = rows.filter((row) => {
    if (row.ts !== null) {
      return new Date(row.ts).toLocaleDateString("sv-SE") === latestDateKey;
    }
    return String(row.recorded_at || "").slice(0, 10) === latestDateKey;
  });

  const sampleRows = dayRows.length > 0 ? dayRows : rows;
  const cpuValues = sampleRows.map((r) => toNumber(r.cpu_load, 0));
  const high = cpuValues.reduce((max, v) => Math.max(max, v), 0);
  const low = cpuValues.reduce(
    (min, v) => (Number.isFinite(min) ? Math.min(min, v) : v),
    Number.POSITIVE_INFINITY,
  );
  const sum = cpuValues.reduce((acc, v) => acc + v, 0);
  const dailyAverage = cpuValues.length > 0 ? sum / cpuValues.length : 0;

  highEl.textContent = high.toFixed(2);
  lowEl.textContent = (Number.isFinite(low) ? low : 0).toFixed(2);
  dailyAvgEl.textContent = dailyAverage.toFixed(2);
}

function toSeriesData(rows, key) {
  return rows
    .filter((row) => row.ts !== null)
    .map((row) => [row.ts, toNumber(row[key], 0)]);
}

function getOrCreateChart(chartKey, containerId) {
  const container = document.getElementById(containerId);
  if (!container || !hasEcharts()) return null;

  if (servmonCharts[chartKey] && !servmonCharts[chartKey].isDisposed()) {
    return servmonCharts[chartKey];
  }

  servmonCharts[chartKey] = window.echarts.init(container);
  servmonCharts[chartKey].group = "servmon-metric-charts";
  window.echarts.connect("servmon-metric-charts");
  return servmonCharts[chartKey];
}

function buildEchartsLineOption({
  palette,
  series,
  yFormatter,
  tooltipFormatter,
  integerAxis = false,
}) {
  return {
    animation: false,
    grid: { top: 22, right: 16, bottom: 42, left: 12, containLabel: true },
    legend: {
      top: 0,
      textStyle: { color: palette.text },
      itemWidth: 12,
      itemHeight: 8,
      data: series.map((s) => s.name),
    },
    tooltip: {
      trigger: "axis",
      confine: true,
      axisPointer: {
        type: "cross",
        lineStyle: { color: palette.series1, type: "dashed", width: 1 },
        crossStyle: { color: palette.axis },
      },
      backgroundColor: palette.tooltipBg,
      borderColor: palette.tooltipBorder,
      textStyle: { color: palette.tooltipText },
      formatter: (params) => {
        const items = Array.isArray(params) ? params : [params];
        if (items.length === 0) return "";
        const ts = items[0].value?.[0] ?? items[0].axisValue;
        const header = new Date(ts).toLocaleString("id-ID", {
          hour12: false,
          year: "numeric",
          month: "2-digit",
          day: "2-digit",
          hour: "2-digit",
          minute: "2-digit",
        });
        const lines = items.map((item) => {
          const value = Array.isArray(item.value) ? item.value[1] : item.value;
          return `${item.marker} ${item.seriesName}: <b>${tooltipFormatter(value)}</b>`;
        });
        return `${header}<br/>${lines.join("<br/>")}`;
      },
    },
    xAxis: {
      type: "time",
      axisLine: { lineStyle: { color: palette.axis } },
      axisLabel: {
        color: palette.textMuted,
        formatter: (value) => formatTimeTick(value),
      },
      splitLine: { lineStyle: { color: palette.grid } },
    },
    yAxis: {
      type: "value",
      min: 0,
      axisLine: { lineStyle: { color: palette.axis } },
      axisLabel: {
        color: palette.textMuted,
        formatter: (value) => yFormatter(value),
      },
      splitLine: { lineStyle: { color: palette.grid } },
      ...(integerAxis ? { minInterval: 1 } : {}),
    },
    dataZoom: [
      { type: "inside", xAxisIndex: 0, filterMode: "none" },
      { type: "inside", yAxisIndex: 0, filterMode: "none" },
    ],
    series: series.map((item) => ({
      name: item.name,
      type: "line",
      showSymbol: false,
      smooth: false,
      lineStyle: { width: 2, color: item.color },
      itemStyle: { color: item.color },
      connectNulls: false,
      data: item.data,
      ...(item.markLine ? { markLine: item.markLine } : {}),
    })),
  };
}

function renderCharts(rows) {
  if (!hasEcharts()) return;

  hideChartSkeletons();

  const palette = getChartPalette();
  const ramData = toSeriesData(rows, "ram_used");
  const diskData = toSeriesData(rows, "hdd_used");
  const cpuData = toSeriesData(rows, "cpu_load");
  const netInData = toSeriesData(rows, "network_in_bps");
  const netOutData = toSeriesData(rows, "network_out_bps");

  const ramChart = getOrCreateChart("ram", "ramHistoryChart");
  if (ramChart) {
    ramChart.setOption(
      buildEchartsLineOption({
        palette,
        series: [{ name: "RAM Used", data: ramData, color: palette.series1 }],
        yFormatter: formatBytes,
        tooltipFormatter: formatBytes,
      }),
      true,
    );
  }

  const diskChart = getOrCreateChart("disk", "diskHistoryChart");
  if (diskChart) {
    diskChart.setOption(
      buildEchartsLineOption({
        palette,
        series: [{ name: "Disk Used", data: diskData, color: palette.series2 }],
        yFormatter: formatBytes,
        tooltipFormatter: formatBytes,
      }),
      true,
    );
  }

  const cpuChart = getOrCreateChart("cpu", "cpuHistoryChart");
  if (cpuChart) {
    const warnThresh = Number(window.SERVMON_CPU_THRESHOLDS?.warn || 2.0);
    const critThresh = Number(window.SERVMON_CPU_THRESHOLDS?.critical || 4.0);
    const cpuMarkLines = {
      symbol: "none",
      silent: true,
      data: [
        {
          yAxis: warnThresh,
          lineStyle: { color: palette.series2, type: "dashed", width: 1.5 },
          label: { formatter: `Warn (${warnThresh.toFixed(1)})`, position: "insideEndTop", color: palette.series2, fontSize: 10 },
        },
        {
          yAxis: critThresh,
          lineStyle: { color: palette.series3, type: "dashed", width: 1.5 },
          label: { formatter: `Crit (${critThresh.toFixed(1)})`, position: "insideEndTop", color: palette.series3, fontSize: 10 },
        },
      ],
    };

    cpuChart.setOption(
      buildEchartsLineOption({
        palette,
        series: [{ name: "CPU Load", data: cpuData, color: palette.series3, markLine: cpuMarkLines }],
        yFormatter: (v) => toNumber(v, 0).toFixed(2),
        tooltipFormatter: (v) => toNumber(v, 0).toFixed(2),
      }),
      true,
    );
  }

  const networkChart = getOrCreateChart("network", "networkHistoryChart");
  if (networkChart) {
    networkChart.setOption(
      buildEchartsLineOption({
        palette,
        series: [
          { name: "Network In", data: netInData, color: palette.series4 },
          { name: "Network Out", data: netOutData, color: palette.series5 },
        ],
        yFormatter: formatBps,
        tooltipFormatter: formatBps,
        integerAxis: true,
      }),
      true,
    );
  }

  updateCpuSummary(rows);
  updateChartSummaries(rows);
}

function scheduleChartRenderRetry() {
  if (servmonChartEngineRetryTimer !== null) return;

  servmonChartEngineRetryTimer = window.setInterval(() => {
    if (!Array.isArray(servmonLastRows) || servmonLastRows.length === 0) return;
    if (!hasEcharts()) return;
    window.clearInterval(servmonChartEngineRetryTimer);
    servmonChartEngineRetryTimer = null;
    renderCharts(servmonLastRows);
  }, 200);

  window.setTimeout(() => {
    if (servmonChartEngineRetryTimer === null) return;
    window.clearInterval(servmonChartEngineRetryTimer);
    servmonChartEngineRetryTimer = null;
  }, 20000);
}

function bindChartResize() {
  if (servmonResizeBound) return;
  servmonResizeBound = true;

  window.addEventListener("resize", () => {
    Object.values(servmonCharts).forEach((chart) => {
      if (chart && typeof chart.resize === "function") {
        chart.resize();
      }
    });
  });
}

function resetHistoryZoom() {
  Object.values(servmonCharts).forEach((chart) => {
    if (!chart || typeof chart.setOption !== "function") return;
    chart.setOption({
      dataZoom: [
        { start: 0, end: 100 },
        { start: 0, end: 100 },
      ],
    });
  });
}

function bootstrapHistory(payload) {
  if (!Array.isArray(payload) || payload.length === 0) return;
  servmonLastRows = normalizeRows(payload);
  renderCharts(servmonLastRows);
  updateCpuSummary(servmonLastRows);
  bindChartResize();
}

async function loadHistory(endpoint) {
  if (document.hidden) return;
  if (!endpoint) return;

  if (!areChartsPaused()) {
    showChartSkeletons();
  }

  const seq = ++servmonHistorySeq;
  if (servmonHistoryRequest) {
    servmonHistoryRequest.abort();
  }
  servmonHistoryRequest = new AbortController();

  let payload = [];
  let timeoutId = null;
  try {
    timeoutId = setTimeout(() => {
      if (servmonHistoryRequest) {
        servmonHistoryRequest.abort();
      }
    }, 10000);

    const url = endpoint.includes("?") ? `${endpoint}&_t=${Date.now()}` : `${endpoint}?_t=${Date.now()}`;
    const response = await fetch(url, {
      headers: { Accept: "application/json" },
      signal: servmonHistoryRequest.signal,
      cache: "no-store",
    });
    if (!response.ok) return;
    payload = await response.json();
  } catch (err) {
    if (err && err.name !== "AbortError") {
      console.error(err);
    }
    return;
  } finally {
    if (timeoutId) {
      clearTimeout(timeoutId);
    }
  }

  if (seq !== servmonHistorySeq) return;

  servmonLastRows = normalizeRows(payload);
  renderCharts(servmonLastRows);
  updateCpuSummary(servmonLastRows);

  if (!hasEcharts()) {
    scheduleChartRenderRetry();
  }

  bindChartResize();
}

document.addEventListener("click", (event) => {
  const target = event.target.closest("[data-reset-zoom]");
  if (!target) return;
  resetHistoryZoom();
});

document.addEventListener("click", (event) => {
  const button = event.target.closest("[data-chart-pause]");
  if (!button) return;
  setChartPaused(!areChartsPaused());
});

document.addEventListener("servmon:theme-changed", () => {
  if (!Array.isArray(servmonLastRows) || servmonLastRows.length === 0) return;
  renderCharts(servmonLastRows);
  updateCpuSummary(servmonLastRows);
});
