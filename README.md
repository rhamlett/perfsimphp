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

### Dual FPM Pool Architecture

To keep the dashboard responsive during load testing, the application uses **two separate PHP-FPM pools**:

```
┌─────────────────────────────────────────────────────────────────┐
│  Nginx (routing by URL)                                         │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  /api/loadtest/*        ───►  Port 9000 (main pool, ~8 workers) │
│  /api/simulations/*     ───►       Load test & simulations      │
│  /api/metrics/probe     ───►       (probe measures main pool)   │
│                                                                 │
│  /api/metrics           ───►  Port 9001 (metrics pool, 4 workers)│
│  /api/metrics/internal  ───►       Dashboard polling (reserved) │
│  /api/health/*          ───►                                    │
│  /api/admin/events      ───►                                    │
└─────────────────────────────────────────────────────────────────┘
```

This prevents load test traffic from starving the dashboard — metrics workers are always available.

### Directory Structure

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

# Configuration (Azure App Service)
├── default                 # Nginx config (dual FPM pool routing)
├── metrics-pool.conf       # Dedicated FPM pool for metrics (port 9001)
└── startup.sh              # Azure startup script (installs configs)
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

## � Application Insights (Optional)

PHP requires manual configuration for Application Insights telemetry. Add these App Settings:

| Setting | Required | Description |
|---------|----------|-------------|
| `APPLICATIONINSIGHTS_CONNECTION_STRING` | **Yes** | Connection string from your Application Insights resource |
| `OTEL_SERVICE_NAME` | No | Custom service name (defaults to `PerfSimPhp`) |

**To get the connection string:**
1. Create an Application Insights resource in Azure Portal
2. Go to **Overview** → copy the **Connection String**
3. Add it as an App Setting in your App Service

When not configured, the app runs normally with telemetry disabled (zero overhead).

See [Enabling Application Insights for PHP](public/azure-diagnostics.html#enable-app-insights) for detailed setup instructions.

## �📊 Dashboard

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

### Admin & Diagnostics

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/admin/status` | GET | Comprehensive status |
| `/api/admin/events` | GET | Recent event log entries |
| `/api/admin/system-info` | GET | System info (CPU, PHP, platform) |
| `/api/admin/memory-debug` | GET | Memory diagnostic info |
| `/api/admin/telemetry-status` | GET | Application Insights status |

### Load Testing

Dedicated endpoint for Azure Load Testing:

```
GET /api/loadtest
GET /api/loadtest?workMs=100&memoryKb=5000
GET /api/loadtest/stats
```

**Query Parameters (all optional):**
| Parameter | Default | Max | Description |
|-----------|---------|-----|-------------|
| `workMs` | 100 | 5000 | Duration of CPU work in milliseconds (uses hash_pbkdf2) |
| `memoryKb` | 5000 | 50000 | Memory to allocate per request in KB |

**Design Philosophy:**
- Each request does a SHORT burst of real work (~100ms)
- Workers return quickly, keeping dashboard responsive
- Load test frameworks hit the endpoint repeatedly for sustained load
- Under heavy load, requests naturally queue (realistic degradation)

**Stats Logging:** Every 60 seconds, a summary is logged to the event log:
```
📊 Load Test (60s): 1523 requests, 112ms avg, 426ms max, 25.4 RPS
```

**Legacy Parameters:** `targetDurationMs` and `memorySizeKb` are still supported as aliases for backwards compatibility.

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


