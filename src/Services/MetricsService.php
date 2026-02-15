<?php
/**
 * =============================================================================
 * METRICS SERVICE — Real-Time System Metrics Collection
 * =============================================================================
 *
 * PURPOSE:
 *   Collects system metrics (CPU, memory, process stats) and provides them
 *   as a unified snapshot. Called by the /api/metrics endpoint each time
 *   the dashboard polls for updates.
 *
 * PHP vs NODE.JS DIFFERENCES:
 *   - PHP has no persistent process; each request computes metrics fresh.
 *   - CPU usage is measured via sys_getloadavg() (system-wide load average)
 *     rather than per-tick deltas like Node.js os.cpus().
 *   - There is no "event loop" in PHP, so event loop lag metrics are N/A.
 *     Instead we report PHP-FPM worker pool utilization via process count.
 *   - Memory is measured via memory_get_usage() and memory_get_peak_usage().
 *   - The "heartbeat lag" concept is replaced by client-side XHR latency
 *     probing (see sse-client.js) which measures PHP-FPM response time.
 *
 * METRICS COLLECTED:
 *   1. CPU     — sys_getloadavg() for 1/5/15 min load averages
 *   2. Memory  — PHP memory_get_usage(), memory_get_peak_usage(), system total
 *   3. Process — PID, uptime estimate, PHP version
 *
 * @module src/Services/MetricsService.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Services;

use PerfSimPhp\Config;

class MetricsService
{
    /**
     * Collects all system metrics as a single snapshot.
     */
    public static function getMetrics(): array
    {
        return [
            'timestamp' => date('c'),
            'cpu' => self::getCpuMetrics(),
            'memory' => self::getMemoryMetrics(),
            'process' => self::getProcessMetrics(),
            'requestBlocking' => self::getBlockingMetrics(),
        ];
    }

    /**
     * CPU metrics via sys_getloadavg().
     *
     * sys_getloadavg() returns system-wide load averages for 1, 5, and 15 minutes.
     * This captures CPU usage from ALL processes including background CPU workers.
     *
     * On Windows, sys_getloadavg() is not available, so we return 0.
     */
    public static function getCpuMetrics(): array
    {
        $loadAvg = function_exists('sys_getloadavg') ? sys_getloadavg() : [0.0, 0.0, 0.0];

        // Estimate CPU percentage from 1-minute load average vs CPU count
        $cpuCount = self::getCpuCount();
        $usagePercent = $cpuCount > 0 ? min(100, ($loadAvg[0] / $cpuCount) * 100) : 0;

        return [
            'usagePercent' => round($usagePercent, 2),
            'loadAvg1m' => round($loadAvg[0], 2),
            'loadAvg5m' => round($loadAvg[1], 2),
            'loadAvg15m' => round($loadAvg[2], 2),
            'cpuCount' => $cpuCount,
        ];
    }

    /**
     * Memory metrics via memory_get_usage() and /proc/meminfo or sysctl.
     *
     * Reports:
     *   - PHP process memory (current and peak)
     *   - System total memory (if available)
     *   - APCu shared memory usage (if available)
     */
    public static function getMemoryMetrics(): array
    {
        $phpUsage = memory_get_usage(true);        // PHP memory allocated
        $phpPeak = memory_get_peak_usage(true);     // PHP peak memory
        $phpUsageReal = memory_get_usage(false);    // Actual PHP usage (no unused pages)

        $systemTotal = self::getSystemMemory();

        $metrics = [
            'phpUsageMb' => round($phpUsage / 1024 / 1024, 2),
            'phpPeakMb' => round($phpPeak / 1024 / 1024, 2),
            'phpUsageRealMb' => round($phpUsageReal / 1024 / 1024, 2),
            'memoryLimitMb' => self::getMemoryLimitMb(),
            'totalSystemMb' => round($systemTotal / 1024 / 1024, 2),
        ];

        // Add APCu memory info if available
        if (function_exists('apcu_cache_info')) {
            try {
                $apcuInfo = apcu_cache_info(true);
                $apcuMem = apcu_sma_info(true);
                $metrics['apcuUsageMb'] = round(($apcuMem['seg_size'] - $apcuMem['avail_mem']) / 1024 / 1024, 2);
                $metrics['apcuAvailMb'] = round($apcuMem['avail_mem'] / 1024 / 1024, 2);
                $metrics['apcuEntries'] = $apcuInfo['num_entries'] ?? 0;
            } catch (\Throwable) {
                // APCu may not be enabled for CLI
            }
        }

        return $metrics;
    }

    /**
     * Process-level metrics.
     */
    public static function getProcessMetrics(): array
    {
        return [
            'pid' => getmypid(),
            'phpVersion' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'uptime' => self::getUptime(),
            'maxExecutionTime' => (int) ini_get('max_execution_time'),
        ];
    }

    /**
     * Request blocking / thread pool metrics.
     *
     * In PHP, there is no event loop to block. Instead we report the number
     * of active blocking simulations from shared storage.
     */
    public static function getBlockingMetrics(): array
    {
        $activeBlocking = SimulationTrackerService::getActiveSimulationsByType('REQUEST_BLOCKING');
        return [
            'activeBlockingSimulations' => count($activeBlocking),
            'note' => 'PHP uses process-per-request; blocking one FPM worker does not affect others unless the pool is exhausted.',
        ];
    }

    /**
     * Lightweight probe endpoint data — used by client-side latency probing.
     * Returns minimal data to minimize overhead.
     */
    public static function getProbeResponse(): array
    {
        return [
            'ok' => true,
            'timestamp' => microtime(true),
            'pid' => getmypid(),
        ];
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Get the number of CPU cores.
     */
    private static function getCpuCount(): int
    {
        // Try /proc/cpuinfo on Linux
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            $count = substr_count($cpuinfo, 'processor');
            if ($count > 0) {
                return $count;
            }
        }

        // Try nproc command
        $nproc = @shell_exec('nproc 2>/dev/null');
        if ($nproc !== null && is_numeric(trim($nproc))) {
            return (int) trim($nproc);
        }

        // Try sysctl on macOS
        $sysctl = @shell_exec('sysctl -n hw.ncpu 2>/dev/null');
        if ($sysctl !== null && is_numeric(trim($sysctl))) {
            return (int) trim($sysctl);
        }

        // Fallback: use NUMBER_OF_PROCESSORS on Windows
        $env = getenv('NUMBER_OF_PROCESSORS');
        if ($env !== false && is_numeric($env)) {
            return (int) $env;
        }

        return 1; // Fallback
    }

    /**
     * Get system total memory in bytes.
     */
    private static function getSystemMemory(): int
    {
        // Try /proc/meminfo on Linux
        if (is_readable('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $matches)) {
                return (int) $matches[1] * 1024; // Convert kB to bytes
            }
        }

        // Try sysctl on macOS
        $sysctl = @shell_exec('sysctl -n hw.memsize 2>/dev/null');
        if ($sysctl !== null && is_numeric(trim($sysctl))) {
            return (int) trim($sysctl);
        }

        return 0; // Unknown
    }

    /**
     * Get PHP memory_limit in MB.
     */
    private static function getMemoryLimitMb(): float
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') {
            return -1; // Unlimited
        }

        $value = (int) $limit;
        $unit = strtolower(substr($limit, -1));
        return match ($unit) {
            'g' => $value * 1024,
            'm' => $value,
            'k' => $value / 1024,
            default => $value / 1024 / 1024,
        };
    }

    /**
     * Estimate process uptime.
     *
     * PHP-FPM workers are recycled, so we estimate from REQUEST_TIME_FLOAT.
     * This gives the duration of the current request, not the FPM worker lifetime.
     */
    private static function getUptime(): float
    {
        if (isset($_SERVER['REQUEST_TIME_FLOAT'])) {
            return round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 2);
        }
        return 0.0;
    }
}
