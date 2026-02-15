<?php
/**
 * =============================================================================
 * INPUT VALIDATION HELPERS
 * =============================================================================
 *
 * PURPOSE:
 *   Reusable validation functions for API request parameters.
 *   Uses "validate-or-throw" pattern — returns validated value or throws
 *   ValidationException (caught by global error handler → HTTP 400).
 *
 * @module src/Middleware/Validation.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Middleware;

use PerfSimPhp\Config;

class Validation
{
    /**
     * Validates that a value is a positive integer within a range.
     *
     * @throws ValidationException if validation fails
     */
    public static function validateInteger(
        mixed $value,
        string $fieldName,
        int $min,
        int $max,
    ): int {
        if ($value === null || $value === '') {
            throw new ValidationException("{$fieldName} is required");
        }

        if (is_string($value)) {
            if (!ctype_digit($value) && !preg_match('/^-?\d+$/', $value)) {
                throw new ValidationException("{$fieldName} must be a number");
            }
            $value = (int)$value;
        }

        if (!is_int($value) && !is_float($value)) {
            throw new ValidationException("{$fieldName} must be a number");
        }

        $intValue = (int)$value;

        if ($intValue < $min || $intValue > $max) {
            throw new ValidationException(
                "{$fieldName} must be between {$min} and {$max}",
                ['field' => $fieldName, 'min' => $min, 'max' => $max, 'received' => $intValue]
            );
        }

        return $intValue;
    }

    /**
     * Validates an optional integer, returning default if not provided.
     *
     * @throws ValidationException if provided but invalid
     */
    public static function validateOptionalInteger(
        mixed $value,
        string $fieldName,
        int $min,
        int $max,
        int $default,
    ): int {
        if ($value === null || $value === '') {
            return $default;
        }
        return self::validateInteger($value, $fieldName, $min, $max);
    }

    /**
     * Validates CPU stress parameters.
     *
     * @throws ValidationException if validation fails
     */
    public static function validateCpuStressParams(mixed $targetLoadPercent, mixed $durationSeconds): array
    {
        return [
            'targetLoadPercent' => self::validateInteger(
                $targetLoadPercent, 'targetLoadPercent',
                Config::MIN_CPU_LOAD_PERCENT, Config::MAX_CPU_LOAD_PERCENT
            ),
            'durationSeconds' => self::validateInteger(
                $durationSeconds, 'durationSeconds',
                Config::MIN_DURATION_SECONDS, Config::maxDurationSeconds()
            ),
        ];
    }

    /**
     * Validates memory pressure parameters.
     *
     * @throws ValidationException if validation fails
     */
    public static function validateMemoryPressureParams(mixed $sizeMb): array
    {
        return [
            'sizeMb' => self::validateInteger(
                $sizeMb, 'sizeMb',
                Config::MIN_MEMORY_MB, Config::maxMemoryMb()
            ),
        ];
    }

    /**
     * Validates blocking simulation parameters.
     *
     * @throws ValidationException if validation fails
     */
    public static function validateBlockingParams(mixed $durationSeconds): array
    {
        return [
            'durationSeconds' => self::validateInteger(
                $durationSeconds, 'durationSeconds',
                Config::MIN_DURATION_SECONDS, Config::maxDurationSeconds()
            ),
        ];
    }

    /**
     * Validates slow request parameters.
     *
     * @throws ValidationException if validation fails
     */
    public static function validateSlowRequestParams(mixed $delaySeconds): array
    {
        return [
            'delaySeconds' => self::validateInteger(
                $delaySeconds, 'delaySeconds',
                Config::MIN_DURATION_SECONDS, Config::maxDurationSeconds()
            ),
        ];
    }

    /**
     * Validates a UUID format.
     *
     * @throws ValidationException if validation fails
     */
    public static function validateUuid(mixed $value, string $fieldName): string
    {
        if (!is_string($value)) {
            throw new ValidationException("{$fieldName} must be a string");
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            throw new ValidationException("{$fieldName} must be a valid UUID");
        }
        return $value;
    }
}
