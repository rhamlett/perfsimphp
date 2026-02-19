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
 * WORK TYPES (all create real, observable metrics):
 *   - CPU Work      : Cryptographic hashing (visible in CPU metrics)
 *   - File I/O      : Write/read temp files (visible in I/O metrics)
 *   - Memory Churn  : Allocate/serialize/free (visible in memory metrics)
 *   - JSON Process  : Deep encode/decode (visible in CPU + memory)
 *
 * DEGRADATION BEHAVIOR:
 *   Below soft limit: ~targetDurationMs response time
 *   Above soft limit: targetDurationMs * degradationFactor^(concurrent - softLimit)
 *   This creates exponential backpressure with real work, not artificial delays.
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
     *
     * QUERY PARAMETERS (all optional):
     *   - cpuWorkMs (int)        : Ms of CPU work per cycle (default: 50)
     *   - memorySizeKb (int)     : KB of persistent memory (default: 5000 = 5MB)
     *   - fileIoKb (int)         : KB to write/read per cycle (default: 100)
     *   - jsonDepth (int)        : Nesting depth for JSON work (default: 5)
     *   - memoryChurnKb (int)    : KB to churn per cycle (default: 500)
     *   - targetDurationMs (int) : Target request duration (default: 1000)
     *   - softLimit (int)        : Concurrent before degradation (default: 20)
     *   - degradationFactor (float): Multiplier per concurrent over limit (default: 1.5)
     *
     * EXAMPLES:
     *   GET /api/loadtest?cpuWorkMs=100&fileIoKb=200
     *   GET /api/loadtest?targetDurationMs=2000&softLimit=10
     */
    public static function execute(): void
    {
        // Parse optional query parameters (integers)
        $request = [];
        $intParams = ['cpuWorkMs', 'memorySizeKb', 'fileIoKb', 'jsonDepth', 'memoryChurnKb', 'targetDurationMs', 'softLimit'];

        foreach ($intParams as $param) {
            if (isset($_GET[$param]) && is_numeric($_GET[$param])) {
                $request[$param] = (int) $_GET[$param];
            }
        }

        // Parse float parameter
        if (isset($_GET['degradationFactor']) && is_numeric($_GET['degradationFactor'])) {
            $request['degradationFactor'] = (float) $_GET['degradationFactor'];
        }

        try {
            $result = LoadTestService::executeWork($request);
            echo json_encode($result);
        } catch (\Throwable $e) {
            // Log full details and show actual error for debugging
            error_log("[LoadTestController] " . get_class($e) . ": " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            http_response_code(500);
            echo json_encode([
                'error' => get_class($e),
                'message' => $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
            ]);
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
