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
            'buildTimestamp' => Config::buildTimestamp(),
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
        
        // Additional Azure env vars for SKU detection
        // WEBSITE_OWNER_NAME format: subscriptionId+resourceGroup-regionwebspace
        // WEBSITE_RESOURCE_GROUP and APP_SERVICE_PLAN can help identify tier
        $resourceGroup = getenv('WEBSITE_RESOURCE_GROUP') ?: null;
        $ownerName = getenv('WEBSITE_OWNER_NAME') ?: null;
        $computeMode = getenv('WEBSITE_COMPUTE_MODE') ?: null; // Dedicated, Dynamic, etc.
        $homeStamp = getenv('HOME_EXPANDED') ?: getenv('HOME') ?: null;

        $isAzure = !empty($websiteSiteName) || !empty($websiteInstanceId) || !empty($homeStamp);
        
        // Try to determine SKU from available info
        $sku = $websiteSku;
        if (!$sku && $isAzure) {
            // Check if it's a consumption/dynamic plan
            if ($computeMode === 'Dynamic') {
                $sku = 'Consumption';
            } else {
                $sku = 'App Service'; // Generic - actual tier not available via env
            }
        }

        return [
            'isAzure' => $isAzure,
            'sku' => $sku ?: ($isAzure ? 'App Service' : 'Local'),
            'siteName' => $websiteSiteName,
            'instanceId' => $websiteInstanceId ? substr($websiteInstanceId, 0, 8) : null,
            'resourceGroup' => $resourceGroup,
        ];
    }

    /**
     * GET /api/health/build
     * Returns build information.
     */
    public static function build(): array
    {
        return [
            'buildTimestamp' => Config::buildTimestamp(),
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
