<?php
/**
 * =============================================================================
 * FRONT CONTROLLER — Application Entry Point & Request Router
 * =============================================================================
 *
 * PURPOSE:
 *   This is the single entry point for ALL HTTP requests. Nginx/Apache routes
 *   everything through this file. It:
 *   1. Bootstraps the autoloader
 *   2. Initializes error handling
 *   3. Logs the request
 *   4. Routes the request to the appropriate controller
 *   5. Handles static file serving (for PHP built-in server)
 *
 * ARCHITECTURE:
 *   Unlike Node.js (persistent process with event loop), PHP uses a
 *   request-response model where each HTTP request spawns a new PHP-FPM
 *   worker process. State does NOT persist between requests unless stored
 *   externally (APCu, files, database).
 *
 *   ┌─────────────────────────────────────────────┐
 *   │  Nginx / Apache / PHP Built-in Server       │
 *   │  └─ Routes all requests to index.php        │
 *   └──────────────┬──────────────────────────────┘
 *                  │ PHP-FPM worker (per-request)
 *   ┌──────────────┴──────────────────────────────┐
 *   │  index.php (this file)                      │
 *   │  ├─ Autoloader (composer/manual)            │
 *   │  ├─ Error handler                           │
 *   │  ├─ Request logger middleware               │
 *   │  ├─ Router → Controller → Service           │
 *   │  └─ JSON response                           │
 *   └─────────────────────────────────────────────┘
 *
 * REAL-TIME DATA (vs Node.js WebSocket):
 *   PHP cannot maintain persistent WebSocket connections in standard
 *   PHP-FPM mode. Instead, the dashboard uses:
 *   - AJAX polling (every 500ms) for metrics
 *   - AJAX polling (every 2s) for event log
 *   - JavaScript-based latency probes (XHR timing to /api/metrics/probe)
 *
 * @module public/index.php
 */

declare(strict_types=1);

// ============================================================================
// AUTOLOADER — Load all classes from src/
// ============================================================================
require_once __DIR__ . '/../src/bootstrap.php';

use PerfSimPhp\Router;
use PerfSimPhp\Middleware\ErrorHandler;
use PerfSimPhp\Middleware\RequestLogger;

// ============================================================================
// PHP BUILT-IN SERVER: Serve static files directly
// ============================================================================
if (php_sapi_name() === 'cli-server') {
    $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    // Serve static files directly (CSS, JS, HTML, SVG, images)
    $staticFile = __DIR__ . $requestUri;
    if ($requestUri !== '/' && $requestUri !== '/index.php' && is_file($staticFile)) {
        // Set appropriate content type
        $ext = pathinfo($staticFile, PATHINFO_EXTENSION);
        $mimeTypes = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'html' => 'text/html',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'ico' => 'image/x-icon',
            'json' => 'application/json',
        ];
        if (isset($mimeTypes[$ext])) {
            header('Content-Type: ' . $mimeTypes[$ext]);
        }
        readfile($staticFile);
        return;
    }
}

// ============================================================================
// ERROR HANDLING — Global exception handler
// ============================================================================
ErrorHandler::register();

// ============================================================================
// REQUEST LOGGING — Log method, URL, status, duration
// ============================================================================
$requestStart = microtime(true);

// ============================================================================
// ROUTING — Match URL to controller action
// ============================================================================
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Set JSON content type for API routes
if (str_starts_with($uri, '/api/')) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Internal-Probe, X-Sidecar-Probe');

    // Handle CORS preflight
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

try {
    $router = new Router();
    $response = $router->dispatch($method, $uri);

    // Send response
    if ($response !== null) {
        http_response_code($response['status'] ?? 200);
        echo json_encode($response['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
} catch (\Throwable $e) {
    ErrorHandler::handleException($e);
}

// Log request completion
RequestLogger::log($method, $uri, http_response_code(), $requestStart);
