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
        $entry = [
            'id' => Utils::generateId(),
            'timestamp' => Utils::formatTimestamp(),
            'level' => $level,
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
     * Clear all log entries.
     */
    public static function clear(): void
    {
        SharedStorage::set(self::STORAGE_KEY, []);
    }
}
