/**
 * IP Reputation detail page – ECharts listing history chart + Check Now
 */
(function () {
  'use strict';

  var API_URL  = window.SERVMON_IP_REP_API || '';
  var chartData = window.SERVMON_IP_REP_CHART_DATA || [];
  var chartDom  = document.getElementById('ip-rep-history-chart');

  /* ── ECharts Timeline ──────────────────────────────────── */
  if (chartDom && typeof echarts !== 'undefined' && chartData.length > 0) {
    var chart = echarts.init(chartDom);

    var times  = chartData.map(function (d) { return d.time; });
    var listed = chartData.map(function (d) { return d.listed; });
    var total  = chartData.map(function (d) { return d.total; });

    var isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
    var textColor = isDark ? '#a0aec0' : '#4a5568';
    var gridColor = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)';

    var option = {
      tooltip: {
        trigger: 'axis',
        backgroundColor: isDark ? '#1a202c' : '#fff',
        borderColor: isDark ? '#2d3748' : '#e2e8f0',
        textStyle: { color: textColor, fontSize: 12 },
        formatter: function (params) {
          var t = params[0].axisValue;
          var html = '<div style="font-size:11px;margin-bottom:4px">' + t + '</div>';
          params.forEach(function (p) {
            html += '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' + p.color + ';margin-right:6px"></span>' + p.seriesName + ': <strong>' + p.value + '</strong><br>';
          });
          return html;
        }
      },
      grid: { left: 50, right: 20, top: 20, bottom: 40 },
      xAxis: {
        type: 'category',
        data: times,
        axisLabel: { color: textColor, fontSize: 10, rotate: 30 },
        axisLine: { lineStyle: { color: gridColor } },
        splitLine: { show: false }
      },
      yAxis: {
        type: 'value',
        name: 'Blacklists',
        nameTextStyle: { color: textColor, fontSize: 11 },
        axisLabel: { color: textColor, fontSize: 11 },
        splitLine: { lineStyle: { color: gridColor } },
        minInterval: 1
      },
      series: [
        {
          name: 'Listed',
          type: 'line',
          data: listed,
          smooth: true,
          symbol: 'circle',
          symbolSize: 6,
          lineStyle: { width: 2.5, color: '#e53e3e' },
          itemStyle: { color: '#e53e3e' },
          areaStyle: {
            color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
              { offset: 0, color: 'rgba(229, 62, 62, 0.25)' },
              { offset: 1, color: 'rgba(229, 62, 62, 0.02)' }
            ])
          }
        },
        {
          name: 'Total Checked',
          type: 'line',
          data: total,
          smooth: true,
          symbol: 'none',
          lineStyle: { width: 1.5, color: '#718096', type: 'dashed' },
          itemStyle: { color: '#718096' }
        }
      ]
    };

    chart.setOption(option);

    window.addEventListener('resize', function () { chart.resize(); });

    // Theme change re-render
    var observer = new MutationObserver(function () {
      chart.dispose();
      var newChart = echarts.init(chartDom);
      var nowDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
      option.tooltip.backgroundColor = nowDark ? '#1a202c' : '#fff';
      option.tooltip.borderColor = nowDark ? '#2d3748' : '#e2e8f0';
      var tc = nowDark ? '#a0aec0' : '#4a5568';
      var gc = nowDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)';
      option.xAxis.axisLabel.color = tc;
      option.xAxis.axisLine.lineStyle.color = gc;
      option.yAxis.axisLabel.color = tc;
      option.yAxis.nameTextStyle.color = tc;
      option.yAxis.splitLine.lineStyle.color = gc;
      option.tooltip.textStyle.color = tc;
      newChart.setOption(option);
    });
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
  } else if (chartDom) {
    chartDom.innerHTML = '<div class="d-flex justify-content-center align-items-center h-100 text-secondary"><i class="ti ti-chart-line me-2"></i>No check history data yet</div>';
  }

  /* ── Check Now button ──────────────────────────────────── */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-ip-rep-check-now]');
    if (!btn || !API_URL) return;

    var targetId = btn.getAttribute('data-ip-rep-check-now');
    if (!targetId) return;

    btn.disabled = true;
    var origHtml = btn.innerHTML;
    btn.innerHTML = '<i class="ti ti-loader-2 ti-spin me-1"></i>Checking…';

    fetch(API_URL + '?action=check_now&id=' + encodeURIComponent(targetId), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ _csrf_token: window.SERVMON_CSRF_TOKEN || '' }),
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          btn.innerHTML = '<i class="ti ti-check me-1"></i>Done';
          setTimeout(function () { location.reload(); }, 1000);
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
})();
