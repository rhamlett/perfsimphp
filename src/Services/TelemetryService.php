<?php
/**
 * =============================================================================
 * TELEMETRY SERVICE — Application Insights Integration (Direct HTTP)
 * =============================================================================
 *
 * PURPOSE:
 *   Provides Application Insights telemetry for PHP applications using
 *   direct HTTP calls to the Track API. Zero external dependencies.
 *   Gracefully handles missing configuration.
 *
 * CONFIGURATION:
 *   Set these App Settings in Azure Portal (or environment variables locally):
 *   - APPLICATIONINSIGHTS_CONNECTION_STRING: Connection string from App Insights resource
 *   - OTEL_SERVICE_NAME: (optional) Custom service name, defaults to "PerfSimPhp"
 *
 * USAGE:
 *   TelemetryService::init();           // Call once at startup
 *   TelemetryService::startRequest(...) // Start tracking HTTP request
 *   TelemetryService::endRequest(...)   // End request tracking
 *   TelemetryService::trackException()  // Track exceptions
 *   TelemetryService::flush();          // Flush before shutdown
 *
 * @module src/Services/TelemetryService.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Services;

class TelemetryService
{
    private static bool $initialized = false;
    private static bool $enabled = false;
    private static string $instrumentationKey = '';
    private static string $ingestionEndpoint = '';
    private static string $serviceName = '';
    private static string $lastError = '';
    private static array $pendingTelemetry = [];
    
    // Current request tracking
    private static float $requestStartTime = 0;
    private static string $requestMethod = '';
    private static string $requestUri = '';
    private static string $operationId = '';

    /**
     * Environment variable for Application Insights connection string.
     */
    private const CONNECTION_STRING_VAR = 'APPLICATIONINSIGHTS_CONNECTION_STRING';
    
    /**
     * Environment variable for custom service name.
     */
    private const SERVICE_NAME_VAR = 'OTEL_SERVICE_NAME';
    
    /**
     * Default service name if not specified.
     */
    private const DEFAULT_SERVICE_NAME = 'PerfSimPhp';

    /**
     * Application Insights SDK version to report.
     */
    private const SDK_VERSION = 'php:1.0.0';

    /**
     * Initialize the telemetry service.
     * Safe to call even if App Insights is not configured.
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        // Get connection string from environment
        $connectionString = self::getEnvVar(self::CONNECTION_STRING_VAR);
        
        if (empty($connectionString)) {
            self::$enabled = false;
            // Don't log - this runs on every request and would spam the event log
            return;
        }

        try {
            // Parse connection string
            $config = self::parseConnectionString($connectionString);
            
            if (empty($config['InstrumentationKey'])) {
                throw new \RuntimeException('InstrumentationKey not found in connection string');
            }
            if (empty($config['IngestionEndpoint'])) {
                throw new \RuntimeException('IngestionEndpoint not found in connection string');
            }

            self::$instrumentationKey = $config['InstrumentationKey'];
            self::$ingestionEndpoint = rtrim($config['IngestionEndpoint'], '/') . '/v2/track';
            self::$serviceName = self::getEnvVar(self::SERVICE_NAME_VAR) ?: self::DEFAULT_SERVICE_NAME;
            
            self::$enabled = true;
            // Don't log success - this runs on every request and would spam the event log
        } catch (\Throwable $e) {
            self::$enabled = false;
            self::$lastError = $e->getMessage();
            // Don't log errors here - would spam on every request
            // Check TelemetryService::getStatus() for initialization errors
        }
    }

    /**
     * Parse Application Insights connection string into components.
     */
    private static function parseConnectionString(string $connectionString): array
    {
        $parts = [];
        foreach (explode(';', $connectionString) as $pair) {
            if (strpos($pair, '=') !== false) {
                [$key, $value] = explode('=', $pair, 2);
                $parts[trim($key)] = trim($value);
            }
        }
        return $parts;
    }

    /**
     * Check if telemetry is enabled.
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Start tracking an HTTP request.
     */
    public static function startRequest(string $method, string $uri, array $attributes = []): void
    {
        self::$requestStartTime = microtime(true);
        self::$requestMethod = $method;
        self::$requestUri = $uri;
        self::$operationId = self::generateOperationId();
    }

    /**
     * End tracking the current request and queue telemetry.
     */
    public static function endRequest(int $statusCode, bool $success = true): void
    {
        if (!self::$enabled || self::$requestStartTime === 0.0) {
            return;
        }

        $duration = microtime(true) - self::$requestStartTime;

        // Format duration in ISO 8601 format (d.hh:mm:ss.fffffff)
        $durationFormatted = self::formatDuration($duration);

        $telemetry = [
            'name' => 'Microsoft.ApplicationInsights.' . str_replace('-', '', self::$instrumentationKey) . '.Request',
            'time' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'iKey' => self::$instrumentationKey,
            'tags' => [
                'ai.operation.id' => self::$operationId,
                'ai.operation.name' => self::$requestMethod . ' ' . self::$requestUri,
                'ai.cloud.role' => self::$serviceName,
                'ai.cloud.roleInstance' => gethostname() ?: 'unknown',
                'ai.internal.sdkVersion' => self::SDK_VERSION,
            ],
            'data' => [
                'baseType' => 'RequestData',
                'baseData' => [
                    'ver' => 2,
                    'id' => self::$operationId,
                    'name' => self::$requestMethod . ' ' . self::$requestUri,
                    'duration' => $durationFormatted,
                    'responseCode' => (string)$statusCode,
                    'success' => $statusCode < 400,
                    'url' => self::getFullUrl(),
                ],
            ],
        ];

        self::$pendingTelemetry[] = $telemetry;
        
        // Reset for next request
        self::$requestStartTime = 0;
    }

    /**
     * Track an exception.
     */
    public static function trackException(\Throwable $exception, array $attributes = []): void
    {
        if (!self::$enabled) {
            return;
        }

        $telemetry = [
            'name' => 'Microsoft.ApplicationInsights.' . str_replace('-', '', self::$instrumentationKey) . '.Exception',
            'time' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'iKey' => self::$instrumentationKey,
            'tags' => [
                'ai.operation.id' => self::$operationId ?: self::generateOperationId(),
                'ai.cloud.role' => self::$serviceName,
                'ai.cloud.roleInstance' => gethostname() ?: 'unknown',
                'ai.internal.sdkVersion' => self::SDK_VERSION,
            ],
            'data' => [
                'baseType' => 'ExceptionData',
                'baseData' => [
                    'ver' => 2,
                    'exceptions' => [
                        [
                            'typeName' => get_class($exception),
                            'message' => $exception->getMessage(),
                            'hasFullStack' => true,
                            'stack' => $exception->getTraceAsString(),
                        ],
                    ],
                ],
            ],
        ];

        self::$pendingTelemetry[] = $telemetry;
    }

    /**
     * Track a custom event.
     */
    public static function trackEvent(string $name, array $properties = [], array $measurements = []): void
    {
        if (!self::$enabled) {
            return;
        }

        $telemetry = [
            'name' => 'Microsoft.ApplicationInsights.' . str_replace('-', '', self::$instrumentationKey) . '.Event',
            'time' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'iKey' => self::$instrumentationKey,
            'tags' => [
                'ai.operation.id' => self::$operationId ?: self::generateOperationId(),
                'ai.cloud.role' => self::$serviceName,
                'ai.cloud.roleInstance' => gethostname() ?: 'unknown',
                'ai.internal.sdkVersion' => self::SDK_VERSION,
            ],
            'data' => [
                'baseType' => 'EventData',
                'baseData' => [
                    'ver' => 2,
                    'name' => $name,
                    'properties' => $properties ?: null,
                    'measurements' => $measurements ?: null,
                ],
            ],
        ];

        self::$pendingTelemetry[] = $telemetry;
    }

    /**
     * Track a dependency call (external service, database, etc.).
     */
    public static function trackDependency(
        string $type,
        string $target,
        string $name,
        float $durationMs,
        bool $success = true,
        array $properties = []
    ): void {
        if (!self::$enabled) {
            return;
        }

        $telemetry = [
            'name' => 'Microsoft.ApplicationInsights.' . str_replace('-', '', self::$instrumentationKey) . '.RemoteDependency',
            'time' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'iKey' => self::$instrumentationKey,
            'tags' => [
                'ai.operation.id' => self::$operationId ?: self::generateOperationId(),
                'ai.cloud.role' => self::$serviceName,
                'ai.cloud.roleInstance' => gethostname() ?: 'unknown',
                'ai.internal.sdkVersion' => self::SDK_VERSION,
            ],
            'data' => [
                'baseType' => 'RemoteDependencyData',
                'baseData' => [
                    'ver' => 2,
                    'name' => $name,
                    'type' => $type,
                    'target' => $target,
                    'duration' => self::formatDuration($durationMs / 1000),
                    'success' => $success,
                    'properties' => $properties ?: null,
                ],
            ],
        ];

        self::$pendingTelemetry[] = $telemetry;
    }

    /**
     * Flush pending telemetry to Application Insights.
     */
    public static function flush(): void
    {
        if (!self::$enabled || empty(self::$pendingTelemetry)) {
            return;
        }

        try {
            $payload = implode("\n", array_map('json_encode', self::$pendingTelemetry));
            
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => [
                        'Content-Type: application/x-json-stream',
                        'Accept: application/json',
                    ],
                    'content' => $payload,
                    'timeout' => 2, // Short timeout to not block requests
                    'ignore_errors' => true,
                ],
            ]);

            // Fire and forget - don't wait for response
            @file_get_contents(self::$ingestionEndpoint, false, $context);
            
            self::$pendingTelemetry = [];
        } catch (\Throwable $e) {
            // Silently ignore telemetry errors
            self::$pendingTelemetry = [];
        }
    }

    /**
     * Shutdown the telemetry service.
     */
    public static function shutdown(): void
    {
        self::flush();
        self::$enabled = false;
        self::$initialized = false;
    }

    /**
     * Get telemetry status for diagnostics.
     */
    public static function getStatus(): array
    {
        return [
            'initialized' => self::$initialized,
            'enabled' => self::$enabled,
            'connectionStringConfigured' => !empty(self::getEnvVar(self::CONNECTION_STRING_VAR)),
            'serviceName' => self::$serviceName ?: self::DEFAULT_SERVICE_NAME,
            'pendingItems' => count(self::$pendingTelemetry),
            'lastError' => self::$lastError ?: null,
        ];
    }

    /**
     * Get an environment variable.
     */
    private static function getEnvVar(string $name): ?string
    {
        $value = getenv($name);
        if ($value !== false && $value !== '') {
            return $value;
        }
        if (isset($_SERVER[$name]) && $_SERVER[$name] !== '') {
            return $_SERVER[$name];
        }
        if (isset($_ENV[$name]) && $_ENV[$name] !== '') {
            return $_ENV[$name];
        }
        return null;
    }

    /**
     * Generate a unique operation ID.
     */
    private static function generateOperationId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Format duration in ISO 8601 format for Application Insights.
     */
    private static function formatDuration(float $seconds): string
    {
        $days = floor($seconds / 86400);
        $seconds = $seconds % 86400;
        $hours = floor($seconds / 3600);
        $seconds = $seconds % 3600;
        $minutes = floor($seconds / 60);
        $seconds = $seconds % 60;
        $wholeSecs = floor($seconds);
        $microsecs = round(($seconds - $wholeSecs) * 10000000);

        return sprintf(
            '%d.%02d:%02d:%02d.%07d',
            $days,
            $hours,
            $minutes,
            $wholeSecs,
            $microsecs
        );
    }

    /**
     * Get full URL for the current request.
     */
    private static function getFullUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? self::$requestUri;
        return $scheme . '://' . $host . $uri;
    }
}
