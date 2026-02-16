<?php
/**
 * =============================================================================
 * CPU STRESS SERVICE — Multi-Core CPU Load Simulation
 * =============================================================================
 *
 * PURPOSE:
 *   Generates real CPU load by spawning separate OS processes that run tight
 *   synchronous loops. This makes CPU usage visible in system monitoring tools
 *   like Azure App Service metrics, top/htop, and Azure Monitor.
 *
 * HOW IT WORKS:
 *   1. Calculate number of worker processes: round((targetLoadPercent/100) * CPU_CORES)
 *   2. Launch N background PHP processes (workers/cpu-worker.php) via exec()
 *   3. Each process runs hash_pbkdf2() in a tight loop, burning 100% of one CPU core
 *   4. After durationSeconds, the worker self-terminates (or is killed manually)
 *
 * WHY SEPARATE PROCESSES:
 *   PHP-FPM workers are request-scoped. CPU work in the current request only
 *   blocks that one request. To produce SYSTEM-WIDE CPU load visible in
 *   monitoring, we need separate long-running processes that persist beyond
 *   the HTTP request lifecycle.
 *
 * PROCESS LIFECYCLE:
 *   - Workers self-terminate after their configured duration
 *   - Worker PIDs are stored in SharedStorage for cross-request tracking
 *   - The stop endpoint sends SIGTERM/SIGKILL to terminate workers early
 *   - On Windows, taskkill is used instead of POSIX signals
 *
 * @module src/Services/CpuStressService.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Services;

use PerfSimPhp\SharedStorage;

class CpuStressService
{
    private const PIDS_KEY = 'perfsim_cpu_pids';

    /**
     * Starts a CPU stress simulation.
     *
     * @param array{targetLoadPercent: int, durationSeconds: int} $params
     * @return array The created simulation record
     */
    public static function start(array $params): array
    {
        $targetLoadPercent = $params['targetLoadPercent'];
        $durationSeconds = $params['durationSeconds'];

        // Create simulation record
        $simulation = SimulationTrackerService::createSimulation(
            'CPU_STRESS',
            ['type' => 'CPU_STRESS', ...$params],
            $durationSeconds
        );

        // Log the start
        EventLogService::info(
            'SIMULATION_STARTED',
            "CPU stress simulation started at {$targetLoadPercent}% for {$durationSeconds}s",
            $simulation['id'],
            'CPU_STRESS',
            ['targetLoadPercent' => $targetLoadPercent, 'durationSeconds' => $durationSeconds]
        );

        // Launch background CPU worker processes
        self::launchWorkers($simulation['id'], $targetLoadPercent, $durationSeconds);

        return $simulation;
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
     * 1. Determine CPU core count
     * 2. Calculate worker count: round((targetLoadPercent / 100) * numCpus)
     * 3. Launch each worker as a background PHP process
     * 4. Store PIDs in SharedStorage for later termination
     *
     * @param string $simulationId Simulation ID for tracking
     * @param int $targetLoadPercent Target CPU load percentage (1-100)
     * @param int $durationSeconds Duration for each worker
     */
    private static function launchWorkers(
        string $simulationId,
        int $targetLoadPercent,
        int $durationSeconds
    ): void {
        $cpuCount = self::getCpuCount();
        
        // Azure App Service containers often have access to fewer cores than reported.
        // To achieve visible CPU load in metrics, we spawn extra workers.
        // Base formula: (targetLoad/100) * cpuCount
        // For high loads (>75%), add extra workers to ensure saturation
        $baseWorkers = (int) round(($targetLoadPercent / 100) * $cpuCount);
        
        // Add extra workers for high loads to ensure visible impact in Azure metrics
        $extraMultiplier = 1.0;
        if ($targetLoadPercent >= 90) {
            $extraMultiplier = 2.0; // Double workers for 90-100%
        } elseif ($targetLoadPercent >= 75) {
            $extraMultiplier = 1.5; // 50% more workers for 75-89%
        }
        
        $numProcesses = max(1, (int) round($baseWorkers * $extraMultiplier));

        $workerScript = dirname(__DIR__, 2) . '/workers/cpu-worker.php';
        
        // Launch all workers in parallel for faster ramp-up
        $pids = self::launchWorkersParallel($workerScript, $durationSeconds, $numProcesses);

        // Store PIDs in shared storage for cross-request access
        SharedStorage::modify(self::PIDS_KEY, function (?array $allPids) use ($simulationId, $pids) {
            $allPids = $allPids ?? [];
            $allPids[$simulationId] = $pids;
            return $allPids;
        }, []);

        error_log("[CPU Stress] Launched {$numProcesses} workers (target={$targetLoadPercent}%, cpus={$cpuCount}): PIDs=" . implode(',', $pids));
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
     *
     * @param string $simulationId Simulation ID
     */
    private static function killWorkers(string $simulationId): void
    {
        $allPids = SharedStorage::get(self::PIDS_KEY, []);
        $pids = $allPids[$simulationId] ?? [];

        foreach ($pids as $pid) {
            self::killProcess($pid);
        }

        // Remove from storage
        SharedStorage::modify(self::PIDS_KEY, function (?array $allPids) use ($simulationId) {
            $allPids = $allPids ?? [];
            unset($allPids[$simulationId]);
            return $allPids;
        }, []);

        if (!empty($pids)) {
            error_log("[CPU Stress] Killed workers for simulation {$simulationId}: PIDs=" . implode(',', $pids));
        }
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
     * Kills a single process by PID.
     *
     * @param int $pid Process ID
     */
    private static function killProcess(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            @exec("taskkill /PID {$pid} /F 2>&1");
        } else {
            // Try SIGTERM first, then SIGKILL
            if (function_exists('posix_kill')) {
                posix_kill($pid, 15); // SIGTERM
                usleep(200_000); // 200ms
                posix_kill($pid, 9); // SIGKILL
            } else {
                @exec("kill -15 {$pid} 2>/dev/null");
                usleep(200_000);
                @exec("kill -9 {$pid} 2>/dev/null");
            }
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
     */
    public static function stopAll(): void
    {
        $active = self::getActiveSimulations();
        foreach ($active as $simulation) {
            self::stop($simulation['id']);
        }
        
        // Also clean up any orphaned workers
        self::cleanupOrphanedWorkers();
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
        foreach (array_keys($allPids) as $simId) {
            if (!in_array($simId, $activeIds, true)) {
                $orphanedIds[] = $simId;
            }
        }

        foreach ($orphanedIds as $simId) {
            $pids = $allPids[$simId] ?? [];
            foreach ($pids as $pid) {
                self::killProcess($pid);
            }
            error_log("[CPU Stress] Cleaned up orphaned workers for simulation {$simId}: PIDs=" . implode(',', $pids));
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
