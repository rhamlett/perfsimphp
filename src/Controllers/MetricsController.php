<?php
/**
 * =============================================================================
 * METRICS CONTROLLER — System Metrics & Probe Endpoints
 * =============================================================================
 *
 * ENDPOINTS:
 *   GET /api/metrics       → Full system metrics snapshot (CPU, memory, process)
 *   GET /api/metrics/probe → Lightweight probe for client-side latency measurement
 *
 * NOTE: Real-time metrics are delivered via AJAX polling from the client.
 *       The client polls /api/metrics every 500ms for dashboard updates.
 *
 * @module src/Controllers/MetricsController.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Controllers;

use PerfSimPhp\Services\MetricsService;
use PerfSimPhp\Services\LoadTestService;
use PerfSimPhp\Services\SimulationTrackerService;
use PerfSimPhp\Services\MemoryPressureService;
use PerfSimPhp\Services\BlockingService;

class MetricsController
{
    /**
     * GET /api/metrics
     * Returns current system metrics snapshot.
     */
    public static function index(): array
    {
        return MetricsService::getMetrics();
    }

    /**
     * GET /api/metrics/probe
     * Probe endpoint for latency monitoring with REAL simulation effects.
     *
     * When simulations are active, this probe performs REAL work that causes
     * REAL latency - no artificial delays. This demonstrates actual performance
     * degradation for educational purposes.
     * 
     * Note: CPU stress latency occurs naturally because PHP-FPM workers compete
     * with cpu-worker processes for CPU time. We don't add extra hash work here.
     */
    public static function probe(): array
    {
        $stats = LoadTestService::getCurrentStats();
        $workDone = [];
        
        // Note: We no longer do explicit CPU work in probe for CPU_STRESS simulations.
        // The latency increase during CPU stress is natural - PHP-FPM workers compete
        // for CPU with the spawned cpu-worker processes. Adding hash work here was
        // causing 100% CPU after restarts due to stale simulation records.
        
        // Blocking: Check if any blocking simulations are within their time window
        // Unlike CPU stress, blocking is synchronous so we use time window instead of ACTIVE status
        $blockingWork = BlockingService::performBlockingIfActive();
        if ($blockingWork) {
            $workDone['blocking'] = $blockingWork;
        }
        $blockingSimCount = $blockingWork ? 1 : 0;
        
        // Memory pressure active: Load allocated memory INTO this worker's heap
        // This creates REAL memory pressure - the worker's RSS will increase
        $memorySims = SimulationTrackerService::getActiveSimulationsByType('MEMORY_PRESSURE');
        if (count($memorySims) > 0) {
            $totalMb = MemoryPressureService::getTotalAllocatedMb();
            if ($totalMb > 0) {
                // Load data into worker heap (capped at 256MB to prevent OOM)
                $memoryLoad = MemoryPressureService::loadIntoWorker(256);
                $workDone['memory'] = [
                    'allocatedMb' => $totalMb,
                    'loadedMb' => $memoryLoad['loadedMb'],
                    'method' => $memoryLoad['method'],
                    'rssBefore' => $memoryLoad['workerRssBefore'],
                    'rssAfter' => $memoryLoad['workerRssAfter'],
                ];
            }
        }
        
        return [
            'ts' => (int) (microtime(true) * 1000),
            'pid' => getmypid(),
            'workDone' => $workDone,
            'loadTest' => [
                'active' => $stats['currentConcurrentRequests'] > 0,
                'concurrent' => $stats['currentConcurrentRequests'],
            ],
            // Debug: show active simulation counts
            '_debug' => [
                'blockingActive' => $blockingSimCount > 0,
                'memorySimCount' => count($memorySims ?? []),
            ],
        ];
    }

    /**
     * GET /api/metrics/internal-probe
     * NON-BLOCKING latency data endpoint.
     * 
     * Returns sampled latencies from load test requests stored in APCu.
     * This endpoint NEVER makes blocking calls to the main FPM pool.
     * 
     * Architecture:
     *   - Load test requests sample their own latency (1 in 10) via LoadTestService
     *   - Latencies are stored in APCu shared memory
     *   - This endpoint reads from APCu and returns the data
     *   - Client displays the latencies on the chart
     * 
     * This design ensures the metrics pool (port 9001) remains responsive even
     * when the main pool (port 9000) is saturated by load test traffic.
     */
    public static function internalProbe(): array
    {
        // Get sampled latencies from APCu (non-blocking read)
        $latencies = LoadTestService::getSampledLatencies(0);
        
        return [
            'success' => true,
            'timestamp' => (int) (microtime(true) * 1000),
            'latencies' => $latencies,
            'count' => count($latencies),
            'source' => 'apcu-sampled',
        ];
    }
}
