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
use PerfSimPhp\SharedStorage;
use PerfSimPhp\Services\SimulationTrackerService;
use PerfSimPhp\Services\MemoryPressureService;
use PerfSimPhp\Services\BlockingService;
use PerfSimPhp\Services\CrashTrackingService;

class MetricsService
{
    /**
     * Collects all system metrics as a single snapshot.
     */
    public static function getMetrics(): array
    {
        // Track worker activity on each metrics poll
        $workerInfo = CrashTrackingService::recordWorkerActivity();
        
        return [
            'timestamp' => date('c'),
            'cpu' => self::getCpuMetrics(),
            'memory' => self::getMemoryMetrics(),
            'process' => self::getProcessMetrics(),
            'requestBlocking' => self::getBlockingMetrics(),
            'simulations' => self::getSimulationsStatus(),
            'crashTracking' => self::getCrashTrackingMetrics(),
            'workerInfo' => $workerInfo,
        ];
    }

    /**
     * Get simulation status for dashboard Active Simulations display.
     * Returns status for each simulation type with 'active' boolean.
     * 
     * Note: Cleanup of expired simulations happens via getActiveSimulations()
     * which is called periodically. We don't run aggressive cleanup here
     * since this is called on every metrics poll (~4x/second).
     */
    private static function getSimulationsStatus(): array
    {
        // CPU stress simulations (getActiveSimulationsByType uses read-only fast path)
        $cpuSims = SimulationTrackerService::getActiveSimulationsByType('CPU_STRESS');
        $cpuActive = count($cpuSims) > 0;
        $cpuTargetLoad = 0;
        foreach ($cpuSims as $sim) {
            $cpuTargetLoad = max($cpuTargetLoad, $sim['parameters']['targetLoadPercent'] ?? 0);
        }

        // Memory pressure simulations
        $memorySims = SimulationTrackerService::getActiveSimulationsByType('MEMORY_PRESSURE');
        $memoryActive = count($memorySims) > 0;
        $memoryAllocatedMb = MemoryPressureService::getTotalAllocatedMb();

        // Blocking simulations - check active time window
        $blockingMode = BlockingService::getBlockingMode();
        $blockingActive = $blockingMode !== null;
        $blockingDuration = $blockingMode['durationSeconds'] ?? 0;

        // Slow request simulations
        $slowSims = SimulationTrackerService::getActiveSimulationsByType('SLOW_REQUEST');
        $slowActive = count($slowSims) > 0;
        $slowCount = count($slowSims);

        return [
            'cpu' => [
                'active' => $cpuActive,
                'targetLoad' => $cpuTargetLoad,
                'count' => count($cpuSims),
            ],
            'memory' => [
                'active' => $memoryActive,
                'allocatedMb' => $memoryAllocatedMb,
                'count' => count($memorySims),
            ],
            'blocking' => [
                'active' => $blockingActive,
                'duration' => $blockingDuration,
            ],
            'slowRequests' => [
                'active' => $slowActive,
                'activeCount' => $slowCount,
            ],
        ];
    }

    /**
     * CPU metrics via /proc/stat for real-time measurement.
     *
     * Uses APCu to store the previous /proc/stat sample, allowing us to
     * calculate actual CPU usage delta between requests - similar to how
     * Node.js and .NET measure CPU in their persistent processes.
     *
     * Falls back to sys_getloadavg() on systems without /proc/stat.
     */
    public static function getCpuMetrics(): array
    {
        $cpuCount = self::getCpuCount();
        
        // Try real-time measurement via /proc/stat delta
        $usagePercent = self::getRealTimeCpuUsage($cpuCount);
        
        // Fallback to load average if /proc/stat not available
        if ($usagePercent === null) {
            $loadAvg = function_exists('sys_getloadavg') ? sys_getloadavg() : [0.0, 0.0, 0.0];
            $usagePercent = $cpuCount > 0 ? min(100, ($loadAvg[0] / $cpuCount) * 100) : 0;
        }
        
        // Also get load averages for reference
        $loadAvg = function_exists('sys_getloadavg') ? sys_getloadavg() : [0.0, 0.0, 0.0];

        return [
            'usagePercent' => round($usagePercent, 2),
            'loadAvg1m' => round($loadAvg[0], 2),
            'loadAvg5m' => round($loadAvg[1], 2),
            'loadAvg15m' => round($loadAvg[2], 2),
            'cpuCount' => $cpuCount,
        ];
    }

    private const CPU_SAMPLE_KEY = 'perfsim_cpu_sample';

    /**
     * Gets real-time CPU usage by storing samples in APCu and calculating deltas.
     * 
     * This mimics how Node.js/NET measure CPU - by tracking the delta in CPU
     * time between two measurement points. We store the previous sample in
     * APCu shared memory so it persists across PHP-FPM requests.
     *
     * @param int $cpuCount Number of CPU cores
     * @return float|null CPU usage percentage (0-100) or null if unavailable
     */
    private static function getRealTimeCpuUsage(int $cpuCount): ?float
    {
        $currentSample = self::readProcStat();
        if ($currentSample === null) {
            return null;
        }
        
        $currentSample['timestamp'] = microtime(true);
        
        // Get previous sample from shared storage
        $previousSample = SharedStorage::get(self::CPU_SAMPLE_KEY);
        
        // Store current sample for next request
        SharedStorage::set(self::CPU_SAMPLE_KEY, $currentSample, 60); // 60s TTL
        
        // If no previous sample, return null (first request)
        if ($previousSample === null || !isset($previousSample['total'])) {
            return null;
        }
        
        // Calculate deltas
        $totalDelta = $currentSample['total'] - $previousSample['total'];
        $idleDelta = $currentSample['idle'] - $previousSample['idle'];
        
        // Avoid division by zero
        if ($totalDelta <= 0) {
            return null;
        }
        
        // CPU usage = (total - idle) / total * 100
        $usagePercent = (($totalDelta - $idleDelta) / $totalDelta) * 100;
        
        // Clamp to 0-100
        return max(0, min(100, $usagePercent));
    }

    /**
     * Reads aggregate CPU stats from /proc/stat.
     */
    private static function readProcStat(): ?array
    {
        $contents = @file_get_contents('/proc/stat');
        if ($contents === false) {
            return null;
        }
        
        // First line is aggregate: cpu user nice system idle iowait irq softirq steal guest guest_nice
        $lines = explode("\n", $contents);
        foreach ($lines as $line) {
            if (strpos($line, 'cpu ') === 0) {
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) >= 5) {
                    // parts: [cpu, user, nice, system, idle, iowait, irq, softirq, ...]
                    $user = (int) $parts[1];
                    $nice = (int) $parts[2];
                    $system = (int) $parts[3];
                    $idle = (int) $parts[4];
                    $iowait = isset($parts[5]) ? (int) $parts[5] : 0;
                    
                    // Total = all CPU time
                    $total = $user + $nice + $system + $idle + $iowait;
                    for ($i = 6; $i < count($parts); $i++) {
                        $total += (int) $parts[$i];
                    }
                    
                    return [
                        'idle' => $idle + $iowait, // Include iowait as idle
                        'total' => $total,
                    ];
                }
            }
        }
        
        return null;
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

        $usedMb = round($phpUsage / 1024 / 1024, 2);
        $totalSystemMb = round($systemTotal / 1024 / 1024, 2);
        
        // Get actual RSS from /proc/self/status (more accurate than memory_get_usage)
        $rssMb = self::getProcessRss();
        
        // Get simulated memory allocation from shared storage
        $simulatedMb = MemoryPressureService::getTotalAllocatedMb();

        $metrics = [
            'usedMb' => $usedMb + $simulatedMb, // Include simulated allocations in working set
            'rssMb' => $rssMb,
            'phpUsageMb' => $usedMb,
            'simulatedMb' => $simulatedMb,
            'phpPeakMb' => round($phpPeak / 1024 / 1024, 2),
            'phpUsageRealMb' => round($phpUsageReal / 1024 / 1024, 2),
            'memoryLimitMb' => self::getMemoryLimitMb(),
            'totalSystemMb' => $totalSystemMb,
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
        $activeBlockingSimulations = SimulationTrackerService::getActiveSimulationsByType('REQUEST_BLOCKING');
        
        // Count actual blocked workers from simulation parameters (not just simulation count)
        // Each blocking simulation may block multiple workers
        $busyWorkers = 0;
        foreach ($activeBlockingSimulations as $sim) {
            $workers = $sim['parameters']['concurrentWorkers'] ?? 1;
            $busyWorkers += $workers;
        }
        
        // Get RSS (Resident Set Size) from /proc if available
        $rssMb = self::getProcessRss();

        return [
            'pid' => getmypid(),
            'phpVersion' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'uptime' => self::getUptime(),
            'maxExecutionTime' => (int) ini_get('max_execution_time'),
            'activeWorkers' => $busyWorkers,
            'rssMb' => $rssMb,
        ];
    }
    
    /**
     * Get RSS memory of current process from /proc/self/status.
     */
    private static function getProcessRss(): float
    {
        // Try /proc/self/status on Linux
        if (is_readable('/proc/self/status')) {
            $status = file_get_contents('/proc/self/status');
            if (preg_match('/VmRSS:\s+(\d+)\s+kB/', $status, $matches)) {
                return round((int)$matches[1] / 1024, 2); // Convert kB to MB
            }
        }
        
        // Fallback to memory_get_usage
        return round(memory_get_usage(true) / 1024 / 1024, 2);
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
     * Crash tracking metrics - shows crash statistics and worker turnover.
     * 
     * PHP-FPM automatically respawns workers after crashes, making crashes
     * nearly invisible without explicit tracking. This provides visibility into:
     * - Total crash count
     * - Worker PID history
     * - Detected worker restarts after crashes
     */
    public static function getCrashTrackingMetrics(): array
    {
        return CrashTrackingService::getCrashStats();
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
