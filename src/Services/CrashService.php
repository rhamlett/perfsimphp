<?php
/**
 * =============================================================================
 * CRASH SERVICE — Intentional Process Termination Simulation
 * =============================================================================
 *
 * PURPOSE:
 *   Intentionally crashes the PHP-FPM worker process using different failure
 *   modes. Each crash type produces a different diagnostic signature in
 *   monitoring tools (Azure AppLens, Application Insights, Kudu log stream),
 *   helping users learn to identify crash types from their diagnostics.
 *
 * CRASH TYPES (PHP equivalents of Node.js crash types):
 *
 *   1. FailFast (exit)         → exit(1) — immediate process termination.
 *      PHP equivalent of process.abort(). Visible in PHP-FPM error logs.
 *      Auto-recovers on Azure (FPM spawns new worker).
 *
 *   2. Stack Overflow           → Infinite recursion until stack exhausted.
 *      Visible as "Maximum function nesting level" or segfault.
 *      May require manual restart on Azure.
 *
 *   3. Fatal Error              → trigger_error(E_ERROR) — unrecoverable error.
 *      Standard crash, auto-recovers on Azure App Service.
 *      PHP equivalent of unhandled exception in Node.js.
 *
 *   4. Memory Exhaustion (OOM)  → Allocate until memory_limit hit.
 *      Visible as "Allowed memory size exhausted" fatal error.
 *      Auto-recovers on Azure (FPM spawns new worker).
 *
 * SAFETY:
 *   The HTTP response (202 Accepted) is sent BEFORE the crash occurs.
 *   We use register_shutdown_function and output buffering to ensure the
 *   response reaches the client.
 *
 * AZURE BEHAVIOR:
 *   - PHP-FPM's process manager automatically spawns a new worker after
 *     most crash types (exit, fatal error, OOM).
 *   - Stack Overflow (segfault) may leave the worker in a bad state.
 *   - None of these crash the entire PHP-FPM master process — only the
 *     individual worker is affected.
 *
 * @module src/Services/CrashService.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Services;

class CrashService
{
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
            ['method' => 'exit(1)']
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
            ['method' => 'infinite recursion']
        );

        EventLogService::warn(
            'CRASH_WARNING',
            'Stack Overflow crashes may not auto-recover on Azure App Service. Manual restart from Azure Portal may be required.',
            null,
            'CRASH_STACKOVERFLOW',
            ['recoveryHint' => 'Azure Portal > App Service > Restart']
        );

        // Schedule crash after response is sent
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
            ['method' => 'trigger_error(E_USER_ERROR)']
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
            ['method' => 'memory exhaustion (OOM)']
        );

        EventLogService::warn(
            'CRASH_WARNING',
            'Out of Memory (OOM) crashes may not auto-recover on Azure App Service. Manual restart from Azure Portal may be required.',
            null,
            'CRASH_MEMORY',
            ['recoveryHint' => 'Azure Portal > App Service > Restart']
        );

        // Schedule crash after response is sent
        register_shutdown_function(function () {
            // Rapidly allocate memory in 10MB chunks
            $allocations = [];
            while (true) {
                $allocations[] = str_repeat('X', 10 * 1024 * 1024); // 10MB
            }
        });
    }
}
