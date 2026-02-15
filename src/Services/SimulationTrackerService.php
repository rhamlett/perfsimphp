<?php
/**
 * =============================================================================
 * SIMULATION TRACKER SERVICE — Simulation Lifecycle Management
 * =============================================================================
 *
 * PURPOSE:
 *   Central registry for all active and completed simulations. Uses shared
 *   storage (APCu/files) to persist state across PHP-FPM requests.
 *
 * STATE MACHINE:
 *   createSimulation() → status=ACTIVE
 *   completeSimulation() → status=COMPLETED (duration elapsed)
 *   stopSimulation() → status=STOPPED (user-initiated)
 *   failSimulation() → status=FAILED (error during execution)
 *
 * @module src/Services/SimulationTrackerService.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Services;

use PerfSimPhp\Utils;
use PerfSimPhp\SharedStorage;
use PerfSimPhp\Config;

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
     */
    public static function getActiveSimulations(): array
    {
        $simulations = SharedStorage::get(self::STORAGE_KEY, []);
        // Also clean up expired simulations
        $now = microtime(true);
        $active = [];

        foreach ($simulations as $sim) {
            if ($sim['status'] === 'ACTIVE') {
                $active[] = $sim;
            }
        }

        return $active;
    }

    /**
     * Gets active simulations of a specific type.
     */
    public static function getActiveSimulationsByType(string $type): array
    {
        return array_values(array_filter(
            self::getActiveSimulations(),
            fn(array $sim) => $sim['type'] === $type
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
