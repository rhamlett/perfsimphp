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
     * GET /api/metrics/internal-probes
     * Performs batch internal probes via localhost:8080 (bypasses stamp frontend).
     * 
     * This endpoint does multiple curl requests to localhost:8080/api/metrics/probe
     * and returns the latency measurements. The internal requests don't go through
     * Azure's front-end infrastructure, so they don't appear in AppLens.
     * 
     * Query params:
     *   count - Number of probes to perform (default: 5, max: 10)
     *   interval - Milliseconds between probes (default: 100, min: 50)
     */
    public static function internalProbes(): array
    {
        try {
            $count = min((int) ($_GET['count'] ?? 5), 10);
            $intervalMs = max((int) ($_GET['interval'] ?? 100), 50);
            
            // Hardcode port 8080 - this is Azure App Service standard
            $port = '8080';
            $baseUrl = "http://127.0.0.1:{$port}/api/metrics/probe";
            
            // Check if curl is available
            if (!function_exists('curl_init')) {
                return [
                    'error' => 'curl extension not available',
                    'probes' => [],
                    'count' => 0,
                ];
            }
            
            $results = [];
            $stats = LoadTestService::getCurrentStats();
            
            for ($i = 0; $i < $count; $i++) {
                $probeStart = microtime(true);
                
                // Send internal probe request
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $baseUrl . '?t=' . microtime(true),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_HTTPHEADER => ['X-Internal-Probe: true'],
                ]);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                $errno = curl_errno($ch);
                curl_close($ch);
                
                $probeEnd = microtime(true);
                $latencyMs = ($probeEnd - $probeStart) * 1000;
                
                $probeResult = [
                    'latencyMs' => round($latencyMs, 2),
                    'timestamp' => (int) ($probeEnd * 1000),
                    'success' => $httpCode === 200 && empty($error),
                    'loadTestActive' => $stats['currentConcurrentRequests'] > 0,
                    'loadTestConcurrent' => $stats['currentConcurrentRequests'],
                ];
                
                // Add debug info if probe failed
                if ($httpCode !== 200 || !empty($error)) {
                    $probeResult['_debug'] = [
                        'httpCode' => $httpCode,
                        'error' => $error,
                        'errno' => $errno,
                        'url' => $baseUrl,
                    ];
                }
                
                $results[] = $probeResult;
                
                // Wait between probes (except after last one)
                if ($i < $count - 1) {
                    usleep($intervalMs * 1000);
                }
            }
            
            return [
                'probes' => $results,
                'count' => count($results),
                'intervalMs' => $intervalMs,
                'pid' => getmypid(),
                'internalPort' => $port,
            ];
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'probes' => [],
                'count' => 0,
            ];
        }
    }
}
