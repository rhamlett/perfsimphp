/**
 * =============================================================================
 * POLLING CLIENT — AJAX Polling Connection Manager (replaces Socket.IO)
 * =============================================================================
 *
 * PURPOSE:
 *   Manages real-time data updates from the PHP backend via AJAX polling.
 *   PHP-FPM does not support persistent WebSocket connections natively,
 *   so this client polls REST endpoints at regular intervals:
 *   - /api/metrics        → System metrics updates (~500ms)
 *   - /api/admin/events   → Event log entries (~2s)
 *   - /api/metrics/probe  → Latency measurement via XHR timing (~100ms)
 *
 * SCRIPT LOADING ORDER:
 *   This file must be loaded BEFORE dashboard.js and charts.js in index.html.
 *   It defines callback hooks (onSocketConnected, onMetricsUpdate, etc.) that
 *   those files implement. This is a simple dependency injection via globals.
 *
 * CONNECTION STRATEGY:
 *   - Uses XMLHttpRequest for latency probes (precise timing)
 *   - Uses fetch() for metrics and events (cleaner API)
 *   - Detects connection loss via failed requests
 *   - Auto-reconnects by resuming polling after failures
 *
 * PORTING NOTES:
 *   This file replaces socket-client.js from PerfSimNode. The callback
 *   interface (onSocketConnected, onMetricsUpdate, onEventUpdate, etc.)
 *   is preserved so dashboard.js and charts.js work without changes.
 *   When porting to a backend that supports WebSockets (Java, .NET, Python),
 *   replace this file with a WebSocket/SSE client implementation.
 */

// Connection state
let isConnected = false;
let reconnectAttempts = 0;
const maxReconnectAttempts = 10;

// Polling intervals (milliseconds)
const METRICS_POLL_INTERVAL = 250;
const EVENTS_POLL_INTERVAL = 2000;
const PROBE_POLL_INTERVAL = 100;

// Polling timer IDs
let metricsPollTimer = null;
let eventsPollTimer = null;
let probePollTimer = null;

// Track last event count to detect new events
let lastEventCount = 0;

// Track consecutive failures for connection status
let consecutiveFailures = 0;
const MAX_CONSECUTIVE_FAILURES = 3;

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
  console.log('[Polling] Connected to server');

  // Start polling loops
  startMetricsPolling();
  startEventsPolling();
  startProbePolling();

  // Add initialization events to the log
  if (typeof addEventToLog === 'function') {
    addEventToLog({ level: 'info', message: 'Dashboard initialized' });
    addEventToLog({ level: 'success', message: 'Connected to metrics hub' });
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
      statusEl.textContent = 'Disconnected';
      statusEl.className = 'status-disconnected';
    }

    if (typeof addEventToLog === 'function') {
      addEventToLog({ level: 'warning', message: 'Connection lost. Attempting to reconnect...' });
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
        addEventToLog({ level: 'success', message: 'Reconnected to server' });
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
  fetch('/api/metrics', { cache: 'no-store' })
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
  fetch('/api/admin/events?limit=50', { cache: 'no-store' })
    .then(response => {
      if (!response.ok) throw new Error('Events fetch failed');
      return response.json();
    })
    .then(data => {
      // Initialize lastEventCount to current server count
      // This marks our "starting point" - we'll only show events after this
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
    .catch(() => {
      // Silent failure for initial event load
    });
}

/**
 * Fetches events and dispatches new ones to handlers.
 */
function pollEventsOnce() {
  fetch('/api/admin/events?limit=20', { cache: 'no-store' })
    .then(response => {
      if (!response.ok) throw new Error('Events fetch failed');
      return response.json();
    })
    .then(data => {
      onPollSuccess();
      const events = data.events || [];
      // Use total event count (not response count) to detect new events
      const newTotal = data.total || data.count || events.length;

      // Only process if there are new events since we last checked
      if (newTotal > lastEventCount && lastEventCount > 0) {
        // Calculate how many new events arrived
        const newEventsCount = newTotal - lastEventCount;
        // Events are newest-first from the API, so take the first N
        const newEvents = events.slice(0, Math.min(newEventsCount, events.length));
        // Dispatch in chronological order (reverse since API returns newest-first)
        for (let i = newEvents.length - 1; i >= 0; i--) {
          if (typeof onEventUpdate === 'function') {
            onEventUpdate(newEvents[i]);
          }
        }
      }
      lastEventCount = newTotal;
    })
    .catch(() => {
      // Silent failure for events polling
    });
}

// ============================================================================
// Latency Probe Polling
// ============================================================================

/**
 * Starts probing /api/metrics/probe at the configured interval.
 * Uses XMLHttpRequest for precise timing measurement.
 */
function startProbePolling() {
  if (probePollTimer) clearInterval(probePollTimer);

  probePollTimer = setInterval(probeOnce, PROBE_POLL_INTERVAL);
}

/**
 * Sends a single latency probe using XMLHttpRequest for timing accuracy.
 */
function probeOnce() {
  const startTime = performance.now();

  const xhr = new XMLHttpRequest();
  xhr.open('GET', '/api/metrics/probe?t=' + Date.now(), true);
  xhr.timeout = 5000; // 5 second timeout

  xhr.onload = function () {
    const endTime = performance.now();
    const latencyMs = endTime - startTime;

    onPollSuccess();

    // Parse response for load test status
    let loadTestActive = false;
    let loadTestConcurrent = 0;
    try {
      const data = JSON.parse(xhr.responseText);
      loadTestActive = data.loadTestActive || false;
      loadTestConcurrent = data.loadTestConcurrent || 0;
    } catch (e) {
      // Ignore parse errors
    }

    // Dispatch to probe handler (same interface as sidecar in Node.js)
    if (typeof onProbeLatency === 'function') {
      onProbeLatency({
        latencyMs: latencyMs,
        timestamp: Date.now(),
        success: true,
        loadTestActive: loadTestActive,
        loadTestConcurrent: loadTestConcurrent,
      });
    }
  };

  xhr.onerror = function () {
    if (typeof onProbeLatency === 'function') {
      onProbeLatency({
        latencyMs: 0,
        timestamp: Date.now(),
        success: false,
        loadTestActive: false,
        loadTestConcurrent: 0,
      });
    }
  };

  xhr.ontimeout = function () {
    if (typeof onProbeLatency === 'function') {
      onProbeLatency({
        latencyMs: 5000,
        timestamp: Date.now(),
        success: false,
        loadTestActive: false,
        loadTestConcurrent: 0,
      });
    }
  };

  xhr.send();
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

// Track when tab was last hidden to detect stale data
let tabHiddenAt = null;
const STALE_THRESHOLD_MS = 5000; // Data older than 5s is stale

/**
 * Handles visibility change events.
 * When returning to a backgrounded tab, clears stale data and resumes fresh.
 */
function handleVisibilityChange() {
  if (document.hidden) {
    // Tab is being hidden - record the time
    tabHiddenAt = Date.now();
  } else {
    // Tab is becoming visible again
    if (tabHiddenAt && (Date.now() - tabHiddenAt) > STALE_THRESHOLD_MS) {
      // Was hidden long enough that data is stale - reset everything
      console.log('[polling-client] Tab was backgrounded, clearing stale chart data');
      
      // Clear chart data
      if (typeof window.chartsClearAll === 'function') {
        window.chartsClearAll();
      }
      
      // Add event log entry about the gap
      if (typeof window.addEventToLog === 'function') {
        window.addEventToLog({
          level: 'info',
          message: 'Tab was in background - charts reset with fresh data'
        });
      }
    }
    tabHiddenAt = null;
  }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', initSocket);

// Handle tab visibility changes (browser throttles JS when tab is in background)
document.addEventListener('visibilitychange', handleVisibilityChange);
