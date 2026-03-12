/**
 * =============================================================================
 * POLLING CLIENT — AJAX Polling Connection Manager
 * =============================================================================
 *
 * FEATURE REQUIREMENTS (language-agnostic):
 *   This client module must:
 *   1. Fetch metrics from server at regular intervals (~250ms)
 *   2. Fetch event log updates at regular intervals (~2s)
 *   3. Measure request latency for responsiveness charts
 *   4. Handle connection failures with retry/backoff
 *   5. Provide callbacks for data updates to other modules
 *
 * ENDPOINTS POLLED:
 *   /api/metrics             → System metrics (CPU, memory, simulations)
 *   /api/admin/events        → Event log entries
 *   /api/metrics/internal-probe → Batch latency probing (10 samples/sec)
 *
 * CONNECTION STRATEGY:
 *   - Uses fetch() for all polling
 *   - Detects connection loss via failed requests
 *   - Auto-reconnects with exponential backoff
 *   - Tracks consecutive failures for status display
 *
 * HOW IT WORKS (this implementation):
 *   - AJAX polling because PHP-FPM doesn't support WebSocket natively
 *   - Internal batch probing: 1 request/sec, server does 10 internal probes
 *   - Results dispatched at 100ms intervals for smooth visualization
 *
 * PORTING NOTES:
 *   This file implements data fetching via polling. When the backend
 *   supports real-time push, replace with WebSocket or SSE:
 *
 *   WebSocket (Node.js, Java, .NET):
 *     const ws = new WebSocket('wss://host/ws');
 *     ws.onmessage = (event) => {
 *       const data = JSON.parse(event.data);
 *       if (data.type === 'metrics') onMetricsUpdate(data.metrics);
 *       if (data.type === 'event') onEventUpdate(data.event);
 *     };
 *
 *   Server-Sent Events (most backends):
 *     const source = new EventSource('/api/events');
 *     source.onmessage = (event) => {
 *       onMetricsUpdate(JSON.parse(event.data));
 *     };
 *
 *   Socket.IO (Node.js):
 *     const socket = io();
 *     socket.on('metrics', onMetricsUpdate);
 *     socket.on('event', onEventUpdate);
 *
 *   SignalR (.NET):
 *     const connection = new signalR.HubConnectionBuilder()
 *       .withUrl("/metricsHub").build();
 *     connection.on("ReceiveMetrics", onMetricsUpdate);
 *
 * CALLBACK INTERFACE:
 *   The following global callbacks are called by this module:
 *   - window.onMetricsUpdate(metrics) — Called with new metrics data
 *   - window.onEventUpdate(events) — Called with new event log entries
 *   - window.onSimulationUpdate(simulations) — Called with active simulations
 *   - window.onProbeLatency(data) — Called with latency probe results
 *
 *   When porting, maintain this callback interface so dashboard.js and
 *   charts.js continue to work without modification.
 */

// Connection state
let isConnected = false;
let reconnectAttempts = 0;
const maxReconnectAttempts = 10;

// Polling intervals (milliseconds)
const METRICS_POLL_INTERVAL = 250;
const EVENTS_POLL_INTERVAL = 2000;
// Chart update interval - latency chart still updates at 100ms for smoothness
// Uses interpolation when PROBE_POLL_INTERVAL is larger
const LATENCY_CHART_UPDATE_INTERVAL = 100;
// Configurable probe interval (fetched from server, default 200ms, min 100ms)
let PROBE_POLL_INTERVAL = 200;
const INTERNAL_PROBE_COUNT = 10;
const INTERNAL_PROBE_INTERVAL = 100;

// Timeouts for fetch requests (prevents UI freeze during load testing)
const METRICS_TIMEOUT_MS = 5000;
// CRITICAL: Aggressive probe timeout to prevent browser connection pool exhaustion
// When main pool is saturated, probes can wait 10+ seconds in queue, consuming
// browser connection slots and blocking metrics requests. Fail fast instead.
const PROBE_TIMEOUT_MS = 2000;
const EVENTS_TIMEOUT_MS = 5000;

// Track if load test is currently active (to skip direct probing)
let loadTestActive = false;
// Cooldown period after load testing stops before resuming direct probes (ms)
// Allows the main FPM pool queue to drain before we start probing it again
const LOAD_TEST_COOLDOWN_MS = 3000;
let loadTestEndTime = 0;

// Track probe failures - stop probing quickly when main pool is saturated
let consecutiveProbeFailures = 0;
const MAX_PROBE_FAILURES_BEFORE_PAUSE = 3; // Stop probing after 3 consecutive failures
let probePausedUntil = 0; // Timestamp when probing can resume
const PROBE_PAUSE_DURATION_MS = 10000; // Pause probing for 10 seconds after failures

// Polling timer IDs
let metricsPollTimer = null;
let eventsPollTimer = null;
let probePollTimer = null;

// Track last event count to detect new events
let lastEventCount = 0;
let lastEventSequence = 0;  // Monotonic sequence for reliable change detection

// Track connection status using time-based detection
let lastSuccessfulPollTime = Date.now();
const UNRESPONSIVE_THRESHOLD_MS = 10000;  // 10 seconds before showing "not responding"

// Track first connection to avoid clearing event log on reconnection
let isFirstConnection = true;

/**
 * Fetch with timeout using AbortController.
 * Prevents UI freeze during load testing when workers are saturated.
 * @param {string} url - URL to fetch
 * @param {object} options - Fetch options
 * @param {number} timeoutMs - Timeout in milliseconds
 * @returns {Promise<Response>}
 */
function fetchWithTimeout(url, options = {}, timeoutMs = 5000) {
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), timeoutMs);
  
  return fetch(url, { ...options, signal: controller.signal })
    .finally(() => clearTimeout(timeoutId));
}

/**
 * Initializes the polling client.
 * Tests connectivity first, fetches probe configuration, then starts polling loops.
 */
function initSocket() {
  const statusEl = document.getElementById('connection-status');
  if (statusEl) {
    statusEl.textContent = 'Connecting...';
    statusEl.className = 'status-reconnecting';
  }

  // Test connectivity with a health check and fetch probe configuration
  fetch('/api/health')
    .then(response => {
      if (!response.ok) {
        throw new Error('Health check failed');
      }
      return response.json();
    })
    .then(data => {
      // Configure probe interval from server (default 200ms, min 100ms enforced server-side)
      if (data.latencyProbeIntervalMs && typeof data.latencyProbeIntervalMs === 'number') {
        PROBE_POLL_INTERVAL = Math.max(100, data.latencyProbeIntervalMs);
        console.log('[polling-client] Probe interval configured:', PROBE_POLL_INTERVAL + 'ms');
      }
      onConnected();
    })
    .catch(() => {
      onConnectionFailed();
    });
}

/**
 * Called when initial connection succeeds.
 */
function onConnected() {
  isConnected = true;
  reconnectAttempts = 0;

  const statusEl = document.getElementById('connection-status');
  if (statusEl) {
    statusEl.textContent = 'Connected';
    statusEl.className = 'status-connected';
  }

  // Start polling loops
  startMetricsPolling();
  startEventsPolling();
  startProbePolling();

  // Note: Event log messages are handled in initializeEventLog() to differentiate
  // between first connection (page load) and reconnection after server recovery

  // Notify dashboard of connection
  if (typeof onSocketConnected === 'function') {
    onSocketConnected();
  }
}

/**
 * Called when connection attempt fails.
 */
function onConnectionFailed() {
  isConnected = false;
  reconnectAttempts++;

  const statusEl = document.getElementById('connection-status');

  if (reconnectAttempts >= maxReconnectAttempts) {
    if (statusEl) {
      statusEl.textContent = 'Connection Failed';
      statusEl.className = 'status-disconnected';
    }
    console.error('[Polling] Failed to connect after', maxReconnectAttempts, 'attempts');
    return;
  }

  if (statusEl) {
    statusEl.textContent = `Reconnecting (${reconnectAttempts}/${maxReconnectAttempts})...`;
    statusEl.className = 'status-reconnecting';
  }

  // Retry with exponential backoff (1s, 2s, 4s, max 5s)
  const delay = Math.min(1000 * Math.pow(2, reconnectAttempts - 1), 5000);
  setTimeout(initSocket, delay);
}

/**
 * Handles a polling failure. Updates connection status after sustained failures.
 * Uses time-based detection (10 seconds) instead of counting failures.
 * 
 * NOTE: This only affects metrics/events polling status display.
 * Probe failures are tracked separately and don't trigger reconnection.
 */
function onPollFailure() {
  const timeSinceSuccess = Date.now() - lastSuccessfulPollTime;
  if (timeSinceSuccess >= UNRESPONSIVE_THRESHOLD_MS && isConnected) {
    isConnected = false;
    const statusEl = document.getElementById('connection-status');
    if (statusEl) {
      statusEl.textContent = 'Not Responding';
      statusEl.className = 'status-disconnected';
    }

    if (typeof addEventToLog === 'function') {
      addEventToLog({ level: 'warning', message: 'Server not responding...' });
    }

    // Stop metrics and events polling, but NOT probe polling (it manages itself)
    // Probe polling will self-pause on failures via consecutiveProbeFailures tracking
    if (metricsPollTimer) { clearInterval(metricsPollTimer); metricsPollTimer = null; }
    if (eventsPollTimer) { clearInterval(eventsPollTimer); eventsPollTimer = null; }
    if (chartUpdateTimer) { clearInterval(chartUpdateTimer); chartUpdateTimer = null; }
    
    reconnectAttempts = 0;
    setTimeout(initSocket, 1000);
  }
}

/**
 * Handles a polling success. Resets failure tracking and updates status.
 */
function onPollSuccess() {
  lastSuccessfulPollTime = Date.now();
  if (!isConnected) {
    isConnected = true;
    const statusEl = document.getElementById('connection-status');
    if (statusEl) {
      statusEl.textContent = 'Connected';
      statusEl.className = 'status-connected';
    }
    if (typeof addEventToLog === 'function') {
      addEventToLog({ level: 'success', message: 'Server responding' });
    }
  }
}

// ============================================================================
// Metrics Polling
// ============================================================================

/**
 * Starts polling /api/metrics at the configured interval.
 */
function startMetricsPolling() {
  if (metricsPollTimer) clearInterval(metricsPollTimer);

  // Poll immediately, then at interval
  pollMetricsOnce();
  metricsPollTimer = setInterval(pollMetricsOnce, METRICS_POLL_INTERVAL);
}

/**
 * Fetches metrics once and dispatches to handlers.
 * Also updates loadTestActive flag based on recent sampled latencies.
 */
function pollMetricsOnce() {
  fetchWithTimeout('/api/metrics', { cache: 'no-store' }, METRICS_TIMEOUT_MS)
    .then(response => {
      if (!response.ok) throw new Error('Metrics fetch failed');
      return response.json();
    })
    .then(metrics => {
      onPollSuccess();
      
      // Detect load test activity from sampled latencies
      // If we have recent load test latencies, load testing is active
      const hasLatencies = metrics.loadTestLatencies && metrics.loadTestLatencies.length > 0;
      const wasActive = loadTestActive;
      
      if (hasLatencies) {
        // Load test is active
        loadTestActive = true;
        loadTestEndTime = 0;
      } else if (wasActive && loadTestEndTime === 0) {
        // Load test just stopped - start cooldown
        loadTestEndTime = Date.now();
        loadTestActive = true; // Stay in "active" mode during cooldown
      } else if (loadTestEndTime > 0) {
        // Check if cooldown has elapsed
        const cooldownElapsed = Date.now() - loadTestEndTime;
        if (cooldownElapsed >= LOAD_TEST_COOLDOWN_MS) {
          loadTestActive = false;
          loadTestEndTime = 0;
        }
      }
      
      // Log state change for debugging
      if (loadTestActive !== wasActive) {
        if (loadTestActive) {
          console.log('[polling-client] Load test ACTIVE - skipping direct probes, using sampled latencies');
        } else {
          console.log('[polling-client] Load test ENDED - resuming direct probes after cooldown');
        }
      }
      
      if (typeof onMetricsUpdate === 'function') {
        onMetricsUpdate(metrics);
      }
    })
    .catch(error => {
      // Don't log every failure to avoid console spam
      onPollFailure();
    });
}

// ============================================================================
// Events Polling
// ============================================================================

/**
 * Starts polling /api/admin/events at the configured interval.
 */
function startEventsPolling() {
  if (eventsPollTimer) clearInterval(eventsPollTimer);

  // Initialize event counter and clear log (fresh start on each page load)
  initializeEventLog();
  
  eventsPollTimer = setInterval(pollEventsOnce, EVENTS_POLL_INTERVAL);
}

/**
 * Initialize event log on page load.
 * Sets the event counter to current server count so we only show NEW events.
 * Clears the log display for a fresh start, then adds connection events.
 */
function initializeEventLog() {
  fetchWithTimeout('/api/admin/events?limit=50', { cache: 'no-store' }, EVENTS_TIMEOUT_MS)
    .then(response => {
      if (!response.ok) throw new Error('Events fetch failed');
      return response.json();
    })
    .then(data => {
      // Use sequence number for change detection (survives ring buffer eviction)
      lastEventSequence = data.sequence || 0;
      lastEventCount = data.total || data.count || (data.events || []).length;
      
      // Only clear event log on true page load, not on reconnection
      // This preserves test results when server temporarily becomes unresponsive
      if (isFirstConnection) {
        // Clear event log state (both JS state and DOM) to start fresh
        if (typeof window.clearEventLog === 'function') {
          window.clearEventLog();
        }
        
        // Add initial connection events AFTER clearing
        // These show the user that background monitoring is active
        if (typeof addEventToLog === 'function') {
          addEventToLog({ level: 'info', message: 'Dashboard initialized' });
          addEventToLog({ level: 'success', message: 'Connected to metrics hub' });
          
          // Add environment/SKU message
          addEnvironmentMessage();
        }
        
        isFirstConnection = false;
      } else {
        // On reconnection, just log that we've reconnected
        if (typeof addEventToLog === 'function') {
          addEventToLog({ level: 'success', message: 'Reconnected to metrics hub' });
        }
      }
    })
    .catch((error) => {
      console.error('[polling-client] Event log init failed:', error.message);
    });
}

/**
 * Adds the environment/SKU startup message to the event log.
 * Uses cached environment data if available, otherwise fetches it.
 */
function addEnvironmentMessage() {
  if (window.cachedEnvironment) {
    logEnvironmentMessage(window.cachedEnvironment);
  } else {
    // Fetch environment data if not cached yet
    fetch('/api/health')
      .then(r => r.json())
      .then(data => {
        if (data.environment) {
          window.cachedEnvironment = data.environment;
          logEnvironmentMessage(data.environment);
        }
      })
      .catch(() => {});
  }
}

/**
 * Logs the environment message with SKU and worker info.
 */
function logEnvironmentMessage(env) {
  if (typeof addEventToLog !== 'function') return;
  const sku = env.sku || 'Local';
  const hostname = env.hostname;
  if (sku === 'Local' || !hostname) {
    addEventToLog({ level: 'info', message: 'Application is currently running on Local' });
  } else {
    addEventToLog({ level: 'info', message: `Application is currently running on ${sku} SKU on worker ${hostname}` });
  }
}

/**
 * Fetches events and dispatches new ones to handlers.
 */
function pollEventsOnce() {
  fetchWithTimeout('/api/admin/events?limit=20', { cache: 'no-store' }, EVENTS_TIMEOUT_MS)
    .then(response => {
      if (!response.ok) throw new Error('Events fetch failed');
      return response.json();
    })
    .then(data => {
      onPollSuccess();
      const events = data.events || [];
      // Use sequence number for reliable change detection (survives ring buffer eviction)
      const newSequence = data.sequence || 0;
      const newTotal = data.total || data.count || events.length;

      // Detect new events using monotonic sequence number
      if (newSequence > lastEventSequence && lastEventSequence > 0) {
        // Calculate how many new events arrived
        const newEventsCount = newSequence - lastEventSequence;
        // Events are newest-first from the API, so take the first N (but no more than available)
        const eventsToShow = events.slice(0, Math.min(newEventsCount, events.length));
        // Dispatch in chronological order (reverse since API returns newest-first)
        for (let i = eventsToShow.length - 1; i >= 0; i--) {
          if (typeof onEventUpdate === 'function') {
            onEventUpdate(eventsToShow[i]);
          }
        }
      } else if (newSequence > 0 && lastEventSequence === 0) {
        // Edge case: first poll after init with no prior events - show recent events
        const recentEvents = events.slice(0, 5);
        for (let i = recentEvents.length - 1; i >= 0; i--) {
          if (typeof onEventUpdate === 'function') {
            onEventUpdate(recentEvents[i]);
          }
        }
      }
      lastEventSequence = newSequence;
      lastEventCount = newTotal;
    })
    .catch((error) => {
      console.warn('[polling-client] Events poll failed:', error.message);
    });
}

// ============================================================================
// Latency Probe Polling
// ============================================================================

// Flag to prevent overlapping probes
let probeInFlight = false;
// Track last successful probe for interpolation
let lastProbeData = null;
let lastProbeTimestamp = 0;
// Timer for chart updates (runs at 100ms regardless of probe rate)
let chartUpdateTimer = null;

/**
 * Starts probe polling at the configured interval (default 200ms).
 * Also starts a separate chart update timer at 100ms for smooth visualization.
 * Uses interpolation to maintain chart smoothness when probe rate is slower.
 * 
 * IMPORTANT: During load testing, direct probing is SKIPPED to prevent browser
 * connection pool exhaustion. Load test latencies are sampled server-side and
 * delivered via the /api/metrics response instead.
 */
function startProbePolling() {
  if (probePollTimer) clearInterval(probePollTimer);
  if (chartUpdateTimer) clearInterval(chartUpdateTimer);
  
  probeInFlight = false;
  lastProbeData = null;
  lastProbeTimestamp = 0;
  
  // Start actual probing at configured interval (default 200ms)
  probeOnce();
  probePollTimer = setInterval(probeOnce, PROBE_POLL_INTERVAL);
  
  // Start chart updates at 100ms for smooth visualization
  // This dispatches interpolated/repeated values when probe rate is slower
  chartUpdateTimer = setInterval(dispatchChartUpdate, LATENCY_CHART_UPDATE_INTERVAL);
}

/**
 * Dispatches chart updates at 100ms intervals.
 * Uses interpolation when the probe rate is slower than chart update rate.
 * Simply repeats the last known value to maintain chart progression.
 */
function dispatchChartUpdate() {
  // Skip if no probe data available yet
  if (!lastProbeData) return;
  
  // Skip chart updates during load test (metrics provide the data)
  if (loadTestActive) return;
  
  const now = Date.now();
  const timeSinceLastProbe = now - lastProbeTimestamp;
  
  // If probe rate is faster than or equal to chart update rate, 
  // probeOnce already dispatches via onProbeLatency, no interpolation needed
  if (PROBE_POLL_INTERVAL <= LATENCY_CHART_UPDATE_INTERVAL) return;
  
  // Interpolation: dispatch the last known value at chart update intervals
  // This fills in the gaps between slower probe measurements
  // Only dispatch if we haven't received a fresh probe recently (within last 50ms)
  // to avoid double-dispatching when probe and chart timer align
  if (timeSinceLastProbe > 50 && timeSinceLastProbe < PROBE_POLL_INTERVAL + 100) {
    if (typeof onProbeLatency === 'function') {
      onProbeLatency({
        latencyMs: lastProbeData.latencyMs,
        timestamp: now,
        success: true,
        loadTestActive: false,
        loadTestConcurrent: 0,
        source: 'interpolated',
      });
    }
  }
}

/**
 * Performs a single probe through the stamp frontend.
 * Uses lightweight health probe for accurate latency measurement.
 * Skips if a previous probe is still in flight to prevent pile-up.
 * 
 * LOAD TEST BEHAVIOR:
 * When load testing is active, direct probing is SKIPPED because:
 * 1. The main FPM pool is saturated - probes would wait 10+ seconds
 * 2. Waiting requests consume browser connection slots (limit ~6)
 * 3. This blocks /api/metrics requests, freezing the dashboard
 * 
 * Instead, load test latencies are sampled server-side (1 in 10 requests)
 * and delivered via /api/metrics response. This keeps the dashboard responsive.
 * 
 * FAILURE HANDLING:
 * After 3 consecutive probe failures, probing pauses for 10 seconds.
 * This prevents connection pool exhaustion when the main pool is overloaded.
 */
function probeOnce() {
  // Skip if previous request hasn't completed
  if (probeInFlight) {
    return;
  }
  
  // Skip direct probing during load tests - latencies come via /api/metrics
  // This prevents browser connection pool exhaustion
  if (loadTestActive) {
    return;
  }
  
  // Check if probing is paused due to failures
  if (probePausedUntil > 0) {
    if (Date.now() < probePausedUntil) {
      return; // Still paused
    }
    // Pause expired - reset and try again
    probePausedUntil = 0;
    consecutiveProbeFailures = 0;
    console.log('[polling-client] Probe pause expired, resuming direct probes');
  }
  
  probeInFlight = true;
  
  const probeStart = Date.now();
  const probeUrl = '/api/health/probe?t=' + probeStart;

  fetchWithTimeout(probeUrl, { 
    method: 'GET',
    headers: { 'Accept': 'application/json' },
  }, PROBE_TIMEOUT_MS)
    .then(response => {
      const latency = Date.now() - probeStart;
      if (!response.ok) {
        throw new Error('HTTP ' + response.status);
      }
      return response.json().then(data => ({ data, latency }));
    })
    .then(({ data, latency }) => {
      // Reset failure counter on success
      consecutiveProbeFailures = 0;
      onPollSuccess();

      // Save probe data for interpolation
      lastProbeData = { latencyMs: latency };
      lastProbeTimestamp = Date.now();

      if (typeof onProbeLatency === 'function') {
        onProbeLatency({
          latencyMs: latency,
          timestamp: Date.now(),
          success: true,
          loadTestActive: false,
          loadTestConcurrent: 0,
          source: 'direct-probe',
        });
      }
    })
    .catch(error => {
      // Track consecutive failures
      consecutiveProbeFailures++;
      
      // After too many failures, pause probing to free up connections
      if (consecutiveProbeFailures >= MAX_PROBE_FAILURES_BEFORE_PAUSE) {
        probePausedUntil = Date.now() + PROBE_PAUSE_DURATION_MS;
        console.log('[polling-client] Probe failures detected (' + consecutiveProbeFailures + '), pausing direct probes for ' + (PROBE_PAUSE_DURATION_MS/1000) + 's');
      }
      
      if (typeof onProbeLatency === 'function') {
        onProbeLatency({
          latencyMs: 0,
          timestamp: Date.now(),
          success: false,
          loadTestActive: loadTestActive,
          loadTestConcurrent: 0,
        });
      }
    })
    .finally(() => {
      probeInFlight = false;
    });
}

// ============================================================================
// Utilities
// ============================================================================

/**
 * Stops all polling loops.
 */
function stopAllPolling() {
  if (metricsPollTimer) { clearInterval(metricsPollTimer); metricsPollTimer = null; }
  if (eventsPollTimer) { clearInterval(eventsPollTimer); eventsPollTimer = null; }
  if (probePollTimer) { clearInterval(probePollTimer); probePollTimer = null; }
  if (chartUpdateTimer) { clearInterval(chartUpdateTimer); chartUpdateTimer = null; }
}

/**
 * Gets the current connection status.
 * @returns {boolean} True if connected
 */
function isSocketConnected() {
  return isConnected;
}

/**
 * Gets a placeholder socket object (compatibility shim for dashboard.js).
 * @returns {null} No socket in polling mode
 */
function getSocket() {
  return null;
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', initSocket);

// Note: Charts persist across tab visibility changes. They reset only on page load/reload
// (via initCharts() in charts.js). Polling continues regardless of visibility state since
// browsers may throttle timers but don't stop them entirely.
