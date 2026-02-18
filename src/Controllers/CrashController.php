<?php
/**
 * =============================================================================
 * CRASH CONTROLLER — Crash Simulation REST API
 * =============================================================================
 *
 * ENDPOINTS:
 *   POST /api/simulations/crash/failfast      → exit(1) immediate termination
 *   POST /api/simulations/crash/stackoverflow → Infinite recursion crash
 *   POST /api/simulations/crash/exception     → Fatal error (trigger_error)
 *   POST /api/simulations/crash/memory        → OOM (allocate until crash)
 *
 * All endpoints return HTTP 202 (Accepted) BEFORE the crash is triggered.
 * The crash is deferred via register_shutdown_function() to allow the
 * response to be sent to the client first.
 *
 * @module src/Controllers/CrashController.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Controllers;

use PerfSimPhp\Services\CrashService;

class CrashController
{
    /**
     * POST /api/simulations/crash/failfast
     * Triggers immediate process termination via exit(1).
     */
    public static function failfast(): void
    {
        // Record crash BEFORE finishing request to ensure it's stored
        \PerfSimPhp\Services\CrashTrackingService::recordCrash('failfast');
        
        header('Content-Type: application/json');
        http_response_code(202);
        echo json_encode([
            'message' => 'FailFast initiated - PHP-FPM worker will terminate via exit(1)',
            'warning' => 'The PHP-FPM worker will terminate immediately. A new worker will be spawned automatically.',
            'workerPid' => getmypid(),
            'timestamp' => date('c'),
        ]);

        // Flush output before crash
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            ob_end_flush();
            flush();
        }

        CrashService::crashWithFailFast();
    }

    /**
     * POST /api/simulations/crash/stackoverflow
     * Triggers infinite recursion crash.
     */
    public static function stackoverflow(): void
    {
        // Record crash BEFORE finishing request to ensure it's stored
        \PerfSimPhp\Services\CrashTrackingService::recordCrash('stackoverflow');
        
        header('Content-Type: application/json');
        http_response_code(202);
        echo json_encode([
            'message' => 'Stack overflow initiated - PHP-FPM worker will terminate via infinite recursion',
            'warning' => 'The PHP-FPM worker will terminate. Stack overflow may require manual restart on Azure.',
            'workerPid' => getmypid(),
            'timestamp' => date('c'),
        ]);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            ob_end_flush();
            flush();
        }

        CrashService::crashWithStackOverflow();
    }

    /**
     * POST /api/simulations/crash/exception
     * Triggers a fatal error.
     */
    public static function exception(): void
    {
        // Record crash BEFORE finishing request to ensure it's stored
        \PerfSimPhp\Services\CrashTrackingService::recordCrash('exception');
        
        header('Content-Type: application/json');
        http_response_code(202);
        echo json_encode([
            'message' => 'Crash initiated - PHP-FPM worker will terminate via fatal error',
            'warning' => 'The PHP-FPM worker will terminate. A new worker will be spawned automatically.',
            'workerPid' => getmypid(),
            'timestamp' => date('c'),
        ]);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            ob_end_flush();
            flush();
        }

        CrashService::crashWithFatalError();
    }

    /**
     * POST /api/simulations/crash/memory
     * Triggers memory exhaustion (OOM) crash.
     */
    public static function memory(): void
    {
        // Record crash BEFORE finishing request to ensure it's stored
        \PerfSimPhp\Services\CrashTrackingService::recordCrash('oom');
        
        header('Content-Type: application/json');
        http_response_code(202);
        echo json_encode([
            'message' => 'Memory exhaustion initiated - PHP-FPM worker will terminate with OOM error',
            'warning' => 'The PHP-FPM worker will terminate. OOM crashes may require manual restart on Azure.',
            'workerPid' => getmypid(),
            'timestamp' => date('c'),
        ]);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            ob_end_flush();
            flush();
        }

        CrashService::crashWithMemoryExhaustion();
    }

    /**
     * POST /api/simulations/crash/all
     * Crashes multiple FPM workers simultaneously to make crash effects more visible.
     * 
     * Request body:
     *   - workerCount: number (optional, default 5, max 20)
     *   - crashType: string (optional, default 'failfast')
     */
    public static function crashAll(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $workerCount = (int) ($input['workerCount'] ?? 5);
        $crashType = $input['crashType'] ?? 'failfast';
        
        // Validate crash type
        $validTypes = ['failfast', 'exception', 'oom'];
        if (!in_array($crashType, $validTypes)) {
            $crashType = 'failfast';
        }
        
        $result = CrashService::initiateMultiWorkerCrash($workerCount, $crashType);
        
        // Build message that reflects actual vs requested
        $message = "Multi-worker crash initiated: {$result['initiated']} workers will crash";
        if ($result['requested'] > $result['available']) {
            $message .= " (requested {$result['requested']}, but only {$result['available']} were available)";
        }
        
        header('Content-Type: application/json');
        http_response_code(202);
        echo json_encode([
            'message' => $message,
            'warning' => 'Multiple PHP-FPM workers will terminate. This may cause brief service interruption.',
            'details' => $result,
            'timestamp' => date('c'),
        ]);
    }

    /**
     * GET /api/simulations/crash/stats
     * Returns crash statistics and worker tracking information.
     */
    public static function stats(): void
    {
        $stats = \PerfSimPhp\Services\CrashTrackingService::getCrashStats();
        
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'stats' => $stats,
            'currentWorkerPid' => getmypid(),
            'timestamp' => date('c'),
        ]);
    }
}
