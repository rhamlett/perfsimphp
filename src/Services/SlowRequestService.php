<?php
/**
 * =============================================================================
 * SLOW REQUEST SERVICE — Multiple Blocking Pattern Simulation
 * =============================================================================
 *
 * PURPOSE:
 *   Simulates slow HTTP responses using multiple blocking strategies, each
 *   demonstrating a different way requests can become slow in a PHP application.
 *
 * THREE BLOCKING PATTERNS (adapted from Node.js):
 *
 *   1. sleep (default) — IDLE WAIT
 *      The FPM worker is idle but held. Like a slow external API call
 *      or database query where the process is waiting for I/O.
 *      PHP equivalent of Node.js setTimeout — the process is occupied
 *      but not consuming CPU.
 *      Real-world: file_get_contents() to slow external service, pg_query().
 *
 *   2. cpu_intensive — CPU-BOUND BLOCKING
 *      Uses hash_pbkdf2() to burn CPU for the entire duration.
 *      Simulates heavy computation: image processing, PDF generation,
 *      complex data transformation, etc.
 *      Real-world: GD image manipulation, large array sorting, XML parsing.
 *
 *   3. file_io — I/O-INTENSIVE BLOCKING
 *      Performs repeated synchronous file read/write operations.
 *      Simulates intensive disk I/O: log processing, CSV imports,
 *      file-based caching, report generation.
 *      Real-world: Reading large files, scanning directories, writing exports.
 *
 * NODE.JS MAPPING:
 *   Node.js "setTimeout" → PHP "sleep" (async wait → process wait)
 *   Node.js "libuv" (thread pool saturation) → PHP "cpu_intensive"
 *     (PHP has no libuv/thread pool; CPU work replaces pool saturation)
 *   Node.js "worker" (Worker Thread blocking) → PHP "file_io"
 *     (PHP has no Worker Threads; file I/O replaces thread blocking)
 *
 * @module src/Services/SlowRequestService.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Services;

class SlowRequestService
{
    /**
     * Delays the response using the specified blocking pattern.
     *
     * @param array{delaySeconds: int, blockingPattern?: string} $params
     * @return array The completed simulation record
     */
    public static function delay(array $params): array
    {
        $delaySeconds = $params['delaySeconds'];
        $blockingPattern = $params['blockingPattern'] ?? 'sleep';

        // Create simulation record
        $simulation = SimulationTrackerService::createSimulation(
            'SLOW_REQUEST',
            ['type' => 'SLOW_REQUEST', 'delaySeconds' => $delaySeconds, 'blockingPattern' => $blockingPattern],
            $delaySeconds
        );

        // Log start with pattern info
        $patternDesc = self::getPatternDescription($blockingPattern);
        EventLogService::info(
            'SIMULATION_STARTED',
            "Slow request started: {$delaySeconds}s delay ({$patternDesc})",
            $simulation['id'],
            'SLOW_REQUEST',
            ['delaySeconds' => $delaySeconds, 'blockingPattern' => $blockingPattern]
        );

        try {
            // Execute the blocking pattern
            match ($blockingPattern) {
                'cpu_intensive' => self::blockCpuIntensive($delaySeconds),
                'file_io' => self::blockFileIo($delaySeconds),
                default => self::blockSleep($delaySeconds), // 'sleep'
            };

            // Mark as completed
            SimulationTrackerService::completeSimulation($simulation['id']);

            EventLogService::info(
                'SIMULATION_COMPLETED',
                "Slow request completed ({$patternDesc})",
                $simulation['id'],
                'SLOW_REQUEST'
            );

            return SimulationTrackerService::getSimulation($simulation['id']) ?? $simulation;
        } catch (\Throwable $e) {
            SimulationTrackerService::failSimulation($simulation['id']);

            EventLogService::error(
                'SIMULATION_FAILED',
                "Slow request failed: {$e->getMessage()}",
                $simulation['id'],
                'SLOW_REQUEST'
            );

            throw $e;
        }
    }

    /**
     * Sleep pattern — idle wait.
     *
     * The process is held but not consuming CPU. This simulates a
     * slow external dependency (database, API, file transfer).
     *
     * @param int $seconds Duration to sleep
     */
    private static function blockSleep(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * CPU-intensive pattern — burns CPU for the full duration.
     *
     * Uses hash_pbkdf2() in a tight loop, consuming 100% of one CPU core.
     * This simulates heavy computation (image processing, data crunching,
     * PDF generation, etc.)
     *
     * @param int $seconds Duration of CPU work
     */
    private static function blockCpuIntensive(int $seconds): void
    {
        $endTime = microtime(true) + $seconds;
        while (microtime(true) < $endTime) {
            // 1000 iterations (vs 10000 in cpu-worker) for finer time granularity
            hash_pbkdf2('sha512', 'password', 'salt', 1000, 64, false);
        }
    }

    /**
     * File I/O pattern — intensive disk operations.
     *
     * Performs repeated read/write cycles to a temp file, simulating
     * heavy file processing (log analysis, CSV import, report generation).
     *
     * @param int $seconds Duration of file I/O
     */
    private static function blockFileIo(int $seconds): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'perfsim_io_');
        if ($tempFile === false) {
            // Fallback to sleep if temp file creation fails
            sleep($seconds);
            return;
        }

        $endTime = microtime(true) + $seconds;
        $chunk = str_repeat('The quick brown fox jumps over the lazy dog. ', 100);

        try {
            while (microtime(true) < $endTime) {
                // Write 4KB of data
                file_put_contents($tempFile, $chunk, LOCK_EX);

                // Read it back
                $data = file_get_contents($tempFile);

                // Do something with it to prevent optimization
                if (strlen($data) === 0) {
                    break; // Should never happen
                }
            }
        } finally {
            // Clean up temp file
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Gets a human-readable description of the blocking pattern.
     */
    private static function getPatternDescription(string $pattern): string
    {
        return match ($pattern) {
            'cpu_intensive' => 'CPU-intensive computation',
            'file_io' => 'file I/O blocking',
            default => 'idle sleep (non-blocking wait)',
        };
    }
}
