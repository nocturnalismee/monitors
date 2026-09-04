function parseTimestampMs(input) {
  if (!input) return null;
  const text = String(input).trim();
  const ms = Date.parse(text.includes("T") ? text : text.replace(" ", "T"));
  return Number.isFinite(ms) ? ms : null;
}

function getThemeColor(varName, fallback) {
  const css = getComputedStyle(document.documentElement);
  const value = css.getPropertyValue(varName).trim();
  return value || fallback;
}

function getChartPalette() {
  const theme = document.documentElement.getAttribute("data-bs-theme") || "dark";
  const isLight = theme === "light";
  return {
    text: getThemeColor("--sv-text", isLight ? "#0f172a" : "#e9e9e9"),
    muted: getThemeColor("--sv-muted", isLight ? "#475569" : "#9a9a9a"),
    grid: isLight ? "rgba(15,23,42,0.08)" : "rgba(148,163,184,0.16)",
    axis: isLight ? "rgba(15,23,42,0.2)" : "rgba(148,163,184,0.25)",
    surface: getThemeColor("--sv-surface-2", isLight ? "#f1f5f9" : "#232323"),
    border: getThemeColor("--sv-border", isLight ? "#cbd5e1" : "#333333"),
    accent: getThemeColor("--sv-chart-1", "#2dd4bf"),
    danger: getThemeColor("--sv-danger", "#ef4444"),
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
      const ts = parseTimestampMs(row.checked_at);
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
  if (rows.length === 0) return;

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
