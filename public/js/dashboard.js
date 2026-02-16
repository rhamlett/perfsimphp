/**
 * =============================================================================
 * DASHBOARD.JS — Main Dashboard Controller
 * =============================================================================
 *
 * PURPOSE:
 *   Handles all dashboard UI interactions, form submissions, event log,
 *   simulation status updates, and server information display.
 *
 * CONTROL SECTIONS:
 *   1. CPU Stress — Generates CPU load via background PHP processes
 *   2. Memory Pressure — Allocates large data structures in shared storage
 *   3. Request Thread Blocking — Blocks PHP-FPM workers with CPU-intensive ops
 *      (In Node.js: "Event Loop Blocking". PHP has no event loop, so blocking
 *       a PHP-FPM worker thread is the equivalent simulation.)
 *   4. Slow Requests — Creates requests that take a long time to complete
 *      Patterns: sleep (PHP sleep()), cpu_intensive (hash_pbkdf2),
 *                file_io (intensive file read/write)
 *      (In Node.js: setTimeout, libuv, worker)
 *   5. Crash Simulator — Triggers various crash types for recovery testing
 *      Types: failfast (exit(1)), stackoverflow (infinite recursion),
 *             exception (trigger_error), oom (memory exhaustion)
 *
 * DATA FLOW:
 *   - polling-client.js → onMetricsUpdate() → updateCharts() + updateDashboard()
 *   - polling-client.js → onEventUpdate() → renderEventLog()
 *   - polling-client.js → onSimulationUpdate() → updateActiveSimulations()
 *   - polling-client.js → onProbeLatency() → onProbeLatency() in charts.js
 *
 * FORM SUBMISSION:
 *   All forms POST JSON to /api/simulations/* endpoints.
 *   Server validates and returns { success, message } or { error }.
 *
 * PORTING NOTES:
 *   This file is FRONTEND JavaScript — it runs in the browser.
 *   When porting the backend to another language:
 *   - Update simulation names and descriptions to match the target runtime
 *   - Update crash types to match available crash mechanisms
 *   - Update slow request patterns to match the target runtime's I/O model
 *   - Update environment info fields for the target runtime
 */

// Current server PID for restart detection
let currentServerPid = null;

// Event log state
let eventLog = [];
let seenEventIds = new Set(); // Track event IDs to prevent duplicates
const MAX_EVENT_LOG_ENTRIES = 100;

// Active simulations tracking
let activeSimulations = {};
let lastSimulationsJson = ''; // Track last state to avoid unnecessary re-renders

/**
 * Initializes the polling client callbacks.
 * Called on page load to wire up data flow from polling-client.js.
 */
function initDashboard() {
  // Note: Event log clearing is handled by loadExistingEvents() in polling-client.js
  // to avoid race conditions between the two DOMContentLoaded handlers

  // Register callbacks with polling client
  if (typeof onSocketConnected !== 'undefined') {
    // polling-client.js exposes these as global callback setters
  }

  // Set up polling client callbacks
  window.onMetricsUpdate = function(metrics) {
    updateDashboard(metrics);
    if (typeof updateCharts === 'function') {
      updateCharts(metrics);
    }
  };

  window.onEventUpdate = function(eventOrEvents) {
    // Polling client may pass single event or array
    // Handle both cases by adding server events to the log
    const events = Array.isArray(eventOrEvents) ? eventOrEvents : [eventOrEvents];
    for (const e of events) {
      // Skip if we've already seen this event (by ID)
      if (e.id && seenEventIds.has(e.id)) {
        continue;
      }
      if (e.id) {
        seenEventIds.add(e.id);
      }
      addEventToLog({
        id: e.id,
        level: e.level || 'info',
        message: e.message,
        timestamp: e.timestamp,
        source: 'server',
      });
    }
  };

  window.onSimulationUpdate = function(simulations) {
    updateActiveSimulations(simulations);
  };

  window.onProbeLatency = function(data) {
    // Call charts.js probe handler if available
    if (typeof window.chartsOnProbeLatency === 'function') {
      window.chartsOnProbeLatency(data);
    }
  };

  // Load server info
  loadEnvironmentInfo();
  loadBuildInfo();

  // Start fallback polling if polling client isn't active
  setTimeout(() => {
    if (!window.pollingClientActive) {
      startFallbackPolling();
    }
  }, 3000);
}

/**
 * Updates dashboard text elements with current metrics data.
 *
 * @param {Object} metrics - Metrics data from server
 *   Expected shape: {
 *     cpu: { usagePercent },
 *     memory: { usedMb, rssMb, totalSystemMb },
 *     process: { pid, uptime, activeWorkers },
 *     simulations: { ... }
 *   }
 */
function updateDashboard(metrics) {
  // CPU value
  const cpuValue = document.getElementById('cpu-value');
  if (cpuValue) {
    cpuValue.textContent = (metrics.cpu?.usagePercent || 0).toFixed(1) + '%';
  }

  // Memory value
  const memoryValue = document.getElementById('memory-value');
  if (memoryValue) {
    memoryValue.textContent = (metrics.memory?.usedMb || 0).toFixed(0) + ' MB';
  }

  // Worker/Eventloop value (PHP: active workers)
  const eventloopValue = document.getElementById('eventloop-value');
  if (eventloopValue) {
    const workers = metrics.process?.activeWorkers || 0;
    eventloopValue.textContent = workers + ' busy';
  }

  // RSS value
  const rssValue = document.getElementById('rss-value');
  if (rssValue) {
    rssValue.textContent = (metrics.memory?.rssMb || 0).toFixed(0) + ' MB';
  }

  // Server connection status
  updateConnectionStatus(true);

  // NOTE: In PHP-FPM, each request may be handled by a different worker from the pool,
  // so PID changes are normal pool rotation, not restarts. We track the FPM master PID
  // if available, but don't log worker PID changes as "restarts" since that would spam
  // the log during normal operation.

  // Update uptime display
  const uptimeEl = document.getElementById('server-uptime');
  if (uptimeEl && metrics.process?.uptime) {
    uptimeEl.textContent = formatUptime(metrics.process.uptime);
  }

  // Update active simulations from metrics
  if (metrics.simulations) {
    updateActiveSimulations(metrics.simulations);
  }
}

/**
 * Updates connection status indicator.
 */
function updateConnectionStatus(connected) {
  const statusDot = document.getElementById('connection-status-dot');
  const statusText = document.getElementById('connection-status-text');

  if (statusDot) {
    statusDot.className = 'status-dot ' + (connected ? 'connected' : 'disconnected');
  }
  if (statusText) {
    statusText.textContent = connected ? 'Connected' : 'Disconnected';
  }
}

/**
 * Formats uptime in seconds to a human-readable string.
 */
function formatUptime(seconds) {
  const days = Math.floor(seconds / 86400);
  const hours = Math.floor((seconds % 86400) / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const secs = Math.floor(seconds % 60);

  const parts = [];
  if (days > 0) parts.push(days + 'd');
  if (hours > 0) parts.push(hours + 'h');
  if (minutes > 0) parts.push(minutes + 'm');
  parts.push(secs + 's');
  return parts.join(' ');
}

// =========================================================================
// EVENT LOG
// =========================================================================

/**
 * Clears the event log state and DOM.
 * Called on page refresh before loading existing events.
 * Exposed globally for polling-client.js to call.
 */
function clearEventLog() {
  eventLog = [];
  seenEventIds.clear();
  const container = document.getElementById('event-log');
  if (container) {
    container.innerHTML = '';
  }
}
// Expose globally for polling-client.js
window.clearEventLog = clearEventLog;

/**
 * Adds an event to the local log and renders it.
 * Used for client-side events (connection changes, restarts, etc.)
 * and server events received via polling.
 *
 * @param {Object} event - { level: 'info'|'warning'|'error'|'success', message: string, timestamp?: string, source?: string }
 */
function addEventToLog(event) {
  const entry = {
    timestamp: event.timestamp || new Date().toISOString(),
    level: event.level || 'info',
    message: event.message,
    source: event.source || 'client',
  };

  eventLog.unshift(entry);
  if (eventLog.length > MAX_EVENT_LOG_ENTRIES) {
    eventLog = eventLog.slice(0, MAX_EVENT_LOG_ENTRIES);
  }

  renderLocalEventLog();
}

/**
 * Renders server-sent events from the polling endpoint.
 * Called when new events arrive from the server.
 *
 * @param {Array} events - Array of event objects from server
 */
function renderEventLog(events) {
  const container = document.getElementById('event-log');
  if (!container) return;

  if (!events || events.length === 0) {
    if (eventLog.length === 0) {
      container.innerHTML = '<div class="event-log-empty">No events yet. Start a simulation to see events here.</div>';
    }
    return;
  }

  // Merge server events with local events, sort by time
  const serverEvents = events.map(e => ({
    timestamp: e.timestamp,
    level: e.level || 'info',
    message: e.message,
    source: 'server',
  }));

  // Combine and sort descending by timestamp
  const allEvents = [...serverEvents, ...eventLog.filter(e => e.source === 'client')];
  allEvents.sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp));

  // Take latest MAX entries
  const displayEvents = allEvents.slice(0, MAX_EVENT_LOG_ENTRIES);

  container.innerHTML = displayEvents.map(event => {
    const time = formatEventTime(event.timestamp);
    const levelClass = event.level || 'info';
    const levelIcon = getEventLevelIcon(event.level);

    return `<div class="event-log-entry ${levelClass}">
      <span class="event-time">${time}</span>
      <span class="event-icon">${levelIcon}</span>
      <span class="event-message">${escapeHtml(event.message)}</span>
    </div>`;
  }).join('');
}

/**
 * Renders only local (client-side) events.
 */
function renderLocalEventLog() {
  const container = document.getElementById('event-log');
  if (!container) return;

  if (eventLog.length === 0) return;

  // Prepend new events
  const latestEvent = eventLog[0];
  const time = formatEventTime(latestEvent.timestamp);
  const levelClass = latestEvent.level || 'info';
  const levelIcon = getEventLevelIcon(latestEvent.level);

  const entryHtml = `<div class="event-log-entry ${levelClass}">
    <span class="event-time">${time}</span>
    <span class="event-icon">${levelIcon}</span>
    <span class="event-message">${escapeHtml(latestEvent.message)}</span>
  </div>`;

  const emptyMsg = container.querySelector('.event-log-empty');
  if (emptyMsg) emptyMsg.remove();

  container.insertAdjacentHTML('afterbegin', entryHtml);

  // Trim excess entries from DOM
  while (container.children.length > MAX_EVENT_LOG_ENTRIES) {
    container.removeChild(container.lastChild);
  }
}

/**
 * Formats event timestamp to UTC HH:MM:SS.
 */
function formatEventTime(timestamp) {
  const d = new Date(timestamp);
  const hours = d.getUTCHours().toString().padStart(2, '0');
  const minutes = d.getUTCMinutes().toString().padStart(2, '0');
  const seconds = d.getUTCSeconds().toString().padStart(2, '0');
  return `${hours}:${minutes}:${seconds} UTC`;
}

/**
 * Returns an icon for the event level.
 */
function getEventLevelIcon(level) {
  switch (level) {
    case 'error': return '❌';
    case 'warning': return '⚠️';
    case 'success': return '✅';
    case 'info':
    default: return 'ℹ️';
  }
}

/**
 * Escapes HTML to prevent XSS in event messages.
 */
function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

// =========================================================================
// ACTIVE SIMULATIONS
// =========================================================================

/**
 * Updates the active simulations display.
 *
 * @param {Object} simulations - Active simulation data from server
 *   Shape: { cpu: { active, ... }, memory: { active, ... }, ... }
 */
function updateActiveSimulations(simulations) {
  // Only update DOM if simulations have changed (prevents blinking)
  const newJson = JSON.stringify(simulations);
  if (newJson === lastSimulationsJson) {
    return; // No change, skip re-render
  }
  lastSimulationsJson = newJson;
  activeSimulations = simulations;

  const container = document.getElementById('active-simulations');
  if (!container) return;

  const activeSims = [];

  if (simulations.cpu?.active) {
    activeSims.push({
      type: 'cpu',
      label: 'CPU Stress',
      detail: `${simulations.cpu.targetLoad || 0}% load`,
      icon: '🔥',
    });
  }

  if (simulations.memory?.active) {
    activeSims.push({
      type: 'memory',
      label: 'Memory Pressure',
      detail: `${simulations.memory.allocatedMb || 0} MB allocated`,
      icon: '💾',
    });
  }

  if (simulations.blocking?.active) {
    activeSims.push({
      type: 'blocking',
      label: 'Thread Blocking',
      detail: `${simulations.blocking.duration || 0}s`,
      icon: '🔒',
    });
  }

  if (simulations.slowRequests?.active) {
    activeSims.push({
      type: 'slow',
      label: 'Slow Requests',
      detail: `${simulations.slowRequests.activeCount || 0} active`,
      icon: '🐌',
    });
  }

  if (simulations.loadTest?.active) {
    activeSims.push({
      type: 'loadtest',
      label: 'Load Test',
      detail: `${simulations.loadTest.concurrent || 0} concurrent`,
      icon: '📊',
    });
  }

  if (activeSims.length === 0) {
    container.innerHTML = '<div class="no-simulations">No active simulations</div>';
    return;
  }

  container.innerHTML = activeSims.map(sim => `
    <div class="simulation-badge ${sim.type}">
      <span class="sim-icon">${sim.icon}</span>
      <span class="sim-label">${sim.label}</span>
      <span class="sim-detail">${sim.detail}</span>
    </div>
  `).join('');
}

// =========================================================================
// SIMULATION CONTROLS — API Calls
// =========================================================================

/**
 * Starts CPU stress simulation.
 * Spawns background PHP processes that consume CPU.
 *
 * @param {number} targetLoadPercent - Target CPU load (1-100)
 * @param {number} durationSeconds - Duration in seconds (1-300)
 */
async function startCpuStress(targetLoadPercent, durationSeconds) {
  try {
    addEventToLog({ level: 'info', message: `Starting CPU stress: ${targetLoadPercent}% for ${durationSeconds}s...` });
    const response = await fetch('/api/simulations/cpu/start', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ targetLoadPercent, durationSeconds }),
    });
    const data = await response.json();
    if (response.ok) {
      addEventToLog({ level: 'success', message: data.message || `CPU stress started: ${targetLoadPercent}% for ${durationSeconds}s` });
    } else {
      addEventToLog({ level: 'error', message: `CPU stress failed: ${data.error || data.message || 'Unknown error'}` });
    }
  } catch (err) {
    addEventToLog({ level: 'error', message: `CPU stress request failed: ${err.message}` });
  }
}

/**
 * Stops CPU stress simulation by killing background processes.
 */
async function stopCpuStress() {
  try {
    const response = await fetch('/api/simulations/cpu/stop', { method: 'POST' });
    const data = await response.json();
    if (response.ok) {
      addEventToLog({ level: 'success', message: data.message || 'CPU stress stopped' });
    } else {
      addEventToLog({ level: 'error', message: `Stop CPU stress failed: ${data.error || data.message || 'Unknown error'}` });
    }
  } catch (err) {
    addEventToLog({ level: 'error', message: `Stop CPU stress request failed: ${err.message}` });
  }
}

/**
 * Starts memory pressure simulation.
 * Allocates large data structures in PHP shared storage.
 *
 * @param {number} sizeMb - Amount of memory to allocate in MB (1-2048)
 */
async function startMemoryPressure(sizeMb) {
  try {
    addEventToLog({ level: 'info', message: `Allocating ${sizeMb}MB memory...` });
    const response = await fetch('/api/simulations/memory/allocate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ sizeMb }),
    });
    const data = await response.json();
    if (response.ok) {
      addEventToLog({ level: 'success', message: data.message || `Allocated ${sizeMb}MB memory` });
    } else {
      addEventToLog({ level: 'error', message: `Memory allocation failed: ${data.error || data.message || 'Unknown error'}` });
    }
  } catch (err) {
    addEventToLog({ level: 'error', message: `Memory allocation request failed: ${err.message}` });
  }
}

/**
 * Releases all allocated memory.
 */
async function releaseMemory() {
  try {
    const response = await fetch('/api/simulations/memory/release', { method: 'POST' });
    const data = await response.json();
    if (response.ok) {
      addEventToLog({ level: 'success', message: data.message || 'Memory released' });
    } else {
      addEventToLog({ level: 'error', message: `Memory release failed: ${data.error || data.message || 'Unknown error'}` });
    }
  } catch (err) {
    addEventToLog({ level: 'error', message: `Memory release request failed: ${err.message}` });
  }
}

/**
 * Blocks PHP-FPM worker threads.
 * Unlike Node.js event loop blocking, PHP blocking only affects individual
 * FPM worker processes. Blocking enough workers exhausts the pool.
 *
 * @param {number} durationSeconds - How long to block (1-60)
 */
async function blockRequestThread(durationSeconds) {
  try {
    addEventToLog({ level: 'info', message: `Blocking request thread for ${durationSeconds}s...` });
    const response = await fetch('/api/simulations/blocking/start', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ durationSeconds }),
    });
    const data = await response.json();
    if (response.ok) {
      addEventToLog({ level: 'success', message: data.message || `Thread blocked for ${durationSeconds}s` });
    } else {
      addEventToLog({ level: 'error', message: `Thread blocking failed: ${data.error || data.message || 'Unknown error'}` });
    }
  } catch (err) {
    addEventToLog({ level: 'error', message: `Thread blocking request failed: ${err.message}` });
  }
}

/**
 * Starts slow request simulation.
 * Creates requests that take a long time to complete via different blocking patterns.
 *
 * PHP slow request patterns:
 *   - "sleep": Uses PHP sleep() — simple delay, doesn't consume CPU
 *   - "cpu_intensive": Uses hash_pbkdf2() loops — consumes CPU while delayed
 *   - "file_io": Intensive file read/write — stresses filesystem I/O
 *
 * @param {number} delaySeconds - Delay per request (1-120)
 * @param {number} intervalSeconds - Interval between requests (1-30)
 * @param {number} maxRequests - Maximum requests (1-100)
 * @param {string} blockingPattern - One of: sleep, cpu_intensive, file_io
 */
async function startSlowRequests(delaySeconds, intervalSeconds, maxRequests, blockingPattern) {
  try {
    addEventToLog({ level: 'info', message: `Starting slow request: ${delaySeconds}s delay (${blockingPattern})...` });
    const response = await fetch('/api/simulations/slow/start', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ delaySeconds, intervalSeconds, maxRequests, blockingPattern }),
    });
    const data = await response.json();
    if (response.ok) {
      addEventToLog({ level: 'success', message: data.message || `Slow request completed: ${delaySeconds}s` });
    } else {
      addEventToLog({ level: 'error', message: `Slow requests failed: ${data.error || data.message || 'Unknown error'}` });
    }
  } catch (err) {
    addEventToLog({ level: 'error', message: `Slow requests request failed: ${err.message}` });
  }
}

/**
 * Stops slow request simulation.
 */
async function stopSlowRequests() {
  try {
    const response = await fetch('/api/simulations/slow/stop', { method: 'POST' });
    const data = await response.json();
    if (response.ok) {
      addEventToLog({ level: 'success', message: data.message || 'Slow requests stopped' });
    } else {
      addEventToLog({ level: 'error', message: `Stop slow requests failed: ${data.error || data.message || 'Unknown error'}` });
    }
  } catch (err) {
    addEventToLog({ level: 'error', message: `Stop slow requests request failed: ${err.message}` });
  }
}

/**
 * Triggers a crash of the specified type.
 * In PHP, crashes affect the individual FPM worker process.
 * Uses fastcgi_finish_request() to send response before crashing.
 *
 * Crash types and their PHP implementations:
 *   - failfast: exit(1) — immediate process termination
 *   - stackoverflow: Infinite recursion — exhausts call stack
 *   - exception: trigger_error(E_USER_ERROR) — fatal error
 *   - oom: Memory exhaustion — exceeds memory_limit
 *
 * @param {string} crashType - One of: failfast, stackoverflow, exception, oom
 */
async function triggerCrash(crashType) {
  // Crash types that require Azure App Service to restart the container
  const requiresAzureRestart = ['failfast'];

  const crashDescriptions = {
    failfast: 'exit(1) — Immediate PHP-FPM worker termination',
    stackoverflow: 'Infinite recursion — Call stack exhaustion',
    exception: 'trigger_error(E_USER_ERROR) — Fatal error',
    oom: 'Memory exhaustion — Exceeds memory_limit',
  };

  const description = crashDescriptions[crashType] || crashType;

  // Confirm dangerous actions
  let message = `⚠️ This will crash the PHP-FPM worker via: ${description}\n\nContinue?`;
  if (requiresAzureRestart.includes(crashType)) {
    message = `🚨 DANGER: This will crash the PHP-FPM worker via: ${description}\n\n` +
      `On Azure App Service, this may cause the container to restart ` +
      `if PHP-FPM determines the worker pool is degraded.\n\nContinue?`;
  }

  if (!confirm(message)) return;

  const crashEndpoints = {
    failfast: '/api/simulations/crash/failfast',
    stackoverflow: '/api/simulations/crash/stackoverflow',
    exception: '/api/simulations/crash/exception',
    oom: '/api/simulations/crash/oom',
  };

  const endpoint = crashEndpoints[crashType];
  if (!endpoint) {
    addEventToLog({ level: 'error', message: `Unknown crash type: ${crashType}` });
    return;
  }

  try {
    addEventToLog({ level: 'warning', message: `Triggering crash: ${description}` });
    const response = await fetch(endpoint, { method: 'POST' });
    // Response may not arrive if the crash happens fast enough
    if (response.ok) {
      const data = await response.json();
      addEventToLog({ level: 'info', message: data.message || 'Crash triggered' });
    }
  } catch (err) {
    // Expected — the crash may kill the connection
    addEventToLog({ level: 'warning', message: `Crash request completed (connection may have been lost)` });
  }
}

// =========================================================================
// SLOW REQUEST PATTERN DESCRIPTIONS
// =========================================================================

/**
 * Returns a human-readable description of a slow request pattern.
 * Used in the UI to help users understand what each pattern does.
 *
 * @param {string} pattern - One of: sleep, cpu_intensive, file_io
 * @returns {string} Description of the pattern
 */
function getPatternDescription(pattern) {
  const descriptions = {
    sleep: '<strong>Sleep</strong> — Uses PHP sleep() to pause execution. ' +
      'The FPM worker is idle but occupied. Does not consume CPU. ' +
      'Other requests can still be served by other FPM workers.',
    cpu_intensive: '<strong>CPU Intensive</strong> — Uses hash_pbkdf2() loops to create ' +
      'a CPU-bound delay. The FPM worker is actively consuming CPU. ' +
      'Simulates computationally expensive operations like image processing.',
    file_io: '<strong>File I/O</strong> — Performs intensive file read/write operations ' +
      'to create an I/O-bound delay. Stresses the filesystem and blocks the ' +
      'FPM worker on disk operations. Simulates log processing or data import.',
  };

  return descriptions[pattern] || 'Unknown pattern';
}

// =========================================================================
// SERVER INFORMATION
// =========================================================================

/**
 * Loads environment information from the server.
 * Displays PHP version, OS, FPM config, Azure info, etc.
 */
async function loadEnvironmentInfo() {
  try {
    const response = await fetch('/api/health');
    if (response.ok) {
      const data = await response.json();

      // Update SKU badge
      const skuBadge = document.getElementById('sku-badge');
      if (skuBadge && data.environment) {
        skuBadge.textContent = 'SKU: ' + (data.environment.sku || 'Local');
      }

      const envContainer = document.getElementById('environment-info');
      if (envContainer && data.environment) {
        const env = data.environment;
        envContainer.innerHTML = `
          <div class="env-item"><span class="env-label">Runtime:</span> <span class="env-value">PHP ${env.phpVersion || '8.4'}</span></div>
          <div class="env-item"><span class="env-label">OS:</span> <span class="env-value">${env.os || 'Linux'}</span></div>
          <div class="env-item"><span class="env-label">Hostname:</span> <span class="env-value">${env.hostname || 'unknown'}</span></div>
          <div class="env-item"><span class="env-label">PID:</span> <span class="env-value">${env.pid || '-'}</span></div>
          <div class="env-item"><span class="env-label">SAPI:</span> <span class="env-value">${env.sapi || 'fpm-fcgi'}</span></div>
        `;

        // Save initial PID
        if (env.pid) {
          currentServerPid = env.pid;
        }
      }
    }
  } catch (err) {
    console.warn('Failed to load environment info:', err);
  }
}

/**
 * Loads build/version information from the server.
 */
async function loadBuildInfo() {
  try {
    const response = await fetch('/api/health');
    if (response.ok) {
      const data = await response.json();

      const buildContainer = document.getElementById('build-info');
      if (buildContainer && data.buildTimestamp) {
        buildContainer.innerHTML = `Build: ${data.buildTimestamp}`;
      }

      // Also update sidebar footer
      const sidebarFooter = document.getElementById('sidebar-footer');
      if (sidebarFooter && data.buildTimestamp) {
        sidebarFooter.textContent = `Build: ${data.buildTimestamp}`;
      }
    }
  } catch (err) {
    console.warn('Failed to load build info:', err);
  }
}

// =========================================================================
// FALLBACK POLLING
// =========================================================================

/**
 * Starts fallback polling if the main polling client isn't active.
 * This provides a safety net for metrics display if polling-client.js fails.
 */
function startFallbackPolling() {
  console.log('Starting fallback polling (polling-client may not be active)');
  pollMetrics();
}

/**
 * Polls metrics directly (fallback mode).
 */
async function pollMetrics() {
  try {
    const response = await fetch('/api/metrics');
    if (response.ok) {
      const metrics = await response.json();
      updateDashboard(metrics);
      if (typeof updateCharts === 'function') {
        updateCharts(metrics);
      }
    }
  } catch (err) {
    updateConnectionStatus(false);
  }

  // Continue polling
  setTimeout(pollMetrics, 1000);
}

// =========================================================================
// SIDE PANEL CONTROLS
// =========================================================================

/**
 * Toggles the side panel open/closed.
 */
function toggleSidePanel() {
  const panel = document.getElementById('side-panel');
  const overlay = document.getElementById('panel-overlay');

  if (panel) {
    panel.classList.toggle('open');
  }
  if (overlay) {
    overlay.classList.toggle('active');
  }
}

/**
 * Closes the side panel.
 */
function closeSidePanel() {
  const panel = document.getElementById('side-panel');
  const overlay = document.getElementById('panel-overlay');

  if (panel) panel.classList.remove('open');
  if (overlay) overlay.classList.remove('active');
}

// =========================================================================
// DOM EVENT HANDLERS
// =========================================================================

document.addEventListener('DOMContentLoaded', () => {
  // Initialize dashboard
  initDashboard();

  // Side panel toggle
  const menuBtn = document.getElementById('menu-toggle');
  if (menuBtn) {
    menuBtn.addEventListener('click', toggleSidePanel);
  }

  // Panel overlay click-to-close
  const overlay = document.getElementById('panel-overlay');
  if (overlay) {
    overlay.addEventListener('click', closeSidePanel);
  }

  // Close panel when clicking outside (also handle Escape key)
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeSidePanel();
  });

  // ---- CPU Stress Form ----
  const cpuForm = document.getElementById('cpu-form');
  if (cpuForm) {
    cpuForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const loadPercent = parseInt(document.getElementById('cpu-load')?.value || '80', 10);
      const duration = parseInt(document.getElementById('cpu-duration')?.value || '30', 10);
      startCpuStress(loadPercent, duration);
    });
  }

  const cpuStopBtn = document.getElementById('stop-cpu');
  if (cpuStopBtn) {
    cpuStopBtn.addEventListener('click', stopCpuStress);
  }

  // ---- Memory Pressure Form ----
  const memoryForm = document.getElementById('memory-form');
  if (memoryForm) {
    memoryForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const sizeMb = parseInt(document.getElementById('memory-size')?.value || '256', 10);
      startMemoryPressure(sizeMb);
    });
  }

  const releaseAllBtn = document.getElementById('release-memory');
  if (releaseAllBtn) {
    releaseAllBtn.addEventListener('click', releaseMemory);
  }

  // ---- Request Thread Blocking Form (Event Loop Blocking in Node.js) ----
  const blockingForm = document.getElementById('eventloop-form');
  if (blockingForm) {
    blockingForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const duration = parseInt(document.getElementById('blocking-duration')?.value || '5', 10);
      blockRequestThread(duration);
    });
  }

  // ---- Slow Requests Form ----
  const slowForm = document.getElementById('slow-form');
  if (slowForm) {
    slowForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const delay = parseInt(document.getElementById('slow-delay')?.value || '10', 10);
      const interval = parseInt(document.getElementById('slow-interval')?.value || '5', 10);
      const maxReqs = parseInt(document.getElementById('slow-max')?.value || '10', 10);
      const pattern = document.getElementById('slow-pattern')?.value || 'sleep';
      startSlowRequests(delay, interval, maxReqs, pattern);
    });
  }

  const stopSlowBtn = document.getElementById('stop-slow');
  if (stopSlowBtn) {
    stopSlowBtn.addEventListener('click', stopSlowRequests);
  }

  // Pattern description update
  const patternSelect = document.getElementById('slow-pattern');
  if (patternSelect) {
    patternSelect.addEventListener('change', (e) => {
      const descEl = document.getElementById('pattern-description');
      if (descEl) {
        descEl.innerHTML = getPatternDescription(e.target.value);
      }
    });
    // Initialize description
    const descEl = document.getElementById('pattern-description');
    if (descEl) {
      descEl.innerHTML = getPatternDescription(patternSelect.value);
    }
  }

  // ---- Crash Form ----
  const crashForm = document.getElementById('crash-form');
  if (crashForm) {
    crashForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const crashType = document.getElementById('crash-type')?.value || 'failfast';
      triggerCrash(crashType);
    });
  }
});
