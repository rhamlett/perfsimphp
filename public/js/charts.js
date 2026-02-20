/**
 * =============================================================================
 * CHARTS.JS INTEGRATION — Real-Time Metric Visualization
 * =============================================================================
 *
 * PURPOSE:
 *   Manages three real-time Chart.js charts on the dashboard:
 *   1. CPU/Memory chart — Combined CPU%, memory MB (60-second window)
 *   2. PHP Worker chart — FPM worker response time in milliseconds (60-second window)
 *   3. Latency chart — HTTP probe latency from browser XHR (600 data points,
 *      ~60 seconds at 100ms probe interval)
 *
 * DATA FLOW:
 *   polling-client.js polls REST endpoints → calls onMetricsUpdate()
 *   and onProbeLatency() → this file updates charts.
 *
 * LATENCY CHART FEATURES:
 *   - Color-coded gradient fill based on latency severity:
 *     * Green (0-200ms): Healthy response times
 *     * Yellow (200ms-1s): Degraded performance
 *     * Orange (1s-30s): Severe degradation
 *     * Red (30s+): Critical / near-timeout
 *   - Smooth color interpolation between thresholds (not hard bands)
 *   - Dynamic Y-axis scaling based on current maximum
 *
 * LATENCY STATISTICS:
 *   - Time-based rolling window (last 60 seconds)
 *   - Calculates: current, average, max, P99 from rolling window
 *
 * SERVER RESPONSIVENESS MONITORING:
 *   - Tracks consecutive probe failures to detect unresponsive state
 *   - Shows probe history as colored dots (green=ok, yellow=slow, red=fail)
 *   - Reports recovery time when server becomes responsive again
 *
 * CHART.JS CONFIGURATION:
 *   - animation: false (real-time charts must not animate)
 *   - Update mode: 'none' (skip animation on data push)
 *   - Custom tooltip formatters for each chart type
 *   - Responsive but not aspect-ratio-maintaining (fills container)
 *
 * PORTING NOTES:
 *   This file is FRONTEND JavaScript — it stays JS regardless of backend.
 *   The key difference from PerfSimNode:
 *   - "Event Loop Lag" replaced with "Worker Response" metric
 *   - Latency probes come from browser XHR (not a sidecar process)
 *   - No timestamp backfill needed (no queued IPC messages)
 *   When porting to another frontend framework (React, Vue, Angular),
 *   use the appropriate Chart.js wrapper library.
 */

/**
 * Gets the current UTC time as a formatted string (HH:MM:SS)
 * All times use UTC to match Azure AppLens and backend diagnostics.
 */
function getUtcTimeString() {
  const now = new Date();
  const hours = now.getUTCHours().toString().padStart(2, '0');
  const minutes = now.getUTCMinutes().toString().padStart(2, '0');
  const seconds = now.getUTCSeconds().toString().padStart(2, '0');
  return `${hours}:${minutes}:${seconds}`;
}

// Chart instances
let cpuMemoryChart = null;
let eventloopChart = null;
let latencyChart = null;

// Data history for CPU/Memory/Worker charts (60 data points at ~1s intervals = 60s)
const maxDataPoints = 60;
const chartData = {
  labels: [],
  cpu: [],
  memory: [],
  eventloop: [],  // In PHP: worker response time (kept name for compatibility)
  rss: [],
};

// Separate data store for latency chart (60 seconds at 100ms intervals)
const maxLatencyDataPoints = 600;
const latencyChartData = {
  labels: [],
  values: [],
};
let lastLatencyChartUpdate = 0;
const LATENCY_CHART_UPDATE_INTERVAL_MS = 100;

// Latency tracking - uses time-based retention (last 60 seconds)
const LATENCY_STATS_WINDOW_MS = 60000;
const latencyStats = {
  entries: [],
  current: 0,
  critical: 0,
};

// Load test activity tracking - logs periodic stats during active load testing
const loadTestTracking = {
  isActive: false,
  lastStatsLogTime: 0,
  statsIntervalMs: 60000,  // Log every 60 seconds
  lastConcurrent: 0,
};

/**
 * Checks if load test stats should be logged and logs them.
 * Called from onProbeLatency when load test is active.
 */
function checkLoadTestStatsLog(loadTestActive, concurrent) {
  const now = Date.now();
  
  // Detect load test becoming active
  if (loadTestActive && !loadTestTracking.isActive) {
    loadTestTracking.isActive = true;
    loadTestTracking.lastStatsLogTime = now;
    loadTestTracking.lastConcurrent = concurrent;
  }
  
  // Detect load test becoming inactive
  if (!loadTestActive && loadTestTracking.isActive) {
    loadTestTracking.isActive = false;
  }
  
  // Log stats every 60 seconds while load test is active
  if (loadTestTracking.isActive && (now - loadTestTracking.lastStatsLogTime >= loadTestTracking.statsIntervalMs)) {
    loadTestTracking.lastStatsLogTime = now;
    loadTestTracking.lastConcurrent = concurrent;
    
    // Calculate average latency from entries in the last 60 seconds
    const values = getLatencyValuesLast60s();
    if (values.length > 0 && typeof addEventToLog === 'function') {
      const avgLatency = values.reduce((sum, v) => sum + v, 0) / values.length;
      const maxLatency = Math.max(...values);
      addEventToLog({
        level: 'info',
        message: `📊 Load Test Stats (60s): avg ${avgLatency.toFixed(0)}ms, max ${maxLatency.toFixed(0)}ms, ${values.length} samples`
      });
    }
  }
}

/**
 * Adds a latency entry and prunes entries older than 60 seconds.
 * @param {number} latencyMs - The latency value in milliseconds
 */
function addLatencyEntry(latencyMs) {
  const now = Date.now();
  latencyStats.entries.push({ time: now, value: latencyMs });
  const cutoff = now - LATENCY_STATS_WINDOW_MS;
  latencyStats.entries = latencyStats.entries.filter(e => e.time >= cutoff);
}

/**
 * Gets all latency values from the last 60 seconds.
 * @returns {number[]} Array of latency values
 */
function getLatencyValuesLast60s() {
  const now = Date.now();
  const cutoff = now - LATENCY_STATS_WINDOW_MS;
  latencyStats.entries = latencyStats.entries.filter(e => e.time >= cutoff);
  return latencyStats.entries.map(e => e.value);
}

// Latency threshold colors for gradient fill
const LATENCY_COLORS = {
  good: { value: 0, color: 'rgba(16, 124, 16' },
  degraded: { value: 200, color: 'rgba(255, 185, 0' },
  severe: { value: 1000, color: 'rgba(255, 140, 0' },
  critical: { value: 30000, color: 'rgba(209, 52, 56' }
};

// RGB values for smooth color interpolation
const LATENCY_RGB = {
  good:     { r: 16,  g: 124, b: 16  },
  degraded: { r: 255, g: 185, b: 0   },
  severe:   { r: 255, g: 140, b: 0   },
  critical: { r: 209, g: 52,  b: 56  }
};

/**
 * Interpolates between two RGB colors.
 */
function lerpColor(color1, color2, t) {
  t = Math.max(0, Math.min(1, t));
  const r = Math.round(color1.r + (color2.r - color1.r) * t);
  const g = Math.round(color1.g + (color2.g - color1.g) * t);
  const b = Math.round(color1.b + (color2.b - color1.b) * t);
  return `rgb(${r}, ${g}, ${b})`;
}

/**
 * Gets a smoothly interpolated color for a latency value.
 */
function getInterpolatedLatencyColor(latencyMs) {
  if (latencyMs <= 0) return lerpColor(LATENCY_RGB.good, LATENCY_RGB.good, 0);
  if (latencyMs <= 200) {
    return lerpColor(LATENCY_RGB.good, LATENCY_RGB.degraded, latencyMs / 200);
  }
  if (latencyMs <= 1000) {
    return lerpColor(LATENCY_RGB.degraded, LATENCY_RGB.severe, (latencyMs - 200) / 800);
  }
  if (latencyMs <= 30000) {
    return lerpColor(LATENCY_RGB.severe, LATENCY_RGB.critical, (latencyMs - 1000) / 29000);
  }
  return lerpColor(LATENCY_RGB.critical, LATENCY_RGB.critical, 1);
}

/**
 * Gets a smoothly interpolated RGBA color for a latency value (for gradient fills).
 */
function getInterpolatedLatencyColorRGBA(latencyMs, alpha) {
  let r, g, b;
  if (latencyMs <= 0) {
    r = LATENCY_RGB.good.r; g = LATENCY_RGB.good.g; b = LATENCY_RGB.good.b;
  } else if (latencyMs <= 200) {
    const t = latencyMs / 200;
    r = Math.round(LATENCY_RGB.good.r + (LATENCY_RGB.degraded.r - LATENCY_RGB.good.r) * t);
    g = Math.round(LATENCY_RGB.good.g + (LATENCY_RGB.degraded.g - LATENCY_RGB.good.g) * t);
    b = Math.round(LATENCY_RGB.good.b + (LATENCY_RGB.degraded.b - LATENCY_RGB.good.b) * t);
  } else if (latencyMs <= 1000) {
    const t = (latencyMs - 200) / 800;
    r = Math.round(LATENCY_RGB.degraded.r + (LATENCY_RGB.severe.r - LATENCY_RGB.degraded.r) * t);
    g = Math.round(LATENCY_RGB.degraded.g + (LATENCY_RGB.severe.g - LATENCY_RGB.degraded.g) * t);
    b = Math.round(LATENCY_RGB.degraded.b + (LATENCY_RGB.severe.b - LATENCY_RGB.degraded.b) * t);
  } else if (latencyMs <= 30000) {
    const t = (latencyMs - 1000) / 29000;
    r = Math.round(LATENCY_RGB.severe.r + (LATENCY_RGB.critical.r - LATENCY_RGB.severe.r) * t);
    g = Math.round(LATENCY_RGB.severe.g + (LATENCY_RGB.critical.g - LATENCY_RGB.severe.g) * t);
    b = Math.round(LATENCY_RGB.severe.b + (LATENCY_RGB.critical.b - LATENCY_RGB.severe.b) * t);
  } else {
    r = LATENCY_RGB.critical.r; g = LATENCY_RGB.critical.g; b = LATENCY_RGB.critical.b;
  }
  return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

/**
 * Creates a vertical gradient for the latency chart with smooth color blending.
 */
function createLatencyGradient(ctx, chartArea, scales) {
  if (!chartArea || !scales.y) return 'rgba(16, 124, 16, 0.2)';
  const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
  const yMax = scales.y.max || 200;
  const numStops = 20;
  for (let i = 0; i <= numStops; i++) {
    const position = i / numStops;
    const latencyAtPosition = position * yMax;
    const alpha = 0.25 + (position * 0.25);
    const color = getInterpolatedLatencyColorRGBA(latencyAtPosition, alpha);
    gradient.addColorStop(position, color);
  }
  return gradient;
}

/**
 * Gets the border color for the latency line based on current max value.
 */
function getLatencyBorderColor(maxValue) {
  return getInterpolatedLatencyColor(maxValue);
}

// Server responsiveness tracking
const serverResponsiveness = {
  isResponsive: true,
  lastProbeTime: Date.now(),
  lastSuccessfulProbe: Date.now(),
  probeInterval: null,
  consecutiveFailures: 0,
  unresponsiveStartTime: null,
  totalUnresponsiveTime: 0,
  probeHistory: [],
  maxProbeHistory: 20,
};

function timestampToUtcTimeString(ts) {
  const d = new Date(ts);
  const hours = d.getUTCHours().toString().padStart(2, '0');
  const minutes = d.getUTCMinutes().toString().padStart(2, '0');
  const seconds = d.getUTCSeconds().toString().padStart(2, '0');
  return `${hours}:${minutes}:${seconds}`;
}

/**
 * Handles incoming probe latency data from the polling client.
 * In PHP, probes come from browser XHR to /api/metrics/probe.
 * The interface matches the Node.js sidecar data format for compatibility.
 * @param {Object} data - { latencyMs, timestamp, success, loadTestActive, loadTestConcurrent }
 */
function onProbeLatency(data) {
  const latency = data.latencyMs;

  recordProbeResult(latency, data.success !== false);

  if (data.success !== false) {
    latencyStats.current = latency;
    addLatencyEntry(latency);
    if (latency > 30000) {
      latencyStats.critical++;
    }

    addLatencyToChart(latency, data.timestamp);
    lastLatencyChartUpdate = Date.now();

    if (!serverResponsiveness.isResponsive) {
      const unresponsiveDuration = Date.now() - serverResponsiveness.unresponsiveStartTime;
      serverResponsiveness.totalUnresponsiveTime += unresponsiveDuration;

      // Only log recovery if unresponsive for at least 5s (matches warning threshold)
      if (!data.loadTestActive && unresponsiveDuration >= 5000 && typeof addEventToLog === 'function') {
        addEventToLog({
          level: 'success',
          message: `Server responsive again after ${(unresponsiveDuration / 1000).toFixed(1)}s unresponsive`
        });
      }
    }

    serverResponsiveness.isResponsive = true;
    serverResponsiveness.lastSuccessfulProbe = Date.now();
    serverResponsiveness.consecutiveFailures = 0;
    serverResponsiveness.unresponsiveStartTime = null;
  } else {
    serverResponsiveness.consecutiveFailures++;

    // Only mark as unresponsive after sustained failures (about 5 seconds at 100ms probe interval)
    if (serverResponsiveness.consecutiveFailures >= 50 && serverResponsiveness.isResponsive) {
      serverResponsiveness.isResponsive = false;
      serverResponsiveness.unresponsiveStartTime = Date.now() - 5000; // Backdate to when failures started

      const now = Date.now();
      if (!data.loadTestActive && typeof addEventToLog === 'function' &&
          (!serverResponsiveness.lastWarningTime || now - serverResponsiveness.lastWarningTime >= 10000)) {
        serverResponsiveness.lastWarningTime = now;
        addEventToLog({
          level: 'warning',
          message: '⚠️ Server unresponsive - PHP-FPM workers may be blocked'
        });
      }
    }
  }

  serverResponsiveness.lastProbeTime = Date.now();
  updateResponsivenessUI();
  updateLatencyDisplay();
  
  // Track load test activity and log periodic stats
  checkLoadTestStatsLog(data.loadTestActive, data.loadTestConcurrent);
}

/**
 * Starts the server responsiveness monitoring.
 * With AJAX polling, unresponsive detection is handled by probe results.
 */
function startHeartbeatProbe() {
  if (serverResponsiveness.probeInterval) {
    clearInterval(serverResponsiveness.probeInterval);
  }

  // Fallback: if no probe data arrives at all, detect via missing probes
  serverResponsiveness.probeInterval = setInterval(() => {
    const timeSinceLastProbe = Date.now() - serverResponsiveness.lastProbeTime;
    if (timeSinceLastProbe > 2000) {
      updateResponsivenessUI();
    }
  }, 1000);
}

/**
 * Records a probe result for visualization.
 */
function recordProbeResult(latency, success) {
  serverResponsiveness.probeHistory.push({
    time: Date.now(),
    latency,
    success
  });

  if (serverResponsiveness.probeHistory.length > serverResponsiveness.maxProbeHistory) {
    serverResponsiveness.probeHistory.shift();
  }

  updateProbeVisualization();
}

/**
 * Updates the probe visualization dots.
 */
function updateProbeVisualization() {
  const container = document.getElementById('probe-visualization');
  if (!container) return;

  const recentProbes = serverResponsiveness.probeHistory.slice(-30);

  container.innerHTML = recentProbes.map(probe => {
    let className = 'probe-dot-inline';
    if (!probe.success) {
      className += ' failed';
    } else if (probe.latency > 1000) {
      className += ' slow';
    } else if (probe.latency > 200) {
      className += ' degraded';
    }
    return `<span class="${className}"></span>`;
  }).join('');
}

/**
 * Adds a latency value to the latency chart.
 */
function addLatencyToChart(latencyMs, timestamp) {
  const label = timestamp ? timestampToUtcTimeString(timestamp) : getUtcTimeString();

  latencyChartData.labels.push(label);
  latencyChartData.values.push(latencyMs);

  if (latencyChartData.labels.length > maxLatencyDataPoints) {
    latencyChartData.labels.shift();
    latencyChartData.values.shift();
  }

  if (latencyChart) {
    latencyChart.update('none');
  }
}

/**
 * Updates the server responsiveness UI elements.
 */
function updateResponsivenessUI() {
  // Probe visualization dots show responsiveness status
}

// Update unresponsive duration display continuously when blocked
setInterval(() => {
  if (!serverResponsiveness.isResponsive) {
    updateResponsivenessUI();
  }
}, 100);

/**
 * Common chart configuration.
 */
const chartConfig = {
  animation: false,
  responsive: true,
  maintainAspectRatio: false,
  interaction: {
    mode: 'index',
    intersect: false,
  },
  plugins: {
    legend: {
      display: false,
    },
    tooltip: {
      enabled: true,
      mode: 'index',
      intersect: false,
      backgroundColor: 'rgba(50, 50, 50, 0.9)',
      titleColor: '#fff',
      bodyColor: '#fff',
      borderColor: 'rgba(255, 255, 255, 0.2)',
      borderWidth: 1,
      cornerRadius: 4,
      padding: 10,
      displayColors: true,
      titleFont: { size: 12, weight: 'bold' },
      bodyFont: { size: 11 },
      callbacks: {
        title: function(tooltipItems) {
          return tooltipItems[0]?.label || '';
        },
      },
    },
  },
  scales: {
    x: {
      display: true,
      ticks: { maxTicksLimit: 6, font: { size: 10 } },
      grid: { color: 'rgba(0,0,0,0.05)' },
    },
    y: {
      beginAtZero: true,
      ticks: { maxTicksLimit: 5, font: { size: 10 } },
      grid: { color: 'rgba(0,0,0,0.05)' },
    },
  },
  elements: {
    point: { radius: 0, hoverRadius: 0 },
    line: { tension: 0.3, borderWidth: 2 },
  },
};

/**
 * Initializes all charts.
 */
function initCharts() {
  // Combined CPU & Memory Chart
  const cpuMemoryCtx = document.getElementById('cpu-memory-chart')?.getContext('2d');
  if (cpuMemoryCtx) {
    cpuMemoryChart = new Chart(cpuMemoryCtx, {
      type: 'line',
      data: {
        labels: chartData.labels,
        datasets: [
          {
            label: 'CPU %',
            data: chartData.cpu,
            borderColor: '#0078d4',
            backgroundColor: 'rgba(0, 120, 212, 0.2)',
            fill: true,
            yAxisID: 'y',
          },
          {
            label: 'Memory MB',
            data: chartData.memory,
            borderColor: '#107c10',
            backgroundColor: 'rgba(16, 124, 16, 0.2)',
            fill: true,
            yAxisID: 'y1',
          },
        ],
      },
      options: {
        ...chartConfig,
        scales: {
          ...chartConfig.scales,
          y: {
            ...chartConfig.scales.y,
            type: 'linear',
            position: 'left',
            max: 100,
          },
          y1: {
            type: 'linear',
            position: 'right',
            beginAtZero: true,
            grid: { drawOnChartArea: false },
            ticks: { maxTicksLimit: 5, font: { size: 10 } },
          },
        },
      },
    });
  }

  // Worker Response & RSS Memory Chart
  // In PHP, this shows FPM worker response time instead of event loop lag.
  const eventloopCtx = document.getElementById('eventloop-chart')?.getContext('2d');
  if (eventloopCtx) {
    eventloopChart = new Chart(eventloopCtx, {
      type: 'line',
      data: {
        labels: chartData.labels,
        datasets: [
          {
            label: 'Workers Busy',
            data: chartData.eventloop,
            borderColor: '#8764b8',
            backgroundColor: 'rgba(135, 100, 184, 0.2)',
            fill: true,
            yAxisID: 'y',
          },
          {
            label: 'RSS (MB)',
            data: chartData.rss,
            borderColor: '#ffb900',
            backgroundColor: 'rgba(255, 185, 0, 0.2)',
            fill: true,
            yAxisID: 'y1',
          },
        ],
      },
      options: {
        ...chartConfig,
        scales: {
          ...chartConfig.scales,
          y: {
            ...chartConfig.scales.y,
            type: 'linear',
            position: 'left',
          },
          y1: {
            type: 'linear',
            position: 'right',
            beginAtZero: true,
            grid: { drawOnChartArea: false },
            ticks: { maxTicksLimit: 5, font: { size: 10 } },
          },
        },
      },
    });
  }

  // Latency Chart (uses separate data store for 60-second window)
  const latencyCtx = document.getElementById('latency-chart')?.getContext('2d');
  if (latencyCtx) {
    latencyChart = new Chart(latencyCtx, {
      type: 'line',
      data: {
        labels: latencyChartData.labels,
        datasets: [
          {
            label: 'Latency (ms)',
            data: latencyChartData.values,
            segment: {
              borderColor: (ctx) => {
                const p0 = ctx.p0.parsed?.y;
                const p1 = ctx.p1.parsed?.y;
                if (p0 == null || p1 == null) return 'rgba(0,0,0,0)';
                return getInterpolatedLatencyColor(Math.max(p0, p1));
              },
            },
            borderColor: '#107c10',
            backgroundColor: (context) => {
              const chart = context.chart;
              const { ctx, chartArea, scales } = chart;
              if (!chartArea) return 'rgba(16, 124, 16, 0.2)';
              return createLatencyGradient(ctx, chartArea, scales);
            },
            fill: true,
            pointRadius: 0,
            pointHoverRadius: 0,
            pointBackgroundColor: (context) => {
              return getInterpolatedLatencyColor(context.raw);
            },
          },
        ],
      },
      options: {
        ...chartConfig,
        scales: {
          ...chartConfig.scales,
          y: {
            ...chartConfig.scales.y,
            beginAtZero: true,
            ticks: {
              maxTicksLimit: 5,
              font: { size: 10 },
              callback: function(value) {
                if (value >= 1000) return (value / 1000).toFixed(0) + 's';
                return value + 'ms';
              }
            },
          },
        },
        plugins: {
          ...chartConfig.plugins,
          tooltip: {
            ...chartConfig.plugins.tooltip,
            callbacks: {
              label: function(context) {
                const value = context.raw;
                if (value >= 1000) return `Latency: ${(value / 1000).toFixed(1)}s`;
                return `Latency: ${value.toFixed(0)}ms`;
              }
            }
          }
        }
      },
    });
  }
}

/**
 * Updates charts with new metrics data.
 * In PHP, metrics come from polling /api/metrics rather than WebSocket.
 * The metrics shape differs slightly from Node.js:
 * - memory.usedMb instead of memory.heapUsedMb
 * - process.activeWorkers instead of eventLoop.heartbeatLagMs
 *
 * @param {Object} metrics - System metrics from server
 */
function updateCharts(metrics) {
  const now = getUtcTimeString();
  chartData.labels.push(now);
  chartData.cpu.push(metrics.cpu?.usagePercent || 0);
  chartData.memory.push(metrics.memory?.usedMb || metrics.memory?.heapUsedMb || 0);
  // PHP doesn't have event loop lag — use active worker count or 0
  chartData.eventloop.push(metrics.process?.activeWorkers || 0);
  chartData.rss.push(metrics.memory?.rssMb || 0);

  updateMetricBars(metrics);

  if (chartData.labels.length > maxDataPoints) {
    chartData.labels.shift();
    chartData.cpu.shift();
    chartData.memory.shift();
    chartData.eventloop.shift();
    chartData.rss.shift();
  }

  if (cpuMemoryChart) cpuMemoryChart.update('none');
  if (eventloopChart) eventloopChart.update('none');
  if (latencyChart) latencyChart.update('none');
}

/**
 * Updates the metric bar fills in the dashboard tiles.
 */
function updateMetricBars(metrics) {
  const cpuBar = document.getElementById('cpu-bar');
  const memoryBar = document.getElementById('memory-bar');
  const eventloopBar = document.getElementById('eventloop-bar');
  const rssBar = document.getElementById('rss-bar');

  if (cpuBar) {
    cpuBar.style.width = Math.min(100, metrics.cpu?.usagePercent || 0) + '%';
  }

  if (memoryBar) {
    const totalMb = metrics.memory?.totalSystemMb || 4096;
    const usedMb = metrics.memory?.usedMb || metrics.memory?.heapUsedMb || 0;
    memoryBar.style.width = Math.min(100, (usedMb / totalMb) * 100) + '%';
  }

  if (eventloopBar) {
    // PHP: show active workers as percentage of something
    const workers = metrics.process?.activeWorkers || 0;
    eventloopBar.style.width = Math.min(100, workers) + '%';
  }

  if (rssBar) {
    const totalMb = metrics.memory?.totalSystemMb || 4096;
    const rssMb = metrics.memory?.rssMb || 0;
    rssBar.style.width = Math.min(100, (rssMb / totalMb) * 100) + '%';
  }
}

/**
 * Formats a latency value for display (ms or seconds).
 */
function formatLatency(latencyMs) {
  if (latencyMs >= 1000) return (latencyMs / 1000).toFixed(1) + 's';
  return latencyMs.toFixed(1) + 'ms';
}

/**
 * Gets the color for a latency value based on thresholds.
 */
function getLatencyColor(latencyMs) {
  if (latencyMs >= 30000) return '#d13438';
  if (latencyMs >= 1000) return '#ff8c00';
  if (latencyMs >= 150) return '#ffb900';
  return '#17a035';
}

/**
 * Updates latency statistics display.
 */
function updateLatencyDisplay() {
  const currentEl = document.getElementById('latency-current');
  const avgEl = document.getElementById('latency-avg');
  const maxEl = document.getElementById('latency-max');
  const criticalEl = document.getElementById('latency-critical');

  const values = getLatencyValuesLast60s();

  if (currentEl) {
    currentEl.textContent = formatLatency(latencyStats.current);
    currentEl.style.color = getLatencyColor(latencyStats.current);
  }

  if (avgEl && values.length > 0) {
    const avg = values.reduce((a, b) => a + b, 0) / values.length;
    avgEl.textContent = formatLatency(avg);
    avgEl.style.color = getLatencyColor(avg);
  }

  if (maxEl && values.length > 0) {
    const max = Math.max(...values);
    maxEl.textContent = formatLatency(max);
    maxEl.style.color = getLatencyColor(max);
    if (max > 1000) maxEl.classList.add('warning');
    else maxEl.classList.remove('warning');
  }

  if (criticalEl) {
    criticalEl.textContent = latencyStats.critical.toString();
    criticalEl.style.color = latencyStats.critical > 0 ? '#d13438' : '#17a035';
  }
}

/**
 * Clears all chart data.
 * Uses in-place array clearing (.length = 0) to preserve references
 * that Chart.js holds to these arrays.
 */
function clearCharts() {
  // Clear arrays in-place to preserve Chart.js references
  chartData.labels.length = 0;
  chartData.cpu.length = 0;
  chartData.memory.length = 0;
  chartData.eventloop.length = 0;
  chartData.rss.length = 0;

  latencyChartData.labels.length = 0;
  latencyChartData.values.length = 0;

  latencyStats.entries.length = 0;
  latencyStats.current = 0;
  latencyStats.critical = 0;

  if (cpuMemoryChart) cpuMemoryChart.update();
  if (eventloopChart) eventloopChart.update();
  if (latencyChart) latencyChart.update();
}

// Expose functions globally so polling-client.js can call them
window.chartsOnProbeLatency = onProbeLatency;
window.chartsClearAll = clearCharts;

// Initialize charts when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
  initCharts();
  startHeartbeatProbe();
});
