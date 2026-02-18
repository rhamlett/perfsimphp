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
use PerfSimPhp\SharedStorage;
use PerfSimPhp\Services\SimulationTrackerService;
use PerfSimPhp\Services\CpuStressService;
use PerfSimPhp\Services\BlockingService;

class HealthController
{
    /**
     * GET /api/health
     * Returns service status and basic metrics including environment info.
     * Also performs startup cleanup on first request after boot.
     */
    public static function index(): array
    {
        // Run startup cleanup (idempotent - only runs once after boot)
        self::runStartupCleanup();
        
        return [
            'status' => 'healthy',
            'timestamp' => date('c'),
            'uptime' => self::getUptime(),
            'buildTimestamp' => Config::buildTimestamp(),
            'runtime' => 'PHP ' . PHP_VERSION,
            'environment' => self::environment(),
        ];
    }

    /**
     * Performs one-time startup cleanup to clear stale simulations.
     * Uses a flag in shared storage to ensure it only runs once per boot.
     */
    private static function runStartupCleanup(): void
    {
        // Check if cleanup already ran this boot cycle
        // Use build timestamp as boot identifier (changes on each deploy)
        $bootId = Config::buildTimestamp() ?: filemtime(__FILE__);
        $cleanupKey = 'perfsim_startup_cleanup_' . md5((string)$bootId);
        
        if (SharedStorage::get($cleanupKey)) {
            return; // Already ran
        }
        
        // Mark cleanup as done (with 1 hour TTL)
        SharedStorage::set($cleanupKey, time(), 3600);
        
        // Clear all simulation records (workers don't survive restart)
        SimulationTrackerService::clear();
        
        // Kill any orphaned cpu-worker processes
        CpuStressService::killAllWorkersByName();
        
        // Clear blocking mode
        BlockingService::stop();
        
        error_log('[Startup] Cleared stale simulations after boot');
    }

    /**
     * GET /api/health/environment
     * Returns Azure environment information or "Local" if not in Azure.
     */
    public static function environment(): array
    {
        // Helper to get env var from multiple sources (getenv, $_SERVER, $_ENV)
        // PHP-FPM on Azure may not populate getenv() with App Service vars
        $getEnvVar = function(string $name): ?string {
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
        };

        $websiteSku = $getEnvVar('WEBSITE_SKU');
        $websiteSiteName = $getEnvVar('WEBSITE_SITE_NAME');
        $websiteInstanceId = $getEnvVar('WEBSITE_INSTANCE_ID');
        
        // Additional Azure env vars for SKU detection
        // WEBSITE_OWNER_NAME format: subscriptionId+resourceGroup-regionwebspace
        // WEBSITE_RESOURCE_GROUP and APP_SERVICE_PLAN can help identify tier
        $resourceGroup = $getEnvVar('WEBSITE_RESOURCE_GROUP');
        $ownerName = $getEnvVar('WEBSITE_OWNER_NAME');
        $computeMode = $getEnvVar('WEBSITE_COMPUTE_MODE'); // Dedicated, Dynamic, etc.
        $homeStamp = $getEnvVar('HOME_EXPANDED') ?? $getEnvVar('HOME');

        // Azure detection: check WEBSITE_* vars (definitive) or Azure-specific paths
        $hasWebsiteVars = !empty($websiteSiteName) || !empty($websiteInstanceId) || !empty($resourceGroup);
        $hasAzurePath = $homeStamp && str_contains($homeStamp, '/home/site');
        $isAzure = $hasWebsiteVars || $hasAzurePath;
        
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
            // Azure-specific info
            'isAzure' => $isAzure,
            'sku' => $sku ?: ($isAzure ? 'App Service' : 'Local'),
            'siteName' => $websiteSiteName,
            'instanceId' => $websiteInstanceId ? substr($websiteInstanceId, 0, 8) : null,
            'resourceGroup' => $resourceGroup,
            // Runtime info for dashboard
            'phpVersion' => PHP_VERSION,
            'os' => PHP_OS,
            'hostname' => gethostname() ?: 'unknown',
            'pid' => getmypid(),
            'sapi' => PHP_SAPI,
        ];
    }

    /**
     * GET /api/health/debug-env
     * Debug endpoint to see all WEBSITE_* environment variables (for troubleshooting)
     */
    public static function debugEnv(): array
    {
        $websiteVars = [];
        
        // Check getenv
        foreach ($_ENV as $key => $value) {
            if (str_starts_with($key, 'WEBSITE_')) {
                $websiteVars['$_ENV'][$key] = $value;
            }
        }
        
        // Check $_SERVER
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'WEBSITE_')) {
                $websiteVars['$_SERVER'][$key] = $value;
            }
        }
        
        // Try getenv for specific vars
        $knownVars = ['WEBSITE_SKU', 'WEBSITE_SITE_NAME', 'WEBSITE_INSTANCE_ID', 'WEBSITE_RESOURCE_GROUP', 'WEBSITE_OWNER_NAME', 'WEBSITE_COMPUTE_MODE', 'HOME', 'HOME_EXPANDED'];
        foreach ($knownVars as $var) {
            $val = getenv($var);
            if ($val !== false) {
                $websiteVars['getenv'][$var] = $val;
            }
        }
        
        return [
            'websiteVars' => $websiteVars,
            'envCount' => [
                '$_ENV' => count($_ENV),
                '$_SERVER' => count($_SERVER),
            ],
            'storage' => SharedStorage::getInfo(),
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
