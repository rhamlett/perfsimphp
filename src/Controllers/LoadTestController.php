<?php
/**
 * =============================================================================
 * LOAD TEST CONTROLLER — Azure Load Testing Integration REST API
 * =============================================================================
 *
 * ENDPOINTS:
 *   GET /api/loadtest       → Execute load test work (all params optional query params)
 *   GET /api/loadtest/stats → Current statistics without performing work
 *
 * Designed for Azure Load Testing, JMeter, k6, Gatling. Does NOT appear
 * in the dashboard UI — it's meant for automated load testing tools.
 *
 * DEGRADATION BEHAVIOR:
 *   Below soft limit:  ~baselineDelayMs response time (default 1000ms)
 *   At soft limit:     Response time starts increasing
 *   Above soft limit:  baselineDelayMs + (concurrent - softLimit) * degradationFactor
 *   Extreme load:      Responses approach 230s Azure App Service frontend timeout
 *
 * @module src/Controllers/LoadTestController.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Controllers;

use PerfSimPhp\Services\LoadTestService;

class LoadTestController
{
    /**
     * GET /api/loadtest
     * Executes a load test request with configurable resource consumption.
     * All parameters are optional query params with sensible defaults.
     */
    public static function execute(): void
    {
        // Parse optional query parameters
        $request = [];
        $optionalParams = ['workIterations', 'bufferSizeKb', 'baselineDelayMs', 'softLimit', 'degradationFactor'];

        foreach ($optionalParams as $param) {
            if (isset($_GET[$param]) && is_numeric($_GET[$param])) {
                $request[$param] = (int) $_GET[$param];
            }
        }

        try {
            $result = LoadTestService::executeWork($request);
            echo json_encode($result);
        } catch (\Throwable $e) {
            // Re-throw to let the error handler produce a 500
            throw $e;
        }
    }

    /**
     * GET /api/loadtest/stats
     * Returns current load test statistics without performing work.
     */
    public static function stats(): void
    {
        $stats = LoadTestService::getCurrentStats();
        echo json_encode($stats);
    }
}
