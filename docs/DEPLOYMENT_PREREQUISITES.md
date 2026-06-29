# DEPLOYMENT_PREREQUISITES.md — Application-Side Requirements

> This document lists what the application needs from the host to run correctly in production. Infrastructure provisioning (DNS, TLS, load balancers, backups at the infra level) is out of scope here and owned by the deployment team.

---

## Purpose

When the project moves from local development to a hosted environment, the deployment team needs a single source of truth for what the application expects. This file captures those expectations. If a requirement here is unmet, the application will not run correctly — not because of an infra misconfiguration, but because the application genuinely depends on it.

This file is paired with (not a replacement for) the infra team's deployment runbook. They own the runbook. This doc tells them what to put in it.

---

## Runtime Requirements

### PHP

- **Version:** PHP 8.3+
- **Extensions required:** `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `json`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `tokenizer`, `xml`, `zip`
- **Process management:** PHP-FPM behind nginx (or equivalent)
- **Memory limit:** 512 MB for web workers, 1 GB for queue workers running the `forecasting` queue
- **Composer:** 2.x, used at deploy time only

### Python

The ML forecasting tier is a Python subprocess called from Laravel. **Python is not optional** — if it's missing, forecasting is disabled and the engine falls back to the 60/30/10 weighted moving average for every SKU.

- **Version:** Python 3.11+
- **Installation:** system-level Python 3.11 present at `/usr/bin/python3.11` or available on `PATH`
- **Virtual environment:** provisioned at deploy time from `python/forecasting/requirements.txt`, located at `python/forecasting/.venv/`
- **PATH configuration:** the `python` executable invoked by Laravel must be the venv Python. Configure via `FORECAST_PYTHON_BIN` env var pointing to `python/forecasting/.venv/bin/python`.
- **Optional libraries:** Prophet and LightGBM are optional dependencies. The pipeline skips them gracefully if missing and logs a warning. If installed, they become additional candidate models.
- **Build tools required to install dependencies:** `gcc`, `g++`, `make`, Python headers (`python3.11-dev`). These are install-time only; they're needed to compile `statsmodels`, `pmdarima`, `lightgbm` native code.

### MySQL

- **Version:** MySQL 8.0+
- **Collation:** `utf8mb4_unicode_ci`
- **Timezone:** UTC at the server level; application-level timezone handling is done in Laravel
- **Connection limit:** estimate 20 + (num queue workers × 2). For current client: ~30 connections sufficient.
- **Read replicas:** not required for the first client. If added later, Reports queries are replica-safe — verify no write statements in reports controllers before routing to replica.

### Redis

- **Version:** Redis 7+
- **Use:** cache, session store, queue backend (Horizon), Reverb pub/sub
- **Persistence:** AOF enabled, snapshots hourly. Loss of Redis = loss of queued jobs and active WebSocket sessions; recoverable but disruptive.
- **Memory:** 512 MB sufficient for current scale; monitor and scale based on queue depth

---

## Process Model

Five long-running processes required. None of them can be cron-based or request-triggered.

### 1. Web (PHP-FPM + nginx)

Standard Laravel web serving. Handles HTTP requests, Inertia responses, file uploads.

### 2. Horizon (queue supervisor)

```
php artisan horizon
```

Must run under a process supervisor (systemd, supervisor, or equivalent) that auto-restarts on crash. **Do not run as a cron job.** Horizon manages three queues; their workers have different resource profiles:

| Queue | Worker count | Memory | Timeout |
|---|---|---|---|
| `default` | 2 | 256 MB | 60s |
| `inventory` | 1 | 512 MB | 300s |
| `forecasting` | 1–2 | 1 GB | 900s |

The `forecasting` queue spawns Python subprocesses. Worker timeout must exceed `FORECAST_PROCESS_TIMEOUT` (default 600s) plus buffer — hence 900s.

**Graceful shutdown** on SIGTERM: workers must finish in-flight jobs before exiting, and Python subprocesses spawned by the forecasting worker must be cleaned up (not orphaned). The Laravel `Process::run()` call handles this if Horizon's graceful shutdown timeout is ≥ the subprocess timeout. Set Horizon's `shutdown_timeout` to at least 910s.

### 3. Scheduler

```
php artisan schedule:work
```

Alternative to a cron-driven `schedule:run`. Runs as a daemon under the same supervisor. Responsible for:

- Hourly Shopify incremental sync (`RunShopifyIncrementalSyncJob`)
- Weekly recommendation feedback analysis (`recommendation:analyze-feedback`)
- Monthly forecast sweep (`forecast:sweep`)
- Daily cleanup of orphan temp files (`ingestion:cleanup-uploads`, forecasting temp file cleanup)
- Annual regional holiday refresh

If using a traditional cron-driven scheduler instead, the cron entry is:

```
* * * * * cd /var/www/app && php artisan schedule:run >> /dev/null 2>&1
```

Either approach works. Daemon is preferred for reliability.

### 4. Reverb (WebSocket server)

```
php artisan reverb:start
```

Long-running WebSocket server broadcasting real-time stock alerts. Must run under supervisor.

**Reverse-proxy configuration required.** The WebSocket upgrade needs explicit handling. Example nginx location block:

```nginx
location /app {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 86400;
}
```

**TLS:** Reverb must be accessible over WSS in production. TLS termination happens at the reverse proxy; Reverb itself runs plain HTTP internally.

**Frontend config:** `VITE_REVERB_HOST` points to the public hostname. `VITE_REVERB_SCHEME=https` for WSS. These are set at build time, not runtime — a change requires a frontend rebuild.

### 5. Vite build artifacts

Not a running process, but a deploy-time step. `npm run build` runs during deployment and produces the compiled frontend assets in `public/build/`. The deployment pipeline must run this after every frontend-affecting code change. Do not skip it — Inertia will serve broken assets.

---

## Filesystem Requirements

### Writable directories

The application writes to several paths. All must be writable by the PHP-FPM user and the queue worker user (typically the same user).

| Path | Purpose | Cleanup policy |
|---|---|---|
| `storage/app/forecasting/tmp/{tenant_id}/` | Python subprocess input JSON | Scheduled daily cleanup of orphans > 24h |
| `storage/app/forecasting/reports/` | Audit, EDA, diagnostics, feature importance outputs | Manual review; no automatic cleanup |
| `storage/app/ingestion/uploads/{tenant_id}/` | Uploaded CSV files | Scheduled daily cleanup of orphans > 24h |
| `storage/app/ingestion/templates/` | Downloadable CSV templates | Deploy artifact; read-only after deploy |
| `storage/logs/` | Laravel logs | Rotate via logrotate; retain 14 days |
| `bootstrap/cache/` | Laravel cache | Cleared on deploy |

### Disk space estimate

For the current client (~30 SKUs, 30 months of history):

- Logs: ~500 MB/month at production log level
- Forecasting reports: ~50 MB/month (per-SKU diagnostic files)
- Temp files: transient, should never exceed 100 MB at any moment
- Ingestion uploads: transient, should never exceed 500 MB

20 GB application disk is comfortably over-provisioned for the current client. Scale linearly with SKU count and tenant count.

### Database size

Starts small. Budget 5 GB for year one per tenant, dominated by `sales_history` (one row per SKU per day, plus indexes). `inventory_decisions` grows proportionally to SKU count × engine run frequency (daily).

---

## Environment Variables

The application reads configuration from environment variables. The production deployment provides a `.env` file with values appropriate for the host. Below are the variables the application expects, grouped by what the deployment team must set.

### Must be set at deploy

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<subdomain>.<domain>
APP_KEY=<generated once per environment, never regenerate>

DB_CONNECTION=mysql
DB_HOST=<managed mysql host>
DB_PORT=3306
DB_DATABASE=<database name>
DB_USERNAME=<username>
DB_PASSWORD=<password>

REDIS_HOST=<redis host>
REDIS_PASSWORD=<password if using auth>
REDIS_PORT=6379

QUEUE_CONNECTION=redis
CACHE_DRIVER=redis
SESSION_DRIVER=redis
BROADCAST_DRIVER=reverb

REVERB_APP_ID=<generated per environment>
REVERB_APP_KEY=<generated per environment>
REVERB_APP_SECRET=<generated per environment>
REVERB_HOST=0.0.0.0
REVERB_PORT=8080
REVERB_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="<public hostname>"
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https

FORECAST_PYTHON_BIN=/var/www/app/python/forecasting/.venv/bin/python
FORECAST_PROCESS_TIMEOUT=600
```

### Safe to leave at defaults

Everything else in `config/*.php` has sensible defaults. The notable application-specific configs that are env-overridable:

```
FORECAST_PROCESS_TIMEOUT=600                 # seconds; upper bound on Python subprocess
FORECAST_MAX_CONCURRENT_PER_TENANT=2         # per-tenant concurrency cap on forecasting jobs
INGESTION_CSV_MAX_UPLOAD_MB=50               # hard limit on CSV upload size
INGESTION_SHOPIFY_DEFAULT_LOOKBACK_MONTHS=24 # initial load window
```

If the deployment team needs to tune forecasting behaviour under load, these are the levers.

---

## Deploy-Time Steps

The deployment team runs these on every deploy. Order matters.

1. **Pull code**
2. **Install PHP dependencies:** `composer install --no-dev --optimize-autoloader`
3. **Install/update Python venv:** if `python/forecasting/requirements.txt` changed since last deploy, `python3.11 -m venv python/forecasting/.venv && python/forecasting/.venv/bin/pip install -r python/forecasting/requirements.txt`
4. **Install Node deps and build frontend:** `npm ci && npm run build`
5. **Run migrations:** `php artisan migrate --force`
6. **Clear and warm caches:** `php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache`
7. **Restart queue workers:** `php artisan horizon:terminate` (supervisor auto-restarts)
8. **Restart Reverb:** supervisor restart command for the reverb process
9. **Reload PHP-FPM** so it picks up new opcache
10. **Smoke test:** hit `/up` health endpoint; expect 200

If step 3 is skipped when Python deps changed, the forecasting tier will fail at runtime with import errors. The deploy pipeline must detect requirements.txt changes and run the pip install step.

---

## Health Checks

Two endpoints for the infra team's monitoring:

- **`GET /up`** — Laravel's built-in health endpoint. Returns 200 if the web tier is up. Does not check database or queues.
- **`GET /up/deep`** — application-level deep check. Verifies: DB connection, Redis connection, Python venv executable exists, last successful engine run within 26 hours, Horizon status is `running`. Returns 200 if all healthy, 503 otherwise with JSON body listing failed checks.

Configure the load balancer to use `/up`. Configure external uptime monitoring (the team's tool of choice) to use `/up/deep` at lower frequency (every 5 minutes) so deep-check failures produce alerts.

---

## First-Deploy Checklist

One-time steps the deployment team runs on the very first deploy of a new environment:

- [ ] Generate `APP_KEY` via `php artisan key:generate` on the host (never reuse from another environment)
- [ ] Generate Reverb credentials (`REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`) — unique per environment
- [ ] Seed initial tenant and owner user via the onboarding seeder (not the synthetic demo seeder — see `INVENTORY_ENGINE.md` → Seeder Instructions)
- [ ] Seed regional holidays for current year
- [ ] Seed per-category forecasting thresholds (`ForecastSettingsSeeder`)
- [ ] Confirm Python venv built and `python/forecasting/.venv/bin/python --version` prints 3.11+
- [ ] Run `php artisan ingestion:csv` dry-run against a sample file to verify the import path works end-to-end
- [ ] Trigger a manual engine run and verify a recommendation is produced
- [ ] Trigger a manual forecast job for one SKU and verify a `forecast_model_registry` row is written
- [ ] Verify WebSocket connection from the frontend succeeds over WSS

---

## Known Constraints

1. **Python and PHP share the host.** The current architecture runs Python as a subprocess of the PHP queue worker. Splitting them across hosts is a non-trivial change (network transport instead of stdin/stdout, different failure modes). If the deployment team wants to separate them, raise it before implementation — it affects the `RunForecastJob` contract.

2. **Horizon does not distribute across hosts out of the box.** Multi-host queue processing requires configuring Redis as the shared backend (already the case) and running Horizon on each host with distinct supervisors configs. Scale-out is possible but requires a small configuration change; design is ready for it.

3. **Reverb is single-node by default.** For horizontal scaling, enable Reverb's scaling mode with a shared Redis backend. Single node is sufficient for the current client.

4. **Managed MySQL vs self-hosted.** Application does not care which. Managed is strongly recommended — backups, failover, and patching are handled by the provider. If self-hosted, the infra team owns the backup strategy.
