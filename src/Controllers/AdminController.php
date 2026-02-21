<?php
/**
 * =============================================================================
 * ADMIN CONTROLLER — Administrative & Diagnostic REST API
 * =============================================================================
 *
 * ENDPOINTS:
 *   GET /api/simulations           → List all active simulations (any type)
 *   GET /api/admin/status          → Comprehensive status (config + simulations + metrics)
 *   GET /api/admin/events          → Recent event log entries (with limit parameter)
 *   GET /api/admin/memory-debug    → Memory diagnostic info (cgroup, OS, process)
 *   GET /api/admin/system-info     → System info (CPU count, PHP version, platform)
 *   GET /api/admin/telemetry-status → Application Insights status
 *
 * @module src/Controllers/AdminController.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Controllers;

use PerfSimPhp\Config;
use PerfSimPhp\SharedStorage;
use PerfSimPhp\Services\SimulationTrackerService;
use PerfSimPhp\Services\EventLogService;
use PerfSimPhp\Services\MetricsService;
use PerfSimPhp\Services\BlockingService;
use PerfSimPhp\Services\TelemetryService;

class AdminController
{
    /**
     * GET /api/simulations
     * Lists all active simulations of any type.
     */
    public static function listSimulations(): void
    {
        $simulations = SimulationTrackerService::getActiveSimulations();

        echo json_encode([
            'simulations' => array_map(fn($sim) => [
                'id' => $sim['id'],
                'type' => $sim['type'],
                'status' => $sim['status'],
                'parameters' => $sim['parameters'],
                'startedAt' => $sim['startedAt'],
                'scheduledEndAt' => $sim['scheduledEndAt'],
            ], $simulations),
            'count' => count($simulations),
        ]);
    }

    /**
     * GET /api/admin/status
     * Returns detailed admin status including configuration and simulations.
     */
    public static function status(): void
    {
        $simulations = SimulationTrackerService::getActiveSimulations();
        $metrics = MetricsService::getMetrics();

        echo json_encode([
            'status' => 'healthy',
            'timestamp' => date('c'),
            'version' => Config::APP_VERSION,
            'runtime' => 'PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ')',
            'config' => Config::toArray(),
            'activeSimulations' => array_map(fn($sim) => [
                'id' => $sim['id'],
                'type' => $sim['type'],
                'status' => $sim['status'],
                'parameters' => $sim['parameters'],
                'startedAt' => $sim['startedAt'],
                'scheduledEndAt' => $sim['scheduledEndAt'],
            ], $simulations),
            'simulationCount' => count($simulations),
            'metrics' => $metrics,
        ]);
    }

    /**
     * GET /api/admin/events
     * Returns recent event log entries.
     */
    public static function events(): void
    {
        // Note: Cleanup is now handled by startup cleanup and explicit stop actions.
        // We no longer trigger cleanup on every events poll to avoid CPU overhead.
        
        $limit = 50;
        if (isset($_GET['limit']) && is_numeric($_GET['limit'])) {
            $limit = max(1, min(100, (int) $_GET['limit']));
        }

        $events = EventLogService::getRecentEntries($limit);
        $total = EventLogService::getCount();
        $sequence = EventLogService::getSequence(); // Monotonic counter for change detection
        
        // Add storage debug info in non-production
        $debug = null;
        if (getenv('APP_ENV') !== 'production') {
            $debug = [
                'storage' => SharedStorage::getInfo(),
                'storagePath' => Config::storagePath(),
                'storageWritable' => is_writable(Config::storagePath()),
            ];
        }

        $response = [
            'events' => $events,
            'count' => count($events),
            'total' => $total,
            'sequence' => $sequence,  // Use this for change detection, not 'total'
        ];
        
        if ($debug) {
            $response['_debug'] = $debug;
        }

        echo json_encode($response);
    }

    /**
     * GET /api/admin/memory-debug
     * Returns diagnostic info about memory detection for troubleshooting.
     */
    public static function memoryDebug(): void
    {
        $cgroupPaths = [
            // cgroup v2 paths
            '/sys/fs/cgroup/memory.max',
            '/sys/fs/cgroup/memory.high',
            '/sys/fs/cgroup/memory.current',
            // cgroup v1 paths
            '/sys/fs/cgroup/memory/memory.limit_in_bytes',
            '/sys/fs/cgroup/memory/memory.soft_limit_in_bytes',
            '/sys/fs/cgroup/memory/memory.usage_in_bytes',
            // Alternative cgroup v1 paths
            '/sys/fs/cgroup/memory.limit_in_bytes',
        ];

        $results = [];
        foreach ($cgroupPaths as $path) {
            try {
                if (file_exists($path) && is_readable($path)) {
                    $results[$path] = trim(file_get_contents($path));
                } else {
                    $results[$path] = null;
                }
            } catch (\Throwable $e) {
                $results[$path] = 'error: ' . $e->getMessage();
            }
        }

        // Check cgroup directories
        $cgroupDirs = ['/sys/fs/cgroup', '/sys/fs/cgroup/memory'];
        $dirContents = [];
        foreach ($cgroupDirs as $dir) {
            try {
                if (is_dir($dir)) {
                    $dirContents[$dir] = array_slice(scandir($dir), 0, 50);
                } else {
                    $dirContents[$dir] = 'does not exist';
                }
            } catch (\Throwable $e) {
                $dirContents[$dir] = 'error: ' . $e->getMessage();
            }
        }

        $metrics = MetricsService::getMetrics();

        echo json_encode([
            'phpMemoryUsage' => memory_get_usage(true),
            'phpMemoryUsageMb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'phpPeakMemory' => memory_get_peak_usage(true),
            'phpPeakMemoryMb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'memoryLimit' => ini_get('memory_limit'),
            'reportedTotalMb' => $metrics['memory']['totalSystemMb'] ?? 0,
            'cgroupFiles' => $results,
            'cgroupDirectories' => $dirContents,
            'platform' => PHP_OS,
            'phpSapi' => PHP_SAPI,
        ]);
    }

    /**
     * GET /api/admin/system-info
     * Returns system information including CPU count and PHP details.
     */
    public static function systemInfo(): void
    {
        $cpuMetrics = MetricsService::getCpuMetrics();

        echo json_encode([
            'cpuCount' => $cpuMetrics['cpuCount'],
            'platform' => PHP_OS,
            'arch' => php_uname('m'),
            'phpVersion' => PHP_VERSION,
            'phpSapi' => PHP_SAPI,
            'memoryLimit' => ini_get('memory_limit'),
            'maxExecutionTime' => (int) ini_get('max_execution_time'),
            'totalMemory' => $cpuMetrics['totalSystemMb'] ?? null,
            'websiteHostname' => getenv('WEBSITE_HOSTNAME') ?: null,
            'websiteSku' => getenv('WEBSITE_SKU') ?: null,
            'extensions' => get_loaded_extensions(),
        ]);
    }

    /**
     * GET /api/admin/telemetry-status
     * Returns Application Insights telemetry configuration status.
     */
    public static function telemetryStatus(): void
    {
        $status = TelemetryService::getStatus();

        echo json_encode([
            'applicationInsights' => [
                'initialized' => $status['initialized'],
                'enabled' => $status['enabled'],
                'connectionStringConfigured' => $status['connectionStringConfigured'],
                'serviceName' => $status['serviceName'],
                'pendingItems' => $status['pendingItems'],
                'lastError' => $status['lastError'],
            ],
            'configuration' => [
                'connectionStringVar' => 'APPLICATIONINSIGHTS_CONNECTION_STRING',
                'serviceNameVar' => 'OTEL_SERVICE_NAME',
            ],
            'help' => $status['enabled']
                ? 'Telemetry is active. Check Application Insights in 2-5 minutes for data.'
                : ($status['connectionStringConfigured']
                    ? 'Connection string found but initialization failed. Check lastError.'
                    : 'Set APPLICATIONINSIGHTS_CONNECTION_STRING in App Settings to enable.'),
        ]);
    }

    /**
     * POST /api/admin/telemetry-test
     * Sends a test telemetry event and returns the actual HTTP response.
     * Use this to debug why telemetry isn't appearing in Application Insights.
     */
    public static function telemetryTest(): void
    {
        $result = TelemetryService::sendTestTelemetry();

        echo json_encode([
            'test' => 'TelemetryTestEvent',
            'success' => $result['success'],
            'statusCode' => $result['statusCode'],
            'response' => $result['response'],
            'responseHeaders' => $result['responseHeaders'] ?? [],
            'error' => $result['error'],
            'endpoint' => $result['endpoint'],
            'payloadSent' => json_decode($result['payload'], true),
            'help' => $result['success']
                ? 'Test event sent successfully. Check Application Insights > Events in 2-5 minutes.'
                : 'Failed to send telemetry. Check error and response for details.',
        ]);
    }
}
