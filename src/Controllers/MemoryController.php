<?php
/**
 * =============================================================================
 * MEMORY CONTROLLER — Memory Pressure Simulation REST API
 * =============================================================================
 *
 * ENDPOINTS:
 *   POST   /api/simulations/memory     → Allocate memory (body: sizeMb)
 *   DELETE /api/simulations/memory/:id → Release a memory allocation (idempotent)
 *   GET    /api/simulations/memory     → List active allocations with total
 *
 * DELETE is idempotent — releasing an already-released allocation returns
 * success (not 404). This prevents issues with double-clicks and retries.
 *
 * @module src/Controllers/MemoryController.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Controllers;

use PerfSimPhp\Services\MemoryPressureService;
use PerfSimPhp\Middleware\Validation;
use PerfSimPhp\Utils;

class MemoryController
{
    /**
     * POST /api/simulations/memory
     * Allocates memory to simulate memory pressure.
     */
    public static function allocate(): void
    {
        $body = Utils::getJsonBody();
        $params = Validation::validateMemoryPressureParams($body);

        $simulation = MemoryPressureService::allocate($params);

        http_response_code(201);
        echo json_encode([
            'id' => $simulation['id'],
            'type' => $simulation['type'],
            'message' => "Allocated {$params['sizeMb']}MB of memory",
            'parameters' => $simulation['parameters'],
            'totalAllocatedMb' => MemoryPressureService::getTotalAllocatedMb(),
        ]);
    }

    /**
     * DELETE /api/simulations/memory/:id
     * Releases a memory allocation.
     */
    public static function release(string $id): void
    {
        Validation::validateUuid($id, 'id');

        $result = MemoryPressureService::release($id);

        if ($result) {
            echo json_encode([
                'id' => $result['simulation']['id'] ?? $id,
                'type' => 'MEMORY_PRESSURE',
                'message' => $result['sizeMb'] > 0
                    ? "Released {$result['sizeMb']}MB of memory"
                    : 'Released memory allocation',
                'status' => $result['simulation']['status'] ?? 'STOPPED',
                'stoppedAt' => $result['simulation']['stoppedAt'] ?? null,
                'totalAllocatedMb' => MemoryPressureService::getTotalAllocatedMb(),
            ]);
        } else {
            // Idempotent delete — success even if not found
            echo json_encode([
                'id' => $id,
                'type' => 'MEMORY_PRESSURE',
                'message' => 'Memory allocation already released or not found',
                'status' => 'STOPPED',
                'totalAllocatedMb' => MemoryPressureService::getTotalAllocatedMb(),
            ]);
        }
    }

    /**
     * POST /api/simulations/memory/release
     * Releases all memory allocations.
     */
    public static function releaseAll(): void
    {
        $allocations = MemoryPressureService::getActiveAllocations();
        $releasedCount = 0;
        $releasedMb = 0;

        foreach ($allocations as $allocation) {
            $result = MemoryPressureService::release($allocation['id']);
            if ($result) {
                $releasedCount++;
                $releasedMb += $result['sizeMb'] ?? 0;
            }
        }

        echo json_encode([
            'message' => $releasedCount > 0 
                ? "Released {$releasedCount} allocation(s), {$releasedMb}MB total" 
                : 'No active memory allocations to release',
            'releasedCount' => $releasedCount,
            'releasedMb' => $releasedMb,
            'totalAllocatedMb' => MemoryPressureService::getTotalAllocatedMb(),
        ]);
    }

    /**
     * GET /api/simulations/memory
     * Lists active memory allocations.
     */
    public static function list(): void
    {
        $allocations = MemoryPressureService::getActiveAllocations();

        echo json_encode([
            'allocations' => array_map(fn($alloc) => [
                'id' => $alloc['id'],
                'type' => $alloc['type'],
                'status' => $alloc['status'],
                'parameters' => $alloc['parameters'],
                'sizeMb' => MemoryPressureService::getAllocationInfo($alloc['id'])['sizeMb'] ?? null,
                'startedAt' => $alloc['startedAt'],
            ], $allocations),
            'count' => count($allocations),
            'totalAllocatedMb' => MemoryPressureService::getTotalAllocatedMb(),
        ]);
    }
}
