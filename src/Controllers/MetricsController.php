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
     * Lightweight probe endpoint for latency monitoring.
     *
     * The client-side JavaScript probes this endpoint every 100ms and
     * measures the round-trip time. This replaces the Node.js sidecar
     * approach — in PHP, separate FPM workers can respond even when
     * one worker is blocked, making client-side probing reliable.
     */
    public static function probe(): array
    {
        $stats = LoadTestService::getCurrentStats();

        return [
            'ts' => (int) (microtime(true) * 1000),
            'pid' => getmypid(),
            'loadTest' => [
                'active' => $stats['currentConcurrentRequests'] > 0,
                'concurrent' => $stats['currentConcurrentRequests'],
            ],
        ];
    }
}
