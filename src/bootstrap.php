<?php
/**
 * =============================================================================
 * BOOTSTRAP — Autoloader & Initialization
 * =============================================================================
 *
 * FEATURE REQUIREMENTS (language-agnostic):
 *   Application initialization before handling requests:
 *   1. Set up class/module autoloading
 *   2. Create required directories (storage, logs)
 *   3. Register global error handlers
 *   4. Load configuration
 *
 * HOW IT WORKS (this implementation):
 *   - PSR-4 autoloader maps PerfSimPhp\ namespace to src/ directory
 *   - Falls back to Composer autoloader if available
 *   - Creates storage/ directory for shared state files
 *
 * PORTING NOTES:
 *
 *   Node.js:
 *     - Use ES modules (import/export) or CommonJS (require)
 *     - No explicit autoloader needed
 *     - Create directories: fs.mkdirSync(dir, { recursive: true })
 *     - Entry: import express from 'express'; const app = express();
 *
 *   Java (Spring Boot):
 *     - Spring handles component scanning and autowiring
 *     - @SpringBootApplication annotation on main class
 *     - Entry: SpringApplication.run(App.class, args)
 *
 *   Python (Flask/FastAPI):
 *     - Python handles imports automatically
 *     - Create directories: os.makedirs(dir, exist_ok=True)
 *     - Entry: app = Flask(__name__) or app = FastAPI()
 *
 *   .NET (ASP.NET Core):
 *     - .NET handles assembly loading
 *     - Dependency injection configured in Program.cs
 *     - Entry: WebApplication.CreateBuilder(args)
 *
 *   Ruby (Rails):
 *     - Bundler handles gem loading
 *     - Rails autoloads from app/ directory
 *     - Entry: Rails.application.initialize!
 *
 * @module src/bootstrap.php
 */

declare(strict_types=1);

// PSR-4 Autoloader (manual implementation — no Composer dependency required)
spl_autoload_register(function (string $class): void {
    $prefix = 'PerfSimPhp\\';
    $baseDir = __DIR__ . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Also load Composer autoloader if available
$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require $composerAutoload;
}

// Ensure storage directory exists
$storageDir = __DIR__ . '/../storage';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0755, true);
}
