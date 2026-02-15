<?php
/**
 * =============================================================================
 * GLOBAL ERROR HANDLER MIDDLEWARE
 * =============================================================================
 *
 * PURPOSE:
 *   Catches ALL unhandled errors and exceptions, transforming them into
 *   consistent JSON error responses. Equivalent to Express's global error
 *   handler middleware.
 *
 * ERROR HIERARCHY:
 *   AppException (base)       → Custom application error with HTTP status code
 *   ├─ ValidationException    → 400 Bad Request (invalid user input)
 *   └─ NotFoundException      → 404 Not Found (resource doesn't exist)
 *   JsonException             → 400 Bad Request (malformed JSON body)
 *   Exception (any other)     → 500 Internal Server Error
 *
 * @module src/Middleware/ErrorHandler.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Middleware;

class ErrorHandler
{
    /**
     * Register global error and exception handlers.
     */
    public static function register(): void
    {
        set_exception_handler([self::class, 'handleException']);
        set_error_handler([self::class, 'handleError']);
    }

    /**
     * Handle uncaught exceptions.
     */
    public static function handleException(\Throwable $e): void
    {
        $statusCode = 500;
        $errorResponse = [
            'error' => 'Internal Server Error',
            'message' => 'An unexpected error occurred',
        ];

        if ($e instanceof AppException) {
            $statusCode = $e->statusCode;
            $errorResponse = [
                'error' => $e->errorType,
                'message' => $e->getMessage(),
            ];
            if ($e->details !== null) {
                $errorResponse['details'] = $e->details;
            }
        } elseif ($e instanceof \JsonException) {
            $statusCode = 400;
            $errorResponse = [
                'error' => 'Bad Request',
                'message' => 'Invalid JSON in request body',
            ];
        }

        // Log error
        error_log("[ERROR] {$e->getMessage()}");
        if (getenv('APP_ENV') !== 'production') {
            error_log($e->getTraceAsString());
        }

        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($errorResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Convert PHP errors to exceptions.
     */
    public static function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new \ErrorException($message, 0, $severity, $file, $line);
    }
}

/**
 * Custom application exception with HTTP status code.
 */
class AppException extends \RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        string $message,
        public readonly string $errorType = 'AppError',
        public readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }
}

/**
 * Validation exception for invalid input. Returns HTTP 400.
 */
class ValidationException extends AppException
{
    public function __construct(string $message, ?array $details = null)
    {
        parent::__construct(400, $message, 'ValidationError', $details);
    }
}

/**
 * Not found exception. Returns HTTP 404.
 */
class NotFoundException extends AppException
{
    public function __construct(string $message = 'Resource not found')
    {
        parent::__construct(404, $message, 'NotFoundError');
    }
}
