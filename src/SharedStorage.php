<?php
/**
 * =============================================================================
 * SHARED STORAGE — Cross-Request State Management
 * =============================================================================
 *
 * FEATURE REQUIREMENTS (language-agnostic):
 *   This service must provide persistent state storage that:
 *   1. Persists data across HTTP requests
 *   2. Is accessible from all request handlers
 *   3. Supports atomic read-modify-write operations
 *   4. Handles concurrent access safely
 *   5. Provides optional TTL (time-to-live) for entries
 *
 * WHY THIS IS NEEDED (PHP-specific):
 *   Unlike Node.js (single persistent process), PHP-FPM spawns a new worker
 *   for each request. In-memory variables are lost between requests. To track
 *   active simulations, event logs, and CPU worker PIDs, we need external
 *   shared storage.
 *
 * HOW IT WORKS (this implementation):
 *   Storage Backends (auto-detected):
 *   1. APCu (preferred): In-process shared memory. Fast, atomic operations.
 *   2. File-based (fallback): JSON files with flock() for concurrency.
 *
 * PORTING NOTES:
 *   Most runtimes have persistent process memory, making this simpler:
 *
 *   Node.js:
 *     - NOT NEEDED: global variables persist across requests
 *     - Just use: const storage = new Map();
 *     - For clustering: Redis or shared Map
 *
 *   Java (Spring Boot):
 *     - NOT NEEDED: @Service singletons persist across requests
 *     - Use ConcurrentHashMap for thread-safe storage
 *     - For scaling: Redis or Hazelcast
 *
 *   Python (Flask/FastAPI):
 *     - Partially needed: depends on server (gunicorn workers)
 *     - Global dict works for single-process
 *     - For multi-worker: Redis or shared memory
 *
 *   .NET (ASP.NET Core):
 *     - NOT NEEDED: singleton services persist across requests
 *     - Use ConcurrentDictionary or IMemoryCache
 *     - For scaling: Redis or IDistributedCache
 *
 *   Ruby (Rails):
 *     - Partially needed: depends on server (Puma workers)
 *     - Class instance variables work for single-process
 *     - For multi-worker: Redis
 *
 * OPERATIONS REQUIRED:
 *   get(key, default): Retrieve value or default
 *   set(key, value, ttl?): Store value with optional TTL
 *   delete(key): Remove value
 *   modify(key, callback, default): Atomic read-modify-write
 *
 * CROSS-PLATFORM CONSIDERATIONS:
 *   - Thread safety for concurrent requests (if applicable)
 *   - Atomic operations for counters and lists
 *   - TTL support for automatic cleanup
 *   - Consider what happens during deployments/restarts
 *   - For production scaling, consider Redis/Memcached
 *
 * @module src/SharedStorage.php
 */

declare(strict_types=1);

namespace PerfSimPhp;

class SharedStorage
{
    private static bool $apcuChecked = false;
    private static bool $apcuAvailable = false;
    
    // Redis connection for cross-pool storage (null = not attempted, false = failed, Redis = connected)
    private static $redis = null;
    private static bool $redisChecked = false;
    
    // Track if we've already warned about file fallback (avoid log spam)
    private static bool $fileFallbackWarned = false;

    /**
     * Check if APCu is available and enabled.
     */
    private static function hasApcu(): bool
    {
        if (!self::$apcuChecked) {
            self::$apcuAvailable = function_exists('apcu_fetch') && \apcu_enabled();
            self::$apcuChecked = true;
        }
        return self::$apcuAvailable;
    }

    /**
     * Get Redis connection for cross-pool storage.
     * Returns Redis instance if connected, false if unavailable/failed.
     * Connection is lazy and cached.
     */
    private static function getRedis(): \Redis|false
    {
        if (self::$redisChecked) {
            return self::$redis instanceof \Redis ? self::$redis : false;
        }
        
        self::$redisChecked = true;
        
        // Check if Redis extension is loaded
        if (!class_exists('\\Redis')) {
            self::$redis = false;
            return false;
        }
        
        // Check if REDIS_URL is configured
        $url = Config::redisUrl();
        if ($url === null) {
            self::$redis = false;
            return false;
        }
        
        try {
            // Parse Redis URL: redis://[:password@]host:port or rediss://... for TLS
            $parsed = parse_url($url);
            if ($parsed === false || !isset($parsed['host'])) {
                self::$redis = false;
                return false;
            }
            
            $host = $parsed['host'];
            $port = $parsed['port'] ?? 6379;
            $password = $parsed['pass'] ?? null;
            $useTls = ($parsed['scheme'] ?? 'redis') === 'rediss';
            
            // Azure Cache for Redis uses TLS on port 6380
            if ($useTls && $port === 6379) {
                $port = 6380;
            }
            
            $redis = new \Redis();
            
            // Connect with TLS if needed
            if ($useTls) {
                $redis->connect('tls://' . $host, $port, 2.0); // 2s timeout
            } else {
                $redis->connect($host, $port, 2.0);
            }
            
            // Authenticate if password provided
            if ($password !== null) {
                $redis->auth($password);
            }
            
            // Test connection
            $redis->ping();
            
            self::$redis = $redis;
            return $redis;
            
        } catch (\Throwable $e) {
            // Log error but don't fail - fall back to file storage
            error_log('Redis connection failed: ' . $e->getMessage());
            self::$redis = false;
            return false;
        }
    }

    /**
     * Get information about the storage backend.
     */
    public static function getInfo(): array
    {
        $redis = self::getRedis();
        $backend = $redis ? 'redis' : (self::hasApcu() ? 'apcu' : 'file');
        return [
            'backend' => $backend,
            'crossPoolBackend' => $redis ? 'redis' : 'file',
            'apcuAvailable' => self::hasApcu(),
            'apcuFunctionExists' => function_exists('apcu_fetch'),
            'apcuEnabled' => function_exists('apcu_enabled') ? \apcu_enabled() : false,
            'redisAvailable' => $redis !== false,
            'redisExtension' => class_exists('\\Redis'),
            'redisConfigured' => Config::redisUrl() !== null,
        ];
    }

    /**
     * Get a value from shared storage.
     * Uses Redis when available (cross-pool compatible), otherwise APCu or file.
     *
     * @param string $key Storage key
     * @param mixed $default Default value if key not found
     * @return mixed The stored value or default
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        // Redis first - ensures cross-pool consistency
        $redis = self::getRedis();
        if ($redis) {
            try {
                $value = $redis->get('shared:' . $key);
                if ($value === false) {
                    return $default;
                }
                $decoded = json_decode($value, true);
                return $decoded ?? $default;
            } catch (\Throwable $e) {
                // Fall through to APCu/file
            }
        }
        
        if (self::hasApcu()) {
            $success = false;
            $value = \apcu_fetch($key, $success);
            return $success ? $value : $default;
        }

        return self::fileGet($key, $default);
    }

    /**
     * Store a value in shared storage.
     * Uses Redis when available (cross-pool compatible), otherwise APCu or file.
     *
     * @param string $key Storage key
     * @param mixed $value Value to store (must be serializable)
     * @param int $ttl Time-to-live in seconds (0 = forever)
     */
    public static function set(string $key, mixed $value, int $ttl = 0): void
    {
        // Redis first - ensures cross-pool consistency
        $redis = self::getRedis();
        if ($redis) {
            try {
                $encoded = json_encode($value);
                if ($ttl > 0) {
                    $redis->setex('shared:' . $key, $ttl, $encoded);
                } else {
                    $redis->set('shared:' . $key, $encoded);
                }
                return;
            } catch (\Throwable $e) {
                // Fall through to APCu/file
            }
        }
        
        if (self::hasApcu()) {
            \apcu_store($key, $value, $ttl);
            return;
        }

        self::fileSet($key, $value, $ttl);
    }

    /**
     * Delete a value from shared storage.
     * Uses Redis when available (cross-pool compatible), otherwise APCu or file.
     *
     * @param string $key Storage key
     */
    public static function delete(string $key): void
    {
        // Redis first - ensures cross-pool consistency
        $redis = self::getRedis();
        if ($redis) {
            try {
                $redis->del('shared:' . $key);
                return;
            } catch (\Throwable $e) {
                // Fall through to APCu/file
            }
        }
        
        if (self::hasApcu()) {
            \apcu_delete($key);
            return;
        }

        self::fileDelete($key);
    }

    /**
     * Atomically add a value only if the key doesn't exist.
     * Returns true if the value was added, false if the key already existed.
     * Uses Redis when available (cross-pool compatible), otherwise APCu or file.
     *
     * @param string $key Storage key
     * @param mixed $value Value to store
     * @param int $ttl Time-to-live in seconds (0 = forever)
     * @return bool True if added, false if key already existed
     */
    public static function addOnce(string $key, mixed $value, int $ttl = 0): bool
    {
        // Redis first - ensures cross-pool consistency
        $redis = self::getRedis();
        if ($redis) {
            try {
                $encoded = json_encode($value);
                // SETNX returns true if key was set (didn't exist)
                $result = $redis->setnx('shared:' . $key, $encoded);
                if ($result && $ttl > 0) {
                    $redis->expire('shared:' . $key, $ttl);
                }
                return (bool) $result;
            } catch (\Throwable $e) {
                // Fall through to APCu/file
            }
        }
        
        if (self::hasApcu()) {
            // apcu_add returns true only if the key didn't exist
            return \apcu_add($key, $value, $ttl);
        }

        return self::fileAddOnce($key, $value);
    }

    /**
     * Atomically modify a value (read-modify-write with locking).
     * Uses Redis when available (cross-pool compatible), otherwise APCu or file.
     *
     * @param string $key Storage key
     * @param callable $modifier Function that takes current value and returns new value
     * @param mixed $default Default value if key doesn't exist
     */
    public static function modify(string $key, callable $modifier, mixed $default = null): mixed
    {
        // Redis first - ensures cross-pool consistency with optimistic locking
        $redis = self::getRedis();
        if ($redis) {
            try {
                $redisKey = 'shared:' . $key;
                
                // Optimistic locking with WATCH
                $maxRetries = 3;
                for ($i = 0; $i < $maxRetries; $i++) {
                    $redis->watch($redisKey);
                    
                    $value = $redis->get($redisKey);
                    $current = ($value !== false) ? json_decode($value, true) : $default;
                    $newValue = $modifier($current ?? $default);
                    
                    $redis->multi();
                    $redis->set($redisKey, json_encode($newValue));
                    $result = $redis->exec();
                    
                    if ($result !== false) {
                        return $newValue;
                    }
                    // Transaction failed due to concurrent modification, retry
                }
                // Fall through to APCu/file after max retries
            } catch (\Throwable $e) {
                // Fall through to APCu/file
            }
        }
        
        if (self::hasApcu()) {
            // APCu doesn't have native CAS for complex types, but since PHP-FPM
            // workers handle one request at a time, this is effectively atomic
            $current = self::get($key, $default);
            $newValue = $modifier($current);
            self::set($key, $newValue);
            return $newValue;
        }

        return self::fileModify($key, $modifier, $default);
    }

    // =========================================================================
    // FILE-BASED FALLBACK
    // =========================================================================

    private static function filePath(string $key): string
    {
        $dir = Config::storagePath();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir . '/store_' . md5($key) . '.json';
    }

    private static function fileGet(string $key, mixed $default): mixed
    {
        $file = self::filePath($key);
        if (!file_exists($file)) {
            return $default;
        }

        $fp = fopen($file, 'r');
        if (!$fp) {
            return $default;
        }

        flock($fp, LOCK_SH);
        $contents = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if ($contents === false || $contents === '') {
            return $default;
        }

        $data = json_decode($contents, true);
        
        // Handle null from json_decode failure
        if ($data === null && $contents !== 'null') {
            return $default;
        }

        // For array data, check if it has TTL metadata
        if (is_array($data)) {
            // Check for TTL expiration (file-based TTL support)
            if (isset($data['__ttl_expires_at']) && microtime(true) > $data['__ttl_expires_at']) {
                // TTL expired - delete file and return default
                self::fileDelete($key);
                return $default;
            }

            // Return actual value (strip TTL metadata) or the array itself
            return $data['__value'] ?? $data;
        }

        // For scalar values (no TTL wrapper), return directly
        return $data;
    }

    private static function fileSet(string $key, mixed $value, int $ttl = 0): void
    {
        $file = self::filePath($key);
        $fp = fopen($file, 'c');
        if (!$fp) {
            error_log("[SharedStorage] Failed to open file for writing: {$file}");
            return;
        }

        // Wrap value with TTL metadata if TTL is set
        $data = $ttl > 0 
            ? ['__value' => $value, '__ttl_expires_at' => microtime(true) + $ttl]
            : $value;

        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    private static function fileDelete(string $key): void
    {
        $file = self::filePath($key);
        if (file_exists($file)) {
            $result = unlink($file);
            if (!$result) {
                error_log("[SharedStorage] Failed to delete file: {$file}");
            }
        }
    }

    private static function fileAddOnce(string $key, mixed $value): bool
    {
        $file = self::filePath($key);
        
        // Use exclusive create mode (x) which fails if file exists
        // This makes the operation atomic
        $fp = @fopen($file, 'x');
        if (!$fp) {
            return false; // File already exists
        }

        flock($fp, LOCK_EX);
        fwrite($fp, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }

    private static function fileModify(string $key, callable $modifier, mixed $default): mixed
    {
        $file = self::filePath($key);

        // Ensure file exists
        if (!file_exists($file)) {
            self::fileSet($key, $default);
        }

        $fp = fopen($file, 'c+');
        if (!$fp) {
            $result = $modifier($default);
            self::fileSet($key, $result);
            return $result;
        }

        flock($fp, LOCK_EX);
        $contents = stream_get_contents($fp);
        $current = ($contents !== false && $contents !== '') ? json_decode($contents, true) : $default;
        $newValue = $modifier($current ?? $default);

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($newValue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return $newValue;
    }

    // =========================================================================
    // CROSS-POOL STORAGE (Redis preferred, file fallback)
    // =========================================================================
    // 
    // These methods share data between separate PHP-FPM pools (e.g.,
    // main pool on port 9000 and metrics pool on port 9001).
    //
    // Storage backends:
    // 1. Redis (preferred): Fast, no file locking, works under high load
    // 2. File-based (fallback): Used when Redis is unavailable
    //
    // APCu is NOT usable here because each FPM pool has isolated APCu.
    //
    // Use cases:
    // - Sampled load test latencies (written by main pool, read by metrics pool)
    // - Any data that needs to cross pool boundaries
    // =========================================================================

    /**
     * Check if running in Azure App Service.
     * Used to enforce Redis requirement in production.
     */
    private static function isRunningInAzure(): bool
    {
        // WEBSITE_SITE_NAME is set by Azure App Service
        return !empty($_ENV['WEBSITE_SITE_NAME']) || !empty(getenv('WEBSITE_SITE_NAME'));
    }
    
    /**
     * Get Redis for cross-pool operations, with environment-aware error handling.
     * - In Azure: throws RuntimeException if Redis unavailable (config error)
     * - Locally: logs warning once and returns null for file fallback
     * 
     * @return \Redis|null Redis instance or null (local dev fallback)
     * @throws \RuntimeException If in Azure and Redis is not available
     */
    private static function requireRedisForCrossPool(): ?\Redis
    {
        $redis = self::getRedis();
        if ($redis) {
            return $redis;
        }
        
        // Redis not available - handle based on environment
        if (self::isRunningInAzure()) {
            throw new \RuntimeException(
                'Redis is required for cross-pool storage in Azure but is not available. ' .
                'Check that REDIS_URL is configured correctly in App Service settings.'
            );
        }
        
        // Local development - warn once and allow file fallback
        if (!self::$fileFallbackWarned) {
            error_log(
                '[SharedStorage] WARNING: Redis not available for cross-pool storage. ' .
                'Falling back to file-based storage. This is acceptable for local development ' .
                'but should not happen in Azure.'
            );
            self::$fileFallbackWarned = true;
        }
        
        return null;
    }

    /**
     * Get a value from cross-pool storage.
     * Uses Redis (required in Azure, optional locally with file fallback).
     * 
     * @throws \RuntimeException If in Azure and Redis is not available
     */
    public static function crossPoolGet(string $key, mixed $default = null): mixed
    {
        $redis = self::requireRedisForCrossPool();
        if ($redis) {
            try {
                $value = $redis->get('crosspool:' . $key);
                if ($value === false) {
                    return $default;
                }
                $decoded = json_decode($value, true);
                return $decoded ?? $default;
            } catch (\Throwable $e) {
                // Redis operation failed - this is unexpected, rethrow
                throw new \RuntimeException('Redis cross-pool get failed: ' . $e->getMessage(), 0, $e);
            }
        }
        // Local dev file fallback
        return self::fileGet($key, $default);
    }

    /**
     * Store a value in cross-pool storage.
     * Uses Redis (required in Azure, optional locally with file fallback).
     * 
     * @throws \RuntimeException If in Azure and Redis is not available
     */
    public static function crossPoolSet(string $key, mixed $value, int $ttl = 0): void
    {
        $redis = self::requireRedisForCrossPool();
        if ($redis) {
            try {
                $encoded = json_encode($value);
                if ($ttl > 0) {
                    $redis->setex('crosspool:' . $key, $ttl, $encoded);
                } else {
                    $redis->set('crosspool:' . $key, $encoded);
                }
                return;
            } catch (\Throwable $e) {
                // Redis operation failed - this is unexpected, rethrow
                throw new \RuntimeException('Redis cross-pool set failed: ' . $e->getMessage(), 0, $e);
            }
        }
        // Local dev file fallback
        self::fileSet($key, $value, $ttl);
    }

    /**
     * Atomically modify a value in cross-pool storage.
     * Uses Redis (required in Azure, optional locally with file fallback).
     * 
     * Note: Redis doesn't have native read-modify-write, but WATCH/MULTI/EXEC
     * provides optimistic locking which is much faster than file locks under load.
     * 
     * @throws \RuntimeException If in Azure and Redis is not available
     */
    public static function crossPoolModify(string $key, callable $modifier, mixed $default = null): mixed
    {
        $redis = self::requireRedisForCrossPool();
        if ($redis) {
            try {
                $redisKey = 'crosspool:' . $key;
                
                // Optimistic locking with WATCH
                $maxRetries = 3;
                for ($i = 0; $i < $maxRetries; $i++) {
                    $redis->watch($redisKey);
                    
                    $value = $redis->get($redisKey);
                    $current = ($value !== false) ? json_decode($value, true) : $default;
                    $newValue = $modifier($current ?? $default);
                    
                    $redis->multi();
                    $redis->set($redisKey, json_encode($newValue));
                    $result = $redis->exec();
                    
                    if ($result !== false) {
                        return $newValue;
                    }
                    // Transaction failed due to concurrent modification, retry
                }
                // Max retries exceeded - this indicates high contention
                throw new \RuntimeException('Redis cross-pool modify failed after max retries (high contention)');
            } catch (\RuntimeException $e) {
                throw $e; // Re-throw our own exceptions
            } catch (\Throwable $e) {
                throw new \RuntimeException('Redis cross-pool modify failed: ' . $e->getMessage(), 0, $e);
            }
        }
        // Local dev file fallback
        return self::fileModify($key, $modifier, $default);
    }

    /**
     * Delete a value from cross-pool storage.
     * Uses Redis (required in Azure, optional locally with file fallback).
     * 
     * @throws \RuntimeException If in Azure and Redis is not available
     */
    public static function crossPoolDelete(string $key): void
    {
        $redis = self::requireRedisForCrossPool();
        if ($redis) {
            try {
                $redis->del('crosspool:' . $key);
                return;
            } catch (\Throwable $e) {
                throw new \RuntimeException('Redis cross-pool delete failed: ' . $e->getMessage(), 0, $e);
            }
        }
        // Local dev file fallback
        self::fileDelete($key);
    }
}
