<?php
/**
 * =============================================================================
 * INPUT VALIDATION HELPERS
 * =============================================================================
 *
 * FEATURE REQUIREMENTS (language-agnostic):
 *   API input validation must:
 *   1. Validate all user input before processing
 *   2. Return clear error messages with field names
 *   3. Use HTTP 400 Bad Request for validation failures
 *   4. Support range validation for numeric fields
 *   5. Handle type coercion (string "100" → int 100)
 *
 * VALIDATION PATTERN:
 *   "Validate-or-throw" — returns sanitized value or throws exception.
 *   The global error handler catches exceptions and returns JSON error.
 *
 * PORTING NOTES:
 *
 *   Node.js:
 *     - Libraries: joi, yup, zod, express-validator
 *     - Example with Joi:
 *       const schema = Joi.object({
 *         targetLoadPercent: Joi.number().min(1).max(100).required()
 *       });
 *       const { error, value } = schema.validate(req.body);
 *
 *   Java (Spring Boot):
 *     - Use Bean Validation (javax.validation / jakarta.validation)
 *     - @Valid annotation triggers validation
 *     - Example: @Min(1) @Max(100) int targetLoadPercent;
 *
 *   Python (Flask):
 *     - Libraries: marshmallow, pydantic, cerberus
 *     - Example with Pydantic (FastAPI):
 *       class CpuParams(BaseModel):
 *         level: Literal['moderate', 'high']
 *         durationSeconds: int = Field(ge=1, le=300)
 *
 *   .NET (ASP.NET Core):
 *     - Data Annotations: [Range(1, 100)], [Required]
 *     - Or FluentValidation library
 *     - ModelState.IsValid for validation check
 *
 *   Ruby (Rails):
 *     - ActiveModel validations
 *     - validates :param, numericality: { in: 1..100 }
 *     - Dry-validation gem for complex schemas
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
     * @param array|mixed $data Array containing level and durationSeconds
     * @throws ValidationException if validation fails
     */
    public static function validateCpuStressParams(mixed $data): array
    {
        if (!is_array($data)) {
            throw new ValidationException("Invalid CPU stress parameters");
        }

        $level = $data['level'] ?? null;
        $validLevels = \PerfSimPhp\Services\CpuStressService::VALID_LEVELS;
        
        if ($level === null || $level === '') {
            throw new ValidationException("level is required");
        }
        
        $level = strtolower(trim((string)$level));
        if (!in_array($level, $validLevels, true)) {
            throw new ValidationException(
                "level must be one of: " . implode(', ', $validLevels),
                ['field' => 'level', 'validValues' => $validLevels, 'received' => $level]
            );
        }

        return [
            'level' => $level,
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
