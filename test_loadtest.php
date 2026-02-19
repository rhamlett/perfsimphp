<?php
require 'vendor/autoload.php';

use PerfSimPhp\Services\LoadTestService;

try {
    $result = LoadTestService::executeWork([
        'cpuWorkMs' => 50,
        'memorySizeKb' => 1000,
        'fileIoKb' => 50,
        'jsonDepth' => 3,
        'memoryChurnKb' => 200,
        'targetDurationMs' => 500,
    ]);
    print_r($result);
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
    echo 'File: ' . $e->getFile() . ':' . $e->getLine() . "\n";
    echo $e->getTraceAsString();
}
