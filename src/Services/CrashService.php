<?php
/**
 * =============================================================================
 * CRASH SERVICE — Intentional Process Termination Simulation
 * =============================================================================
 *
 * FEATURE REQUIREMENTS (language-agnostic):
 *   This service must provide multiple crash types that:
 *   1. Generate different diagnostic signatures in monitoring tools
 *   2. Allow users to practice identifying crash types from logs
 *   3. Send HTTP response BEFORE crashing (client gets confirmation)
 *   4. Demonstrate auto-recovery behavior of the hosting platform
 *   5. Help learn Azure AppLens, Application Insights, log analysis
 *
 * REQUIRED CRASH TYPES (implement equivalent for each runtime):
 *
 *   1. FailFast / Exit:
 *      Immediate process termination with non-zero exit code.
 *      Most platforms auto-restart the worker process.
 *
 *   2. Stack Overflow:
 *      Infinite recursion until stack exhausted.
 *      May cause segfault; recovery behavior varies.
 *
 *   3. Unhandled Exception / Fatal Error:
 *      Standard crash from uncaught exception or error.
 *      Typically auto-recovers on all platforms.
 *
 *   4. Out of Memory (OOM):
 *      Rapidly allocate until memory limit exceeded.
 *      May be killed by OS OOM killer on containers.
 *
 * HOW IT WORKS (this implementation):
 *   - HTTP 202 response is sent BEFORE crash occurs
 *   - register_shutdown_function schedules crash after response is flushed
 *   - PHP-FPM spawns new worker to replace crashed one
 *   - Event log records the crash for debugging practice
 *
 * PORTING NOTES:
 *
 *   Node.js:
 *     - FailFast: process.exit(1) or process.abort()
 *     - StackOverflow: function recurse() { recurse(); }
 *     - Exception: throw new Error() (uncaught)
 *     - OOM: while(true) { arrays.push(Buffer.alloc(10*1024*1024)); }
 *     - NOTE: Node crashes the entire process, not just one worker
 *
 *   Java (Spring Boot):
 *     - FailFast: System.exit(1) or Runtime.halt(1)
 *     - StackOverflow: recursive method without base case
 *     - Exception: throw new RuntimeException() (uncaught)
 *     - OOM: while(true) { list.add(new byte[10*1024*1024]); }
 *     - Consider sending response first via async or @ResponseBody
 *
 *   Python (Flask/FastAPI):
 *     - FailFast: os._exit(1) or sys.exit(1)
 *     - StackOverflow: def recurse(): recurse() (hits recursion limit)
 *     - Exception: raise Exception() (uncaught)
 *     - OOM: while True: lists.append('X' * 10*1024*1024)
 *     - Use background task or atexit for delayed crash
 *
 *   .NET (ASP.NET Core):
 *     - FailFast: Environment.FailFast() or Environment.Exit(1)
 *     - StackOverflow: recursive method (hard to catch in .NET)
 *     - Exception: throw new Exception() (uncaught)
 *     - OOM: while(true) { list.Add(new byte[10*1024*1024]); }
 *
 *   Ruby (Rails):
 *     - FailFast: Kernel.exit!(1) or Process.kill('KILL', Process.pid)
 *     - StackOverflow: def recurse; recurse; end (SystemStackError)
 *     - Exception: raise "error" (uncaught)
 *     - OOM: loop { array << 'X' * 10*1024*1024 }
 *
 * CROSS-PLATFORM CONSIDERATIONS:
 *   - ALWAYS send response before crashing (user needs feedback)
 *   - Log the crash before it happens for debugging practice
 *   - Warn user if crash type may require manual restart
 *   - Test recovery behavior on Azure/cloud platform
 *   - Some crash types (StackOverflow, OOM) may not auto-recover
 *
 * @module src/Services/CrashService.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Services;

class CrashService
{
    /**
     * Number of concurrent requests to send for the "crash all workers" feature.
     * This attempts to crash multiple FPM workers simultaneously.
     */
    private const MULTI_CRASH_WORKERS = 5;
    /**
     * Crashes via exit(1) — immediate process termination.
     *
     * This terminates the PHP-FPM worker process. The FPM master will
     * spawn a replacement worker automatically.
     *
     * Unlike Node.js process.abort(), PHP exit() is a clean termination
     * that runs shutdown functions. We schedule the crash in a shutdown
     * function to ensure the response is sent first.
     */
    public static function crashWithFailFast(): void
    {
        EventLogService::error(
            'SIMULATION_STARTED',
            'Crash simulation initiated: FailFast (exit)',
            null,
            'CRASH_FAILFAST',
            ['method' => 'exit(1)', 'workerPid' => getmypid()]
        );

        // Schedule crash after response is sent
        register_shutdown_function(function () {
            exit(1);
        });
    }

    /**
     * Crashes via stack overflow (infinite recursion).
     *
     * The default PHP max_function_nesting_level is typically 256 (Xdebug)
     * or unlimited. Without Xdebug, PHP will segfault on stack exhaustion.
     *
     * WARNING: Stack overflow/segfault may not auto-recover on Azure.
     */
    public static function crashWithStackOverflow(): void
    {
        EventLogService::error(
            'SIMULATION_STARTED',
            'Crash simulation initiated: stack overflow',
            null,
            'CRASH_STACKOVERFLOW',
            ['method' => 'infinite recursion', 'workerPid' => getmypid()]
        );

        EventLogService::warn(
            'CRASH_WARNING',
            'Stack Overflow crashes may not auto-recover on Azure App Service. Manual restart from Azure Portal may be required.',
            null,
            'CRASH_STACKOVERFLOW',
            ['recoveryHint' => 'Azure Portal > App Service > Restart']
        );

        // Schedule crash after response is sent
        /** @noinspection PhpInfiniteRecursionInspection - Intentional infinite recursion for crash simulation */
        register_shutdown_function(function () {
            $recurse = function () use (&$recurse) {
                $recurse();
            };
            $recurse();
        });
    }

    /**
     * Crashes via fatal error.
     *
     * Uses trigger_error with E_USER_ERROR to cause a fatal error.
     * This is the PHP equivalent of an unhandled exception in Node.js.
     * Auto-recovers on Azure (FPM spawns new worker).
     */
    public static function crashWithFatalError(): void
    {
        EventLogService::error(
            'SIMULATION_STARTED',
            'Crash simulation initiated: fatal error',
            null,
            'CRASH_FATAL',
            ['method' => 'trigger_error(E_USER_ERROR)', 'workerPid' => getmypid()]
        );

        // Schedule crash after response is sent
        register_shutdown_function(function () {
            trigger_error('Intentional crash: Fatal error simulation', E_USER_ERROR);
        });
    }

    /**
     * Crashes via memory exhaustion (OOM).
     *
     * Rapidly allocates memory until PHP's memory_limit is exceeded.
     * Produces "Allowed memory size of X bytes exhausted" fatal error.
     *
     * WARNING: On Azure, OOM may cause the worker to be killed by the
     * OS (SIGKILL/OOM Killer) if it exceeds container memory limits.
     */
    public static function crashWithMemoryExhaustion(): void
    {
        EventLogService::error(
            'SIMULATION_STARTED',
            'Crash simulation initiated: memory exhaustion',
            null,
            'CRASH_MEMORY',
            ['method' => 'memory exhaustion (OOM)', 'workerPid' => getmypid()]
        );

        EventLogService::warn(
            'CRASH_WARNING',
            'Out of Memory (OOM) crashes may not auto-recover on Azure App Service. Manual restart from Azure Portal may be required.',
            null,
            'CRASH_MEMORY',
            ['recoveryHint' => 'Azure Portal > App Service > Restart']
        );

        // Schedule crash after response is sent
        /** @noinspection PhpInfiniteLoopInspection - Intentional infinite loop for OOM crash simulation */
        register_shutdown_function(function () {
            // Rapidly allocate memory in 10MB chunks
            $allocations = [];
            while (true) {
                $allocations[] = str_repeat('X', 10 * 1024 * 1024); // 10MB
            }
        });
    }

    /**
     * Initiates a "crash all workers" simulation by making concurrent requests
     * to crash multiple FPM workers simultaneously.
     *
     * This creates a more visible crash effect since multiple workers are
     * terminated at once, potentially causing temporary service degradation
     * until FPM respawns enough workers.
     *
     * @param int $workerCount Number of workers to crash (default: 5)
     * @param string $crashType The crash method to use for each worker
     * @return array Summary of the initiated crashes
     */
    public static function initiateMultiWorkerCrash(int $workerCount = 5, string $crashType = 'failfast'): array
    {
        $requestedCount = min(max(1, $workerCount), 20); // Clamp between 1-20
        
        // Get actual available workers - subtract 1 because this worker handles crashAll
        $activeWorkers = CrashTrackingService::getActiveWorkerCount();
        $availableTocrash = max(0, $activeWorkers - 1);
        
        // Limit to actual available workers
        $workerCount = min($requestedCount, $availableTocrash);
        
        // If no workers available to crash, return early
        if ($workerCount <= 0) {
            EventLogService::warn(
                'MULTI_CRASH_SKIPPED',
                "Multi-worker crash skipped: requested {$requestedCount} but only {$activeWorkers} workers active (1 handling this request)",
                null,
                'MULTI_CRASH',
                [
                    'requestedCount' => $requestedCount,
                    'activeWorkers' => $activeWorkers,
                    'availableToCrash' => $availableTocrash,
                ]
            );
            
            return [
                'requested' => $requestedCount,
                'available' => $availableTocrash,
                'initiated' => 0,
                'crashType' => $crashType,
                'message' => "No workers available to crash (only {$activeWorkers} active, 1 handling this request)",
            ];
        }
        
        EventLogService::error(
            'MULTI_CRASH_INITIATED',
            "Multi-worker crash initiated: crashing {$workerCount} of {$activeWorkers} FPM workers via {$crashType}" . 
                ($requestedCount > $workerCount ? " (requested {$requestedCount}, limited to available)" : ""),
            null,
            'MULTI_CRASH',
            [
                'requestedCount' => $requestedCount,
                'actualCount' => $workerCount,
                'activeWorkers' => $activeWorkers,
                'crashType' => $crashType,
                'initiatingPid' => getmypid(),
            ]
        );

        // Get the base URL for internal requests
        $baseUrl = self::getInternalBaseUrl();
        $crashEndpoint = "{$baseUrl}/api/simulations/crash/{$crashType}";
        
        // Make concurrent crash requests using curl_multi
        $multiHandle = curl_multi_init();
        $handles = [];
        
        for ($i = 0; $i < $workerCount; $i++) {
            $ch = curl_init($crashEndpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => '{}',
            ]);
            curl_multi_add_handle($multiHandle, $ch);
            $handles[] = $ch;
        }
        
        // Execute all requests concurrently
        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle);
        } while ($running > 0);
        
        // Collect results
        $successCount = 0;
        foreach ($handles as $ch) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpCode === 202) {
                $successCount++;
            }
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }
        curl_multi_close($multiHandle);

        EventLogService::info(
            'MULTI_CRASH_COMPLETED',
            "Multi-worker crash requests completed: {$successCount} of {$workerCount} workers crashed" .
                ($requestedCount > $workerCount ? " (requested {$requestedCount}, limited to {$workerCount} available)" : ""),
            null,
            'MULTI_CRASH',
            [
                'successCount' => $successCount,
                'attemptedCount' => $workerCount,
                'requestedCount' => $requestedCount,
                'activeWorkers' => $activeWorkers,
            ]
        );

        return [
            'requested' => $requestedCount,
            'available' => $availableTocrash,
            'initiated' => $successCount,
            'crashType' => $crashType,
        ];
    }

    /**
     * Get the internal base URL for self-requests.
     * On Azure, we must use the actual HTTP_HOST, not localhost.
     */
    private static function getInternalBaseUrl(): string
    {
        // Use the actual host from the request - required for Azure
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        
        return "{$scheme}://{$host}";
    }
}
