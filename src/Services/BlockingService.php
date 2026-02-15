<?php
/**
 * =============================================================================
 * BLOCKING SERVICE — Request Thread Blocking Simulation
 * =============================================================================
 *
 * PURPOSE:
 *   Simulates the effect of synchronous/blocking operations on a PHP-FPM
 *   worker process. When a worker is blocked, that ONE request is stuck.
 *   If enough workers are blocked simultaneously, the entire FPM pool is
 *   exhausted and ALL new requests queue up.
 *
 * PHP vs NODE.JS DIFFERENCES:
 *   - In Node.js, blocking the event loop blocks ALL I/O on that process.
 *   - In PHP-FPM, each request gets its own worker process. Blocking one
 *     worker only blocks that single request. Other requests are handled
 *     by other workers in the pool.
 *   - To simulate "everything is blocked," you need to exhaust the FPM
 *     worker pool by sending concurrent blocking requests >= pm.max_children.
 *   - The dashboard probes (client-side XHR) measure response time from
 *     ANY available worker, showing degradation as the pool fills up.
 *
 * HOW IT WORKS:
 *   Uses hash_pbkdf2() in a tight loop to block the current PHP-FPM worker.
 *   The response is sent AFTER the blocking completes (same behavior as
 *   the Node.js version). The simulation is synchronous — the HTTP request
 *   hangs until the blocking duration elapses.
 *
 * RENAMED: "Event Loop Blocking" → "Request Thread Blocking"
 *   PHP doesn't have an event loop. The concept maps to blocking a request
 *   handler thread/process, which is how synchronous operations manifest
 *   in PHP applications (e.g., synchronous DB queries, file_get_contents
 *   to slow external services, heavy computation without yielding).
 *
 * @module src/Services/BlockingService.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Services;

class BlockingService
{
    /**
     * Blocks the current PHP-FPM worker for the specified duration.
     *
     * This is a SYNCHRONOUS operation — the HTTP response will not be sent
     * until the blocking duration elapses. The calling controller should
     * send the response AFTER this method returns.
     *
     * @param array{durationSeconds: int} $params
     * @return array The completed simulation record
     */
    public static function block(array $params): array
    {
        $durationSeconds = $params['durationSeconds'];

        // Create simulation record
        $simulation = SimulationTrackerService::createSimulation(
            'REQUEST_BLOCKING',
            ['type' => 'REQUEST_BLOCKING', 'durationSeconds' => $durationSeconds],
            $durationSeconds
        );

        // Log start — this is a warning because blocking has visible impact
        EventLogService::warn(
            'SIMULATION_STARTED',
            "Request thread blocking started for {$durationSeconds}s — this FPM worker will be unresponsive",
            $simulation['id'],
            'REQUEST_BLOCKING',
            ['durationSeconds' => $durationSeconds, 'pid' => getmypid()]
        );

        try {
            // Block the current process
            self::performBlocking($durationSeconds);

            // Mark as completed
            SimulationTrackerService::completeSimulation($simulation['id']);

            EventLogService::info(
                'SIMULATION_COMPLETED',
                'Request thread blocking completed',
                $simulation['id'],
                'REQUEST_BLOCKING'
            );

            // Return updated simulation
            return SimulationTrackerService::getSimulation($simulation['id']) ?? $simulation;
        } catch (\Throwable $e) {
            SimulationTrackerService::failSimulation($simulation['id']);

            EventLogService::error(
                'SIMULATION_FAILED',
                "Request thread blocking failed: {$e->getMessage()}",
                $simulation['id'],
                'REQUEST_BLOCKING'
            );

            throw $e;
        }
    }

    /**
     * Blocks the current PHP process for the specified duration using
     * CPU-intensive hash computation.
     *
     * ALGORITHM:
     *   Tight loop calling hash_pbkdf2() until the duration elapses.
     *   Each call takes ~5-10ms of pure CPU work, keeping the process
     *   fully blocked and consuming CPU (unlike sleep() which is idle).
     *
     * WHY hash_pbkdf2() AND NOT sleep():
     *   sleep() puts the process in an idle wait state — CPU usage stays low.
     *   hash_pbkdf2() performs real CPU work, which:
     *   1. Shows in sys_getloadavg() and Azure CPU metrics
     *   2. More accurately simulates a "stuck" computation
     *   3. Matches the Node.js implementation (pbkdf2Sync)
     *
     * @param int $durationSeconds How long to block
     */
    private static function performBlocking(int $durationSeconds): void
    {
        $endTime = microtime(true) + $durationSeconds;

        while (microtime(true) < $endTime) {
            // PBKDF2 with 10,000 iterations: ~5-10ms of CPU-intensive work.
            // Matches the Node.js cpu-worker.ts implementation.
            hash_pbkdf2('sha512', 'password', 'salt', 10000, 64, false);
        }
    }
}
