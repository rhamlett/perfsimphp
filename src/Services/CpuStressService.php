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
        $numProcesses = max(1, (int) round(($targetLoadPercent / 100) * $cpuCount));

        $workerScript = dirname(__DIR__, 2) . '/workers/cpu-worker.php';
        $pids = [];

        for ($i = 0; $i < $numProcesses; $i++) {
            $pid = self::launchBackgroundProcess($workerScript, $durationSeconds);
            if ($pid !== null) {
                $pids[] = $pid;
            }
        }

        // Store PIDs in shared storage for cross-request access
        SharedStorage::modify(self::PIDS_KEY, function (?array $allPids) use ($simulationId, $pids) {
            $allPids = $allPids ?? [];
            $allPids[$simulationId] = $pids;
            return $allPids;
        }, []);

        error_log("[CPU Stress] Launched {$numProcesses} workers (target={$targetLoadPercent}%, cpus={$cpuCount}): PIDs=" . implode(',', $pids));
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
