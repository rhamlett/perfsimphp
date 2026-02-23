<?php
/**
 * =============================================================================
 * EVENT LOG SERVICE — Application Event Ring Buffer
 * =============================================================================
 *
 * FEATURE REQUIREMENTS (language-agnostic):
 *   This service must provide a real-time event log that:
 *   1. Records simulation lifecycle events (started, stopped, completed)
 *   2. Records system events (warnings, errors, crashes)
 *   3. Maintains bounded history (ring buffer, ~100 entries)
 *   4. Supports polling for new events (client checks periodically)
 *   5. Assigns level (info, warn, error, success) for UI coloring
 *
 * EVENT STRUCTURE:
 *   Each event should contain:
 *   - id: Unique identifier (UUID)
 *   - seq: Monotonic sequence number (for change detection)
 *   - timestamp: ISO 8601 format
 *   - level: info | warn | error | success
 *   - message: Human-readable description
 *   - event: Event type code (SIMULATION_STARTED, CRASH_WARNING, etc.)
 *   - simulationId: Reference to associated simulation (optional)
 *   - simulationType: Type of simulation (CPU_STRESS, MEMORY_PRESSURE, etc.)
 *
 * HOW IT WORKS (this implementation):
 *   - Events stored in APCu/file-based SharedStorage
 *   - Ring buffer evicts oldest when full
 *   - Sequence number increments monotonically for change detection
 *   - Also logs to PHP error_log for server log visibility
 *
 * PORTING NOTES:
 *
 *   Node.js:
 *     - Simple in-memory array (process is persistent)
 *     - Use EventEmitter for real-time push via WebSocket
 *     - No need for shared storage between requests
 *
 *   Java (Spring Boot):
 *     - ConcurrentLinkedDeque or CircularFifoQueue (Apache Commons)
 *     - @Service singleton holds events in memory
 *     - WebSocket/SSE for real-time push
 *
 *   Python (Flask/FastAPI):
 *     - collections.deque(maxlen=100) for bounded buffer
 *     - Global variable in application scope
 *     - Redis for multi-worker scenarios
 *
 *   .NET (ASP.NET Core):
 *     - Singleton service with ConcurrentQueue<T>
 *     - IMemoryCache for shared state
 *     - SignalR for real-time push
 *
 *   Ruby (Rails):
 *     - Global array with mutex for thread safety
 *     - Redis for multi-process scenarios
 *     - ActionCable for WebSocket push
 *
 * CROSS-PLATFORM CONSIDERATIONS:
 *   - Events must persist across HTTP requests
 *   - Sequence numbers enable efficient change detection
 *   - Consider WebSocket/SSE for push instead of polling
 *   - Ring buffer prevents unbounded memory growth
 *   - Include worker/process ID for debugging
 *
 * @module src/Services/EventLogService.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Services;

use PerfSimPhp\Utils;
use PerfSimPhp\SharedStorage;
use PerfSimPhp\Config;

class EventLogService
{
    private const STORAGE_KEY = 'perfsim_events';
    private const SEQUENCE_KEY = 'perfsim_events_seq';

    /**
     * Log a new event.
     */
    public static function log(
        string $event,
        string $message,
        string $level = 'info',
        ?string $simulationId = null,
        ?string $simulationType = null,
        ?array $details = null,
    ): array {
        // Get next sequence number (monotonically increasing, survives ring buffer eviction)
        $seq = SharedStorage::modify(self::SEQUENCE_KEY, function (?int $s) {
            return ($s ?? 0) + 1;
        }, 0);

        $entry = [
            'id' => Utils::generateId(),
            'seq' => $seq,  // Sequence number for change detection
            'timestamp' => Utils::formatTimestamp(),
            'level' => $level,
            'workerPid' => getmypid(),
            'simulationId' => $simulationId,
            'simulationType' => $simulationType,
            'event' => $event,
            'message' => $message,
            'details' => $details,
        ];

        // Store in ring buffer
        $maxEntries = Config::eventLogMaxEntries();

        SharedStorage::modify(self::STORAGE_KEY, function (?array $entries) use ($entry, $maxEntries) {
            $entries = $entries ?? [];
            $entries[] = $entry;

            // Trim to max entries (ring buffer)
            while (count($entries) > $maxEntries) {
                array_shift($entries);
            }

            return $entries;
        }, []);

        // Also log to stderr for server log visibility
        $logLine = "[{$entry['timestamp']}] [" . strtoupper($level) . "] {$event}: {$message}";
        error_log($logLine);

        return $entry;
    }

    /** Log an info-level event. */
    public static function info(
        string $event,
        string $message,
        ?string $simulationId = null,
        ?string $simulationType = null,
        ?array $details = null,
    ): array {
        return self::log($event, $message, 'info', $simulationId, $simulationType, $details);
    }

    /** Log a warning-level event. */
    public static function warn(
        string $event,
        string $message,
        ?string $simulationId = null,
        ?string $simulationType = null,
        ?array $details = null,
    ): array {
        return self::log($event, $message, 'warn', $simulationId, $simulationType, $details);
    }

    /** Log an error-level event. */
    public static function error(
        string $event,
        string $message,
        ?string $simulationId = null,
        ?string $simulationType = null,
        ?array $details = null,
    ): array {
        return self::log($event, $message, 'error', $simulationId, $simulationType, $details);
    }

    /** Log a success-level event. */
    public static function success(
        string $event,
        string $message,
        ?string $simulationId = null,
        ?string $simulationType = null,
        ?array $details = null,
    ): array {
        return self::log($event, $message, 'success', $simulationId, $simulationType, $details);
    }

    /**
     * Get all log entries.
     */
    public static function getEntries(): array
    {
        return SharedStorage::get(self::STORAGE_KEY, []);
    }

    /**
     * Get the most recent entries (newest first).
     */
    public static function getRecentEntries(int $limit = 50): array
    {
        $entries = self::getEntries();
        $reversed = array_reverse($entries);
        return array_slice($reversed, 0, $limit);
    }

    /**
     * Get the count of log entries.
     */
    public static function getCount(): int
    {
        return count(self::getEntries());
    }

    /**
     * Get the current sequence number (monotonically increasing).
     * This survives ring buffer eviction and is used for change detection.
     */
    public static function getSequence(): int
    {
        return SharedStorage::get(self::SEQUENCE_KEY, 0);
    }

    /**
     * Clear all log entries.
     */
    public static function clear(): void
    {
        SharedStorage::set(self::STORAGE_KEY, []);
    }
}
