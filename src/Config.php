<?php
/**
 * =============================================================================
 * APPLICATION CONFIGURATION
 * =============================================================================
 *
 * FEATURE REQUIREMENTS (language-agnostic):
 *   The application must have centralized configuration for:
 *   1. Server port (default: 8080 for Azure App Service)
 *   2. Simulation limits (max duration, max memory allocation)
 *   3. Default simulation parameters
 *   4. Event log buffer size
 *   5. All values should be overridable via environment variables
 *
 * CONFIGURATION VALUES:
 *   Server:
 *     PORT                         → HTTP server port (default: 8080)
 *     METRICS_INTERVAL_MS          → How often metrics are polled (default: 500)
 *
 *   Limits:
 *     MAX_SIMULATION_DURATION_SECONDS → Max duration for timed simulations (default: 86400)
 *     MAX_MEMORY_ALLOCATION_MB        → Max single memory allocation (default: 65536)
 *     EVENT_LOG_MAX_ENTRIES           → Ring buffer size (default: 100)
 *
 *   Defaults:
 *     DEFAULT_CPU_LEVEL              → Default CPU stress level ('moderate')
 *     DEFAULT_CPU_DURATION_SECONDS   → Default CPU stress duration (30)
 *     DEFAULT_MEMORY_SIZE_MB         → Default memory allocation (100)
 *     DEFAULT_BLOCKING_DURATION_SECONDS → Default blocking duration (5)
 *
 * PORTING NOTES:
 *
 *   Node.js:
 *     - Use process.env.PORT ?? 8080
 *     - Consider dotenv for .env file support
 *     - Export as module or use config library (convict, node-config)
 *
 *   Java (Spring Boot):
 *     - Use @Value("${PORT:8080}") with application.properties
 *     - Or @ConfigurationProperties for typed config
 *     - Spring profiles for environment-specific values
 *
 *   Python (Flask/FastAPI):
 *     - os.environ.get('PORT', 8080)
 *     - Consider python-dotenv for .env support
 *     - Pydantic Settings for typed config (FastAPI)
 *
 *   .NET (ASP.NET Core):
 *     - IConfiguration with appsettings.json
 *     - Environment.GetEnvironmentVariable("PORT") ?? "8080"
 *     - Options pattern for typed config
 *
 *   Ruby (Rails):
 *     - ENV.fetch('PORT', 8080)
 *     - Rails credentials or dotenv gem
 *     - config/settings.yml with config gem
 *
 * CROSS-PLATFORM CONSIDERATIONS:
 *   - Environment variables are the standard for cloud platforms
 *   - Default values should be sensible for development
 *   - Consider Azure App Service configuration blade
 *   - Build timestamp helps identify deployed version
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

    /** Default CPU stress level ('moderate' or 'high') */
    public const DEFAULT_CPU_LEVEL = 'moderate';

    /** Default CPU stress duration in seconds */
    public const DEFAULT_CPU_DURATION_SECONDS = 30;

    /** Default memory allocation size in MB */
    public const DEFAULT_MEMORY_SIZE_MB = 100;

    /** Default blocking duration in seconds */
    public const DEFAULT_BLOCKING_DURATION_SECONDS = 5;

    /** Default concurrent workers to block (5 is safe for most FPM pools) */
    public const DEFAULT_BLOCKING_CONCURRENT_WORKERS = 5;

    /** Maximum concurrent workers to block (no hard limit - let users exhaust the pool if desired) */
    public const MAX_BLOCKING_CONCURRENT_WORKERS = 1000;

    // =========================================================================
    // VALIDATION LIMITS
    // =========================================================================

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
