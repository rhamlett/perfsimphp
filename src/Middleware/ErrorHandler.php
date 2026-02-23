<?php
/**
 * =============================================================================
 * GLOBAL ERROR HANDLER MIDDLEWARE
 * =============================================================================
 *
 * FEATURE REQUIREMENTS (language-agnostic):
 *   The application must have centralized error handling that:
 *   1. Catches ALL unhandled errors and exceptions
 *   2. Returns consistent JSON error responses
 *   3. Uses appropriate HTTP status codes (400, 404, 500)
 *   4. Hides stack traces in production
 *   5. Logs all errors for debugging
 *
 * ERROR RESPONSE FORMAT:
 *   {
 *     "error": "Error Type Name",
 *     "message": "Human-readable error message",
 *     "details": { ... } // Optional, for validation errors
 *   }
 *
 * ERROR TYPES:
 *   ValidationException    → 400 Bad Request (invalid user input)
 *   NotFoundException      → 404 Not Found (resource doesn't exist)
 *   JsonException          → 400 Bad Request (malformed JSON body)
 *   Any other exception    → 500 Internal Server Error
 *
 * PORTING NOTES:
 *
 *   Node.js (Express):
 *     app.use((err, req, res, next) => {
 *       const status = err.statusCode || 500;
 *       res.status(status).json({ error: err.name, message: err.message });
 *     });
 *     - Express requires error middleware to have 4 parameters
 *     - Use async-handler wrapper for async route handlers
 *
 *   Java (Spring Boot):
 *     @ControllerAdvice
 *     public class GlobalExceptionHandler {
 *       @ExceptionHandler(ValidationException.class)
 *       @ResponseStatus(HttpStatus.BAD_REQUEST)
 *       public ErrorResponse handleValidation(ValidationException e) {...}
 *     }
 *     - Spring's @ExceptionHandler annotation
 *     - Return ResponseEntity for custom status codes
 *
 *   Python (Flask):
 *     @app.errorhandler(Exception)
 *     def handle_exception(e):
 *       return jsonify(error=type(e).__name__, message=str(e)), 500
 *     - Register handlers per exception type
 *     - FastAPI: use exception_handlers dict
 *
 *   .NET (ASP.NET Core):
 *     app.UseExceptionHandler(errorApp => { ... });
 *     - Or custom middleware with try/catch
 *     - ProblemDetails for RFC 7807 compliance
 *
 *   Ruby (Rails):
 *     rescue_from StandardError do |e|
 *       render json: { error: e.class.name, message: e.message }, status: 500
 *     end
 *     - In ApplicationController
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
        $isProduction = getenv('APP_ENV') === 'production';
        
        // Default: hide details in production
        $errorResponse = $isProduction
            ? [
                'error' => 'Internal Server Error',
                'message' => 'An unexpected error occurred',
            ]
            : [
                'error' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
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
