<?php
/**
 * APCu extension stubs for static analysis.
 * 
 * These function declarations tell the PHP static analyzer (Intelephense, PHPStan, etc.)
 * about APCu functions so it doesn't report "undefined function" errors.
 * 
 * This file is NEVER executed at runtime - the guard below ensures these
 * polyfill declarations only exist for the IDE/analyzer.
 * 
 * @see https://www.php.net/manual/en/book.apcu.php
 */

// Only declare stubs if APCu extension is not loaded (for IDE support)
if (!extension_loaded('apcu')) {
    /**
     * Check whether APCu is enabled.
     * @return bool
     */
    function apcu_enabled(): bool { return false; }

    /**
     * Fetch a stored variable from the cache.
     * @param string|string[] $key
     * @param bool|null &$success
     * @return mixed
     */
    function apcu_fetch(string|array $key, ?bool &$success = null): mixed { return false; }

    /**
     * Cache a variable in the data store.
     * @param string|array $key
     * @param mixed $var
     * @param int $ttl
     * @return bool|array
     */
    function apcu_store(string|array $key, mixed $var = null, int $ttl = 0): bool|array { return false; }

    /**
     * Cache a new variable in the data store (only if not exists).
     * @param string|array $key
     * @param mixed $var
     * @param int $ttl
     * @return bool|array
     */
    function apcu_add(string|array $key, mixed $var = null, int $ttl = 0): bool|array { return false; }

    /**
     * Remove a stored variable from the cache.
     * @param string|string[] $key
     * @return bool|string[]
     */
    function apcu_delete(string|array $key): bool|array { return false; }

    /**
     * Checks if entry exists.
     * @param string|string[] $keys
     * @return bool|string[]
     */
    function apcu_exists(string|array $keys): bool|array { return false; }

    /**
     * Retrieves cached information from APCu's data store.
     * @param bool $limited
     * @return array|false
     */
    function apcu_cache_info(bool $limited = false): array|false { return false; }

    /**
     * Retrieves APCu shared memory allocation information.
     * @param bool $limited
     * @return array|false
     */
    function apcu_sma_info(bool $limited = false): array|false { return false; }

    /**
     * Clears the APCu cache.
     * @return bool
     */
    function apcu_clear_cache(): bool { return false; }

    /**
     * Atomically increment a stored number.
     * @param string $key
     * @param int $step
     * @param bool|null &$success
     * @return int|false
     */
    function apcu_inc(string $key, int $step = 1, ?bool &$success = null): int|false { return false; }

    /**
     * Atomically decrement a stored number.
     * @param string $key
     * @param int $step
     * @param bool|null &$success
     * @return int|false
     */
    function apcu_dec(string $key, int $step = 1, ?bool &$success = null): int|false { return false; }
}
