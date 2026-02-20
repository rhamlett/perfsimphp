<?php
/**
 * =============================================================================
 * LOAD TEST CONTROLLER — Simple Load Testing REST API
 * =============================================================================
 *
 * ENDPOINTS:
 *   GET /api/loadtest       → Execute load test work
 *   GET /api/loadtest/stats → Current FPM worker statistics
 *
 * Designed for Azure Load Testing, JMeter, k6, Gatling.
 *
 * PARAMETERS:
 *   - workMs (int)    : Duration of CPU work in ms (default: 100, max: 5000)
 *   - memoryKb (int)  : Memory to allocate in KB (default: 5000, max: 50000)
 *   - holdMs (int)    : Hold memory after CPU work in ms (default: 500, max: 5000)
 *
 * EXAMPLES:
 *   GET /api/loadtest
 *   GET /api/loadtest?workMs=200
 *   GET /api/loadtest?workMs=50&memoryKb=10000&holdMs=1000
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
     * Executes a load test request with configurable work duration.
     */
    public static function execute(): void
    {
        $request = [];
        
        // Parse parameters
        if (isset($_GET['workMs']) && is_numeric($_GET['workMs'])) {
            $request['workMs'] = (int) $_GET['workMs'];
        }
        if (isset($_GET['memoryKb']) && is_numeric($_GET['memoryKb'])) {
            $request['memoryKb'] = (int) $_GET['memoryKb'];
        }
        if (isset($_GET['holdMs']) && is_numeric($_GET['holdMs'])) {
            $request['holdMs'] = (int) $_GET['holdMs'];
        }
        
        // Legacy parameter support
        if (isset($_GET['targetDurationMs']) && is_numeric($_GET['targetDurationMs'])) {
            $request['targetDurationMs'] = (int) $_GET['targetDurationMs'];
        }
        if (isset($_GET['memorySizeKb']) && is_numeric($_GET['memorySizeKb'])) {
            $request['memorySizeKb'] = (int) $_GET['memorySizeKb'];
        }

        try {
            $result = LoadTestService::executeWork($request);
            echo json_encode($result);
        } catch (\Throwable $e) {
            error_log("[LoadTestController] " . get_class($e) . ": " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * GET /api/loadtest/stats
     * Returns current FPM worker statistics.
     */
    public static function stats(): void
    {
        $stats = LoadTestService::getCurrentStats();
        echo json_encode($stats);
    }
}

