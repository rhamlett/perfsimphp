<?php
/**
 * =============================================================================
 * REQUEST LOGGER MIDDLEWARE
 * =============================================================================
 *
 * PURPOSE:
 *   Logs HTTP requests with method, URL, status code, and response time.
 *   Filters out internal probe requests to reduce log noise.
 *
 * @module src/Middleware/RequestLogger.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Middleware;

class RequestLogger
{
    /**
     * Log a completed request.
     *
     * @param string $method HTTP method
     * @param string $uri Request URI
     * @param int $statusCode Response status code
     * @param float $startTime Start time from microtime(true)
     */
    public static function log(string $method, string $uri, int $statusCode, float $startTime): void
    {
        $durationMs = round((microtime(true) - $startTime) * 1000, 1);
        $isError = $statusCode >= 400;

        // Skip internal probe logs unless error
        $isInternalProbe = isset($_SERVER['HTTP_X_INTERNAL_PROBE']) && $_SERVER['HTTP_X_INTERNAL_PROBE'] === 'true';
        $isProbeEndpoint = parse_url($uri, PHP_URL_PATH) === '/api/metrics/probe';
        $isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
        $isInternalProbeRequest = $isInternalProbe || ($isProbeEndpoint && $isLocalhost);

        if ($isInternalProbeRequest && !$isError) {
            return;
        }

        $timestamp = date('Y-m-d\TH:i:s.v\Z');
        $logMessage = "[{$timestamp}] {$method} {$uri} {$statusCode} {$durationMs}ms";

        if ($isError) {
            error_log($logMessage);
        } else {
            // Use error_log for stdout in PHP-FPM
            error_log($logMessage);
        }
    }
}
