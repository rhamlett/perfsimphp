<?php
/**
 * =============================================================================
 * SIMULATION TRACKER SERVICE — Simulation Lifecycle Management
 * =============================================================================
 *
 * FEATURE REQUIREMENTS (language-agnostic):
 *   This service must track all simulations throughout their lifecycle:
 *   1. Create simulations with unique IDs and parameters
 *   2. Track status: ACTIVE → COMPLETED | STOPPED | FAILED
 *   3. Support timed simulations (auto-complete after duration)
 *   4. Support indefinite simulations (manual stop required)
 *   5. Provide filtered queries (by type, by status)
 *   6. Handle cleanup of expired simulations
 *
 * STATE MACHINE:
 *   createSimulation() → status=ACTIVE
 *   completeSimulation() → status=COMPLETED (duration elapsed)
 *   stopSimulation() → status=STOPPED (user-initiated)
 *   failSimulation() → status=FAILED (error during execution)
 *
 * SIMULATION RECORD STRUCTURE:
 *   {
 *     id: string (UUID),
 *     type: string (CPU_STRESS | MEMORY_PRESSURE | REQUEST_BLOCKING),
 *     parameters: object (simulation-specific config),
 *     status: string (ACTIVE | COMPLETED | STOPPED | FAILED),
 *     startedAt: string (ISO 8601),
 *     stoppedAt: string | null (ISO 8601),
 *     scheduledEndAt: string | null (ISO 8601, for timed simulations)
 *   }
 *
 * HOW IT WORKS (this implementation):
 *   - Simulations stored in APCu/file-based SharedStorage
 *   - Cleanup runs during getActiveSimulations() (lazy cleanup)
 *   - CPU worker processes killed on simulation expiry
 *   - Read-only fast path for high-frequency polling
 *
 * PORTING NOTES:
 *
 *   Node.js:
 *     - In-memory Map or object (process is persistent)
 *     - Use setInterval to check for expiring simulations
 *     - No shared storage needed between requests
 *
 *   Java (Spring Boot):
 *     - ConcurrentHashMap in @Service singleton
 *     - ScheduledExecutorService for expiration checking
 *     - Consider Spring's @Scheduled for periodic cleanup
 *
 *   Python (Flask/FastAPI):
 *     - Dict in application scope
 *     - Background task for cleanup (APScheduler or Celery)
 *     - Redis for multi-worker state
 *
 *   .NET (ASP.NET Core):
 *     - ConcurrentDictionary in singleton service
 *     - IHostedService + Timer for cleanup
 *     - IMemoryCache with automatic expiration
 *
 *   Ruby (Rails):
 *     - Hash in application scope with mutex
 *     - Sidekiq for background cleanup jobs
 *     - Redis for multi-process state
 *
 * CROSS-PLATFORM CONSIDERATIONS:
 *   - State must persist across HTTP requests
 *   - Cleanup must not block request handling
 *   - Consider lazy vs active cleanup strategies
 *   - CPU workers need explicit termination on stop
 *   - High-frequency polling needs fast read path
 *
 * @module src/Services/SimulationTrackerService.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Services;

use PerfSimPhp\Utils;
use PerfSimPhp\SharedStorage;
use PerfSimPhp\Config;
use PerfSimPhp\Services\CpuStressService;

class SimulationTrackerService
{
    private const STORAGE_KEY = 'perfsim_simulations';

    /**
     * Creates and registers a new simulation.
     */
    public static function createSimulation(
        string $type,
        array $parameters,
        ?int $durationSeconds = null,
    ): array {
        $id = Utils::generateId();
        $now = Utils::formatTimestamp();
        $duration = $durationSeconds ?? Config::maxSimulationDurationSeconds();
        $scheduledEndAt = Utils::formatTimestamp(microtime(true) + $duration);

        $simulation = [
            'id' => $id,
            'type' => $type,
            'parameters' => $parameters,
            'status' => 'ACTIVE',
            'startedAt' => $now,
            'stoppedAt' => null,
            'scheduledEndAt' => $scheduledEndAt,
        ];

        SharedStorage::modify(self::STORAGE_KEY, function (?array $simulations) use ($simulation) {
            $simulations = $simulations ?? [];
            $simulations[$simulation['id']] = $simulation;
            return $simulations;
        }, []);

        return $simulation;
    }

    /**
     * Gets a simulation by ID.
     */
    public static function getSimulation(string $id): ?array
    {
        $simulations = SharedStorage::get(self::STORAGE_KEY, []);
        return $simulations[$id] ?? null;
    }

    /**
     * Gets all active simulations.
     * Also cleans up expired simulations that weren't properly completed.
     */
    public static function getActiveSimulations(): array
    {
        $simulations = SharedStorage::get(self::STORAGE_KEY, []);
        $now = Utils::formatTimestamp();
        $active = [];
        $toLog = [];
        $modified = false;

        foreach ($simulations as $id => $sim) {
            if ($sim['status'] === 'ACTIVE') {
                // Check if simulation has expired
                $scheduledEnd = $sim['scheduledEndAt'] ?? null;
                if ($scheduledEnd && $scheduledEnd < $now) {
                    // Mark as completed
                    $simulations[$id]['status'] = 'COMPLETED';
                    $simulations[$id]['stoppedAt'] = $now;
                    $toLog[] = $sim;
                    $modified = true;
                } else {
                    $active[] = $sim;
                }
            }
        }

        // Save changes
        if ($modified) {
            SharedStorage::set(self::STORAGE_KEY, $simulations);
        }

        // Log completion messages and perform cleanup
        foreach ($toLog as $sim) {
            $duration = $sim['parameters']['durationSeconds'] ?? null;
            $message = match($sim['type']) {
                'REQUEST_BLOCKING' => "Request thread blocking completed" . ($duration ? " after {$duration}s" : ""),
                'CPU_STRESS' => "CPU stress simulation completed" . ($duration ? " after {$duration}s" : ""),
                default => "{$sim['type']} simulation completed",
            };
            
            // Clean up CPU worker processes when simulation expires
            // Note: cleanupWorkers uses batch kill which is fast
            if ($sim['type'] === 'CPU_STRESS') {
                CpuStressService::cleanupWorkers($sim['id']);
            }
            
            EventLogService::success(
                'SIMULATION_COMPLETED',
                $message,
                $sim['id'],
                $sim['type']
            );
        }

        return $active;
    }

    /**
     * Gets active simulations of a specific type.
     * Uses read-only fast path for probe endpoints.
     */
    public static function getActiveSimulationsByType(string $type): array
    {
        return array_values(array_filter(
            self::getActiveSimulationsReadOnly(),
            fn(array $sim) => $sim['type'] === $type
        ));
    }

    /**
     * Fast read-only check for active simulations.
     * Does NOT do cleanup - use for high-frequency polling like probes.
     */
    public static function getActiveSimulationsReadOnly(): array
    {
        $simulations = SharedStorage::get(self::STORAGE_KEY, []);
        $now = Utils::formatTimestamp();
        $active = [];

        foreach ($simulations as $sim) {
            if ($sim['status'] === 'ACTIVE') {
                // Check if not expired (but don't clean up)
                $scheduledEnd = $sim['scheduledEndAt'] ?? null;
                if (!$scheduledEnd || $scheduledEnd >= $now) {
                    $active[] = $sim;
                }
            }
        }

        return $active;
    }

    /**
     * Gets simulations of a type that are within their scheduled time window,
     * regardless of current status. Useful for blocking simulations where the
     * "ACTIVE" status only exists during the synchronous blocking period.
     */
    public static function getSimulationsInTimeWindow(string $type): array
    {
        $simulations = SharedStorage::get(self::STORAGE_KEY, []);
        $now = Utils::formatTimestamp();
        
        return array_values(array_filter(
            $simulations,
            fn(array $sim) => 
                $sim['type'] === $type &&
                $sim['startedAt'] <= $now &&
                ($sim['scheduledEndAt'] ?? '9999') >= $now
        ));
    }

    /**
     * Stops a simulation (user-initiated).
     */
    public static function stopSimulation(string $id): ?array
    {
        return self::updateStatus($id, 'STOPPED');
    }

    /**
     * Marks a simulation as completed.
     */
    public static function completeSimulation(string $id): ?array
    {
        return self::updateStatus($id, 'COMPLETED');
    }

    /**
     * Marks a simulation as failed.
     */
    public static function failSimulation(string $id): ?array
    {
        return self::updateStatus($id, 'FAILED');
    }

    /**
     * Updates a simulation's status.
     */
    private static function updateStatus(string $id, string $status): ?array
    {
        $result = null;

        SharedStorage::modify(self::STORAGE_KEY, function (?array $simulations) use ($id, $status, &$result) {
            $simulations = $simulations ?? [];

            if (!isset($simulations[$id]) || $simulations[$id]['status'] !== 'ACTIVE') {
                return $simulations;
            }

            $simulations[$id]['status'] = $status;
            $simulations[$id]['stoppedAt'] = Utils::formatTimestamp();
            $result = $simulations[$id];

            return $simulations;
        }, []);

        return $result;
    }

    /**
     * Removes a simulation from tracking.
     */
    public static function removeSimulation(string $id): bool
    {
        $removed = false;

        SharedStorage::modify(self::STORAGE_KEY, function (?array $simulations) use ($id, &$removed) {
            $simulations = $simulations ?? [];
            if (isset($simulations[$id])) {
                unset($simulations[$id]);
                $removed = true;
            }
            return $simulations;
        }, []);

        return $removed;
    }

    /**
     * Gets the count of active simulations.
     */
    public static function getActiveCount(): int
    {
        return count(self::getActiveSimulations());
    }

    /**
     * Clears all simulations.
     */
    public static function clear(): void
    {
        SharedStorage::set(self::STORAGE_KEY, []);
    }
}
