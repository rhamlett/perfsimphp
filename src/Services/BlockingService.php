<?php
/**
 * =============================================================================
 * BLOCKING SERVICE — Request Thread Blocking Simulation
 * =============================================================================
 *
 * FEATURE REQUIREMENTS (language-agnostic):
 *   This service must demonstrate the impact of blocking operations that:
 *   1. Causes visible latency spike in HTTP response times
 *   2. Demonstrates worker/thread pool exhaustion under load
 *   3. Shows what happens when sync-over-async antipatterns occur
 *   4. Can block multiple workers simultaneously to amplify effect
 *   5. Self-recovers after configured duration
 *
 * WHAT THIS SIMULATES:
 *   Real-world scenarios that cause this problem:
 *   - Synchronous HTTP calls (file_get_contents to external APIs)
 *   - Blocking database queries without connection pooling
 *   - Heavy computation on the request thread
 *   - Synchronous file I/O in async contexts
 *
 * HOW IT WORKS (this implementation):
 *   1. User triggers blocking with duration + worker count
 *   2. A time window is set (current time + duration) in shared storage
 *   3. The server spawns concurrent curl requests to itself
 *   4. Each request performs CPU-intensive work, blocking its FPM worker
 *   5. All probe requests during this window show increased latency
 *
 * PORTING NOTES:
 *   The blocking mechanism differs significantly by runtime:
 *
 *   Node.js (Event Loop Blocking):
 *     - Block the single-threaded event loop with sync operations
 *     - while(Date.now() < endTime) { heavyComputation(); }
 *     - This blocks ALL requests, not just some workers
 *     - More dramatic effect than PHP's worker-based blocking
 *
 *   Java (Thread Pool Blocking):
 *     - Block Tomcat/Jetty worker threads with Thread.sleep() or computation
 *     - Use CountDownLatch or synchronized blocks to hold threads
 *     - Similar worker-pool exhaustion model to PHP-FPM
 *
 *   Python (Worker Blocking):
 *     - With gunicorn: block workers with time.sleep() or computation
 *     - With asyncio: block event loop with sync operations
 *     - GIL means only one thread executes Python at a time
 *
 *   .NET (Thread Pool Blocking):
 *     - Block ThreadPool threads with Thread.Sleep() or computation
 *     - ASP.NET Core has limited request threads
 *     - Task.Run with blocking operations consumes thread pool
 *
 *   Ruby (Worker Blocking):
 *     - Block Puma/Unicorn workers with sleep or computation
 *     - Similar worker-pool model to PHP-FPM
 *
 * CROSS-PLATFORM CONSIDERATIONS:
 *   - The blocking MUST affect request handling (not background threads)
 *   - Dashboard should show latency increase during blocking
 *   - Recovery should be automatic after duration expires
 *   - Consider what "concurrent workers" means in each runtime
 *     (PHP: FPM workers, Node: doesn't apply, Java: thread pool size)
 *
 * @module src/Services/BlockingService.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Services;

use PerfSimPhp\SharedStorage;
use PerfSimPhp\Services\EventLogService;
use PerfSimPhp\Services\SimulationTrackerService;

class BlockingService
{
    private const BLOCKING_MODE_KEY = 'perfsim_blocking_mode';

    /**
     * Start blocking mode for the specified duration.
     * All probe requests during this window will perform blocking work.
     * Note: Call spawnConcurrentBlockingRequests() separately after sending response.
     *
     * @param array{durationSeconds: int, concurrentWorkers?: int} $params
     * @return array The simulation record
     */
    public static function block(array $params): array
    {
        $durationSeconds = $params['durationSeconds'];
        $concurrentWorkers = $params['concurrentWorkers'] ?? 1;
        $endTime = microtime(true) + $durationSeconds;

        // Create simulation record first
        $simulation = SimulationTrackerService::createSimulation(
            'REQUEST_BLOCKING',
            [
                'type' => 'REQUEST_BLOCKING',
                'durationSeconds' => $durationSeconds,
                'concurrentWorkers' => $concurrentWorkers,
            ],
            $durationSeconds
        );

        // Set blocking mode window with simulation ID
        SharedStorage::set(self::BLOCKING_MODE_KEY, [
            'endTime' => $endTime,
            'durationSeconds' => $durationSeconds,
            'concurrentWorkers' => $concurrentWorkers,
            'startedAt' => microtime(true),
            'simulationId' => $simulation['id'],
        ], $durationSeconds + 60); // TTL slightly longer than duration

        return $simulation;
    }

    /**
     * Spawn concurrent requests to the internal probe endpoint to block multiple FPM workers.
     * Uses curl_multi to fire requests that will each block a worker for the duration.
     * This method blocks until all requests complete or timeout.
     * 
     * Call this AFTER fastcgi_finish_request() to avoid delaying the client response.
     *
     * @param int $count Number of requests to spawn
     * @param int $durationSeconds How long each request should block
     */
    public static function spawnConcurrentBlockingRequests(int $count, int $durationSeconds): void
    {
        if ($count < 1) {
            return;
        }

        // Use localhost:8080 (Azure App Service internal port)
        $port = getenv('HTTP_PLATFORM_PORT') ?: '8080';
        $url = "http://127.0.0.1:{$port}/api/metrics/probe";

        $mh = curl_multi_init();
        $handles = [];

        for ($i = 0; $i < $count; $i++) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $durationSeconds + 5, // Allow time for blocking work
                CURLOPT_CONNECTTIMEOUT => 2,
                // Add header to identify this as a blocking request
                CURLOPT_HTTPHEADER => ['X-Blocking-Request: true'],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }

        // Execute all requests in parallel
        // This loops until all blocking requests complete (each takes ~durationSeconds)
        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                // Wait for activity on any socket (with timeout)
                curl_multi_select($mh, 0.5);
            }
        } while ($running > 0 && $status === CURLM_OK);

        // Clean up handles
        foreach ($handles as $ch) {
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }

    /**
     * Check if blocking mode is currently active.
     * If blocking has expired, cleans up and logs completion.
     *
     * @return array|null Blocking mode info if active, null otherwise
     */
    public static function getBlockingMode(): ?array
    {
        $mode = SharedStorage::get(self::BLOCKING_MODE_KEY);
        
        // No blocking mode active
        if (!$mode || !isset($mode['endTime'])) {
            return null;
        }

        if (microtime(true) > $mode['endTime']) {
            // Blocking period has ended - clean up and log completion
            SharedStorage::delete(self::BLOCKING_MODE_KEY);
            
            // Log completion event
            $duration = $mode['durationSeconds'] ?? 0;
            EventLogService::success(
                'SIMULATION_COMPLETED',
                "Request thread blocking completed after {$duration}s",
                $mode['simulationId'] ?? null,
                'REQUEST_BLOCKING'
            );
            
            // Mark simulation as completed in tracker
            if (isset($mode['simulationId'])) {
                SimulationTrackerService::completeSimulation($mode['simulationId']);
            }
            
            return null;
        }

        return $mode;
    }

    /**
     * Perform blocking work if blocking mode is active.
     * Returns the work done for debugging.
     *
     * @return array|null Work done info, or null if not in blocking mode
     */
    public static function performBlockingIfActive(): ?array
    {
        $mode = self::getBlockingMode();
        if (!$mode) {
            return null;
        }

        // Calculate how much work to do based on remaining time
        // More aggressive blocking = more iterations
        $remaining = $mode['endTime'] - microtime(true);
        $total = $mode['durationSeconds'];
        $intensity = max(0.5, min(1.0, $remaining / $total)); // 0.5 to 1.0

        // Do CPU-intensive blocking work
        // ~10-20 iterations of PBKDF2 with 10000 rounds each = 100-400ms latency
        $iterations = (int) (15 * $intensity);
        for ($i = 0; $i < $iterations; $i++) {
            hash_pbkdf2('sha512', 'blocking-probe', 'salt', 10000, 64, false);
        }

        return [
            'iterations' => $iterations,
            'intensity' => round($intensity, 2),
            'remainingSeconds' => round($remaining, 1),
        ];
    }

    /**
     * Stop blocking mode immediately.
     */
    public static function stop(): void
    {
        $mode = SharedStorage::get(self::BLOCKING_MODE_KEY);
        SharedStorage::delete(self::BLOCKING_MODE_KEY);
        
        // Mark simulation as stopped and log
        if ($mode && isset($mode['simulationId'])) {
            SimulationTrackerService::stopSimulation($mode['simulationId']);
            EventLogService::info(
                'SIMULATION_STOPPED',
                'Request thread blocking stopped manually',
                $mode['simulationId'],
                'REQUEST_BLOCKING'
            );
        }
    }
}
