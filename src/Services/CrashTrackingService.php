<?php
/**
 * =============================================================================
 * CRASH TRACKING SERVICE — Worker PID Tracking & Crash Metrics
 * =============================================================================
 *
 * PURPOSE:
 *   Tracks worker process IDs and crash events to make PHP-FPM crash effects
 *   visible on the dashboard. Since PHP-FPM automatically respawns workers,
 *   individual crashes are nearly invisible without explicit tracking.
 *
 * TRACKED METRICS:
 *   - Worker PID history: Shows when workers are replaced (new PIDs appearing)
 *   - Crash count: Total number of intentional crashes triggered
 *   - Worker restarts: Detected when a previously crashed worker's PID reappears
 *   - Last crash time: When the most recent crash was triggered
 *
 * HOW IT WORKS:
 *   1. Each request registers its worker PID via recordWorkerActivity()
 *   2. Before a crash, recordCrash() marks that PID as "crashed"
 *   3. When a new request arrives, we check if the PID is new (worker replaced)
 *   4. Dashboard polls /api/metrics which includes crash stats
 *
 * @module src/Services/CrashTrackingService.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Services;

use PerfSimPhp\SharedStorage;
use PerfSimPhp\Utils;

class CrashTrackingService
{
    private const CRASH_STATS_KEY = 'perfsim_crash_stats';
    private const WORKER_PIDS_KEY = 'perfsim_worker_pids';
    private const MAX_PID_HISTORY = 50;
    private const PID_TTL_SECONDS = 300; // 5 minutes

    /**
     * Record that a worker is about to crash (called before crash).
     * Increments crash counter and logs the crashed PID.
     */
    public static function recordCrash(string $crashType): void
    {
        $pid = getmypid();
        $timestamp = Utils::formatTimestamp();

        SharedStorage::modify(self::CRASH_STATS_KEY, function (?array $stats) use ($pid, $crashType, $timestamp) {
            $stats = $stats ?? self::getDefaultStats();
            
            $stats['totalCrashes']++;
            $stats['lastCrashTime'] = $timestamp;
            $stats['lastCrashType'] = $crashType;
            $stats['lastCrashedPid'] = $pid;
            
            // Track crashes by type
            $stats['crashesByType'][$crashType] = ($stats['crashesByType'][$crashType] ?? 0) + 1;
            
            // Add to crashed PIDs list
            $stats['crashedPids'][] = [
                'pid' => $pid,
                'type' => $crashType,
                'time' => $timestamp,
            ];
            
            // Keep only recent crashes
            $stats['crashedPids'] = array_slice($stats['crashedPids'], -self::MAX_PID_HISTORY);
            
            return $stats;
        }, self::getDefaultStats());
    }

    /**
     * Record worker activity and detect worker restarts.
     * Called on every request to track which worker PIDs are active.
     * 
     * Returns info about whether this is a new/restarted worker.
     */
    public static function recordWorkerActivity(): array
    {
        $pid = getmypid();
        $now = time();
        $isNewWorker = false;
        $isRestartedWorker = false;

        SharedStorage::modify(self::WORKER_PIDS_KEY, function (?array $data) use ($pid, $now, &$isNewWorker, &$isRestartedWorker) {
            $data = $data ?? ['pids' => [], 'history' => []];
            
            // Check if this PID was previously seen
            $wasKnown = isset($data['pids'][$pid]);
            
            // Check if this PID had crashed
            $crashedPid = self::wasRecentlyCrashed($pid);
            if ($crashedPid) {
                $isRestartedWorker = true;
                // Record the restart
                $data['history'][] = [
                    'event' => 'worker_restart',
                    'pid' => $pid,
                    'time' => Utils::formatTimestamp(),
                    'crashType' => $crashedPid['type'] ?? 'unknown',
                ];
            } elseif (!$wasKnown) {
                $isNewWorker = true;
                // Record new worker
                $data['history'][] = [
                    'event' => 'new_worker',
                    'pid' => $pid,
                    'time' => Utils::formatTimestamp(),
                ];
            }
            
            // Update this PID's last activity
            $data['pids'][$pid] = $now;
            
            // Clean up stale PIDs (not seen in PID_TTL_SECONDS)
            foreach ($data['pids'] as $p => $lastSeen) {
                if ($now - $lastSeen > self::PID_TTL_SECONDS) {
                    unset($data['pids'][$p]);
                }
            }
            
            // Keep history bounded
            $data['history'] = array_slice($data['history'], -self::MAX_PID_HISTORY);
            
            return $data;
        }, ['pids' => [], 'history' => []]);

        return [
            'pid' => $pid,
            'isNewWorker' => $isNewWorker,
            'isRestartedWorker' => $isRestartedWorker,
        ];
    }

    /**
     * Check if a PID was recently marked as crashed.
     */
    private static function wasRecentlyCrashed(int $pid): ?array
    {
        $stats = SharedStorage::get(self::CRASH_STATS_KEY);
        if (!$stats || empty($stats['crashedPids'])) {
            return null;
        }

        // Look for this PID in crashed list (most recent first)
        foreach (array_reverse($stats['crashedPids']) as $crashed) {
            if ($crashed['pid'] === $pid) {
                return $crashed;
            }
        }
        return null;
    }

    /**
     * Get crash statistics and worker PID info for dashboard display.
     */
    public static function getCrashStats(): array
    {
        $stats = SharedStorage::get(self::CRASH_STATS_KEY) ?? self::getDefaultStats();
        $workerData = SharedStorage::get(self::WORKER_PIDS_KEY) ?? ['pids' => [], 'history' => []];
        
        // Count unique workers seen recently
        $activeWorkers = count($workerData['pids'] ?? []);
        $workerPids = array_keys($workerData['pids'] ?? []);
        
        // Get recent worker events (restarts, new workers)
        $recentEvents = array_slice($workerData['history'] ?? [], -10);
        
        // Count detected restarts
        $restartCount = count(array_filter($workerData['history'] ?? [], fn($e) => $e['event'] === 'worker_restart'));

        return [
            'totalCrashes' => $stats['totalCrashes'],
            'lastCrashTime' => $stats['lastCrashTime'],
            'lastCrashType' => $stats['lastCrashType'],
            'lastCrashedPid' => $stats['lastCrashedPid'],
            'crashesByType' => $stats['crashesByType'],
            'detectedRestarts' => $restartCount,
            'activeWorkerCount' => $activeWorkers,
            'activeWorkerPids' => $workerPids,
            'recentWorkerEvents' => $recentEvents,
        ];
    }

    /**
     * Get count of unique worker PIDs currently active.
     */
    public static function getActiveWorkerCount(): int
    {
        $workerData = SharedStorage::get(self::WORKER_PIDS_KEY);
        return count($workerData['pids'] ?? []);
    }

    /**
     * Reset crash statistics (for testing/cleanup).
     */
    public static function resetStats(): void
    {
        SharedStorage::set(self::CRASH_STATS_KEY, self::getDefaultStats());
        SharedStorage::set(self::WORKER_PIDS_KEY, ['pids' => [], 'history' => []]);
    }

    /**
     * Default crash stats structure.
     */
    private static function getDefaultStats(): array
    {
        return [
            'totalCrashes' => 0,
            'lastCrashTime' => null,
            'lastCrashType' => null,
            'lastCrashedPid' => null,
            'crashesByType' => [],
            'crashedPids' => [],
        ];
    }
}
