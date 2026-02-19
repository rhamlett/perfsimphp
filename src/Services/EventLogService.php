<?php
/**
 * =============================================================================
 * EVENT LOG SERVICE — Application Event Ring Buffer
 * =============================================================================
 *
 * PURPOSE:
 *   Central logging service for simulation lifecycle events and system events.
 *   Maintains a bounded ring buffer in shared storage. Events are written to
 *   shared storage and also logged to PHP's error_log for server log visibility.
 *
 * RING BUFFER:
 *   Fixed-size (default 100 entries). When full, oldest entries are evicted.
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
