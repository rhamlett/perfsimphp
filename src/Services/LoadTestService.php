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
 * ALGORITHM (per request):
 *   1. INCREMENT concurrent request counter (via SharedStorage — atomic modify)
 *   2. ALLOCATE MEMORY: PHP arrays to consume managed memory
 *   3. CALCULATE TOTAL DELAY:
 *      totalDelay = baselineDelayMs + max(0, concurrent - softLimit) * degradationFactor
 *      Example with defaults: 30 concurrent = 1000 + (30-20)*1000 = 11000ms
 *   4. SUSTAINED WORK LOOP: interleave CPU work and usleep() yields
 *   5. RANDOM EXCEPTIONS: After 120s elapsed, 20% chance per cycle
 *   6. DECREMENT counter; return timing diagnostics
 *
 * PHP vs NODE.JS DIFFERENCES:
 *   - Concurrent counter uses SharedStorage (APCu/file) because each PHP
 *     request is a separate process — no shared memory by default.
 *   - Memory allocation is within the current request (process memory),
 *     not split between heap/native as in Node.js.
 *   - usleep() is used instead of async sleep (PHP is synchronous).
 *   - No Buffer.alloc() equivalent; PHP strings serve the same purpose.
 *
 * EXCEPTION POOL:
 *   17 different exception types simulating real-world PHP failures.
 *   These produce diverse error signatures in Application Insights.
 *
 * @module src/Services/LoadTestService.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Services;

use PerfSimPhp\SharedStorage;

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
        'workIterations' => 700,
        'bufferSizeKb' => 100000,
        'baselineDelayMs' => 1000,
        'softLimit' => 20,
        'degradationFactor' => 1000,
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

        $startTime = microtime(true);
        $totalCpuWorkDone = 0;
        $workCompleted = false;
        $allocatedBytes = $params['bufferSizeKb'] * 1024;
        $memory = null;

        try {
            // -----------------------------------------------------------------
            // STEP 1: ALLOCATE MEMORY
            //
            // In PHP, we allocate arrays on the current process heap.
            // This is visible in memory_get_usage() and Azure metrics.
            // -----------------------------------------------------------------
            $memory = self::allocateMemory($params['bufferSizeKb']);

            // -----------------------------------------------------------------
            // STEP 2: CALCULATE TOTAL REQUEST DURATION
            // Formula: baselineDelayMs + max(0, concurrent - softLimit) * degradationFactor
            // -----------------------------------------------------------------
            $overLimit = max(0, $currentConcurrent - $params['softLimit']);
            $degradationDelayMs = $overLimit * $params['degradationFactor'];
            $totalDurationMs = $params['baselineDelayMs'] + $degradationDelayMs;

            // -----------------------------------------------------------------
            // STEP 3: SUSTAINED WORK LOOP
            // Interleave CPU work and brief sleeps until total duration reached.
            // -----------------------------------------------------------------
            $cpuWorkMsPerCycle = $params['workIterations'] / 10;

            while ((microtime(true) - $startTime) * 1000 < $totalDurationMs) {
                // CPU work phase
                if ($cpuWorkMsPerCycle > 0) {
                    self::performCpuWork($cpuWorkMsPerCycle);
                    $totalCpuWorkDone += $cpuWorkMsPerCycle;
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

            error_log("[LoadTest] Exception after {$elapsedMs}ms: " . get_class($e) . " - {$e->getMessage()}");

            throw $e;
        } finally {
            // Decrement concurrent counter
            self::decrementConcurrent();

            // Update stats
            $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);
            self::updateStats($elapsedMs);

            // Release memory
            $memory = null;
        }
    }

    /**
     * Gets current load test statistics.
     */
    public static function getCurrentStats(): array
    {
        $stats = SharedStorage::get(self::STATS_KEY, [
            'totalRequestsProcessed' => 0,
            'totalExceptionsThrown' => 0,
            'totalResponseTimeMs' => 0,
        ]);

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
     * Allocates memory using PHP arrays.
     *
     * Creates arrays of random values that occupy approximately the
     * requested amount of memory on the PHP heap.
     *
     * @param int $sizeKb Amount of memory to allocate in KB
     * @return array The allocated memory array
     */
    private static function allocateMemory(int $sizeKb): array
    {
        $memory = [];
        // Each entry is approximately 1KB (128 doubles × 8 bytes)
        for ($i = 0; $i < $sizeKb; $i++) {
            $chunk = [];
            for ($j = 0; $j < 128; $j++) {
                $chunk[] = mt_rand() / mt_getrandmax();
            }
            $memory[] = $chunk;
        }
        return $memory;
    }

    /**
     * Touches memory to prevent optimization/GC.
     *
     * @param array &$memory The memory array to touch
     */
    private static function touchMemory(array &$memory): void
    {
        // Touch every 4th chunk
        $len = count($memory);
        for ($i = 0; $i < $len; $i += 4) {
            if (isset($memory[$i][0])) {
                $memory[$i][0] += 0.001;
            }
        }
    }

    /**
     * Performs CPU-intensive work for the specified duration.
     *
     * @param float $workMs Milliseconds of CPU work
     */
    private static function performCpuWork(float $workMs): void
    {
        if ($workMs <= 0) return;
        $endTime = microtime(true) + ($workMs / 1000);
        while (microtime(true) < $endTime) {
            // Spin loop — Date.now() equivalent prevents optimization
        }
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
            'workIterationsCompleted' => $workCompleted ? (int) $totalCpuWork : 0,
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
        SharedStorage::modify(self::STATS_KEY, function (?array $stats) use ($stat) {
            $stats = $stats ?? [
                'totalRequestsProcessed' => 0,
                'totalExceptionsThrown' => 0,
                'totalResponseTimeMs' => 0,
            ];
            $stats[$stat] = ($stats[$stat] ?? 0) + 1;
            return $stats;
        }, []);
    }

    private static function updateStats(int $elapsedMs): void
    {
        SharedStorage::modify(self::STATS_KEY, function (?array $stats) use ($elapsedMs) {
            $stats = $stats ?? [
                'totalRequestsProcessed' => 0,
                'totalExceptionsThrown' => 0,
                'totalResponseTimeMs' => 0,
            ];
            $stats['totalRequestsProcessed']++;
            $stats['totalResponseTimeMs'] += $elapsedMs;
            return $stats;
        }, []);
    }
}
