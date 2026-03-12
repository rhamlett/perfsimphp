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

## 📊 Dashboard

The real-time dashboard displays:

- **CPU Usage** — Percentage from `/proc/stat` with delta calculation
- **Memory (MB)** — Combined: PHP-FPM worker RSS + APCu memory pressure allocations. Shows both load test memory and memory simulation.
- **FPM Workers** — Active workers and busy count
- **RSS Memory** — Resident set size of the current metrics process
- **Request Latency** — Live latency chart from XHR probes (targets <200ms)

## 🔥 Simulations

### CPU Stress

Generates high CPU usage using separate background PHP processes via `exec()`.

```bash
POST /api/simulations/cpu/start
Content-Type: application/json

{
  "level": "high",
  "durationSeconds": 30
}
```

**Parameters:**
- `level` — "moderate" or "high" intensity
- `durationSeconds` — Duration of simulation (1-300)

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

### Load Testing

Dedicated endpoint for Azure Load Testing:

```
GET /api/loadtest
GET /api/loadtest?workMs=100&memoryKb=5000&holdMs=500
GET /api/loadtest?workMs=100&errorAfter=2&errorPercent=50
GET /api/loadtest?errorAfter=0
GET /api/loadtest/stats
```

**Query Parameters (all optional):**
| Parameter | Default | Max | Description |
|-----------|---------|-----|-------------|
| `workMs` | 100 | 5000 | Duration of CPU work in milliseconds (uses hash_pbkdf2) |
| `memoryKb` | 5000 | 50000 | Memory to allocate per request in KB |
| `holdMs` | 500 | 5000 | Time to hold memory after CPU work (ms). Ensures metrics polling captures memory usage. |
| `errorAfter` | 120 | 300 | Throw random error if total request time (including queue wait) exceeds this many seconds (0 = disabled) |
| `errorPercent` | 20 | 100 | Percentage chance to throw error when errorAfter threshold exceeded |

**Chaos Error Injection:**
For realistic load testing with unpredictable errors, use `errorAfter` and `errorPercent`:
- By default, requests over 120s have a 20% chance to throw a random error
- `errorAfter=2&errorPercent=50` — 50% chance of random error if total request time exceeds 2 seconds
- `errorAfter=0` — Disable chaos errors entirely
- Measures TOTAL request time from when PHP received the request (includes FPM queue wait)
- Errors trigger when requests are delayed due to worker pool exhaustion
- Error types include `RuntimeException`, `LogicException`, `InvalidArgumentException`, etc.

**Design Philosophy:**
- Each request does a SHORT burst of real work (~100ms)
- Memory is held for `holdMs` after CPU work so dashboard can capture it
- Workers return quickly, keeping dashboard responsive
- Load test frameworks hit the endpoint repeatedly for sustained load
- Under heavy load, requests naturally queue (realistic degradation)

**Stats Logging:** Every 60 seconds, a summary is logged to the event log:
```
📊 Load test period stats (60s): 1523 requests, 112.5 avg ms, 426 max ms, 25.38 RPS, 0.0% errors
```

**Legacy Parameters:** `targetDurationMs` and `memorySizeKb` are still supported as aliases for backwards compatibility.

## 🔬 Diagnostics

For comprehensive guidance on diagnosing PHP performance issues, see the built-in [Azure Diagnostics Guide](public/azure-diagnostics.html).

### Key Azure Tools

- **App Service Diagnostics** — CPU drill-down, memory analysis, application crashes
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

## ⚙️ Configuration

The application supports configuration via environment variables:

| Variable | Default | Description |
|----------|---------|-------------|
| `PORT` | 8080 | HTTP server port |
| `METRICS_INTERVAL_MS` | 500 | Metrics collection interval (ms) |
| `MAX_SIMULATION_DURATION_SECONDS` | 86400 | Max simulation duration |
| `MAX_MEMORY_ALLOCATION_MB` | 65536 | Max memory allocation per simulation |
| `EVENT_LOG_MAX_ENTRIES` | 100 | Event log ring buffer size |
| `HEALTH_PROBE_RATE` | 200 | Health probe interval in ms (min: 100) |
| `REDIS_URL` | - | Redis connection for cross-pool storage (see below) |
| `PAGE_FOOTER` | - | Custom footer HTML |

### Redis Configuration (Optional)

For better load test performance, configure Azure Cache for Redis or Azure Managed Redis 
to eliminate file-lock contention during high-concurrency load tests:

```bash
# Create Azure Managed Redis (recommended)
az redisenterprise create --name myredis --resource-group $RESOURCE_GROUP \
  --location eastus --sku Balanced_B0 --public-network-access Enabled

# Get connection details
az redisenterprise database show --cluster-name myredis --resource-group $RESOURCE_GROUP \
  --database-name default --query "hostName" -o tsv

# Get access key
az redisenterprise database list-keys --cluster-name myredis --resource-group $RESOURCE_GROUP \
  --database-name default --query "primaryKey" -o tsv

# Configure the app with Redis URL (TLS on port 10000 for Managed Redis)
az webapp config appsettings set --name $APP_NAME --resource-group $RESOURCE_GROUP \
  --settings REDIS_URL="rediss://:YOUR_KEY@myredis.eastus.redisenterprise.cache.azure.net:10000"
```

The app automatically uses Redis for cross-pool storage when `REDIS_URL` is set, 
falling back to file-based storage otherwise.

### Setting Environment Variables (Azure CLI)

```bash
# Set health probe rate to 400ms (reduces probe overhead for profiling)
az webapp config appsettings set --name $APP_NAME --resource-group $RESOURCE_GROUP \
  --settings HEALTH_PROBE_RATE=400

# Set multiple settings
az webapp config appsettings set --name $APP_NAME --resource-group $RESOURCE_GROUP \
  --settings HEALTH_PROBE_RATE=400 MAX_SIMULATION_DURATION_SECONDS=300
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



