<?php
/**
 * =============================================================================
 * CPU CONTROLLER — CPU Stress Simulation REST API
 * =============================================================================
 *
 * ENDPOINTS:
 *   POST   /api/simulations/cpu     → Start CPU stress (body: targetLoadPercent, durationSeconds)
 *   DELETE /api/simulations/cpu/:id → Stop a running simulation
 *   GET    /api/simulations/cpu     → List active CPU simulations
 *
 * @module src/Controllers/CpuController.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Controllers;

use PerfSimPhp\Services\CpuStressService;
use PerfSimPhp\Services\SimulationTrackerService;
use PerfSimPhp\Middleware\Validation;
use PerfSimPhp\Middleware\ErrorHandler;
use PerfSimPhp\Utils;

class CpuController
{
    /**
     * POST /api/simulations/cpu
     * Starts a new CPU stress simulation.
     */
    public static function start(): void
    {
        $body = Utils::parseJsonBody();
        $params = Validation::validateCpuStressParams($body);

        $simulation = CpuStressService::start($params);

        http_response_code(201);
        echo json_encode([
            'id' => $simulation['id'],
            'type' => $simulation['type'],
            'message' => "CPU stress simulation started at {$params['targetLoadPercent']}% for {$params['durationSeconds']}s",
            'parameters' => $simulation['parameters'],
            'scheduledEndAt' => $simulation['scheduledEndAt'],
        ]);
    }

    /**
     * DELETE /api/simulations/cpu/:id
     * Stops a running CPU stress simulation.
     */
    public static function stop(string $id): void
    {
        Validation::validateUuid($id, 'id');

        // Check if simulation exists and is a CPU stress simulation
        $simulation = SimulationTrackerService::getSimulation($id);
        if (!$simulation) {
            throw new ErrorHandler\NotFoundException('Simulation not found');
        }
        if ($simulation['type'] !== 'CPU_STRESS') {
            throw new ErrorHandler\NotFoundException('Simulation not found (not a CPU stress simulation)');
        }
        if ($simulation['status'] !== 'ACTIVE') {
            throw new ErrorHandler\NotFoundException('Simulation is not active');
        }

        $stopped = CpuStressService::stop($id);
        if (!$stopped) {
            throw new ErrorHandler\NotFoundException('Failed to stop simulation');
        }

        echo json_encode([
            'id' => $stopped['id'],
            'type' => $stopped['type'],
            'message' => 'CPU stress simulation stopped',
            'status' => $stopped['status'],
            'stoppedAt' => $stopped['stoppedAt'] ?? null,
        ]);
    }

    /**
     * GET /api/simulations/cpu
     * Lists active CPU stress simulations.
     */
    public static function list(): void
    {
        $simulations = CpuStressService::getActiveSimulations();

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
}
