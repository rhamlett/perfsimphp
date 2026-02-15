<?php
/**
 * =============================================================================
 * HEALTH CONTROLLER — Health Check & Environment Endpoints
 * =============================================================================
 *
 * ENDPOINTS:
 *   GET /api/health             → Standard health check (status, version)
 *   GET /api/health/environment → Azure environment info (SKU, site name)
 *   GET /api/health/build       → Build timestamp for deployment tracking
 *   GET /api/health/probe       → Ultra-lightweight probe for heartbeat
 *
 * @module src/Controllers/HealthController.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Controllers;

use PerfSimPhp\Config;

class HealthController
{
    /**
     * GET /api/health
     * Returns service status and basic metrics.
     */
    public static function index(): array
    {
        return [
            'status' => 'healthy',
            'timestamp' => date('c'),
            'uptime' => self::getUptime(),
            'version' => Config::APP_VERSION,
            'runtime' => 'PHP ' . PHP_VERSION,
        ];
    }

    /**
     * GET /api/health/environment
     * Returns Azure environment information or "Local" if not in Azure.
     */
    public static function environment(): array
    {
        $websiteSku = getenv('WEBSITE_SKU') ?: null;
        $websiteSiteName = getenv('WEBSITE_SITE_NAME') ?: null;
        $websiteInstanceId = getenv('WEBSITE_INSTANCE_ID') ?: null;

        $isAzure = !empty($websiteSiteName) || !empty($websiteInstanceId);

        return [
            'isAzure' => $isAzure,
            'sku' => $websiteSku ?: ($isAzure ? 'Unknown' : 'Local'),
            'siteName' => $websiteSiteName,
            'instanceId' => $websiteInstanceId ? substr($websiteInstanceId, 0, 8) : null,
        ];
    }

    /**
     * GET /api/health/build
     * Returns build information.
     */
    public static function build(): array
    {
        return [
            'version' => Config::APP_VERSION,
            'buildTime' => date('Y-m-d H:i:s') . ' UTC',
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
        ];
    }

    /**
     * GET /api/health/probe
     * Ultra-lightweight endpoint for heartbeat detection.
     */
    public static function probe(): array
    {
        return ['ts' => (int) (microtime(true) * 1000)];
    }

    /**
     * Get process uptime (request duration for PHP).
     */
    private static function getUptime(): float
    {
        if (isset($_SERVER['REQUEST_TIME_FLOAT'])) {
            return round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 2);
        }
        return 0.0;
    }
}
