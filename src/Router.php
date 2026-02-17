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
 *   GET    /api/metrics/internal-probes → MetricsController::internalProbes
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
use PerfSimPhp\Controllers\SessionController;
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
            return self::ok(HealthController::index());
        }
        if ($method === 'GET' && $path === '/api/health/environment') {
            return self::ok(HealthController::environment());
        }
        if ($method === 'GET' && $path === '/api/health/build') {
            return self::ok(HealthController::build());
        }
        if ($method === 'GET' && $path === '/api/health/probe') {
            return self::ok(HealthController::probe());
        }
        if ($method === 'GET' && $path === '/api/health/debug-env') {
            return self::ok(HealthController::debugEnv());
        }

        // Metrics endpoints
        if ($method === 'GET' && $path === '/api/metrics') {
            return self::ok(MetricsController::index());
        }
        if ($method === 'GET' && $path === '/api/metrics/probe') {
            return self::ok(MetricsController::probe());
        }
        if ($method === 'GET' && $path === '/api/metrics/internal-probes') {
            return self::ok(MetricsController::internalProbes());
        }

        // CPU simulation endpoints
        if ($method === 'POST' && ($path === '/api/simulations/cpu' || $path === '/api/simulations/cpu/start')) {
            CpuController::start();
            return null;
        }
        if ($method === 'POST' && $path === '/api/simulations/cpu/stop') {
            CpuController::stopAll();
            return null;
        }
        if ($method === 'GET' && $path === '/api/simulations/cpu') {
            CpuController::list();
            return null;
        }
        if ($method === 'DELETE' && preg_match('#^/api/simulations/cpu/([a-f0-9-]+)$#i', $path, $matches)) {
            CpuController::stop($matches[1]);
            return null;
        }

        // Memory simulation endpoints
        if ($method === 'POST' && ($path === '/api/simulations/memory' || $path === '/api/simulations/memory/allocate')) {
            MemoryController::allocate();
            return null;
        }
        if ($method === 'POST' && $path === '/api/simulations/memory/release') {
            MemoryController::releaseAll();
            return null;
        }
        if ($method === 'GET' && $path === '/api/simulations/memory') {
            MemoryController::list();
            return null;
        }
        if ($method === 'DELETE' && preg_match('#^/api/simulations/memory/([a-f0-9-]+)$#i', $path, $matches)) {
            MemoryController::release($matches[1]);
            return null;
        }

        // Blocking simulation endpoint (replaces Node.js "event loop blocking")
        if ($method === 'POST' && ($path === '/api/simulations/blocking' || $path === '/api/simulations/blocking/start')) {
            BlockingController::block();
            return null;
        }

        // Session lock contention endpoint (PHP-specific gotcha)
        if ($method === 'POST' && ($path === '/api/simulations/session/lock' || $path === '/api/simulations/session/start')) {
            SessionController::lock();
            return null;
        }
        if ($method === 'GET' && $path === '/api/simulations/session/probe') {
            SessionController::probe();
            return null;
        }

        // Crash simulation endpoints
        if ($method === 'POST' && $path === '/api/simulations/crash/failfast') {
            CrashController::failfast();
            return null;
        }
        if ($method === 'POST' && $path === '/api/simulations/crash/stackoverflow') {
            CrashController::stackoverflow();
            return null;
        }
        if ($method === 'POST' && $path === '/api/simulations/crash/exception') {
            CrashController::exception();
            return null;
        }
        if ($method === 'POST' && ($path === '/api/simulations/crash/memory' || $path === '/api/simulations/crash/oom')) {
            CrashController::memory();
            return null;
        }

        // Load test endpoints
        if ($method === 'GET' && $path === '/api/loadtest') {
            LoadTestController::execute();
            return null;
        }
        if ($method === 'GET' && $path === '/api/loadtest/stats') {
            LoadTestController::stats();
            return null;
        }

        // Admin endpoints
        if ($method === 'GET' && $path === '/api/simulations') {
            AdminController::listSimulations();
            return null;
        }
        if ($method === 'GET' && $path === '/api/admin/status') {
            AdminController::status();
            return null;
        }
        if ($method === 'GET' && $path === '/api/admin/events') {
            AdminController::events();
            return null;
        }
        if ($method === 'GET' && $path === '/api/admin/memory-debug') {
            AdminController::memoryDebug();
            return null;
        }
        if ($method === 'GET' && $path === '/api/admin/system-info') {
            AdminController::systemInfo();
            return null;
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
     * Wrap a controller result in a standard response envelope.
     *
     * Controllers that already return ['status' => int, 'body' => array]
     * are passed through unchanged; otherwise the result is wrapped as a 200 OK.
     */
    private static function ok(array $result): array
    {
        // If the controller already returned the envelope format, pass through
        if (isset($result['status']) && is_int($result['status']) && array_key_exists('body', $result)) {
            return $result;
        }
        return ['status' => 200, 'body' => $result];
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
