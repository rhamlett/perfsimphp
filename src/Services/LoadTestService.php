<?php
/**
 * =============================================================================
 * LOAD TEST SERVICE — Simulated Application Under Load
 * =============================================================================
 *
 * PURPOSE:
 *   Provides a load test endpoint designed for Azure Load Testing (or similar
 *   tools like JMeter, k6, Gatling). Simulates realistic application behavior
 *   that degrades gracefully under increasing concurrency, eventually leading
 *   to the 230-second Azure App Service frontend timeout.
 *
 * QUERY PARAMETERS:
 *   - cpuWorkMs (default: 100)         — Milliseconds of real CPU work per cycle
 *   - memorySizeKb (default: 10000)    — KB of memory to allocate (10MB default)
 *   - baselineDelayMs (default: 1000)  — Base response time before degradation
 *   - softLimit (default: 20)          — Concurrent requests before degradation starts
 *   - degradationFactor (default: 1000)— Ms added per concurrent request over softLimit
 *
 * ALGORITHM (per request):
 *   1. INCREMENT concurrent request counter (via SharedStorage — atomic modify)
 *   2. ALLOCATE MEMORY: PHP string buffer to consume process memory
 *   3. CALCULATE TOTAL DELAY:
 *      totalDelay = baselineDelayMs + max(0, concurrent - softLimit) * degradationFactor
 *      Example with defaults: 30 concurrent = 1000 + (30-20)*1000 = 11000ms
 *   4. SUSTAINED WORK LOOP: interleave real CPU work (hash_pbkdf2) and usleep()
 *   5. RANDOM EXCEPTIONS: After 120s elapsed, 20% chance per cycle
 *   6. DECREMENT counter; return timing diagnostics
 *
 * PHP vs NODE.JS DIFFERENCES:
 *   - Concurrent counter uses SharedStorage (APCu/file) because each PHP
 *     request is a separate process — no shared memory by default.
 *   - Memory allocation is within the current request (process memory),
 *     released when request completes (unlike Node.js persistent heap).
 *   - usleep() is used instead of async sleep (PHP is synchronous).
 *   - CPU work uses hash_pbkdf2() for measurable CPU load in metrics.
 *
 * EXCEPTION POOL:
 *   15 different exception types simulating real-world PHP failures.
 *   These produce diverse error signatures in Application Insights.
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

    /** Time threshold in seconds after which exceptions may be thrown */
    private const EXCEPTION_THRESHOLD_SECONDS = 120;

    /** Probability of throwing exception per check after threshold */
    private const EXCEPTION_PROBABILITY = 0.20;

    /** Milliseconds of usleep per cycle in the sustained work loop */
    private const SLEEP_PER_CYCLE_MS = 50;

    /** Default request parameters */
    private const DEFAULTS = [
        'cpuWorkMs' => 100,          // Ms of real CPU work per cycle (hash_pbkdf2)
        'memorySizeKb' => 10000,     // 10MB per request (increase to stress memory)
        'baselineDelayMs' => 1000,   // Base response time before degradation
        'softLimit' => 20,           // Concurrent requests before degradation
        'degradationFactor' => 1000, // Ms added per request over softLimit
    ];

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
        // Merge with defaults
        $params = array_merge(self::DEFAULTS, $request);

        // Increment concurrent counter
        $currentConcurrent = self::incrementConcurrent();

        // Log load test start with concurrency info
        $overLimit = max(0, $currentConcurrent - $params['softLimit']);
        $expectedDegradation = $overLimit * $params['degradationFactor'];
        EventLogService::info(
            'LOADTEST_START',
            "Load test request started (concurrent: {$currentConcurrent}, degradation: {$expectedDegradation}ms)",
            null,
            'loadtest',
            [
                'concurrent' => $currentConcurrent,
                'softLimit' => $params['softLimit'],
                'overLimit' => $overLimit,
                'expectedDegradationMs' => $expectedDegradation,
                'baselineDelayMs' => $params['baselineDelayMs'],
            ]
        );

        $startTime = microtime(true);
        $totalCpuWorkDone = 0;
        $workCompleted = false;
        $allocatedBytes = $params['memorySizeKb'] * 1024;
        $memory = null;

        try {
            // -----------------------------------------------------------------
            // STEP 1: ALLOCATE MEMORY
            //
            // Allocate a string buffer on the PHP process heap.
            // This is visible in memory_get_usage() and Azure metrics.
            // Memory is released when this request completes.
            // -----------------------------------------------------------------
            $memory = self::allocateMemory($params['memorySizeKb']);

            // -----------------------------------------------------------------
            // STEP 2: CALCULATE TOTAL REQUEST DURATION
            // Formula: baselineDelayMs + max(0, concurrent - softLimit) * degradationFactor
            // -----------------------------------------------------------------
            $overLimit = max(0, $currentConcurrent - $params['softLimit']);
            $degradationDelayMs = $overLimit * $params['degradationFactor'];
            $totalDurationMs = $params['baselineDelayMs'] + $degradationDelayMs;

            // -----------------------------------------------------------------
            // STEP 3: SUSTAINED WORK LOOP
            // Interleave real CPU work (hash_pbkdf2) and usleep() until done.
            // -----------------------------------------------------------------
            $cpuWorkMsPerCycle = $params['cpuWorkMs'];

            while ((microtime(true) - $startTime) * 1000 < $totalDurationMs) {
                // CPU work phase - uses hash_pbkdf2 for real measurable CPU load
                if ($cpuWorkMsPerCycle > 0) {
                    $actualWorkMs = self::performCpuWork($cpuWorkMsPerCycle);
                    $totalCpuWorkDone += $actualWorkMs;
                }

                // Touch memory to prevent optimization
                self::touchMemory($memory);

                // Check for timeout exception (20% chance after 120s)
                self::checkAndThrowException($startTime);

                // Sleep phase (yield CPU)
                $remainingMs = $totalDurationMs - ((microtime(true) - $startTime) * 1000);
                $sleepMs = min(self::SLEEP_PER_CYCLE_MS, max(0, $remainingMs));
                if ($sleepMs > 0) {
                    usleep((int) ($sleepMs * 1000));
                }
            }

            $workCompleted = true;
            $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);

            return self::buildResult(
                $elapsedMs,
                $currentConcurrent,
                $totalDurationMs,
                $totalCpuWorkDone,
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

            // Log completion
            EventLogService::info(
                'LOADTEST_COMPLETE',
                "Load test completed in {$elapsedMs}ms (concurrent was: {$currentConcurrent})",
                null,
                'loadtest',
                [
                    'elapsedMs' => $elapsedMs,
                    'concurrentAtStart' => $currentConcurrent,
                    'memoryAllocatedKb' => $params['memorySizeKb'],
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
        float $degradationDelayMs,
        float $totalCpuWork,
        int $bufferSizeBytes,
        bool $workCompleted,
        bool $exceptionThrown,
        ?string $exceptionType
    ): array {
        return [
            'elapsedMs' => $elapsedMs,
            'concurrentRequestsAtStart' => $concurrentRequests,
            'degradationDelayAppliedMs' => (int) $degradationDelayMs,
            'cpuWorkCompletedMs' => $workCompleted ? (int) $totalCpuWork : 0,
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
