<?php
/**
 * =============================================================================
 * APPLICATION CONFIGURATION
 * =============================================================================
 *
 * PURPOSE:
 *   Centralizes all configurable values. Every tunable parameter is defined
 *   here with sensible defaults that can be overridden via environment variables.
 *
 * ENVIRONMENT VARIABLES:
 *   - PORT                          → HTTP server port (Azure App Service sets this to 8080)
 *   - METRICS_INTERVAL_MS           → How often the client polls for metrics
 *   - MAX_SIMULATION_DURATION_SECONDS → Upper limit for timed simulations
 *   - MAX_MEMORY_ALLOCATION_MB      → Upper limit for single memory allocation
 *   - EVENT_LOG_MAX_ENTRIES         → Ring buffer size for event log
 *
 * @module src/Config.php
 */

declare(strict_types=1);

namespace PerfSimPhp;

class Config
{
    /** HTTP server port (default: 8080 for Azure App Service) */
    public static function port(): int
    {
        return self::intEnv('PORT', 8080);
    }

    /** Metrics collection/broadcast interval in milliseconds */
    public static function metricsIntervalMs(): int
    {
        return self::intEnv('METRICS_INTERVAL_MS', 500);
    }

    /** Maximum allowed simulation duration in seconds */
    public static function maxSimulationDurationSeconds(): int
    {
        return self::intEnv('MAX_SIMULATION_DURATION_SECONDS', 86400);
    }

    /** Maximum single memory allocation in megabytes */
    public static function maxMemoryAllocationMb(): int
    {
        return self::intEnv('MAX_MEMORY_ALLOCATION_MB', 65536);
    }

    /** Maximum number of event log entries to retain (ring buffer) */
    public static function eventLogMaxEntries(): int
    {
        return self::intEnv('EVENT_LOG_MAX_ENTRIES', 100);
    }

    /** Application version */
    public const APP_VERSION = '1.0.0';

    /** Application name */
    public const APP_NAME = 'PerfSimPhp';

    /** Get build timestamp from buildversion.txt */
    public static function buildTimestamp(): string
    {
        $file = dirname(__DIR__) . '/buildversion.txt';
        if (file_exists($file)) {
            return trim(file_get_contents($file));
        }
        // Fallback: use index.php modification time
        $indexFile = dirname(__DIR__) . '/public/index.php';
        if (file_exists($indexFile)) {
            return gmdate('Y-m-d H:i:s', filemtime($indexFile)) . ' UTC';
        }
        return 'Unknown';
    }

    // =========================================================================
    // DEFAULT SIMULATION PARAMETERS
    // =========================================================================

    /** Default CPU stress target load percentage */
    public const DEFAULT_CPU_TARGET_LOAD_PERCENT = 50;

    /** Default CPU stress duration in seconds */
    public const DEFAULT_CPU_DURATION_SECONDS = 30;

    /** Default memory allocation size in MB */
    public const DEFAULT_MEMORY_SIZE_MB = 100;

    /** Default blocking duration in seconds */
    public const DEFAULT_BLOCKING_DURATION_SECONDS = 5;

    /** Default concurrent workers to block */
    public const DEFAULT_BLOCKING_CONCURRENT_WORKERS = 1;

    /** Maximum concurrent workers to block (prevents exhausting entire FPM pool) */
    public const MAX_BLOCKING_CONCURRENT_WORKERS = 20;

    /** Default slow request delay in seconds */
    public const DEFAULT_SLOW_REQUEST_DELAY_SECONDS = 5;

    // =========================================================================
    // VALIDATION LIMITS
    // =========================================================================

    public const MIN_CPU_LOAD_PERCENT = 1;
    public const MAX_CPU_LOAD_PERCENT = 100;
    public const MIN_DURATION_SECONDS = 1;
    public const MIN_MEMORY_MB = 1;

    public static function maxDurationSeconds(): int
    {
        return self::maxSimulationDurationSeconds();
    }

    public static function maxMemoryMb(): int
    {
        return self::maxMemoryAllocationMb();
    }

    // =========================================================================
    // STORAGE PATHS
    // =========================================================================

    public static function storagePath(): string
    {
        return dirname(__DIR__) . '/storage';
    }

    public static function simulationsFile(): string
    {
        return self::storagePath() . '/simulations.json';
    }

    public static function eventLogFile(): string
    {
        return self::storagePath() . '/events.json';
    }

    public static function pidFile(): string
    {
        return self::storagePath() . '/cpu_pids.json';
    }

    // =========================================================================
    // HELPER
    // =========================================================================

    private static function intEnv(string $name, int $default): int
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        return $parsed !== false ? $parsed : $default;
    }

    /** Get full config as array (for admin status endpoint) */
    public static function toArray(): array
    {
        return [
            'port' => self::port(),
            'metricsIntervalMs' => self::metricsIntervalMs(),
            'maxSimulationDurationSeconds' => self::maxSimulationDurationSeconds(),
            'maxMemoryAllocationMb' => self::maxMemoryAllocationMb(),
            'eventLogMaxEntries' => self::eventLogMaxEntries(),
        ];
    }
}
