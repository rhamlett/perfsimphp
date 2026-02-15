<?php
/**
 * =============================================================================
 * BLOCKING CONTROLLER — Request Thread Blocking Simulation REST API
 * =============================================================================
 *
 * ENDPOINTS:
 *   POST /api/simulations/blocking → Block this FPM worker (body: durationSeconds)
 *
 * BLOCKING BEHAVIOR:
 *   Unlike CPU and memory simulations which start in the background and return
 *   immediately, this endpoint is synchronous. The HTTP response is NOT sent
 *   until the blocking duration elapses. The response time equals the blocking
 *   duration.
 *
 * PHP NOTE:
 *   In PHP-FPM, blocking one worker does NOT block other requests (unlike
 *   Node.js where blocking the event loop blocks everything). To observe
 *   full application blocking, send concurrent blocking requests >= pm.max_children.
 *
 * RENAMED: "Event Loop Blocking" → "Request Thread Blocking"
 *   PHP doesn't have an event loop. This simulates what happens when a
 *   PHP request handler is stuck in synchronous computation (e.g.,
 *   file_get_contents() to a slow external service, synchronous DB query
 *   on a locked table, heavy computation without yielding).
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
     * Blocks the current PHP-FPM worker for the specified duration.
     *
     * WARNING: This request will hang for the full duration before responding.
     */
    public static function block(): void
    {
        $body = Utils::parseJsonBody();
        $params = Validation::validateBlockingParams($body);

        // This call blocks synchronously for durationSeconds
        $simulation = BlockingService::block($params);

        echo json_encode([
            'id' => $simulation['id'],
            'type' => $simulation['type'],
            'message' => "Request thread was blocked for {$params['durationSeconds']}s",
            'status' => $simulation['status'],
            'startedAt' => $simulation['startedAt'],
            'stoppedAt' => $simulation['stoppedAt'] ?? null,
            'actualDurationMs' => isset($simulation['stoppedAt'], $simulation['startedAt'])
                ? (int) ((strtotime($simulation['stoppedAt']) - strtotime($simulation['startedAt'])) * 1000)
                : null,
        ]);
    }
}
