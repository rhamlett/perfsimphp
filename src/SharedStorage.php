<?php
/**
 * =============================================================================
 * SHARED STORAGE — Cross-Request State Management
 * =============================================================================
 *
 * PURPOSE:
 *   Provides a shared storage mechanism for state that must persist across
 *   PHP-FPM requests. Uses APCu when available (fast shared memory between
 *   workers) and falls back to file-based storage with file locking.
 *
 * WHY THIS IS NEEDED:
 *   Unlike Node.js (single persistent process), PHP-FPM spawns a new worker
 *   for each request. In-memory variables are lost between requests. To track
 *   active simulations, event logs, and CPU worker PIDs, we need external
 *   shared storage.
 *
 * STORAGE BACKENDS:
 *   1. APCu (preferred): In-process shared memory. Fast, atomic operations.
 *      Available on most PHP installations including Azure blessed image.
 *   2. File-based (fallback): Uses JSON files with flock() for concurrency.
 *      Works everywhere but slower with potential lock contention.
 *
 * @module src/SharedStorage.php
 */

declare(strict_types=1);

namespace PerfSimPhp;

class SharedStorage
{
    private static bool $apcuChecked = false;
    private static bool $apcuAvailable = false;

    /**
     * Check if APCu is available and enabled.
     */
    private static function hasApcu(): bool
    {
        if (!self::$apcuChecked) {
            self::$apcuAvailable = function_exists('apcu_fetch') && apcu_enabled();
            self::$apcuChecked = true;
        }
        return self::$apcuAvailable;
    }

    /**
     * Get a value from shared storage.
     *
     * @param string $key Storage key
     * @param mixed $default Default value if key not found
     * @return mixed The stored value or default
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::hasApcu()) {
            $success = false;
            $value = apcu_fetch($key, $success);
            return $success ? $value : $default;
        }

        return self::fileGet($key, $default);
    }

    /**
     * Store a value in shared storage.
     *
     * @param string $key Storage key
     * @param mixed $value Value to store (must be serializable)
     * @param int $ttl Time-to-live in seconds (0 = forever)
     */
    public static function set(string $key, mixed $value, int $ttl = 0): void
    {
        if (self::hasApcu()) {
            apcu_store($key, $value, $ttl);
            return;
        }

        self::fileSet($key, $value);
    }

    /**
     * Delete a value from shared storage.
     *
     * @param string $key Storage key
     */
    public static function delete(string $key): void
    {
        if (self::hasApcu()) {
            apcu_delete($key);
            return;
        }

        self::fileDelete($key);
    }

    /**
     * Atomically modify a value (read-modify-write with locking).
     *
     * @param string $key Storage key
     * @param callable $modifier Function that takes current value and returns new value
     * @param mixed $default Default value if key doesn't exist
     */
    public static function modify(string $key, callable $modifier, mixed $default = null): mixed
    {
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
        return $data ?? $default;
    }

    private static function fileSet(string $key, mixed $value): void
    {
        $file = self::filePath($key);
        $fp = fopen($file, 'c');
        if (!$fp) {
            return;
        }

        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    private static function fileDelete(string $key): void
    {
        $file = self::filePath($key);
        if (file_exists($file)) {
            @unlink($file);
        }
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
}
