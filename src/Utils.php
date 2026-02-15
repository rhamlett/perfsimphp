<?php
/**
 * =============================================================================
 * UTILITY FUNCTIONS
 * =============================================================================
 *
 * PURPOSE:
 *   Shared helper functions used across services and controllers.
 *   All functions are pure and stateless.
 *
 * @module src/Utils.php
 */

declare(strict_types=1);

namespace PerfSimPhp;

class Utils
{
    /**
     * Generates a UUID v4.
     */
    public static function generateId(): string
    {
        // Use random_bytes for cryptographically secure UUID
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Converts bytes to megabytes (2 decimal places).
     */
    public static function bytesToMb(int|float $bytes): float
    {
        return round($bytes / (1024 * 1024), 2);
    }

    /**
     * Converts megabytes to bytes.
     */
    public static function mbToBytes(int|float $mb): int
    {
        return (int)($mb * 1024 * 1024);
    }

    /**
     * Formats a timestamp as ISO 8601.
     */
    public static function formatTimestamp(?float $microtime = null): string
    {
        $time = $microtime ?? microtime(true);
        $dt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6f', $time));
        if ($dt === false) {
            $dt = new \DateTimeImmutable();
        }
        return $dt->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Returns current time in milliseconds.
     */
    public static function nowMs(): float
    {
        return round(microtime(true) * 1000, 2);
    }

    /**
     * Get elapsed milliseconds since a start time.
     */
    public static function elapsedMs(float $startMicrotime): float
    {
        return round((microtime(true) - $startMicrotime) * 1000, 2);
    }

    /**
     * Checks if a value is within a range (inclusive).
     */
    public static function isInRange(int|float $value, int|float $min, int|float $max): bool
    {
        return $value >= $min && $value <= $max;
    }

    /**
     * Clamps a value to a range.
     */
    public static function clamp(int|float $value, int|float $min, int|float $max): int|float
    {
        return max($min, min($max, $value));
    }

    /**
     * Parse the JSON request body.
     */
    public static function getJsonBody(): array
    {
        $body = file_get_contents('php://input');
        if ($body === false || $body === '') {
            return [];
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Get a query parameter with optional default.
     */
    public static function queryParam(string $name, mixed $default = null): mixed
    {
        return $_GET[$name] ?? $default;
    }

    /**
     * Parse an optional integer query parameter.
     */
    public static function queryInt(string $name, ?int $default = null): ?int
    {
        $value = $_GET[$name] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        return $parsed !== false ? $parsed : $default;
    }

    /**
     * Validates a UUID format.
     */
    public static function isValidUuid(string $value): bool
    {
        return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }
}
