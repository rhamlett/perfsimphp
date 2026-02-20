<?php
/**
 * =============================================================================
 * LOAD TEST SERVICE — Simple, Non-Blocking Load Testing
 * =============================================================================
 *
 * PURPOSE:
 *   Load test endpoint for Azure Load Testing, JMeter, k6, Gatling.
 *   Performs REAL work (CPU, memory) that shows in metrics.
 *
 * DESIGN PHILOSOPHY:
 *   - Each request does a SHORT burst of real work (50-200ms default)
 *   - Workers return quickly, allowing dashboard polls to succeed
 *   - Load test frameworks hit the endpoint repeatedly for sustained load
 *   - Under heavy load, requests naturally queue (realistic degradation)
 *
 * PARAMETERS (2 tunable):
 *   - workMs (default: 100) — Duration of CPU work in milliseconds
 *   - memoryKb (default: 5000) — Memory to allocate in KB (5MB)
 *
 * STATS LOGGING:
 *   Every 60 seconds, logs a summary to the event log with:
 *   - Request count for the period
 *   - Average response time
 *   - Peak response time
 *   Triggered by either load test requests OR metrics polling (hybrid).
 *
 * @module src/Services/LoadTestService.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Services;

use PerfSimPhp\Services\EventLogService;
use PerfSimPhp\SharedStorage;

class LoadTestService
{
    /** Maximum work duration to prevent runaway (5 seconds) */
    private const MAX_WORK_MS = 5000;

    /** Period stats broadcast interval (seconds) */
    private const STATS_PERIOD_SECONDS = 60;

    /** APCu keys for period stats */
    private const STATS_KEY = 'loadtest_period_stats';

    /** Default request parameters */
    private const DEFAULTS = [
        'workMs' => 100,      // Duration of CPU work (ms)
        'memoryKb' => 5000,   // Memory to hold during work (KB) - 5MB default
        'holdMs' => 500,      // How long to hold memory after CPU work (ms)
    ];

    /**
     * Returns the default request parameters.
     */
    public static function getDefaults(): array
    {
        return self::DEFAULTS;
    }

    /**
     * Executes load test work - simple CPU + memory allocation.
     *
     * @param array $request Configuration (workMs, memoryKb)
     * @return array Result containing timing information
     */
    public static function executeWork(array $request = []): array
    {
        $startTime = microtime(true);

        // Parse and validate parameters
        $workMs = isset($request['workMs']) ? (int)$request['workMs'] : self::DEFAULTS['workMs'];
        $memoryKb = isset($request['memoryKb']) ? (int)$request['memoryKb'] : self::DEFAULTS['memoryKb'];
        $holdMs = isset($request['holdMs']) ? (int)$request['holdMs'] : self::DEFAULTS['holdMs'];

        // Legacy parameter support
        if (isset($request['targetDurationMs'])) {
            $workMs = (int)$request['targetDurationMs'];
        }
        if (isset($request['memorySizeKb'])) {
            $memoryKb = (int)$request['memorySizeKb'];
        }

        // Enforce limits
        $workMs = max(10, min($workMs, self::MAX_WORK_MS));
        $memoryKb = max(1, min($memoryKb, 50000)); // Max 50MB
        $holdMs = max(0, min($holdMs, 5000)); // Max 5s hold

        // Step 1: Allocate memory (held during work AND hold period)
        $memory = self::allocateRealMemory($memoryKb);
        $memoryAllocated = strlen($memory);

        // Step 2: Do real CPU work
        $cpuWorkActual = self::doCpuWork($workMs);

        // Step 3: Hold memory for additional time so metrics polling can see it
        // This gives the 500ms metrics poll a chance to capture the memory usage
        if ($holdMs > 0) {
            usleep($holdMs * 1000);
            // Touch memory during hold to prevent optimization
            $touchPos = mt_rand(0, $memoryAllocated - 1);
            $_ = ord($memory[$touchPos]);
        }

        // Touch memory to prevent optimization
        $touchPos = mt_rand(0, $memoryAllocated - 1);
        $_ = ord($memory[$touchPos]);

        // Calculate total elapsed time
        $totalElapsedMs = (microtime(true) - $startTime) * 1000;

        // Record stats for this request (and check for 60s broadcast)
        self::recordAndMaybeBroadcast($totalElapsedMs);

        return [
            'success' => true,
            'requestedWorkMs' => $workMs,
            'actualCpuWorkMs' => round($cpuWorkActual, 2),
            'holdMs' => $holdMs,
            'totalElapsedMs' => round($totalElapsedMs, 2),
            'memoryAllocatedKb' => round($memoryAllocated / 1024, 2),
            'timestamp' => date('c'),
            'workerPid' => getmypid(),
        ];
    }

    /**
     * Gets current statistics.
     * Returns format expected by MetricsController probe endpoints.
     * Also checks if 60s period has elapsed and broadcasts if needed.
     */
    public static function getCurrentStats(): array
    {
        // Check for 60s broadcast (triggered by metrics polling)
        self::checkAndBroadcast();

        return [
            'currentConcurrentRequests' => 0,
            'totalRequestsProcessed' => 0,
            'totalExceptionsThrown' => 0,
            'averageResponseTimeMs' => 0,
            'timestamp' => date('c'),
        ];
    }

    /**
     * Records a request's stats and broadcasts if 60s elapsed.
     * Called after each load test request completes.
     */
    private static function recordAndMaybeBroadcast(float $responseTimeMs): void
    {
        try {
            SharedStorage::modify(self::STATS_KEY, function($stats) use ($responseTimeMs) {
                if (!is_array($stats)) {
                    $stats = self::initPeriodStats();
                }

                // Record this request
                $stats['requestCount']++;
                $stats['responseTimeSum'] += $responseTimeMs;
                $stats['maxResponseTime'] = max($stats['maxResponseTime'], $responseTimeMs);

                return $stats;
            }, self::initPeriodStats());

            // Check if we should broadcast
            self::checkAndBroadcast();
        } catch (\Throwable $e) {
            // Silently skip - stats are nice-to-have
        }
    }

    /**
     * Checks if 60 seconds have elapsed and broadcasts stats if so.
     * Can be called from load test requests OR metrics polling.
     */
    public static function checkAndBroadcast(): void
    {
        try {
            $stats = SharedStorage::get(self::STATS_KEY);
            if (!is_array($stats) || !isset($stats['periodStart'])) {
                return;
            }

            $elapsed = time() - $stats['periodStart'];
            if ($elapsed < self::STATS_PERIOD_SECONDS) {
                return;
            }

            // 60s elapsed - broadcast and reset
            $requestCount = $stats['requestCount'] ?? 0;
            if ($requestCount === 0) {
                // No requests, just reset the timer
                SharedStorage::set(self::STATS_KEY, self::initPeriodStats());
                return;
            }

            // Calculate averages
            $avgResponseTime = $stats['responseTimeSum'] / $requestCount;
            $maxResponseTime = $stats['maxResponseTime'];
            $requestsPerSecond = $requestCount / self::STATS_PERIOD_SECONDS;

            // Log to event log
            EventLogService::info(
                'LOAD_TEST_STATS',
                sprintf(
                    '📊 Load Test (60s): %d requests, %.0fms avg, %.0fms max, %.1f RPS',
                    $requestCount,
                    $avgResponseTime,
                    $maxResponseTime,
                    $requestsPerSecond
                )
            );

            // Reset for next period
            SharedStorage::set(self::STATS_KEY, self::initPeriodStats());
        } catch (\Throwable $e) {
            // Silently skip
        }
    }

    /**
     * Initialize period stats structure.
     */
    private static function initPeriodStats(): array
    {
        return [
            'periodStart' => time(),
            'requestCount' => 0,
            'responseTimeSum' => 0.0,
            'maxResponseTime' => 0.0,
        ];
    }

    /**
     * Performs CPU-intensive work using cryptographic hashing.
     *
     * @param int $targetMs Target milliseconds of work
     * @return float Actual milliseconds of work performed
     */
    private static function doCpuWork(int $targetMs): float
    {
        $startTime = microtime(true);
        $endTime = $startTime + ($targetMs / 1000);

        // Do cryptographic work until target time reached
        // hash_pbkdf2 with 1000 iterations takes ~1-2ms per call
        while (microtime(true) < $endTime) {
            hash_pbkdf2('sha256', 'loadtest', 'salt', 1000, 32, false);
        }

        return (microtime(true) - $startTime) * 1000;
    }

    /**
     * Allocates real memory that can't be optimized away.
     * Uses random bytes to prevent PHP's copy-on-write optimization.
     *
     * @param int $sizeKb Size in kilobytes
     * @return string The allocated memory buffer
     */
    private static function allocateRealMemory(int $sizeKb): string
    {
        // Build buffer in chunks with unique content per chunk
        // This prevents PHP from using copy-on-write optimization
        $buffer = '';
        
        for ($i = 0; $i < $sizeKb; $i++) {
            // Each chunk has unique content based on index and time
            $seed = md5((string)$i . microtime(true));
            $buffer .= str_repeat($seed, 32); // 32 * 32 = 1024 bytes
        }
        
        return $buffer;
    }
}
