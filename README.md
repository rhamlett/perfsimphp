# 🐘 PerfSimPhp - Performance Problem Simulator

An educational tool designed to help Azure support engineers practice diagnosing common PHP performance problems on Azure App Service. It intentionally generates controllable performance issues that mimic real-world scenarios.

**Runtime:** PHP Blessed Image (PHP|8.4) on Linux

[![Deploy to Azure](https://aka.ms/deploytoazurebutton)](https://portal.azure.com/#create/Microsoft.Template/uri/https%3A%2F%2Fraw.githubusercontent.com%2Frhamlett%2Fperfsimphp%2Fmain%2Fazuredeploy.json)

## ✨ Features

| Simulation | Description | Dashboard Control |
|------------|-------------|-------------------|
| **CPU Stress** | Generate high CPU usage via background PHP processes | Target %, Duration |
| **Memory Pressure** | Allocate and retain memory in shared storage (APCu) | Size in MB |
| **Request Thread Blocking** | Block PHP-FPM workers with synchronous operations | Duration, # Workers |
| **Crash Simulation** | Trigger fatal errors, exit, stack overflow, or OOM conditions | Crash Type |

## 🏗️ Architecture

The application runs on **PHP 8.4** with **Nginx + PHP-FPM**, using APCu or file-based shared storage for cross-request state, and AJAX polling for real-time metrics.

```
public/
├── index.php               # Front controller (all requests)
├── index.html              # Main dashboard
├── docs.html               # Documentation
├── azure-diagnostics.html  # Diagnostics guide
├── azure-deployment.html   # Deployment guide
├── css/styles.css          # Shared stylesheet
└── js/
    ├── polling-client.js   # AJAX polling client
    ├── charts.js           # Real-time Chart.js charts
    └── dashboard.js        # UI interactions & form handlers

src/
├── bootstrap.php           # Autoloader & initialization
├── Config.php              # Application configuration
├── SharedStorage.php       # Cross-request state (APCu or file)
├── Router.php              # URL routing
├── Utils.php               # Utility functions
├── Middleware/             # Error handling, logging, validation
├── Services/               # Business logic for each simulation
└── Controllers/            # HTTP endpoint handlers

workers/
└── cpu-worker.php          # Background CPU stress process
```

## 🚀 Quick Start

### Deploy to Azure App Service

1. **Create App Service** (PHP 8.4, Linux)
   ```bash
   az webapp create \
     --name perfsimphp \
     --resource-group my-rg \
     --plan my-plan \
     --runtime "PHP:8.4"
   ```

2. **Deploy via Git or ZIP**
   ```bash
   # ZIP deploy
   zip -r deploy.zip . -x ".git/*" "vendor/jetbrains/*"
   az webapp deployment source config-zip \
     --name perfsimphp \
     --resource-group my-rg \
     --src deploy.zip
   ```

3. **Open Dashboard**
   ```
   https://perfsimphp.azurewebsites.net/
   ```

For detailed deployment with GitHub Actions and OIDC, see the [Azure Deployment Guide](public/azure-deployment.html).

## 📊 Dashboard

The real-time dashboard displays:

- **CPU Usage** — Percentage from `/proc/stat` with delta calculation
- **Memory** — PHP memory usage plus simulated allocations
- **FPM Workers** — Active workers and busy count
- **RSS Memory** — Resident set size from `/proc/self/status`
- **Request Latency** — Live latency chart from XHR probes

## 🔥 Simulations

### CPU Stress

Generates high CPU usage using separate background PHP processes via `exec()`.

```bash
POST /api/simulations/cpu/start
Content-Type: application/json

{
  "targetLoadPercent": 75,
  "durationSeconds": 30
}
```

**Why background processes?** Unlike naive CPU burning in the request thread (which blocks the FPM worker), this simulation spawns separate processes that each run `hash_pbkdf2()` in a tight loop. FPM workers stay available.

### Memory Pressure

Allocates large data blocks in shared storage (APCu) to simulate memory leaks.

```bash
POST /api/simulations/memory/allocate
{"sizeMb": 100}

POST /api/simulations/memory/release
```

### Request Thread Blocking

Blocks PHP-FPM workers with CPU-intensive synchronous operations, demonstrating worker pool exhaustion.

```bash
POST /api/simulations/blocking/start
{"durationSeconds": 5, "concurrentWorkers": 3}
```

**Key difference from CPU stress:** CPU stress uses background processes (FPM workers stay available). Request blocking runs inside FPM workers (those workers become unavailable).

### Crash Simulation

Intentionally crashes PHP-FPM workers for testing recovery:

| Type | Endpoint | Method |
|------|----------|--------|
| FailFast | `/api/simulations/crash/failfast` | `exit(1)` |
| Stack Overflow | `/api/simulations/crash/stackoverflow` | Infinite recursion |
| Fatal Error | `/api/simulations/crash/exception` | `trigger_error(E_USER_ERROR)` |
| OOM | `/api/simulations/crash/oom` | Exceed `memory_limit` |

PHP-FPM master automatically respawns crashed workers.

## 📋 API Reference

### Health & Metrics

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/health` | GET | Health check with environment info |
| `/api/metrics` | GET | Current system metrics |
| `/api/metrics/probe` | GET | Lightweight latency probe |
| `/api/metrics/internal-probes` | GET | Batch internal probes |

### Simulations

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/simulations` | GET | List all active simulations |
| `/api/simulations/cpu/start` | POST | Start CPU stress |
| `/api/simulations/cpu/stop` | POST | Stop all CPU stress |
| `/api/simulations/memory/allocate` | POST | Allocate memory |
| `/api/simulations/memory/release` | POST | Release all memory |
| `/api/simulations/blocking/start` | POST | Block FPM workers |
| `/api/simulations/crash/{type}` | POST | Trigger crash |
| `/api/simulations/crash/stats` | GET | Crash statistics |

### Load Testing

Dedicated endpoint for Azure Load Testing:

```
GET /api/loadtest?cpuWorkMs=50&memorySizeKb=5000&baselineDelayMs=500
GET /api/loadtest/stats
```

**Query Parameters (all optional):**
| Parameter | Default | Description |
|-----------|---------|-------------|
| `cpuWorkMs` | 100 | Milliseconds of real CPU work per cycle (uses hash_pbkdf2) |
| `memorySizeKb` | 10000 | KB of memory to allocate per request (increase to trigger OOM) |
| `baselineDelayMs` | 1000 | Minimum response time before degradation |
| `softLimit` | 20 | Concurrent requests before degradation starts |
| `degradationFactor` | 1000 | Milliseconds added per request over softLimit |

**Degradation Formula:** `responseTime = baselineDelayMs + max(0, concurrent - softLimit) * degradationFactor`

## 🔬 Diagnostics

For comprehensive guidance on diagnosing PHP performance issues, see the built-in [Azure Diagnostics Guide](public/azure-diagnostics.html).

### Key Azure Tools

- **App Service Diagnostics** — CPU drill-down, memory analysis, application crashes
- **Application Insights** — Performance metrics, failures, live metrics stream
- **Kudu Console** — SSH access, process explorer, log stream
- **Log Analytics** — KQL queries for deep analysis

### Linux Commands (via Kudu SSH)

```bash
# CPU analysis
top -H -p $(pgrep php-fpm | head -1)
ps aux --sort=-%cpu | head

# Memory analysis
free -m
ps aux --sort=-%mem | head

# PHP-FPM status
pgrep -a php-fpm
```

## 🛠️ Development

### Requirements

- PHP 8.4+
- Nginx + PHP-FPM (or PHP built-in server for testing)
- APCu extension (optional, falls back to file storage)

### Local Testing

```bash
# Using PHP built-in server (limited functionality)
php -S localhost:8080 -t public public/index.php

# Or with proper PHP-FPM setup
# Configure Nginx to proxy to PHP-FPM
```

### Project Structure

```
├── composer.json           # Composer dependencies
├── public/                 # Web root
│   ├── index.php          # Front controller
│   └── ...                # Static assets
├── src/                   # PHP application code
├── storage/               # File-based storage (auto-created)
├── workers/               # Background worker scripts
├── startup.sh             # Azure startup script
└── default                # Nginx configuration
```

## 📝 License

This project is for educational and training purposes. Created by [SpecKit](https://speckit.org/) in collaboration with Richard Hamlett (Microsoft).

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## 📚 Related Resources

- [Azure App Service Documentation](https://docs.microsoft.com/azure/app-service/)
- [PHP on Azure App Service](https://docs.microsoft.com/azure/app-service/configure-language-php)
- [Application Insights for PHP](https://docs.microsoft.com/azure/azure-monitor/app/app-insights-overview)


