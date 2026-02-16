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
        
        // Memory pressure active: Touch the allocated memory (read it)
        $memorySims = SimulationTrackerService::getActiveSimulationsByType('MEMORY_PRESSURE');
        if (count($memorySims) > 0) {
            // Read from shared storage to cause real memory access
            $totalMb = MemoryPressureService::getTotalAllocatedMb();
            if ($totalMb > 0) {
                // Access the allocations to prevent optimization
                $allocations = MemoryPressureService::getActiveAllocations();
                $workDone['memory'] = $totalMb . 'MB';
            }
        }
        
        // Slow requests active: Do real I/O work (file operations)
        $slowSims = SimulationTrackerService::getActiveSimulationsByType('SLOW_REQUEST');
        if (count($slowSims) > 0) {
            // Perform real file I/O proportional to active slow requests
            $tempFile = sys_get_temp_dir() . '/probe_' . getmypid() . '.tmp';
            $data = str_repeat('X', 1024 * count($slowSims)); // 1KB per active slow request
            file_put_contents($tempFile, $data);
            $read = file_get_contents($tempFile);
            @unlink($tempFile);
            $workDone['io'] = strlen($read);
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
                'slowSimCount' => count($slowSims ?? []),
            ],
        ];
    }
}
