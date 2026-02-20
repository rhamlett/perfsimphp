/**
 * =============================================================================
 * POLLING CLIENT — AJAX Polling Connection Manager (replaces Socket.IO)
 * =============================================================================
 *
 * PURPOSE:
 *   Manages real-time data updates from the PHP backend via AJAX polling.
 *   PHP-FPM does not support persistent WebSocket connections natively,
 *   so this client polls REST endpoints at regular intervals:
 *   - /api/metrics                → System metrics updates (~250ms)
 *   - /api/admin/events           → Event log entries (~2s)
 *   - /api/metrics/internal-probes → Batch latency measurement (~1s)
 *
 *   LATENCY PROBING STRATEGY:
 *   To reduce AppLens traffic, latency probes use internal batch probing.
 *   The server performs 10 curl requests to localhost:8080 internally at
 *   100ms intervals (bypasses Azure's stamp frontend). Results are dispatched
 *   to the chart at 100ms intervals for smooth visualization.
 *   Result: 10 latency samples/sec with only 1 external request/sec to AppLens.
 *
 * SCRIPT LOADING ORDER:
 *   This file must be loaded BEFORE dashboard.js and charts.js in index.html.
 *   It defines callback hooks (onSocketConnected, onMetricsUpdate, etc.) that
 *   those files implement. This is a simple dependency injection via globals.
 *
 * CONNECTION STRATEGY:
 *   - Uses fetch() for all polling (metrics, events, batch probes)
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
const MAX_CONSECUTIVE_FAILURES = 3;

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
  console.log('[polling-client] Initializing event log...');
  fetchWithTimeout('/api/admin/events?limit=50', { cache: 'no-store' }, EVENTS_TIMEOUT_MS)
    .then(response => {
      if (!response.ok) throw new Error('Events fetch failed');
      return response.json();
    })
    .then(data => {
      // Use sequence number for change detection (survives ring buffer eviction)
      lastEventSequence = data.sequence || 0;
      lastEventCount = data.total || data.count || (data.events || []).length;
      console.log('[polling-client] Event log initialized, sequence:', lastEventSequence, 'count:', lastEventCount);
      
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

      // Debug logging for event detection
      if (newSequence !== lastEventSequence) {
        console.log('[polling-client] Sequence changed:', lastEventSequence, '->', newSequence, 'events:', events.length);
      }

      // Detect new events using monotonic sequence number
      if (newSequence > lastEventSequence && lastEventSequence > 0) {
        // Calculate how many new events arrived
        const newEventsCount = newSequence - lastEventSequence;
        console.log('[polling-client] New events detected:', newEventsCount);
        // Events are newest-first from the API, so take the first N (but no more than available)
        const eventsToShow = events.slice(0, Math.min(newEventsCount, events.length));
        console.log('[polling-client] Dispatching events:', eventsToShow.map(e => e.event || e.message));
        // Dispatch in chronological order (reverse since API returns newest-first)
        for (let i = eventsToShow.length - 1; i >= 0; i--) {
          if (typeof onEventUpdate === 'function') {
            onEventUpdate(eventsToShow[i]);
          }
        }
      } else if (newSequence > 0 && lastEventSequence === 0) {
        // Edge case: first poll after init with no prior events - show recent events
        console.log('[polling-client] Initial events load, showing recent:', Math.min(5, events.length));
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

/**
 * Starts batch probing via /api/metrics/internal-probes at the configured interval.
 * The server performs multiple internal curl probes to localhost:8080, which
 * bypass Azure's stamp frontend and don't appear in AppLens metrics.
 */
function startProbePolling() {
  if (probePollTimer) clearTimeout(probePollTimer);

  // Fire first probe immediately, then schedule subsequent probes after completion
  console.log('[polling-client] Starting probe polling');
  probeOnce();
}

/**
 * Schedules the next probe immediately after the current one completes.
 * Server response time (~1s) provides natural pacing between requests.
 */
function scheduleNextProbe() {
  // Small delay to prevent tight loop on errors
  const delay = Math.max(PROBE_POLL_INTERVAL, 100);
  probePollTimer = setTimeout(() => {
    probeOnce();
  }, delay);
}

/**
 * Fetches a batch of internal latency probes from the server.
 * The server does multiple curl requests to localhost:8080/api/metrics/probe,
 * avoiding the stamp frontend while still measuring real PHP-FPM latency.
 * 
 * After ALL probes are dispatched to the chart, schedules the next probe.
 */
function probeOnce() {
  // Use internal batch probing (reduces AppLens traffic)
  const params = new URLSearchParams({
    count: INTERNAL_PROBE_COUNT.toString(),
    interval: INTERNAL_PROBE_INTERVAL.toString(),
    t: Date.now().toString(),
  });
  const probeUrl = '/api/metrics/internal-probes?' + params.toString();

  fetchWithTimeout(probeUrl, { 
    method: 'GET',
    headers: { 'Accept': 'application/json' },
  }, PROBE_TIMEOUT_MS)
    .then(response => {
      if (!response.ok) {
        throw new Error('HTTP ' + response.status);
      }
      return response.json();
    })
    .then(data => {
      onPollSuccess();

      // Debug logging for troubleshooting
      const failedProbes = data.probes?.filter(p => !p.success) || [];
      if (failedProbes.length > 0) {
        console.warn('[polling-client] Internal probes failed:', {
          port: data.internalPort,
          failedCount: failedProbes.length,
          firstError: failedProbes[0]?._debug
        });
      }

      // Process each probe in the batch, dispatching at 100ms intervals
      // This gives smooth chart updates while only making 1 request/sec to AppLens
      if (data.probes && Array.isArray(data.probes)) {
        const probeCount = data.probes.length;
        data.probes.forEach((probe, index) => {
          setTimeout(() => {
            if (typeof onProbeLatency === 'function') {
              onProbeLatency({
                latencyMs: probe.latencyMs,
                timestamp: probe.timestamp,
                success: probe.success,
                loadTestActive: probe.loadTestActive || false,
                loadTestConcurrent: probe.loadTestConcurrent || 0,
              });
            }
            
            // Schedule next batch AFTER the last probe is dispatched
            // This prevents overlap/gaps between batches
            if (index === probeCount - 1) {
              scheduleNextProbe();
            }
          }, index * INTERNAL_PROBE_INTERVAL);
        });
      } else {
        // No probes in response, schedule next batch immediately
        scheduleNextProbe();
      }
    })
    .catch(error => {
      console.error('[polling-client] Probe batch failed:', error.message || error);
      // Report a single failure for the batch
      if (typeof onProbeLatency === 'function') {
        onProbeLatency({
          latencyMs: 0,
          timestamp: Date.now(),
          success: false,
          loadTestActive: false,
          loadTestConcurrent: 0,
        });
      }
      // Schedule next probe after failure
      scheduleNextProbe();
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
  if (probePollTimer) { clearTimeout(probePollTimer); probePollTimer = null; }
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
      // Was hidden long enough that data is stale - reset charts silently
      if (typeof window.chartsClearAll === 'function') {
        window.chartsClearAll();
      }
    }
    tabHiddenAt = null;
  }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', initSocket);

// Handle tab visibility changes (browser throttles JS when tab is in background)
document.addEventListener('visibilitychange', handleVisibilityChange);
