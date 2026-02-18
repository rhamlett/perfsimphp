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
     * @param array|mixed $data Array containing targetLoadPercent and durationSeconds
     * @throws ValidationException if validation fails
     */
    public static function validateCpuStressParams(mixed $data): array
    {
        if (!is_array($data)) {
            throw new ValidationException("Invalid CPU stress parameters");
        }

        return [
            'targetLoadPercent' => self::validateInteger(
                $data['targetLoadPercent'] ?? null, 'targetLoadPercent',
                Config::MIN_CPU_LOAD_PERCENT, Config::MAX_CPU_LOAD_PERCENT
            ),
            'durationSeconds' => self::validateInteger(
                $data['durationSeconds'] ?? null, 'durationSeconds',
                Config::MIN_DURATION_SECONDS, Config::maxDurationSeconds()
            ),
        ];
    }

    /**
     * Validates memory pressure parameters.
     *
     * @param array|mixed $data Array containing sizeMb
     * @throws ValidationException if validation fails
     */
    public static function validateMemoryPressureParams(mixed $data): array
    {
        if (!is_array($data)) {
            throw new ValidationException("Invalid memory pressure parameters");
        }

        return [
            'sizeMb' => self::validateInteger(
                $data['sizeMb'] ?? null, 'sizeMb',
                Config::MIN_MEMORY_MB, Config::maxMemoryMb()
            ),
        ];
    }

    /**
     * Validates blocking simulation parameters.
     *
     * @param array|mixed $data Array containing durationSeconds and optional concurrentWorkers
     * @throws ValidationException if validation fails
     */
    public static function validateBlockingParams(mixed $data): array
    {
        if (!is_array($data)) {
            throw new ValidationException("Invalid blocking parameters");
        }

        // Validate concurrentWorkers (optional, defaults to 1)
        $concurrentWorkers = $data['concurrentWorkers'] ?? Config::DEFAULT_BLOCKING_CONCURRENT_WORKERS;
        if (!is_numeric($concurrentWorkers) || $concurrentWorkers < 1) {
            $concurrentWorkers = Config::DEFAULT_BLOCKING_CONCURRENT_WORKERS;
        }
        $concurrentWorkers = min((int)$concurrentWorkers, Config::MAX_BLOCKING_CONCURRENT_WORKERS);

        return [
            'durationSeconds' => self::validateInteger(
                $data['durationSeconds'] ?? null, 'durationSeconds',
                Config::MIN_DURATION_SECONDS, Config::maxDurationSeconds()
            ),
            'concurrentWorkers' => $concurrentWorkers,
        ];
    }

    /**
     * Validates slow request parameters.
     *
     * @param array|mixed $data Array containing delaySeconds and optional blockingPattern
     * @throws ValidationException if validation fails
     */
    public static function validateSlowRequestParams(mixed $data): array
    {
        if (!is_array($data)) {
            throw new ValidationException("Invalid slow request parameters");
        }

        $delaySeconds = $data['delaySeconds'] ?? null;
        $blockingPattern = $data['blockingPattern'] ?? 'sleep';

        // Validate delaySeconds
        $validatedDelay = self::validateInteger(
            $delaySeconds, 'delaySeconds',
            Config::MIN_DURATION_SECONDS, Config::maxDurationSeconds()
        );

        // Validate blockingPattern
        $validPatterns = ['sleep', 'cpu_intensive', 'file_io'];
        if (!in_array($blockingPattern, $validPatterns, true)) {
            $blockingPattern = 'sleep'; // Default to sleep if invalid
        }

        return [
            'delaySeconds' => $validatedDelay,
            'blockingPattern' => $blockingPattern,
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
