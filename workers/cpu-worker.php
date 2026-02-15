<?php
/**
 * =============================================================================
 * CPU WORKER — Standalone Background Process That Burns One CPU Core at 100%
 * =============================================================================
 *
 * PURPOSE:
 *   This file is the ENTRY POINT for a separately spawned background process.
 *   It is NOT included/required by the main application — it is launched via
 *   shell exec from CpuStressService.
 *   Each instance burns exactly one CPU core at 100% utilization.
 *
 * EXECUTION MODEL:
 *   - Spawned by: CpuStressService::launchWorkers() via shell_exec()
 *   - Each spawned process = 1 OS process = 1 CPU core pinned at 100%
 *   - The parent spawns N workers (one per target core)
 *   - Self-terminates after durationSeconds (passed as CLI argument)
 *
 * USAGE:
 *   php workers/cpu-worker.php <durationSeconds>
 *
 * HOW IT BURNS CPU:
 *   A tight while(true) loop calling hash_pbkdf2() (PBKDF2 with 10,000 iterations).
 *   Each call takes ~5-10ms of pure CPU work. The loop runs until the duration
 *   elapses or a SIGTERM signal is received.
 *
 * SIGNAL HANDLING:
 *   - SIGTERM: graceful shutdown (sets $running = false, loop exits)
 *   - SIGINT:  graceful shutdown (for manual Ctrl+C)
 *   - Duration timeout: self-terminates when durationSeconds elapses
 *
 * @module workers/cpu-worker.php
 */

declare(strict_types=1);

// Read duration from command line argument
$durationSeconds = isset($argv[1]) ? (int) $argv[1] : 60;

if ($durationSeconds <= 0) {
    fwrite(STDERR, "[cpu-worker] Invalid duration: {$durationSeconds}\n");
    exit(1);
}

$running = true;

// Set up signal handlers for graceful shutdown (POSIX systems only)
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use (&$running) {
        $running = false;
    });
    pcntl_signal(SIGINT, function () use (&$running) {
        $running = false;
    });
}

$pid = getmypid();
fwrite(STDERR, "[cpu-worker] PID={$pid} started, will burn CPU for {$durationSeconds}s\n");

$endTime = microtime(true) + $durationSeconds;

/**
 * Main CPU burn loop. Runs synchronously until stopped.
 *
 * ALGORITHM:
 *   1. Enter tight while loop
 *   2. Each iteration: hash_pbkdf2 with 10,000 rounds (~5-10ms of pure CPU)
 *   3. Loop exits when duration elapses or signal received
 *
 * The choice of hash_pbkdf2 is deliberate:
 *   - Cryptographic work that cannot be optimized away
 *   - Predictable duration per call (~5-10ms)
 *   - Available in PHP's standard library (no extensions needed)
 */
while ($running && microtime(true) < $endTime) {
    // PBKDF2 with 10,000 iterations: ~5-10ms of CPU-intensive synchronous work
    hash_pbkdf2('sha512', 'password', 'salt', 10000, 64, false);

    // Check for signals periodically (POSIX systems only)
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }
}

fwrite(STDERR, "[cpu-worker] PID={$pid} finished after {$durationSeconds}s\n");
exit(0);
