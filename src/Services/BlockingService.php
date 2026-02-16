<?php
/**
 * =============================================================================
 * BLOCKING SERVICE — Request Thread Blocking Simulation
 * =============================================================================
 *
 * PURPOSE:
 *   Simulates the effect of synchronous/blocking operations (sync-over-async
 *   antipattern) on request latency. This demonstrates what happens when code
 *   performs blocking I/O operations like:
 *   - Synchronous HTTP calls (file_get_contents to external APIs)
 *   - Blocking database queries without connection pooling
 *   - Heavy computation on the request thread
 *
 * HOW IT WORKS:
 *   When blocking is triggered:
 *   1. A time window is set (current time + duration)
 *   2. All probe requests during this window perform CPU-intensive work
 *   3. This causes visible latency spike in the dashboard charts
 *   4. Demonstrates how sync-over-async patterns degrade system performance
 *
 * @module src/Services/BlockingService.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Services;

use PerfSimPhp\SharedStorage;

class BlockingService
{
    private const BLOCKING_MODE_KEY = 'perfsim_blocking_mode';

    /**
     * Start blocking mode for the specified duration.
     * All probe requests during this window will perform blocking work.
     *
     * @param array{durationSeconds: int} $params
     * @return array The simulation record
     */
    public static function block(array $params): array
    {
        $durationSeconds = $params['durationSeconds'];
        $endTime = microtime(true) + $durationSeconds;

        // Create simulation record first
        $simulation = SimulationTrackerService::createSimulation(
            'REQUEST_BLOCKING',
            ['type' => 'REQUEST_BLOCKING', 'durationSeconds' => $durationSeconds],
            $durationSeconds
        );

        // Set blocking mode window with simulation ID
        SharedStorage::set(self::BLOCKING_MODE_KEY, [
            'endTime' => $endTime,
            'durationSeconds' => $durationSeconds,
            'startedAt' => microtime(true),
            'simulationId' => $simulation['id'],
        ], $durationSeconds + 60); // TTL slightly longer than duration

        // Log start
        EventLogService::warn(
            'SIMULATION_STARTED',
            "Request thread blocking started for {$durationSeconds}s — probe requests will experience latency",
            $simulation['id'],
            'REQUEST_BLOCKING',
            ['durationSeconds' => $durationSeconds]
        );

        return $simulation;
    }

    /**
     * Check if blocking mode is currently active.
     * If blocking has expired, completes the simulation and logs completion.
     *
     * @return array|null Blocking mode info if active, null otherwise
     */
    public static function getBlockingMode(): ?array
    {
        $mode = SharedStorage::get(self::BLOCKING_MODE_KEY);
        if (!$mode || !isset($mode['endTime'])) {
            return null;
        }

        if (microtime(true) > $mode['endTime']) {
            // Blocking period has ended - complete the simulation
            SharedStorage::delete(self::BLOCKING_MODE_KEY);
            
            // Mark simulation as completed and log
            if (isset($mode['simulationId'])) {
                SimulationTrackerService::completeSimulation($mode['simulationId']);
                EventLogService::info(
                    'SIMULATION_COMPLETED',
                    "Request thread blocking completed after {$mode['durationSeconds']}s",
                    $mode['simulationId'],
                    'REQUEST_BLOCKING'
                );
            }
            
            return null;
        }

        return $mode;
    }

    /**
     * Perform blocking work if blocking mode is active.
     * Returns the work done for debugging.
     *
     * @return array|null Work done info, or null if not in blocking mode
     */
    public static function performBlockingIfActive(): ?array
    {
        $mode = self::getBlockingMode();
        if (!$mode) {
            return null;
        }

        // Calculate how much work to do based on remaining time
        // More aggressive blocking = more iterations
        $remaining = $mode['endTime'] - microtime(true);
        $total = $mode['durationSeconds'];
        $intensity = max(0.5, min(1.0, $remaining / $total)); // 0.5 to 1.0

        // Do CPU-intensive blocking work
        // ~10-20 iterations of PBKDF2 with 10000 rounds each = 100-400ms latency
        $iterations = (int) (15 * $intensity);
        for ($i = 0; $i < $iterations; $i++) {
            hash_pbkdf2('sha512', 'blocking-probe', 'salt', 10000, 64, false);
        }

        return [
            'iterations' => $iterations,
            'intensity' => round($intensity, 2),
            'remainingSeconds' => round($remaining, 1),
        ];
    }

    /**
     * Stop blocking mode immediately.
     */
    public static function stop(): void
    {
        $mode = SharedStorage::get(self::BLOCKING_MODE_KEY);
        SharedStorage::delete(self::BLOCKING_MODE_KEY);
        
        // Mark simulation as stopped and log
        if ($mode && isset($mode['simulationId'])) {
            SimulationTrackerService::stopSimulation($mode['simulationId']);
            EventLogService::info(
                'SIMULATION_STOPPED',
                'Request thread blocking stopped manually',
                $mode['simulationId'],
                'REQUEST_BLOCKING'
            );
        }
    }
}
