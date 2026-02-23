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
// Internal batch probe: 1 request/sec to AppLens, server does 10 internal probes at 100ms intervals
// Results are dispatched to chart at 100ms intervals for smooth visualization
// PROBE_POLL_INTERVAL is 0 because server response time (~1s) provides natural pacing
const PROBE_POLL_INTERVAL = 0;
const INTERNAL_PROBE_COUNT = 10;
const INTERNAL_PROBE_INTERVAL = 100;

// Timeouts for fetch requests (prevents UI freeze during load testing)
const METRICS_TIMEOUT_MS = 5000;
const PROBE_TIMEOUT_MS = 15000;
const EVENTS_TIMEOUT_MS = 5000;

// Polling timer IDs
let metricsPollTimer = null;
let eventsPollTimer = null;
let probePollTimer = null;

// Track last event count to detect new events
let lastEventCount = 0;
let lastEventSequence = 0;  // Monotonic sequence for reliable change detection

// Track consecutive failures for connection status
let consecutiveFailures = 0;
const MAX_CONSECUTIVE_FAILURES = 10;

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
 * Tests connectivity first, then starts polling loops.
 */
function initSocket() {
  const statusEl = document.getElementById('connection-status');
  if (statusEl) {
    statusEl.textContent = 'Connecting...';
    statusEl.className = 'status-reconnecting';
  }

  // Test connectivity with a health check
  fetch('/api/health')
    .then(response => {
      if (response.ok) {
        onConnected();
      } else {
        onConnectionFailed();
      }
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
  consecutiveFailures = 0;

  const statusEl = document.getElementById('connection-status');
  if (statusEl) {
    statusEl.textContent = 'Connected';
    statusEl.className = 'status-connected';
  }

  // Start polling loops
  startMetricsPolling();
  startEventsPolling();
  startProbePolling();

  // Add initialization events to the log
  if (typeof addEventToLog === 'function') {
    addEventToLog({ level: 'info', message: 'Dashboard initialized' });
    addEventToLog({ level: 'success', message: 'Server responding' });
  }

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
 * Handles a polling failure. Updates connection status after consecutive failures.
 */
function onPollFailure() {
  consecutiveFailures++;
  if (consecutiveFailures >= MAX_CONSECUTIVE_FAILURES && isConnected) {
    isConnected = false;
    const statusEl = document.getElementById('connection-status');
    if (statusEl) {
      statusEl.textContent = 'Not Responding';
      statusEl.className = 'status-disconnected';
    }

    if (typeof addEventToLog === 'function') {
      addEventToLog({ level: 'warning', message: 'Server not responding...' });
    }

    // Stop polling and attempt reconnection
    stopAllPolling();
    reconnectAttempts = 0;
    setTimeout(initSocket, 1000);
  }
}

/**
 * Handles a polling success. Resets failure tracking and updates status.
 */
function onPollSuccess() {
  if (consecutiveFailures > 0) {
    consecutiveFailures = 0;
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
 */
function pollMetricsOnce() {
  fetchWithTimeout('/api/metrics', { cache: 'no-store' }, METRICS_TIMEOUT_MS)
    .then(response => {
      if (!response.ok) throw new Error('Metrics fetch failed');
      return response.json();
    })
    .then(metrics => {
      onPollSuccess();
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
      
      // Clear event log state (both JS state and DOM) to start fresh
      if (typeof window.clearEventLog === 'function') {
        window.clearEventLog();
      }
      
      // Add initial connection events AFTER clearing
      // These show the user that background monitoring is active
      if (typeof addEventToLog === 'function') {
        addEventToLog({ level: 'info', message: 'Dashboard initialized' });
        addEventToLog({ level: 'success', message: 'Connected to metrics hub' });
      }
    })
    .catch((error) => {
      console.error('[polling-client] Event log init failed:', error.message);
    });
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

/**
 * Starts probe polling every 100ms via direct frontend requests.
 * Measures full round-trip latency including Azure Front Door and stamp frontend.
 * Uses a flag to prevent pile-up if requests take longer than 100ms.
 */
function startProbePolling() {
  if (probePollTimer) clearInterval(probePollTimer);
  
  probeInFlight = false;
  probeOnce();
  probePollTimer = setInterval(probeOnce, INTERNAL_PROBE_INTERVAL);
}

/**
 * Performs a single probe through the stamp frontend.
 * Uses lightweight health probe for accurate latency measurement.
 * Skips if a previous probe is still in flight to prevent pile-up.
 */
function probeOnce() {
  // Skip if previous request hasn't completed
  if (probeInFlight) {
    return;
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
      onPollSuccess();

      if (typeof onProbeLatency === 'function') {
        onProbeLatency({
          latencyMs: latency,
          timestamp: Date.now(),
          success: true,
          loadTestActive: false,
          loadTestConcurrent: 0,
        });
      }
    })
    .catch(error => {
      console.error('[polling-client] Probe failed:', error.message || error);
      if (typeof onProbeLatency === 'function') {
        onProbeLatency({
          latencyMs: 0,
          timestamp: Date.now(),
          success: false,
          loadTestActive: false,
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
