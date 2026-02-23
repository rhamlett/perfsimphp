<?php
/**
 * =============================================================================
 * HTTP ROUTER — URL Pattern Matching & Controller Dispatch
 * =============================================================================
 *
 * FEATURE REQUIREMENTS (language-agnostic):
 *   The application must expose these REST API endpoints:
 *
 *   HEALTH ENDPOINTS:
 *     GET  /api/health              → Basic health check (status, timestamp)
 *     GET  /api/health/environment  → Runtime environment info
 *     GET  /api/health/build        → Build version/timestamp
 *     GET  /api/health/probe        → Lightweight probe for latency testing
 *
 *   METRICS ENDPOINTS:
 *     GET  /api/metrics             → Full metrics snapshot (CPU, memory, simulations)
 *     GET  /api/metrics/probe       → Lightweight probe (may include blocking work)
 *     GET  /api/metrics/internal-probe → Batch internal latency probing
 *
 *   CPU SIMULATION:
 *     POST /api/simulations/cpu/start    → Start CPU stress (body: level, durationSeconds)
 *     POST /api/simulations/cpu/stop     → Stop all CPU simulations
 *     DELETE /api/simulations/cpu/:id    → Stop specific simulation
 *     GET  /api/simulations/cpu          → List active CPU simulations
 *
 *   MEMORY SIMULATION:
 *     POST /api/simulations/memory/allocate → Allocate memory (body: sizeMb)
 *     POST /api/simulations/memory/release  → Release all memory
 *     DELETE /api/simulations/memory/:id    → Release specific allocation
 *     GET  /api/simulations/memory          → List active allocations
 *
 *   BLOCKING SIMULATION:
 *     POST /api/simulations/blocking    → Start blocking (body: durationSeconds, concurrentWorkers)
 *
 *   CRASH SIMULATION:
 *     POST /api/simulations/crash/failfast      → Crash via exit
 *     POST /api/simulations/crash/stackoverflow → Crash via stack overflow
 *     POST /api/simulations/crash/exception     → Crash via unhandled exception
 *     POST /api/simulations/crash/oom           → Crash via memory exhaustion
 *     POST /api/simulations/crash/all           → Crash multiple workers
 *
 *   ADMIN ENDPOINTS:
 *     GET  /api/simulations         → List all active simulations
 *     GET  /api/admin/status        → Full admin status
 *     GET  /api/admin/events        → Event log entries
 *
 *   STATIC FILES:
 *     GET  /                   → Dashboard (index.html)
 *     GET  /docs.html          → API documentation
 *     GET  /azure-*.html       → Azure-specific guides
 *
 * HOW IT WORKS (this implementation):
 *   Simple pattern matching without external router library.
 *   Uses if/switch statements for route matching.
 *   Path parameters extracted via regex (e.g., :id → ([a-f0-9-]+))
 *
 * PORTING NOTES:
 *
 *   Node.js (Express):
 *     app.get('/api/health', HealthController.index);
 *     app.post('/api/simulations/cpu/start', CpuController.start);
 *     app.delete('/api/simulations/cpu/:id', CpuController.stop);
 *
 *   Java (Spring Boot):
 *     @GetMapping("/api/health") public Health index() {...}
 *     @PostMapping("/api/simulations/cpu/start") public Simulation start(...) {...}
 *     @DeleteMapping("/api/simulations/cpu/{id}") public void stop(@PathVariable String id) {...}
 *
 *   Python (Flask):
 *     @app.route('/api/health', methods=['GET']) def health(): ...
 *     @app.route('/api/simulations/cpu/start', methods=['POST']) def cpu_start(): ...
 *     @app.route('/api/simulations/cpu/<id>', methods=['DELETE']) def cpu_stop(id): ...
 *
 *   .NET (ASP.NET Core):
 *     [HttpGet("api/health")] public IActionResult Index() {...}
 *     [HttpPost("api/simulations/cpu/start")] public IActionResult Start([FromBody] CpuParams params) {...}
 *     [HttpDelete("api/simulations/cpu/{id}")] public IActionResult Stop(string id) {...}
 *
 *   Ruby (Rails/Sinatra):
 *     get '/api/health' => 'health#index'
 *     post '/api/simulations/cpu/start' => 'cpu#start'
 *     delete '/api/simulations/cpu/:id' => 'cpu#stop'
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
        if ($method === 'GET' && $path === '/api/metrics/internal-probe') {
            return self::ok(MetricsController::internalProbe());
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
        // Multi-worker crash endpoint - crashes multiple FPM workers at once
        if ($method === 'POST' && $path === '/api/simulations/crash/all') {
            CrashController::crashAll();
            return null;
        }
        // Crash stats endpoint
        if ($method === 'GET' && $path === '/api/simulations/crash/stats') {
            CrashController::stats();
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
