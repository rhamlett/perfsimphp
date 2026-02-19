<?php
/**
 * =============================================================================
 * LOAD TEST SERVICE — Real Work-Based Load Testing
 * =============================================================================
 *
 * PURPOSE:
 *   Load test endpoint for Azure Load Testing, JMeter, k6, Gatling.
 *   Performs REAL work (CPU, I/O, memory) that shows in metrics.
 *
 * PARAMETERS (5 tunable):
 *   - targetDurationMs (default: 1000) — Base request duration in ms
 *   - memorySizeKb (default: 5000)     — Memory to allocate in KB
 *   - cpuWorkMs (default: 20)          — CPU work per cycle in ms
 *   - softLimit (default: 20)          — Concurrent requests before degradation
 *   - degradationFactor (default: 1.2) — Multiplier per concurrent over limit
 *
 * SAFEGUARDS:
 *   - Max duration: 60 seconds (prevents runaway)
 *   - Max degradation: 30x (caps exponential growth)
 *
 * DEGRADATION FORMULA:
 *   duration = targetDurationMs × min(degradationFactor^overLimit, 30)
 *   Capped at 60 seconds regardless of parameters.
 *
 * @module src/Services/LoadTestService.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Services;

use PerfSimPhp\SharedStorage;
use PerfSimPhp\Services\EventLogService;

class LoadTestService
{
    private const STATS_KEY = 'perfsim_loadtest_stats';
    private const CONCURRENT_KEY = 'perfsim_loadtest_concurrent';

    /** Time threshold in seconds after which exceptions may be thrown (must be < MAX_DURATION_MS/1000) */
    private const EXCEPTION_THRESHOLD_SECONDS = 45;

    /** Probability of throwing exception per check after threshold */
    private const EXCEPTION_PROBABILITY = 0.20;

    /** Directory for temp file I/O work */
    private const TEMP_DIR = '/tmp/loadtest';

    /** Maximum allowed duration to prevent runaway (60 seconds) */
    private const MAX_DURATION_MS = 60000;

    /** Maximum degradation multiplier to prevent exponential explosion */
    private const MAX_DEGRADATION_MULTIPLIER = 30.0;

    /** Default request parameters */
    private const DEFAULTS = [
        'targetDurationMs' => 1000,  // Target request duration (ms)
        'memorySizeKb' => 5000,      // Memory to allocate (KB)
        'cpuWorkMs' => 20,           // CPU work per cycle (ms)
        'softLimit' => 20,           // Concurrent requests before degradation
        'degradationFactor' => 1.2,  // Multiplier per concurrent over limit
    ];

    /** Internal work settings (not user-tunable) */
    private const INTERNAL_FILE_IO_KB = 20;
    private const INTERNAL_JSON_DEPTH = 3;
    private const INTERNAL_MEMORY_CHURN_KB = 100;

    /**
     * Returns the default request parameters.
     */
    public static function getDefaults(): array
    {
        return self::DEFAULTS;
    }

    /**
     * Executes the load test work with the specified parameters.
     *
     * @param array $request Configuration for load test behavior
     * @return array Result containing timing and diagnostic information
     * @throws \RuntimeException If a random exception is triggered
     */
    public static function executeWork(array $request = []): array
    {
        // Backwards compatibility: map old param names to new ones
        if (isset($request['baselineDelayMs']) && !isset($request['targetDurationMs'])) {
            $request['targetDurationMs'] = $request['baselineDelayMs'];
        }

        // Merge with defaults
        $params = array_merge(self::DEFAULTS, $request);

        // Ensure temp directory exists
        self::ensureTempDir();

        // Increment concurrent counter
        $currentConcurrent = self::incrementConcurrent();

        // Calculate degradation multiplier (exponential backpressure)
        // CAPPED to prevent runaway when degradationFactor is too high
        $overLimit = max(0, $currentConcurrent - $params['softLimit']);
        $rawMultiplier = $overLimit > 0 
            ? pow($params['degradationFactor'], $overLimit)
            : 1.0;
        $degradationMultiplier = min($rawMultiplier, self::MAX_DEGRADATION_MULTIPLIER);
        
        // Work metrics tracking
        $workMetrics = [
            'cpuWorkMs' => 0,
            'fileIoMs' => 0,
            'memoryChurnMs' => 0,
            'jsonWorkMs' => 0,
            'cycles' => 0,
        ];

        EventLogService::info(
            'LOADTEST_START',
            "Load test started (concurrent: {$currentConcurrent}, degradation: {$degradationMultiplier}x)",
            null,
            'loadtest',
            [
                'concurrent' => $currentConcurrent,
                'softLimit' => $params['softLimit'],
                'overLimit' => $overLimit,
                'degradationMultiplier' => round($degradationMultiplier, 2),
                'targetDurationMs' => $params['targetDurationMs'],
                'multiplierCapped' => $rawMultiplier > self::MAX_DEGRADATION_MULTIPLIER,
            ]
        );

        $startTime = microtime(true);
        $allocatedBytes = $params['memorySizeKb'] * 1024;
        $memory = null;
        $tempFile = null;

        try {
            // -----------------------------------------------------------------
            // STEP 1: ALLOCATE PERSISTENT MEMORY
            // -----------------------------------------------------------------
            $memory = self::allocateMemory($params['memorySizeKb']);

            // Create unique temp file for this request
            $tempFile = self::TEMP_DIR . '/lt_' . getmypid() . '_' . uniqid() . '.tmp';

            // -----------------------------------------------------------------
            // STEP 2: CALCULATE TARGET DURATION WITH DEGRADATION
            // More concurrent requests = more work cycles = longer duration
            // CAPPED to MAX_DURATION_MS to prevent runaway
            // -----------------------------------------------------------------
            $rawTargetDurationMs = $params['targetDurationMs'] * $degradationMultiplier;
            $targetDurationMs = min($rawTargetDurationMs, self::MAX_DURATION_MS);

            // -----------------------------------------------------------------
            // STEP 3: REAL WORK LOOP (NO usleep!)
            // Each cycle performs actual work that shows in metrics
            // -----------------------------------------------------------------
            while ((microtime(true) - $startTime) * 1000 < $targetDurationMs) {
                $workMetrics['cycles']++;

                // CPU Work - cryptographic hashing (visible in CPU metrics)
                if ($params['cpuWorkMs'] > 0) {
                    $workMetrics['cpuWorkMs'] += self::performCpuWork($params['cpuWorkMs']);
                }

                // File I/O Work - write/read temp file (visible in I/O metrics)
                $workMetrics['fileIoMs'] += self::performFileIoWork($tempFile, self::INTERNAL_FILE_IO_KB);

                // Memory Churn - allocate/serialize/free (visible in memory metrics)
                $workMetrics['memoryChurnMs'] += self::performMemoryChurnWork(self::INTERNAL_MEMORY_CHURN_KB);

                // JSON Processing - deep encode/decode (visible in CPU + alloc)
                $workMetrics['jsonWorkMs'] += self::performJsonWork(self::INTERNAL_JSON_DEPTH);

                // Touch persistent memory to prevent optimization
                self::touchMemory($memory);

                // HARD FAIL-SAFE: Force exit if elapsed time exceeds absolute max
                // This catches cases where individual work operations take too long
                // under heavy system load (prevents 240s Azure timeout)
                $currentElapsedMs = (microtime(true) - $startTime) * 1000;
                if ($currentElapsedMs > self::MAX_DURATION_MS) {
                    EventLogService::warn(
                        'LOADTEST_FAILSAFE',
                        "Force exit: elapsed {$currentElapsedMs}ms exceeds " . self::MAX_DURATION_MS . "ms max",
                        null,
                        'loadtest',
                        ['elapsedMs' => round($currentElapsedMs), 'maxMs' => self::MAX_DURATION_MS]
                    );
                    break;
                }

                // Check for timeout exception (20% chance after threshold)
                self::checkAndThrowException($startTime);
            }

            $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);

            return self::buildResult(
                $elapsedMs,
                $currentConcurrent,
                $targetDurationMs,
                $workMetrics,
                $allocatedBytes,
                true,
                false,
                null
            );
        } catch (\Throwable $e) {
            $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);
            self::incrementStat('totalExceptions');

            EventLogService::error(
                'LOADTEST_EXCEPTION',
                get_class($e) . ": " . $e->getMessage() . " after {$elapsedMs}ms",
                null,
                'loadtest',
                [
                    'exceptionType' => get_class($e),
                    'elapsedMs' => $elapsedMs,
                    'concurrent' => $currentConcurrent,
                ]
            );

            throw $e;
        } finally {
            // Decrement concurrent counter
            self::decrementConcurrent();

            // Update stats
            $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);
            self::updateStats($elapsedMs);

            // Clean up temp file
            if ($tempFile && file_exists($tempFile)) {
                @unlink($tempFile);
            }

            // Log completion with work metrics
            EventLogService::info(
                'LOADTEST_COMPLETE',
                "Load test: {$elapsedMs}ms, {$workMetrics['cycles']} cycles (concurrent: {$currentConcurrent})",
                null,
                'loadtest',
                [
                    'elapsedMs' => $elapsedMs,
                    'concurrentAtStart' => $currentConcurrent,
                    'cycles' => $workMetrics['cycles'],
                    'cpuWorkMs' => round($workMetrics['cpuWorkMs'], 1),
                    'fileIoMs' => round($workMetrics['fileIoMs'], 1),
                    'memoryChurnMs' => round($workMetrics['memoryChurnMs'], 1),
                    'jsonWorkMs' => round($workMetrics['jsonWorkMs'], 1),
                ]
            );

            // Release memory
            $memory = null;
        }
    }

    /**
     * Gets current load test statistics.
     */
    public static function getCurrentStats(): array
    {
        $defaultStats = [
            'totalRequestsProcessed' => 0,
            'totalExceptionsThrown' => 0,
            'totalResponseTimeMs' => 0,
        ];
        
        $stats = SharedStorage::get(self::STATS_KEY, $defaultStats);
        
        // Ensure all required keys exist (handles corrupted/partial storage data)
        $stats = array_merge($defaultStats, is_array($stats) ? $stats : []);

        $concurrent = SharedStorage::get(self::CONCURRENT_KEY, 0);

        $avgResponseTime = $stats['totalRequestsProcessed'] > 0
            ? $stats['totalResponseTimeMs'] / $stats['totalRequestsProcessed']
            : 0;

        return [
            'currentConcurrentRequests' => $concurrent,
            'totalRequestsProcessed' => $stats['totalRequestsProcessed'],
            'totalExceptionsThrown' => $stats['totalExceptionsThrown'],
            'averageResponseTimeMs' => round($avgResponseTime, 2),
        ];
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Allocates memory using a PHP string buffer.
     *
     * Uses str_repeat() to create a string of the requested size.
     * This is more memory-efficient than nested arrays and provides
     * predictable memory consumption.
     *
     * @param int $sizeKb Amount of memory to allocate in KB
     * @return string The allocated memory buffer
     */
    private static function allocateMemory(int $sizeKb): string
    {
        // Create a 1KB block using only printable characters
        // Avoids random_bytes() which can exhaust entropy under high load
        // Pattern is seeded with microtime for uniqueness per request
        $seed = substr(md5((string) microtime(true)), 0, 16);
        $block = str_repeat($seed, 64); // 16 chars × 64 = 1024 bytes
        return str_repeat($block, max(1, $sizeKb));
    }

    /**
     * Touches memory to prevent optimization/GC.
     *
     * @param string &$memory The memory buffer to touch
     */
    private static function touchMemory(string &$memory): void
    {
        // Read a character from random positions to prevent optimization
        $len = strlen($memory);
        if ($len > 0) {
            $pos = mt_rand(0, $len - 1);
            $_ = ord($memory[$pos]);
        }
    }

    /**
     * Performs real CPU-intensive work using hash_pbkdf2.
     *
     * Uses the same approach as cpu-worker.php for consistency.
     * Each hash_pbkdf2 call with 1000 iterations takes ~1-2ms.
     *
     * @param float $workMs Target milliseconds of CPU work
     * @return float Actual milliseconds of CPU work performed
     */
    private static function performCpuWork(float $workMs): float
    {
        if ($workMs <= 0) return 0;
        
        $startTime = microtime(true);
        $endTime = $startTime + ($workMs / 1000);
        
        // Each hash_pbkdf2 call does real cryptographic work
        // This produces measurable CPU load in monitoring tools
        while (microtime(true) < $endTime) {
            hash_pbkdf2('sha256', 'loadtest', 'salt', 1000, 32, false);
        }
        
        return (microtime(true) - $startTime) * 1000;
    }

    /**
     * Ensures the temp directory exists for file I/O work.
     */
    private static function ensureTempDir(): void
    {
        if (!is_dir(self::TEMP_DIR)) {
            @mkdir(self::TEMP_DIR, 0755, true);
        }
    }

    /**
     * Performs file I/O work - writes and reads data to/from disk.
     * This creates real I/O load visible in disk metrics.
     *
     * @param string $tempFile Path to temp file
     * @param int $sizeKb Amount of data to write/read in KB
     * @return float Milliseconds spent on I/O
     */
    private static function performFileIoWork(string $tempFile, int $sizeKb): float
    {
        if ($sizeKb <= 0) return 0;

        $startTime = microtime(true);

        // Generate data to write (use random-ish content to prevent compression)
        $data = '';
        for ($i = 0; $i < $sizeKb; $i++) {
            $data .= md5((string)($i + microtime(true))) . str_repeat('x', 992); // ~1KB chunks
        }

        // Write to file (creates real disk I/O)
        file_put_contents($tempFile, $data);

        // Read it back (forces actual disk read, not just cache)
        clearstatcache(true, $tempFile);
        $readData = file_get_contents($tempFile);

        // Verify to prevent optimization
        if (strlen($readData) !== strlen($data)) {
            error_log("[LoadTest] File I/O verification failed");
        }

        return (microtime(true) - $startTime) * 1000;
    }

    /**
     * Performs memory churn work - allocates, serializes, and frees memory.
     * This creates real memory pressure visible in memory metrics.
     *
     * @param int $sizeKb Amount of memory to churn in KB
     * @return float Milliseconds spent on memory operations
     */
    private static function performMemoryChurnWork(int $sizeKb): float
    {
        if ($sizeKb <= 0) return 0;

        $startTime = microtime(true);

        // Create a complex nested array structure (harder to optimize away)
        $depth = 10;
        $elementsPerLevel = max(1, (int)($sizeKb / $depth));
        
        $data = [];
        for ($i = 0; $i < $elementsPerLevel; $i++) {
            $nested = ['id' => $i, 'timestamp' => microtime(true)];
            $current = &$nested;
            for ($d = 0; $d < $depth; $d++) {
                $current['data'] = str_repeat('x', 100);
                $current['child'] = ['level' => $d];
                $current = &$current['child'];
            }
            $data[] = $nested;
        }

        // Serialize to string (forces memory allocation for string representation)
        $serialized = serialize($data);

        // Unserialize (more memory allocation)
        $restored = unserialize($serialized);

        // Force use of restored data to prevent optimization
        $checksum = count($restored);

        // Explicit cleanup (triggers GC work)
        unset($data, $serialized, $restored);

        return (microtime(true) - $startTime) * 1000;
    }

    /**
     * Performs JSON processing work - encodes and decodes nested structures.
     * This creates real CPU + memory load typical of API processing.
     *
     * @param int $depth Nesting depth of JSON structure
     * @return float Milliseconds spent on JSON operations
     */
    private static function performJsonWork(int $depth): float
    {
        if ($depth <= 0) return 0;

        $startTime = microtime(true);

        // Build a deeply nested structure (simulates complex API payloads)
        $data = [
            'requestId' => uniqid('req_'),
            'timestamp' => date('c'),
            'metadata' => [
                'version' => '1.0',
                'source' => 'loadtest',
                'correlationId' => md5((string)microtime(true)),
            ],
            'payload' => self::buildNestedJson($depth),
        ];

        // Encode to JSON (CPU + memory for string building)
        $json = json_encode($data, JSON_PRETTY_PRINT);

        // Decode back (CPU + memory for parsing)
        $decoded = json_decode($json, true);

        // Re-encode with different options (more work)
        $reencoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Force use to prevent optimization
        $len = strlen($reencoded);

        return (microtime(true) - $startTime) * 1000;
    }

    /**
     * Builds a nested JSON-compatible array structure.
     */
    private static function buildNestedJson(int $depth, int $currentDepth = 0): array
    {
        if ($currentDepth >= $depth) {
            return [
                'leaf' => true,
                'value' => md5((string)microtime(true)),
                'data' => str_repeat('payload_', 10),
            ];
        }

        return [
            'level' => $currentDepth,
            'items' => array_map(
                fn($i) => self::buildNestedJson($depth, $currentDepth + 1),
                range(1, 3)
            ),
            'metadata' => [
                'created' => microtime(true),
                'hash' => md5((string)$currentDepth),
            ],
        ];
    }

    /**
     * Checks if elapsed time exceeds threshold and randomly throws an exception.
     *
     * @param float $startTime Request start time (microtime)
     */
    private static function checkAndThrowException(float $startTime): void
    {
        $elapsedSeconds = microtime(true) - $startTime;

        if ($elapsedSeconds > self::EXCEPTION_THRESHOLD_SECONDS) {
            if (mt_rand() / mt_getrandmax() < self::EXCEPTION_PROBABILITY) {
                $exceptions = self::getExceptionPool();
                $idx = mt_rand(0, count($exceptions) - 1);
                throw $exceptions[$idx];
            }
        }
    }

    /**
     * Returns the pool of random exceptions.
     *
     * PHP equivalents of the Node.js exception pool.
     * These produce diverse error signatures in Application Insights.
     */
    private static function getExceptionPool(): array
    {
        return [
            // Common application logic errors
            new \RuntimeException('InvalidOperationError: Operation is not valid due to current state'),
            new \InvalidArgumentException('Value does not fall within the expected range'),
            new \TypeError('Cannot access property of null'),

            // Reference/range errors
            new \OutOfRangeException('Index was outside the bounds of the array'),
            new \DomainException('The given key was not present in the dictionary'),

            // I/O and network-related
            new \RuntimeException('TimeoutError: The operation has timed out'),
            new \RuntimeException('IOException: Unable to read data from the transport connection'),
            new \RuntimeException('HttpRequestError: An error occurred while sending the request'),

            // Math and format errors
            new \DivisionByZeroError('Attempted to divide by zero'),
            new \UnexpectedValueException('Input string was not in a correct format'),
            new \OverflowException('Arithmetic operation resulted in an overflow'),

            // Async-related
            new \RuntimeException('AbortError: The operation was aborted'),
            new \RuntimeException('OperationCancelledError: The operation was canceled'),

            // Scary ones
            new \RuntimeException('OutOfMemoryError: Insufficient memory to continue execution'),
            new \RuntimeException('StackOverflowError: Maximum call stack size exceeded'),
        ];
    }

    /**
     * Builds the load test result array.
     */
    private static function buildResult(
        int $elapsedMs,
        int $concurrentRequests,
        float $targetDurationMs,
        array $workMetrics,
        int $bufferSizeBytes,
        bool $workCompleted,
        bool $exceptionThrown,
        ?string $exceptionType
    ): array {
        return [
            'elapsedMs' => $elapsedMs,
            'concurrentRequestsAtStart' => $concurrentRequests,
            'targetDurationMs' => (int) $targetDurationMs,
            'workCycles' => $workMetrics['cycles'],
            'cpuWorkMs' => (int) $workMetrics['cpuWorkMs'],
            'fileIoMs' => (int) $workMetrics['fileIoMs'],
            'memoryChurnMs' => (int) $workMetrics['memoryChurnMs'],
            'jsonWorkMs' => (int) $workMetrics['jsonWorkMs'],
            'memoryAllocatedBytes' => $workCompleted ? $bufferSizeBytes : 0,
            'workCompleted' => $workCompleted,
            'exceptionThrown' => $exceptionThrown,
            'exceptionType' => $exceptionType,
            'timestamp' => date('c'),
        ];
    }

    // =========================================================================
    // CONCURRENT COUNTER (SharedStorage-based atomic operations)
    // =========================================================================

    private static function incrementConcurrent(): int
    {
        return SharedStorage::modify(self::CONCURRENT_KEY, function (?int $count) {
            return ($count ?? 0) + 1;
        }, 0);
    }

    private static function decrementConcurrent(): int
    {
        return SharedStorage::modify(self::CONCURRENT_KEY, function (?int $count) {
            return max(0, ($count ?? 0) - 1);
        }, 0);
    }

    private static function incrementStat(string $stat): void
    {
        $defaultStats = [
            'totalRequestsProcessed' => 0,
            'totalExceptionsThrown' => 0,
            'totalResponseTimeMs' => 0,
        ];
        SharedStorage::modify(self::STATS_KEY, function ($stats) use ($stat, $defaultStats) {
            // Handle null, empty array, or non-array values
            if (!is_array($stats) || empty($stats)) {
                $stats = $defaultStats;
            } else {
                $stats = array_merge($defaultStats, $stats);
            }
            $stats[$stat] = ($stats[$stat] ?? 0) + 1;
            return $stats;
        }, $defaultStats);
    }

    private static function updateStats(int $elapsedMs): void
    {
        $defaultStats = [
            'totalRequestsProcessed' => 0,
            'totalExceptionsThrown' => 0,
            'totalResponseTimeMs' => 0,
        ];
        SharedStorage::modify(self::STATS_KEY, function ($stats) use ($elapsedMs, $defaultStats) {
            // Handle null, empty array, or non-array values
            if (!is_array($stats) || empty($stats)) {
                $stats = $defaultStats;
            } else {
                $stats = array_merge($defaultStats, $stats);
            }
            $stats['totalRequestsProcessed']++;
            $stats['totalResponseTimeMs'] += $elapsedMs;
            return $stats;
        }, $defaultStats);
    }
}
