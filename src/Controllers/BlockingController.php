<?php
/**
 * =============================================================================
 * BLOCKING CONTROLLER — Request Thread Blocking Simulation REST API
 * =============================================================================
 *
 * ENDPOINTS:
 *   POST /api/simulations/blocking → Start blocking mode (body: durationSeconds)
 *
 * PURPOSE:
 *   Demonstrates the sync-over-async antipattern. When triggered, all subsequent
 *   probe requests will experience latency for the specified duration. This
 *   simulates what happens when blocking operations (synchronous I/O, heavy
 *   computation) tie up request handlers.
 *
 * EXAMPLES OF SYNC-OVER-ASYNC IN PHP:
 *   - file_get_contents() to external APIs instead of async HTTP
 *   - Synchronous database queries without connection pooling  
 *   - Heavy computation on the request thread
 *   - Waiting on file locks or inter-process communication
 *
 * @module src/Controllers/BlockingController.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Controllers;

use PerfSimPhp\Services\BlockingService;
use PerfSimPhp\Middleware\Validation;
use PerfSimPhp\Utils;

class BlockingController
{
    /**
     * POST /api/simulations/blocking
     * Starts blocking mode for the specified duration.
     * All probe requests during this window will experience latency.
     */
    public static function block(): void
    {
        $body = Utils::getJsonBody();
        $params = Validation::validateBlockingParams($body);

        // Set blocking mode (returns immediately)
        $simulation = BlockingService::block($params);

        echo json_encode([
            'id' => $simulation['id'],
            'type' => $simulation['type'],
            'message' => "Blocking mode active for {$params['durationSeconds']}s — probe requests will experience latency",
            'status' => $simulation['status'],
            'startedAt' => $simulation['startedAt'],
            'scheduledEndAt' => $simulation['scheduledEndAt'],
            'durationSeconds' => $params['durationSeconds'],
        ]);
    }
}
