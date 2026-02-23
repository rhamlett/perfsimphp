<?php
/**
 * =============================================================================
 * CPU WORKER — Standalone Background Process That Burns One CPU Core at 100%
 * =============================================================================
 *
 * FEATURE REQUIREMENTS (language-agnostic):
 *   Each worker instance must:
 *   1. Burn 100% of one CPU core for the specified duration
 *   2. Self-terminate after duration expires
 *   3. Handle termination signals gracefully (SIGTERM, SIGINT)
 *   4. Write status to stderr for debugging
 *   5. Exit with code 0 on success, 1 on error
 *
 * EXECUTION MODEL:
 *   - Launched as separate OS process by CpuStressService
 *   - Receives duration as command-line argument
 *   - Runs independently of HTTP request lifecycle
 *   - Multiple instances = multiple cores utilized
 *
 * HOW IT BURNS CPU (this implementation):
 *   A tight while(true) loop calling hash_pbkdf2() with 5000 iterations.
 *   Each call takes ~5-10ms of pure CPU work. Loop runs until duration
 *   elapses or termination signal received.
 *
 * PORTING NOTES:
 *   The key requirement is sustained CPU work that:
 *   - Actually consumes CPU (not just sleeping)
 *   - Runs for the specified duration
 *   - Can be terminated early via signal or PID kill
 *
 *   Node.js (Worker Thread):
 *     // In worker file:
 *     const { parentPort } = require('worker_threads');
 *     const endTime = Date.now() + durationMs;
 *     while (Date.now() < endTime) {
 *       crypto.pbkdf2Sync('password', 'salt', 10000, 64, 'sha512');
 *     }
 *     process.exit(0);
 *
 *   Java (Runnable):
 *     public void run() {
 *       long endTime = System.currentTimeMillis() + durationMs;
 *       while (System.currentTimeMillis() < endTime && !Thread.interrupted()) {
 *         MessageDigest.getInstance("SHA-512").digest(data);
 *       }
 *     }
 *
 *   Python (multiprocessing.Process):
 *     def worker(duration_seconds):
 *       end_time = time.time() + duration_seconds
 *       while time.time() < end_time:
 *         hashlib.pbkdf2_hmac('sha512', b'password', b'salt', 10000)
 *
 *   .NET (Task):
 *     var endTime = DateTime.UtcNow.AddSeconds(duration);
 *     while (DateTime.UtcNow < endTime && !cancellationToken.IsCancellationRequested) {
 *       using var sha = SHA512.Create();
 *       sha.ComputeHash(data);
 *     }
 *
 *   Ruby (fork):
 *     end_time = Time.now + duration_seconds
 *     while Time.now < end_time
 *       Digest::SHA512.digest('data' * 10000)
 *     end
 *
 * CPU-INTENSIVE OPERATIONS (pick one per language):
 *   - Cryptographic hashing (PBKDF2, SHA-512, bcrypt)
 *   - Mathematical computations (prime factorization, Fibonacci)
 *   - Compression/decompression
 *   - Matrix multiplication
 *   - Regular expression on large strings
 *
 * CROSS-PLATFORM CONSIDERATIONS:
 *   - Write start/end messages to stderr for debugging
 *   - Use monotonic time (not wall clock) for duration
 *   - Check termination signal periodically during loop
 *   - Exit cleanly when duration expires
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
 *   2. Each iteration: multiple hash operations to minimize loop overhead
 *   3. Loop exits when duration elapses or signal received
 *
 * Using multiple hash operations per loop iteration reduces the percentage
 * of time spent on loop overhead and time checks, maximizing CPU burn.
 */
$checkInterval = 0;
while ($running && microtime(true) < $endTime) {
    // Batch multiple CPU-intensive operations per loop iteration
    // This reduces time check overhead and maximizes CPU burn
    for ($i = 0; $i < 10; $i++) {
        hash_pbkdf2('sha512', 'password', 'salt', 5000, 64, false);
    }

    // Check for signals less frequently (every ~50ms instead of every ~5ms)
    if (++$checkInterval >= 10 && function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
        $checkInterval = 0;
    }
}

fwrite(STDERR, "[cpu-worker] PID={$pid} finished after {$durationSeconds}s\n");
exit(0);
