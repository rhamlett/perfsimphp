<?php
/**
 * =============================================================================
 * BOOTSTRAP — Autoloader & Initialization
 * =============================================================================
 *
 * PURPOSE:
 *   Sets up the PSR-4 autoloader and initializes core services.
 *   This file is included by public/index.php before any other code runs.
 *
 * @module src/bootstrap.php
 */

declare(strict_types=1);

// PSR-4 Autoloader (manual implementation — no Composer dependency required)
spl_autoload_register(function (string $class): void {
    $prefix = 'PerfSimPhp\\';
    $baseDir = __DIR__ . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Also load Composer autoloader if available
$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require $composerAutoload;
}

// Ensure storage directory exists
$storageDir = __DIR__ . '/../storage';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0755, true);
}
