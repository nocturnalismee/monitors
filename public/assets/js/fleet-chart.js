/**
 * Dashboard fleet performance card: fleet-wide average CPU / RAM / disk.
 *
 * The card is a single 48px summary row by default so the server table stays
 * above the fold. Expanding it reveals the time-series chart; the preference
 * is remembered in localStorage.
 *
 * Two things are deliberately deferred:
 *  - the ~1 MB ECharts bundle only loads the first time the card is expanded
 *    (and, with IntersectionObserver, only once the card is actually visible);
 *  - the coloured chart is only painted when there is room for it.
 * The cheap aggregate JSON (/api/status?fleet_history=…) is still fetched on
 * load so the collapsed row can show live CPU/RAM/disk numbers.
 */
(() => {
  const card = document.querySelector("[data-fleet-chart]");
  if (!card) return;

  const canvas = card.querySelector("[data-fleet-canvas]");
  const stateNode = card.querySelector("[data-fleet-state]");
  const skeleton = card.querySelector("[data-fleet-skeleton]");
  const toggle = card.querySelector("[data-fleet-toggle]");
  const body = card.querySelector("[data-fleet-body]");
  const rangeButtons = Array.from(card.querySelectorAll("[data-fleet-range]"));
  const metricKeys = ["cpu", "ram", "disk"];
  const valueNodes = {};
  const trendNodes = {};
  const metricNodes = {};
  metricKeys.forEach((key) => {
    valueNodes[key] = card.querySelector(`[data-fleet-value="${key}"]`);
    trendNodes[key] = card.querySelector(`[data-fleet-trend="${key}"]`);
    metricNodes[key] = card.querySelector(`[data-fleet-metric="${key}"]`);
  });

  const endpoint = card.getAttribute("data-endpoint") || "";
  const echartsSrc = card.getAttribute("data-echarts-src") || "";
  const rangeKey = "monitors_fleet_range";
  const expandedKey = "monitors_fleet_chart_expanded";
  const validRanges = new Set(["30m", "6h", "24h", "7d"]);
  const rangeLabels = { "30m": "30 minutes", "6h": "6 hours", "24h": "24 hours", "7d": "7 days" };

  let chart = null;
  let enginePromise = null;
  let observer = null;
  let currentRange = "30m";
  let lastRows = [];
  let inFlight = false;
  let timer = null;
  let disposed = false;
  let chartStarted = false;
  let expanded = false;

  try {
    const storedRange = sessionStorage.getItem(rangeKey);
    if (storedRange && validRanges.has(storedRange)) currentRange = storedRange;
    expanded = localStorage.getItem(expandedKey) === "1";
  } catch (err) {
    // Storage may be blocked; the defaults (30m, collapsed) still work.
  }

  const setState = (message) => {
    if (!stateNode) return;
    if (!message) {
      stateNode.hidden = true;
      stateNode.textContent = "";
      return;
    }
    stateNode.hidden = false;
    stateNode.textContent = message;
  };

  const cssVar = (name, fallback) => {
    const value = getComputedStyle(document.documentElement).getPropertyValue(name);
    return value && value.trim() !== "" ? value.trim() : fallback;
  };

  const loadEngine = () => {
    if (window.echarts) return Promise.resolve(window.echarts);
    if (enginePromise) return enginePromise;
    enginePromise = new Promise((resolve, reject) => {
      const script = document.createElement("script");
      script.src = echartsSrc;
      script.async = true;
      script.onload = () => (window.echarts ? resolve(window.echarts) : reject(new Error("ECharts did not initialise")));
      script.onerror = () => reject(new Error("ECharts failed to load"));
      document.head.appendChild(script);
    });
    return enginePromise;
  };

  const numberOrNull = (value) => {
    const num = Number(value);
    return Number.isFinite(num) ? Number(num.toFixed(1)) : null;
  };

  const seriesRows = (rows) =>
    rows
      .map((row) => {
        const ts = Number(row.ts);
        if (!Number.isFinite(ts) || ts <= 0) return null;
        return [ts * 1000, numberOrNull(row.cpu_load), numberOrNull(row.ram_pct), numberOrNull(row.hdd_pct)];
      })
      .filter(Boolean)
      .sort((a, b) => a[0] - b[0]);

  const firstValue = (points, index) => {
    for (let i = 0; i < points.length; i += 1) {
      if (points[i][index] !== null) return points[i][index];
    }
    return null;
  };

  const updateSummary = (points) => {
    const latest = points.length ? points[points.length - 1] : null;
    const columns = [
      { key: "cpu", index: 1, label: "CPU" },
      { key: "ram", index: 2, label: "RAM" },
      { key: "disk", index: 3, label: "Disk" },
    ];

    columns.forEach(({ key, index, label }) => {
      const value = latest ? latest[index] : null;
      const valueNode = valueNodes[key];
      if (valueNode) valueNode.textContent = value === null ? "\u2014" : `${value}%`;

      const trendNode = trendNodes[key];
      if (!trendNode) return;
      const first = firstValue(points, index);
      const delta = value !== null && first !== null ? value - first : 0;
      const trend = delta >= 0.5 ? "up" : delta <= -0.5 ? "down" : "flat";
      trendNode.hidden = value === null || trend === "flat";
      trendNode.classList.remove("is-up", "is-down", "is-flat");
      trendNode.classList.add(`is-${trend}`);
      const icon = trendNode.querySelector("i");
      if (icon) {
        icon.className = `ti ${trend === "up" ? "ti-trending-up" : trend === "down" ? "ti-trending-down" : "ti-minus"}`;
      }
      const move = trend === "flat" ? "flat" : `${delta > 0 ? "+" : ""}${delta.toFixed(1)} points`;
      const description =
        value === null
          ? `${label}: no data in the selected window`
          : `${label} ${value}%, ${move} over the last ${rangeLabels[currentRange] || currentRange}`;
      trendNode.setAttribute("title", description);
      trendNode.setAttribute("aria-label", description);
      if (metricNodes[key]) metricNodes[key].setAttribute("title", description);
    });
  };

  const latestSummary = (points) => {
    if (!points.length) return "";
    const cpu = points[points.length - 1][1];
    const ram = points[points.length - 1][2];
    const disk = points[points.length - 1][3];
    const fmt = (v) => (v === null ? "\u2014" : `${v}%`);
    return `Fleet average over the selected window. Latest: CPU ${fmt(cpu)}, RAM ${fmt(ram)}, disk ${fmt(disk)}.`;
  };

  const buildOption = (points) => {
    const axisText = cssVar("--sv-muted", "#9a9a9a");
    const gridLine = cssVar("--sv-border", "#333333");
    const cpuColor = cssVar("--sv-accent", "#2dd4bf");
    const ramColor = cssVar("--sv-warning", "#d99a2b");
    const diskColor = cssVar("--sv-danger", "#e06060");

    const series = [
      { name: "CPU", color: cpuColor, index: 1, area: true },
      { name: "RAM", color: ramColor, index: 2, area: false },
      { name: "Disk", color: diskColor, index: 3, area: false },
    ].map((entry) => ({
      name: entry.name,
      type: "line",
      showSymbol: false,
      symbol: "circle",
      symbolSize: 5,
      smooth: false,
      connectNulls: false,
      animation: false,
      lineStyle: { width: 2, color: entry.color },
      itemStyle: { color: entry.color },
      areaStyle: entry.area
        ? { color: entry.color, opacity: 0.12 }
        : undefined,
      emphasis: { focus: "series" },
      data: points.map((point) => [point[0], point[entry.index]]),
    }));

    return {
      animation: false,
      textStyle: { fontFamily: cssVar("--sv-font-sans", "system-ui, sans-serif") },
      grid: { left: 46, right: 18, top: 34, bottom: 28 },
      legend: {
        top: 0,
        left: 0,
        itemWidth: 10,
        itemHeight: 10,
        icon: "roundRect",
        textStyle: { color: axisText, fontSize: 11 },
        data: ["CPU", "RAM", "Disk"],
      },
      tooltip: {
        trigger: "axis",
        confine: true,
        backgroundColor: cssVar("--sv-surface-2", "#232323"),
        borderColor: gridLine,
        textStyle: { color: cssVar("--sv-text", "#e9e9e9"), fontSize: 11 },
        axisPointer: { type: "line", lineStyle: { color: gridLine, width: 1 } },
        valueFormatter: (value) => (value === null || value === undefined ? "\u2014" : `${Number(value).toFixed(1)}%`),
      },
      xAxis: {
        type: "time",
        axisLine: { lineStyle: { color: gridLine } },
        axisTick: { show: false },
        axisLabel: { color: axisText, fontSize: 10, hideOverlap: true },
        splitLine: { show: false },
      },
      yAxis: {
        type: "value",
        min: 0,
        max: 100,
        interval: 25,
        axisLabel: { color: axisText, fontSize: 10, formatter: "{value}%" },
        axisLine: { show: false },
        axisTick: { show: false },
        splitLine: { lineStyle: { color: gridLine, opacity: 0.45 } },
      },
      series,
    };
  };

  const render = () => {
    if (disposed) return;
    const points = seriesRows(lastRows);
    updateSummary(points);
    if (!chart) return;
    if (skeleton) skeleton.classList.remove("is-active");
    if (!points.length) {
      chart.clear();
      setState("No telemetry for this window yet. Points appear as agents report in.");
      card.setAttribute("data-empty", "1");
      return;
    }
    card.removeAttribute("data-empty");
    setState("");
    chart.setOption(buildOption(points), true);
    if (canvas) {
      canvas.setAttribute("aria-label", latestSummary(points));
    }
  };

  const setActiveRange = (range) => {
    currentRange = range;
    try {
      sessionStorage.setItem(rangeKey, range);
    } catch (err) {
      // Ignore storage errors.
    }
    rangeButtons.forEach((btn) => {
      const active = btn.getAttribute("data-fleet-range") === range;
      btn.classList.toggle("is-active", active);
      btn.setAttribute("aria-pressed", active ? "true" : "false");
    });
  };

  const fetchSeries = async () => {
    if (inFlight || disposed) return;
    inFlight = true;
    card.setAttribute("data-loading", "1");
    try {
      const url = new URL(endpoint, window.location.origin);
      url.searchParams.set("fleet_history", currentRange);
      const response = await fetch(url.toString(), {
        headers: { Accept: "application/json" },
        credentials: "same-origin",
        cache: "no-store",
      });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const payload = await response.json();
      lastRows = Array.isArray(payload) ? payload : [];
      render();
    } catch (err) {
      if (skeleton) skeleton.classList.remove("is-active");
      if (!lastRows.length) {
        setState("Telemetry is temporarily unavailable. Retrying automatically.");
      }
    } finally {
      card.removeAttribute("data-loading");
      inFlight = false;
    }
  };

  const stopRefresh = () => {
    if (timer) {
      clearInterval(timer);
      timer = null;
    }
  };

  const scheduleRefresh = () => {
    stopRefresh();
    // One poll a minute is enough for a fleet trend view; paused while the
    // tab is hidden and while the card is collapsed.
    timer = setInterval(() => {
      if (expanded && document.visibilityState === "visible") fetchSeries();
    }, 60000);
  };

  const start = async () => {
    if (disposed) return;
    try {
      const echarts = await loadEngine();
      if (disposed || !canvas) return;
      if (!chart) {
        chart = echarts.init(canvas, null, { renderer: "canvas" });
      }
      setActiveRange(currentRange);
      if (lastRows.length) {
        render();
      } else {
        if (skeleton) skeleton.classList.add("is-active");
        setState("Loading fleet telemetry\u2026");
      }
      await fetchSeries();
      if (chart) chart.resize();
      scheduleRefresh();
    } catch (err) {
      setState("Chart engine could not be loaded. Reload the page to try again.");
    }
  };

  const startChart = () => {
    if (chartStarted || disposed) return;
    chartStarted = true;
    start();
  };

  const ensureChart = () => {
    if (!expanded || chartStarted || disposed) return;
    if (typeof IntersectionObserver === "function") {
      if (observer) return;
      observer = new IntersectionObserver(
        (entries) => {
          entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            if (observer) {
              observer.disconnect();
              observer = null;
            }
            startChart();
          });
        },
        { rootMargin: "160px 0px" }
      );
      observer.observe(card);
      return;
    }
    startChart();
  };

  const applyExpanded = (value, persist = true) => {
    expanded = Boolean(value);
    card.classList.toggle("is-collapsed", !expanded);
    if (toggle) toggle.setAttribute("aria-expanded", expanded ? "true" : "false");
    if (body) body.hidden = !expanded;
    if (persist) {
      try {
        localStorage.setItem(expandedKey, expanded ? "1" : "0");
      } catch (err) {
        // Ignore storage errors.
      }
    }

    if (!expanded) {
      stopRefresh();
      return;
    }

    if (chart) {
      requestAnimationFrame(() => {
        if (!chart || disposed) return;
        chart.resize();
        render();
      });
      fetchSeries();
    }
    ensureChart();
    scheduleRefresh();
  };

  rangeButtons.forEach((btn) => {
    btn.addEventListener("click", () => {
      const range = btn.getAttribute("data-fleet-range");
      if (!range || !validRanges.has(range) || range === currentRange) return;
      setActiveRange(range);
      lastRows = [];
      setState("Loading\u2026");
      fetchSeries();
    });
  });

  if (toggle) {
    toggle.addEventListener("click", () => applyExpanded(!expanded));
  }

  let resizeFrame = null;
  window.addEventListener("resize", () => {
    if (!chart) return;
    if (resizeFrame) cancelAnimationFrame(resizeFrame);
    resizeFrame = requestAnimationFrame(() => {
      if (chart) chart.resize();
    });
  });

  document.addEventListener("monitors:theme-changed", () => {
    // ECharts paints hard-coded colours at option time, so a theme flip needs
    // a re-render with the newly resolved CSS variables. Skip it while the
    // canvas is display:none (collapsed) — the next expand re-renders.
    if (chart && expanded) render();
  });

  applyExpanded(expanded, false);
  setActiveRange(currentRange);
  // Populate the collapsed summary row without pulling in the chart engine.
  fetchSeries();
  if (expanded) ensureChart();

  window.addEventListener("pagehide", () => {
    disposed = true;
    stopRefresh();
    if (observer) {
      observer.disconnect();
      observer = null;
    }
    if (chart) chart.dispose();
  });
})();