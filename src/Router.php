<?php
/**
 * =============================================================================
 * HTTP ROUTER — URL Pattern Matching & Controller Dispatch
 * =============================================================================
 *
 * PURPOSE:
 *   Maps HTTP method + URL path to controller actions. Lightweight router
 *   without external dependencies. Supports path parameters (e.g., :id).
 *
 * ROUTE STRUCTURE:
 *   GET    /api/health            → HealthController::index
 *   GET    /api/health/environment → HealthController::environment
 *   GET    /api/health/build      → HealthController::build
 *   GET    /api/health/probe      → HealthController::probe
 *   GET    /api/metrics           → MetricsController::index
 *   GET    /api/metrics/probe     → MetricsController::probe
 *   POST   /api/simulations/cpu   → CpuController::start
 *   DELETE /api/simulations/cpu/:id → CpuController::stop
 *   GET    /api/simulations/cpu   → CpuController::list
 *   POST   /api/simulations/memory → MemoryController::allocate
 *   DELETE /api/simulations/memory/:id → MemoryController::release
 *   GET    /api/simulations/memory → MemoryController::list
 *   POST   /api/simulations/blocking → BlockingController::block
 *   GET    /api/simulations/slow  → SlowController::delay
 *   POST   /api/simulations/crash/* → CrashController::*
 *   GET    /api/loadtest          → LoadTestController::execute
 *   GET    /api/loadtest/stats    → LoadTestController::stats
 *   GET    /api/simulations       → AdminController::listSimulations
 *   GET    /api/admin/status      → AdminController::status
 *   GET    /api/admin/events      → AdminController::events
 *   GET    /api/admin/memory-debug → AdminController::memoryDebug
 *   GET    /api/admin/system-info → AdminController::systemInfo
 *
 * @module src/Router.php
 */

declare(strict_types=1);

namespace PerfSimPhp;

use PerfSimPhp\Controllers\HealthController;
use PerfSimPhp\Controllers\MetricsController;
use PerfSimPhp\Controllers\CpuController;
use PerfSimPhp\Controllers\MemoryController;
use PerfSimPhp\Controllers\BlockingController;
use PerfSimPhp\Controllers\SlowController;
use PerfSimPhp\Controllers\CrashController;
use PerfSimPhp\Controllers\LoadTestController;
use PerfSimPhp\Controllers\AdminController;

class Router
{
    /**
     * Dispatch an HTTP request to the appropriate controller.
     *
     * @param string $method HTTP method (GET, POST, DELETE, etc.)
     * @param string $uri Request URI path
     * @return array|null Response array with 'status' and 'body', or null for static files
     */
    public function dispatch(string $method, string $uri): ?array
    {
        // Strip query string
        $path = parse_url($uri, PHP_URL_PATH);

        // ====================================================================
        // STATIC FILE ROUTES — serve HTML pages
        // ====================================================================
        if ($method === 'GET') {
            switch ($path) {
                case '/':
                case '/index.html':
                    $this->serveStaticFile(__DIR__ . '/../public/index.html', 'text/html');
                    return null;
                case '/docs.html':
                    $this->serveStaticFile(__DIR__ . '/../public/docs.html', 'text/html');
                    return null;
                case '/azure-deployment.html':
                    $this->serveStaticFile(__DIR__ . '/../public/azure-deployment.html', 'text/html');
                    return null;
                case '/azure-diagnostics.html':
                    $this->serveStaticFile(__DIR__ . '/../public/azure-diagnostics.html', 'text/html');
                    return null;
            }
        }

        // ====================================================================
        // API ROUTES
        // ====================================================================

        // Health endpoints
        if ($method === 'GET' && $path === '/api/health') {
            return HealthController::index();
        }
        if ($method === 'GET' && $path === '/api/health/environment') {
            return HealthController::environment();
        }
        if ($method === 'GET' && $path === '/api/health/build') {
            return HealthController::build();
        }
        if ($method === 'GET' && $path === '/api/health/probe') {
            return HealthController::probe();
        }

        // Metrics endpoints
        if ($method === 'GET' && $path === '/api/metrics') {
            return MetricsController::index();
        }
        if ($method === 'GET' && $path === '/api/metrics/probe') {
            return MetricsController::probe();
        }

        // CPU simulation endpoints
        if ($method === 'POST' && $path === '/api/simulations/cpu') {
            return CpuController::start();
        }
        if ($method === 'GET' && $path === '/api/simulations/cpu') {
            return CpuController::list();
        }
        if ($method === 'DELETE' && preg_match('#^/api/simulations/cpu/([a-f0-9-]+)$#i', $path, $matches)) {
            return CpuController::stop($matches[1]);
        }

        // Memory simulation endpoints
        if ($method === 'POST' && $path === '/api/simulations/memory') {
            return MemoryController::allocate();
        }
        if ($method === 'GET' && $path === '/api/simulations/memory') {
            return MemoryController::list();
        }
        if ($method === 'DELETE' && preg_match('#^/api/simulations/memory/([a-f0-9-]+)$#i', $path, $matches)) {
            return MemoryController::release($matches[1]);
        }

        // Blocking simulation endpoint (replaces Node.js "event loop blocking")
        if ($method === 'POST' && $path === '/api/simulations/blocking') {
            return BlockingController::block();
        }

        // Slow request endpoint
        if ($method === 'GET' && $path === '/api/simulations/slow') {
            return SlowController::delay();
        }

        // Crash simulation endpoints
        if ($method === 'POST' && $path === '/api/simulations/crash/failfast') {
            return CrashController::failfast();
        }
        if ($method === 'POST' && $path === '/api/simulations/crash/stackoverflow') {
            return CrashController::stackoverflow();
        }
        if ($method === 'POST' && $path === '/api/simulations/crash/exception') {
            return CrashController::exception();
        }
        if ($method === 'POST' && $path === '/api/simulations/crash/memory') {
            return CrashController::memory();
        }

        // Load test endpoints
        if ($method === 'GET' && $path === '/api/loadtest') {
            return LoadTestController::execute();
        }
        if ($method === 'GET' && $path === '/api/loadtest/stats') {
            return LoadTestController::stats();
        }

        // Admin endpoints
        if ($method === 'GET' && $path === '/api/simulations') {
            return AdminController::listSimulations();
        }
        if ($method === 'GET' && $path === '/api/admin/status') {
            return AdminController::status();
        }
        if ($method === 'GET' && $path === '/api/admin/events') {
            return AdminController::events();
        }
        if ($method === 'GET' && $path === '/api/admin/memory-debug') {
            return AdminController::memoryDebug();
        }
        if ($method === 'GET' && $path === '/api/admin/system-info') {
            return AdminController::systemInfo();
        }

        // 404 — No matching route
        return [
            'status' => 404,
            'body' => [
                'error' => 'Not Found',
                'message' => 'The requested resource does not exist',
            ],
        ];
    }

    /**
     * Serve a static HTML file and exit.
     */
    private function serveStaticFile(string $filePath, string $contentType): void
    {
        if (!file_exists($filePath)) {
            http_response_code(404);
            echo '404 Not Found';
            exit;
        }

        header('Content-Type: ' . $contentType);
        readfile($filePath);
        exit;
    }
}
