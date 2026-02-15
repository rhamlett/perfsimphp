<?php
/**
 * =============================================================================
 * ADMIN CONTROLLER — Administrative & Diagnostic REST API
 * =============================================================================
 *
 * ENDPOINTS:
 *   GET /api/simulations        → List all active simulations (any type)
 *   GET /api/admin/status       → Comprehensive status (config + simulations + metrics)
 *   GET /api/admin/events       → Recent event log entries (with limit parameter)
 *   GET /api/admin/memory-debug → Memory diagnostic info (cgroup, OS, process)
 *   GET /api/admin/system-info  → System info (CPU count, PHP version, platform)
 *
 * @module src/Controllers/AdminController.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Controllers;

use PerfSimPhp\Config;
use PerfSimPhp\Services\SimulationTrackerService;
use PerfSimPhp\Services\EventLogService;
use PerfSimPhp\Services\MetricsService;

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
        $limit = 50;
        if (isset($_GET['limit']) && is_numeric($_GET['limit'])) {
            $limit = max(1, min(100, (int) $_GET['limit']));
        }

        $events = EventLogService::getRecentEntries($limit);

        echo json_encode([
            'events' => $events,
            'count' => count($events),
            'total' => EventLogService::getCount(),
        ]);
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
}
