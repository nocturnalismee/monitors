function getChartPalette() {
  const theme = document.documentElement.getAttribute("data-bs-theme") || "dark";
  const isLight = theme === "light";
  return {
    text: ServMon.getThemeColor("--sv-text", isLight ? "#0f172a" : "#e9e9e9"),
    muted: ServMon.getThemeColor("--sv-muted", isLight ? "#475569" : "#9a9a9a"),
    grid: isLight ? "rgba(15,23,42,0.08)" : "rgba(148,163,184,0.16)",
    axis: isLight ? "rgba(15,23,42,0.2)" : "rgba(148,163,184,0.25)",
    surface: ServMon.getThemeColor("--sv-surface-2", isLight ? "#f1f5f9" : "#232323"),
    border: ServMon.getThemeColor("--sv-border", isLight ? "#cbd5e1" : "#333333"),
    accent: ServMon.getThemeColor("--sv-chart-1", "#2dd4bf"),
    danger: ServMon.getThemeColor("--sv-danger", "#ef4444"),
  };
}

function formatTimeLabel(ms) {
  if (!Number.isFinite(ms)) return "-";
  const d = new Date(ms);
  const hh = String(d.getHours()).padStart(2, "0");
  const mm = String(d.getMinutes()).padStart(2, "0");
  const prevDay = new Date(ms - 60 * 1000).getDate();
  if (d.getDate() !== prevDay) {
    const dd = String(d.getDate()).padStart(2, "0");
    const mon = String(d.getMonth() + 1).padStart(2, "0");
    return `${dd}/${mon} ${hh}:${mm}`;
  }
  return `${hh}:${mm}`;
}

function normalizeRows(payload) {
  if (!Array.isArray(payload)) return [];
  return payload
    .map((row) => {
      const ts = ServMon.parseTimestampMs(row.checked_at);
      const status = String(row.status || "down");
      const latency = Number(row.latency_ms);
      return {
        ts,
        status,
        latency: Number.isFinite(latency) ? latency : null,
      };
    })
    .filter((row) => row.ts !== null)
    .sort((a, b) => a.ts - b.ts);
}

let pingHistoryChart = null;
let pingChartRetryTimer = null;

function renderPingHistory() {
  const container = document.getElementById("pingHistoryChart");
  if (!container) return;
  if (typeof window.echarts === "undefined") {
    schedulePingChartRetry();
    return;
  }

  const rows = normalizeRows(window.SERVMON_PING_HISTORY);
  if (rows.length === 0) {
    if (pingHistoryChart && !pingHistoryChart.isDisposed()) pingHistoryChart.clear();
    return;
  }

  const palette = getChartPalette();
  const labels = rows.map((row) => formatTimeLabel(row.ts));
  const latencyData = rows.map((row) => row.status === "up" ? row.latency : null);
  const statusData = rows.map(() => 1);

  if (!pingHistoryChart || pingHistoryChart.isDisposed()) {
    pingHistoryChart = window.echarts.init(container);
    window.addEventListener("resize", () => {
      if (pingHistoryChart && typeof pingHistoryChart.resize === "function") {
        pingHistoryChart.resize();
      }
    });
  }

  pingHistoryChart.setOption(
    {
      animation: false,
      grid: [
        { top: 34, right: 14, bottom: 104, left: 56, containLabel: true },
        { top: "78%", right: 14, height: 24, left: 56, containLabel: true },
      ],
      legend: {
        top: 0,
        textStyle: { color: palette.text },
        data: ["Latency", "Availability"],
      },
      tooltip: {
        trigger: "axis",
        axisPointer: { type: "cross" },
        confine: true,
        backgroundColor: palette.surface,
        borderColor: palette.border,
        textStyle: { color: palette.text },
        formatter: (params) => {
          const items = Array.isArray(params) ? params : [params];
          const idx = Number(items[0]?.dataIndex ?? 0);
          const row = rows[idx];
          if (!row) return "";
          const time = new Date(row.ts).toLocaleString("id-ID", { hour12: false });
          const value = row.status === "up" && row.latency !== null
            ? `${Number(row.latency).toFixed(2)} ms`
            : "No response";
          const statusColor = row.status === "up" ? palette.accent : palette.danger;
          return `${time}<br/>${items[0]?.marker || ""} Latency: <b>${value}</b><br/>` +
            `<span style="color:${statusColor}">●</span> Status: <b>${row.status.toUpperCase()}</b>`;
        },
      },
      yAxis: [
        {
          type: "value", gridIndex: 0, min: 0,
          axisLine: { lineStyle: { color: palette.axis } },
          axisLabel: { color: palette.muted, formatter: (value) => `${value} ms` },
          splitLine: { lineStyle: { color: palette.grid } },
        },
        {
          type: "value", gridIndex: 1, min: 0, max: 1, show: false,
        },
      ],
      xAxis: [
        {
          type: "category", data: labels, gridIndex: 0, boundaryGap: false,
          axisLine: { lineStyle: { color: palette.axis } }, axisLabel: { show: false },
        },
        {
          type: "category", data: labels, gridIndex: 1, boundaryGap: true,
          axisTick: { alignWithLabel: true },
          axisLine: { lineStyle: { color: palette.axis } },
          axisLabel: { color: palette.muted, hideOverlap: true },
        },
      ],
      dataZoom: [
        { type: "inside", xAxisIndex: [0, 1], filterMode: "none" },
        { type: "slider", xAxisIndex: [0, 1], height: 14, bottom: 6 },
      ],
      series: [
        {
          name: "Latency",
          type: "line", xAxisIndex: 0, yAxisIndex: 0,
          data: latencyData, connectNulls: false, showSymbol: false,
          smooth: false,
          lineStyle: { color: palette.accent, width: 2 },
          areaStyle: { color: palette.accent, opacity: 0.12 },
        },
        {
          name: "Availability",
          type: "bar", xAxisIndex: 1, yAxisIndex: 1,
          data: statusData.map((value, index) => ({
            value,
            itemStyle: {
              color: rows[index].status === "up" ? palette.accent : palette.danger,
              opacity: 0.9,
            },
          })),
          barMaxWidth: 12,
          barMinHeight: 3,
        },
      ],
    },
    true,
  );
}

function schedulePingChartRetry() {
  if (pingChartRetryTimer !== null) return;
  pingChartRetryTimer = window.setInterval(() => {
    if (typeof window.echarts === "undefined") return;
    window.clearInterval(pingChartRetryTimer);
    pingChartRetryTimer = null;
    renderPingHistory();
  }, 200);

  window.setTimeout(() => {
    if (pingChartRetryTimer === null) return;
    window.clearInterval(pingChartRetryTimer);
    pingChartRetryTimer = null;
  }, 20000);
}

document.addEventListener("DOMContentLoaded", renderPingHistory);
document.addEventListener("servmon:theme-changed", renderPingHistory);
window.addEventListener("load", renderPingHistory);

/* --- Partial refresh without full-page reload --- */
(function () {
  var cfg = window.SERVMON_PING_DETAIL || {};
  var endpoint = typeof cfg.endpoint === "string" ? cfg.endpoint : "";
  var monitorId = Number(cfg.id) || 0;
  var currentRange = typeof cfg.range === "string" && cfg.range !== "" ? cfg.range : "24h";
  var isFirstPage = Number(cfg.page) === 1;
  if (!endpoint || monitorId <= 0) return;
  if (typeof ServMon === "undefined" || typeof ServMon.startPoller !== "function") return;

  function esc(value) {
    if (ServMon && typeof ServMon.escapeHtml === "function") {
      return ServMon.escapeHtml(String(value ?? ""));
    }
    return String(value ?? "").replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function setUpdated(ok) {
    var el = document.querySelector("[data-ping-updated]");
    if (!el) return;
    el.textContent = ok ? `Updated ${new Date().toLocaleTimeString()}` : "Update unavailable";
  }

  function setText(selector, text) {
    var el = document.querySelector(selector);
    if (el) el.textContent = text;
  }

  function applySummary(data) {
    var monitor = data.monitor || {};
    var latency = monitor.last_latency_ms;
    setText("[data-ping-name]", monitor.name || "-");
    setText("[data-ping-target]", `Target: ${monitor.target || "-"}`);
    setText("[data-ping-latency]", latency !== null && latency !== undefined ? `${Number(latency).toFixed(2)} ms` : "-");
    setText("[data-ping-last-check]", `Last check: ${monitor.last_checked_at || "-"}`);
    setText("[data-ping-last-change]", `Last change: ${monitor.last_change_at || "-"}`);
    setText("[data-ping-failures]", String(data.failure_count_30d ?? "-"));
    setText("[data-ping-threshold]", `30d DOWN \u00B7 Alert: ${monitor.failure_threshold ?? 2} consecutive`);
    setText(
      "[data-ping-uptime]",
      data.uptime_percent === null || data.uptime_percent === undefined ? "-" : `${Number(data.uptime_percent).toFixed(2)}%`
    );
    setText("[data-ping-uptime-detail]", `${data.uptime_up_checks ?? 0}/${data.uptime_total_checks ?? 0} checks in ${data.range || currentRange}`);

    var badge = document.querySelector("[data-ping-status]");
    if (badge) {
      badge.textContent = String(data.display_status || "unknown");
      badge.className = `badge ${data.status_badge_class || "badge-pending"} text-uppercase`;
    }

    var errBox = document.querySelector("[data-ping-last-error]");
    if (errBox) {
      var msg = String(monitor.last_error || "");
      if (msg === "") {
        errBox.hidden = true;
        errBox.textContent = "";
      } else {
        errBox.hidden = false;
        errBox.textContent = `Last error: ${msg}`;
      }
    }
  }

  function renderRecent(recent) {
    var tbody = document.querySelector("[data-ping-recent-body]");
    if (!tbody) return;
    if (!Array.isArray(recent) || recent.length === 0) {
      tbody.innerHTML = `<tr><td colspan="4" class="table-empty">No checks in selected range.</td></tr>`;
      return;
    }
    tbody.innerHTML = recent
      .map((row) => {
        var status = String(row.status || "down");
        var badgeClass = status === "up" ? "badge-online" : "badge-down";
        var latency = row.latency_ms === null || row.latency_ms === undefined ? "-" : `${Number(row.latency_ms).toFixed(2)} ms`;
        var err = String(row.error_message || "");
        return `<tr><td>${esc(row.checked_at || "-")}</td>` +
          `<td><span class="badge ${badgeClass} text-uppercase">${esc(status)}</span></td>` +
          `<td class="font-mono">${esc(latency)}</td><td>${esc(err === "" ? "-" : err)}</td></tr>`;
      })
      .join("");
  }

  function fetchApply(range) {
    var url = `${endpoint}?id=${encodeURIComponent(String(monitorId))}&range=${encodeURIComponent(range)}`;
    return fetch(url, { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then((res) => {
        if (!res.ok) throw new Error(`ping detail poll failed: ${res.status}`);
        return res.json();
      })
      .then((data) => {
        if (!data || typeof data !== "object") throw new Error("invalid ping detail payload");
        window.SERVMON_PING_HISTORY = Array.isArray(data.chart) ? data.chart : [];
        renderPingHistory();
        applySummary(data);
        // Page > 1 shows older history; leave it alone so polling never yanks pagination.
        if (isFirstPage) renderRecent(data.recent);
        setUpdated(true);
        return true;
      })
      .catch((err) => {
        console.error(err);
        setUpdated(false);
        return false;
      });
  }

  var select = document.getElementById("pingRangeSelect");
  if (select) {
    select.addEventListener("change", () => {
      currentRange = String(select.value || "24h");
      try {
        var url = new URL(window.location.href);
        url.searchParams.set("range", currentRange);
        url.searchParams.delete("page");
        window.history.replaceState(null, "", url.toString());
      } catch (e) {
        console.warn("Could not update range URL", e);
      }
      select.disabled = true;
      fetchApply(currentRange).finally(() => {
        select.disabled = false;
      });
    });
  }

  ServMon.startPoller(() => {
    if (document.hidden) return Promise.resolve(true);
    return fetchApply(currentRange);
  }, { baseMs: 15000, maxMs: 120000 });
})();
