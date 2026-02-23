<?php
/**
 * =============================================================================
 * CPU STRESS SERVICE — Multi-Core CPU Load Simulation
 * =============================================================================
 *
 * FEATURE REQUIREMENTS (language-agnostic):
 *   This service must generate sustained, measurable CPU load that:
 *   1. Is visible in system monitoring tools (Azure Monitor, top, htop)
 *   2. Does NOT block the web server from handling other requests
 *   3. Can be started/stopped on demand via API
 *   4. Self-terminates after a configurable duration
 *   5. Supports two intensity levels: "moderate" and "high"
 *
 * HOW IT WORKS (this implementation):
 *   1. User selects intensity level: 'moderate' or 'high'
 *   2. Launch N background PHP processes (workers/cpu-worker.php) via exec()
 *   3. Each process runs hash_pbkdf2() in a tight loop, burning 100% of one CPU core
 *   4. After durationSeconds, the worker self-terminates (or is killed manually)
 *
 * WHY SEPARATE PROCESSES (PHP-specific):
 *   PHP-FPM workers are request-scoped. CPU work in the current request only
 *   blocks that one request. To produce SYSTEM-WIDE CPU load visible in
 *   monitoring, we need separate long-running processes that persist beyond
 *   the HTTP request lifecycle.
 *
 * PORTING NOTES:
 *   When porting to another language/runtime, the key requirement is generating
 *   CPU load WITHOUT blocking the web server:
 *
 *   Node.js:
 *     - Use worker_threads (not child processes) for true multi-core
 *     - setInterval with CPU-bound work would block the event loop
 *     - Each Worker runs in its own V8 isolate with separate event loop
 *
 *   Java (Spring Boot):
 *     - Use ExecutorService with thread pool for CPU workers
 *     - Each thread runs tight loop with Math operations or hashing
 *     - Spring's @Async with custom ThreadPoolTaskExecutor
 *
 *   Python (Flask/FastAPI):
 *     - Use multiprocessing.Process (not threading due to GIL)
 *     - Each process runs CPU-bound loop independently
 *     - asyncio won't help here - need true parallel processes
 *
 *   .NET (ASP.NET Core):
 *     - Use Task.Run with dedicated threads for CPU work
 *     - Or spawn background processes similar to PHP approach
 *     - Avoid ThreadPool threads as they're shared with request handling
 *
 *   Ruby (Rails):
 *     - Fork background processes (similar to PHP approach)
 *     - Threads won't help due to MRI's GIL
 *
 * CROSS-PLATFORM CONSIDERATIONS:
 *   - Worker count calculation must account for container CPU throttling
 *   - Azure App Service often shows more vCPUs than actually available
 *   - PID tracking is optional but enables clean shutdown
 *   - On Windows, use taskkill instead of POSIX signals
 *
 * @module src/Services/CpuStressService.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Services;

use PerfSimPhp\SharedStorage;

class CpuStressService
{
    private const PIDS_KEY = 'perfsim_cpu_pids';

    /** Valid CPU stress levels */
    public const VALID_LEVELS = ['moderate', 'high'];

    /**
     * Starts a CPU stress simulation.
     *
     * @param array{level: string, durationSeconds: int} $params
     * @return array The created simulation record
     */
    public static function start(array $params): array
    {
        $level = $params['level'];
        $durationSeconds = $params['durationSeconds'];

        // Create simulation record
        $simulation = SimulationTrackerService::createSimulation(
            'CPU_STRESS',
            ['type' => 'CPU_STRESS', ...$params],
            $durationSeconds
        );

        // Calculate worker count for logging
        $workerCount = self::calculateWorkerCount($level);

        // Log the start with worker count
        $levelLabel = ucfirst($level);
        EventLogService::info(
            'SIMULATION_STARTED',
            "CPU stress started: {$levelLabel} intensity, {$workerCount} workers, {$durationSeconds}s",
            $simulation['id'],
            'CPU_STRESS',
            ['level' => $level, 'durationSeconds' => $durationSeconds, 'workers' => $workerCount]
        );

        // Launch background CPU worker processes
        self::launchWorkers($simulation['id'], $level, $durationSeconds);

        return $simulation;
    }

    /**
     * Calculates the number of worker processes for a given intensity level.
     *
     * TUNING FOR AZURE APP SERVICE:
     * Azure containers often see more vCPUs than actually available due to shared
     * infrastructure. We use aggressive worker counts to compensate:
     * - High: Maximum workers to saturate all available CPU
     * - Moderate: Fewer workers for sustained medium load
     */
    private static function calculateWorkerCount(string $level): int
    {
        $cpuCount = self::getCpuCount();
        
        if ($level === 'high') {
            // High: 3x CPU count to maximize CPU burn, cap at 16 workers
            return min(16, max(2, $cpuCount * 3));
        }
        
        // Moderate: 1.5x CPU count for medium sustained load
        return min(8, max(1, (int) ceil($cpuCount * 1.5)));
    }

    /**
     * Stops a running CPU stress simulation.
     *
     * @param string $id Simulation ID
     * @return array|null The stopped simulation or null if not found
     */
    public static function stop(string $id): ?array
    {
        // Kill the worker processes
        self::killWorkers($id);

        // Update simulation status
        $simulation = SimulationTrackerService::stopSimulation($id);

        if ($simulation) {
            EventLogService::info(
                'SIMULATION_STOPPED',
                'CPU stress simulation stopped by user',
                $id,
                'CPU_STRESS'
            );
        }

        return $simulation;
    }

    /**
     * Launches background CPU worker processes.
     *
     * ALGORITHM:
     * 1. Calculate worker count based on intensity level
     * 2. Launch all workers in parallel for instant ramp-up
     * 3. Store PIDs in SharedStorage for later termination
     *
     * AZURE APP SERVICE CONSIDERATIONS:
     * - Containers often report more cores than available (e.g., 4 cores visible but throttled to 1)
     * - We spawn extra workers to compensate for this throttling
     * - Workers should hit target CPU within 2-3 seconds
     *
     * @param string $simulationId Simulation ID for tracking
     * @param string $level Intensity level ('moderate' or 'high')
     * @param int $durationSeconds Duration for each worker
     */
    private static function launchWorkers(
        string $simulationId,
        string $level,
        int $durationSeconds
    ): void {
        $numProcesses = self::calculateWorkerCount($level);
        $cpuCount = self::getCpuCount();

        $workerScript = dirname(__DIR__, 2) . '/workers/cpu-worker.php';
        
        // Launch all workers in parallel for faster ramp-up
        $pids = self::launchWorkersParallel($workerScript, $durationSeconds, $numProcesses);

        // Store PIDs in shared storage for cross-request access
        SharedStorage::modify(self::PIDS_KEY, function (?array $allPids) use ($simulationId, $pids) {
            $allPids = $allPids ?? [];
            $allPids[$simulationId] = $pids;
            return $allPids;
        }, []);

        error_log("[CPU Stress] Launched {$numProcesses} workers (level={$level}, cpus={$cpuCount}): PIDs=" . implode(',', $pids));
    }

    /**
     * Launches multiple background PHP processes in parallel.
     * Uses a single shell command to spawn all workers at once for instant ramp-up.
     *
     * @param string $script Path to the PHP script
     * @param int $durationSeconds Duration argument for each worker
     * @param int $count Number of workers to spawn
     * @return array Array of PIDs (may be empty on Windows)
     */
    private static function launchWorkersParallel(string $script, int $durationSeconds, int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        if (PHP_OS_FAMILY === 'Windows') {
            // Windows: spawn all workers in parallel using multiple start commands
            // Each "start /B" runs independently
            for ($i = 0; $i < $count; $i++) {
                $cmd = "start /B php \"{$script}\" {$durationSeconds}";
                pclose(popen($cmd, 'r'));
            }
            return []; // Can't reliably get PIDs on Windows
        }

        // Linux/macOS: spawn all workers in a single shell command
        // This launches all processes simultaneously without waiting
        $commands = [];
        for ($i = 0; $i < $count; $i++) {
            // Each worker: nohup php script duration >/dev/null 2>&1 & echo $!
            $commands[] = "nohup php \"{$script}\" {$durationSeconds} > /dev/null 2>&1 & echo \$!";
        }
        
        // Join with semicolons and execute all at once
        $fullCmd = implode('; ', $commands);
        $output = trim((string) shell_exec($fullCmd));
        
        // Parse PIDs from output (one per line)
        $pids = [];
        if (!empty($output)) {
            foreach (explode("\n", $output) as $line) {
                $pid = trim($line);
                if (is_numeric($pid) && (int)$pid > 0) {
                    $pids[] = (int) $pid;
                }
            }
        }
        
        return $pids;
    }

    /**
     * Launches a single background PHP process.
     *
     * @param string $script Path to the PHP script
     * @param int $durationSeconds Duration argument for the worker
     * @return int|null The PID of the launched process, or null on failure
     */
    private static function launchBackgroundProcess(string $script, int $durationSeconds): ?int
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows: use start /B to run in background
            $cmd = "start /B php \"{$script}\" {$durationSeconds} 2>&1";
            pclose(popen($cmd, 'r'));
            // On Windows, getting PID from popen is unreliable; return null
            // Workers will self-terminate after duration
            return null;
        }

        // Linux/macOS: nohup + background + echo PID
        $cmd = "nohup php \"{$script}\" {$durationSeconds} > /dev/null 2>&1 & echo $!";
        $output = trim((string) shell_exec($cmd));

        if (is_numeric($output)) {
            return (int) $output;
        }

        return null;
    }

    /**
     * Kills all worker processes for a simulation.
     * Uses batch killing for fast cleanup.
     *
     * @param string $simulationId Simulation ID
     */
    private static function killWorkers(string $simulationId): void
    {
        $allPids = SharedStorage::get(self::PIDS_KEY, []);
        $pids = $allPids[$simulationId] ?? [];

        if (!empty($pids)) {
            // Kill all processes in batch for instant cleanup
            self::killProcessesBatch($pids);
            error_log("[CPU Stress] Killed workers for simulation {$simulationId}: PIDs=" . implode(',', $pids));
        }

        // Remove from storage
        SharedStorage::modify(self::PIDS_KEY, function (?array $allPids) use ($simulationId) {
            $allPids = $allPids ?? [];
            unset($allPids[$simulationId]);
            return $allPids;
        }, []);
    }

    /**
     * Public cleanup method for expired simulations.
     * Called by SimulationTrackerService when a CPU_STRESS simulation expires.
     *
     * @param string $simulationId Simulation ID
     */
    public static function cleanupWorkers(string $simulationId): void
    {
        self::killWorkers($simulationId);
    }

    /**
     * Kills multiple processes in batch for fast cleanup.
     * Uses a single shell command to kill all PIDs at once.
     *
     * @param array $pids Array of process IDs
     */
    private static function killProcessesBatch(array $pids): void
    {
        $validPids = array_filter($pids, fn($pid) => is_numeric($pid) && $pid > 0);
        if (empty($validPids)) {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            // Windows: batch taskkill
            $pidList = implode(' ', array_map(fn($p) => "/PID {$p}", $validPids));
            @exec("taskkill {$pidList} /F 2>&1");
        } else {
            // Linux: kill all PIDs in single command with SIGKILL (immediate)
            $pidList = implode(' ', $validPids);
            @exec("kill -9 {$pidList} 2>/dev/null");
        }
    }

    /**
     * Gets all active CPU stress simulations.
     *
     * @return array Active CPU stress simulations
     */
    public static function getActiveSimulations(): array
    {
        return SimulationTrackerService::getActiveSimulationsByType('CPU_STRESS');
    }

    /**
     * Checks if there are any active CPU stress simulations.
     */
    public static function hasActiveSimulations(): bool
    {
        return count(self::getActiveSimulations()) > 0;
    }

    /**
     * Stops all active CPU stress simulations.
     * Also kills any orphaned workers and cpu-worker processes.
     */
    public static function stopAll(): void
    {
        $active = self::getActiveSimulations();
        foreach ($active as $simulation) {
            self::stop($simulation['id']);
        }
        
        // Always clean up orphaned workers (PIDs without active simulations)
        self::cleanupOrphanedWorkers();
        
        // Nuclear option: kill any remaining cpu-worker processes by name
        // This handles cases where PID tracking failed
        self::killAllWorkersByName();
    }

    /**
     * Kills all cpu-worker.php processes by name.
     * Nuclear option when PID tracking fails.
     */
    public static function killAllWorkersByName(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows: use wmic to find and kill php processes running cpu-worker
            @exec('wmic process where "commandline like \'%cpu-worker%\'" call terminate 2>&1');
        } else {
            // Linux: pkill processes matching cpu-worker.php
            @exec('pkill -9 -f cpu-worker.php 2>/dev/null');
        }
        
        // Clear all stored PIDs since we killed everything
        SharedStorage::delete(self::PIDS_KEY);
        
        error_log('[CPU Stress] Killed all cpu-worker processes by name');
    }

    /**
     * Clean up worker PIDs for simulations that no longer exist or are completed.
     * This handles cases where:
     * - App restarted and simulation records were lost
     * - Simulations were completed but workers weren't killed
     * - Manual cleanup needed after errors
     */
    public static function cleanupOrphanedWorkers(): void
    {
        $allPids = SharedStorage::get(self::PIDS_KEY, []);
        if (empty($allPids)) {
            return;
        }

        $activeSimulations = SimulationTrackerService::getActiveSimulationsByType('CPU_STRESS');
        $activeIds = array_map(fn($s) => $s['id'], $activeSimulations);

        $orphanedIds = [];
        $allOrphanedPids = [];
        foreach (array_keys($allPids) as $simId) {
            if (!in_array($simId, $activeIds, true)) {
                $orphanedIds[] = $simId;
                $pids = $allPids[$simId] ?? [];
                $allOrphanedPids = array_merge($allOrphanedPids, $pids);
            }
        }

        // Batch kill all orphaned workers in single command
        if (!empty($allOrphanedPids)) {
            self::killProcessesBatch($allOrphanedPids);
            error_log("[CPU Stress] Cleaned up orphaned workers: PIDs=" . implode(',', $allOrphanedPids));
        }

        // Remove orphaned entries from storage
        if (!empty($orphanedIds)) {
            SharedStorage::modify(self::PIDS_KEY, function (?array $allPids) use ($orphanedIds) {
                $allPids = $allPids ?? [];
                foreach ($orphanedIds as $simId) {
                    unset($allPids[$simId]);
                }
                return $allPids;
            }, []);
        }
    }

    /**
     * Get CPU core count.
     */
    private static function getCpuCount(): int
    {
        if (is_readable('/proc/cpuinfo')) {
            $count = substr_count(file_get_contents('/proc/cpuinfo'), 'processor');
            if ($count > 0) return $count;
        }

        $nproc = @shell_exec('nproc 2>/dev/null');
        if ($nproc !== null && is_numeric(trim($nproc))) {
            return (int) trim($nproc);
        }

        $env = getenv('NUMBER_OF_PROCESSORS');
        if ($env !== false && is_numeric($env)) {
            return (int) $env;
        }

        return 1;
    }
}
