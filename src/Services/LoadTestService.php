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
 * PARAMETERS:
 *   - workMs (default: 100) — Duration of CPU work in milliseconds
 *   - memoryKb (default: 5000) — Memory to allocate in KB (5MB)
 *   - holdMs (default: 500) — How long to hold memory after CPU work (ms)
 *   - errorAfter (default: 120) — Throw random error if request exceeds this many seconds (0 = disabled)
 *   - errorPercent (default: 20) — Percentage chance to throw error when threshold exceeded
 *
 * CHAOS ERROR INJECTION:
 *   For realistic load testing with unpredictable failures, use errorAfter and errorPercent:
 *   - Errors are thrown AFTER the elapsed time exceeds the threshold (post-blocking delays)
 *   - Error types include RuntimeException, LogicException, InvalidArgumentException, etc.
 *   - Error statistics are tracked and included in the 60-second stats summary
 *
 * LATENCY SAMPLING:
 *   1 in 10 load test requests have their latency sampled for the dashboard's latency monitor.
 *   This prevents flooding the UI while still showing representative load test performance.
 *
 * STATS LOGGING:
 *   Every 60 seconds, logs a summary to the event log with:
 *   - Request count for the period
 *   - Average response time
 *   - Peak response time
 *   - Requests per second
 *   - Error percentage
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
    
    /** APCu key for sampled latencies (for latency monitor) */
    private const SAMPLED_LATENCIES_KEY = 'loadtest_sampled_latencies';
    
    /** Sample rate for latency monitor (1 in N requests) */
    private const LATENCY_SAMPLE_RATE = 10;
    
    /** Maximum sampled latencies to keep (circular buffer) */
    private const MAX_SAMPLED_LATENCIES = 100;

    /** Default request parameters */
    private const DEFAULTS = [
        'workMs' => 100,      // Duration of CPU work (ms)
        'memoryKb' => 5000,   // Memory to hold during work (KB) - 5MB default
        'holdMs' => 500,      // How long to hold memory after CPU work (ms)
        'errorAfter' => 120,  // Throw random error if request exceeds this many seconds (default: 120s)
        'errorPercent' => 20, // Percentage chance to throw error when errorAfter threshold exceeded
    ];

    /** Types of random errors that can be thrown for chaos testing */
    private const ERROR_TYPES = [
        'RuntimeException',
        'LogicException',
        'InvalidArgumentException',
        'OutOfBoundsException',
        'UnexpectedValueException',
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
        $errorAfter = isset($request['errorAfter']) ? (int)$request['errorAfter'] : self::DEFAULTS['errorAfter'];
        $errorPercent = isset($request['errorPercent']) ? (int)$request['errorPercent'] : self::DEFAULTS['errorPercent'];

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
        $errorAfter = max(0, min($errorAfter, 300)); // Max 5 minutes
        $errorPercent = max(0, min($errorPercent, 100)); // 0-100%

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

        // Step 4: Chaos error injection - check AFTER blocking delays
        // Throws random error if elapsed time exceeds errorAfter seconds with errorPercent probability
        if ($errorAfter > 0) {
            $elapsedSeconds = (microtime(true) - $startTime);
            if ($elapsedSeconds > $errorAfter) {
                // Roll the dice - throw error with errorPercent probability
                if (mt_rand(1, 100) <= $errorPercent) {
                    $errorType = self::ERROR_TYPES[array_rand(self::ERROR_TYPES)];
                    $errorClass = '\\' . $errorType;
                    self::recordError(); // Record error in stats before throwing
                    throw new $errorClass(
                        sprintf('Chaos error: request exceeded %ds (actual: %.2fs)', $errorAfter, $elapsedSeconds)
                    );
                }
            }
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
     * Also samples 1 in 10 requests for the latency monitor.
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
            
            // Sample 1 in 10 requests for the latency monitor
            self::maybeSampleLatency($responseTimeMs);

            // Check if we should broadcast
            self::checkAndBroadcast();
        } catch (\Throwable $e) {
            // Silently skip - stats are nice-to-have
        }
    }
    
    /**
     * Samples 1 in 10 load test request latencies for the latency monitor.
     * Stores sampled latencies with time-based expiry (keeps last 5 seconds).
     * 
     * IMPORTANT: Uses cross-pool file storage (not APCu) because the main FPM pool
     * (port 9000) writes these latencies, but the metrics FPM pool (port 9001)
     * reads them. Each pool has its own APCu, so we must use file-based storage.
     * 
     * OPTIMIZATION: Writer handles expiry to keep file small and reduce read contention.
     */
    private static function maybeSampleLatency(float $responseTimeMs): void
    {
        // Sample 1 in 10 requests
        if (mt_rand(1, self::LATENCY_SAMPLE_RATE) !== 1) {
            return;
        }
        
        try {
            $now = (int)(microtime(true) * 1000);
            
            // Use cross-pool storage (file-based) so metrics pool can read these
            SharedStorage::crossPoolModify(self::SAMPLED_LATENCIES_KEY, function($data) use ($responseTimeMs, $now) {
                if (!is_array($data)) {
                    $data = ['latencies' => []];
                }
                
                // Add new latency entry
                $data['latencies'][] = [
                    'latencyMs' => round($responseTimeMs, 2),
                    'timestamp' => $now,
                    'source' => 'loadtest',
                ];
                
                // TIME-BASED EXPIRY: Remove entries older than 5 seconds
                // This keeps the file small and reduces read/parse time
                $cutoff = $now - 5000;
                $data['latencies'] = array_values(array_filter($data['latencies'], function($entry) use ($cutoff) {
                    return $entry['timestamp'] > $cutoff;
                }));
                
                return $data;
            }, ['latencies' => []]);
        } catch (\Throwable $e) {
            // Silently skip - sampling is nice-to-have
        }
    }
    
    /**
     * Gets sampled latencies for the latency monitor (READ-ONLY).
     * Returns latencies from the last few seconds without modifying storage.
     * 
     * IMPORTANT: This is a non-destructive read. The writer handles expiry
     * to keep the file small. This eliminates write contention on the metrics
     * pool which was causing UI freezes under load.
     * 
     * @param int $sinceTimestamp Only return latencies after this timestamp (ms), 0 for all recent
     * @return array Array of latency entries
     */
    public static function getSampledLatencies(int $sinceTimestamp = 0): array
    {
        static $lastReadTimestamp = 0;
        static $lastReadData = [];
        
        try {
            // Cache reads for 200ms to reduce file I/O under rapid polling
            $now = (int)(microtime(true) * 1000);
            if ($now - $lastReadTimestamp < 200 && !empty($lastReadData)) {
                // Return cached data, filtered by sinceTimestamp
                if ($sinceTimestamp > 0) {
                    return array_values(array_filter($lastReadData, function($entry) use ($sinceTimestamp) {
                        return $entry['timestamp'] > $sinceTimestamp;
                    }));
                }
                return $lastReadData;
            }
            
            // Read from cross-pool storage (file-based)
            $data = SharedStorage::crossPoolGet(self::SAMPLED_LATENCIES_KEY);
            if (!is_array($data) || !isset($data['latencies'])) {
                $lastReadTimestamp = $now;
                $lastReadData = [];
                return [];
            }
            
            // Cache the read
            $lastReadTimestamp = $now;
            $lastReadData = $data['latencies'];
            
            // Filter by sinceTimestamp if provided
            if ($sinceTimestamp > 0) {
                return array_values(array_filter($data['latencies'], function($entry) use ($sinceTimestamp) {
                    return $entry['timestamp'] > $sinceTimestamp;
                }));
            }
            
            return $data['latencies'];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Checks if 60 seconds have elapsed and broadcasts stats if so.
     * Can be called from load test requests OR metrics polling.
     * 
     * Uses atomic modify to prevent race conditions where multiple workers
     * all try to broadcast simultaneously. Only one worker will succeed.
     */
    public static function checkAndBroadcast(): void
    {
        try {
            // Atomically check and capture stats, resetting if period elapsed.
            // This prevents multiple workers from racing to broadcast.
            $captured = SharedStorage::modify(self::STATS_KEY, function($stats) {
                if (!is_array($stats) || !isset($stats['periodStart'])) {
                    return ['_action' => 'skip'];
                }

                $elapsed = time() - $stats['periodStart'];
                if ($elapsed < self::STATS_PERIOD_SECONDS) {
                    return ['_action' => 'skip'];
                }

                // Capture current stats and reset atomically
                $stats['_action'] = 'broadcast';
                $stats['_elapsed'] = $elapsed;
                
                // Return fresh stats (this is what gets stored)
                // We'll return the OLD stats as the result for logging
                return self::initPeriodStats() + ['_captured' => $stats];
            }, self::initPeriodStats());
            
            // Check if we won the race to broadcast
            if (!is_array($captured) || !isset($captured['_captured'])) {
                return;
            }
            
            $stats = $captured['_captured'];
            if (($stats['_action'] ?? '') !== 'broadcast') {
                return;
            }

            $elapsed = $stats['_elapsed'];
            $requestCount = $stats['requestCount'] ?? 0;
            
            // No requests in this period - nothing to log
            if ($requestCount === 0) {
                return;
            }

            // Calculate stats (single period - no batching to avoid log spam)
            $avgResponseTime = $stats['responseTimeSum'] / $requestCount;
            $maxResponseTime = $stats['maxResponseTime'];
            $totalErrorCount = $stats['errorCount'] ?? 0;
            $requestsPerSecond = $requestCount / $elapsed;
            $errorPercent = ($totalErrorCount / $requestCount) * 100;

            // Log a single summary message for this period
            EventLogService::info(
                'LOAD_TEST_STATS',
                sprintf(
                    'Load test period stats: %d requests, %.1f avg ms, %.0f max ms, %.2f RPS, %.1f%% errors',
                    $requestCount,
                    $avgResponseTime,
                    $maxResponseTime,
                    $requestsPerSecond,
                    $errorPercent
                )
            );
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
            'errorCount' => 0,
        ];
    }

    /**
     * Records an error in the current period stats.
     * Called before throwing a chaos error.
     */
    private static function recordError(): void
    {
        try {
            SharedStorage::modify(self::STATS_KEY, function($stats) {
                if (!is_array($stats)) {
                    $stats = self::initPeriodStats();
                }
                $stats['errorCount']++;
                return $stats;
            }, self::initPeriodStats());
        } catch (\Throwable $e) {
            // Silently skip - stats are nice-to-have
        }
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
