# ARCHITECTURE.md — Inventory Decision Engine

## System Overview

The engine takes stock data + sales history as input and outputs **per-SKU recommendations** — not just forecasts. It is a recommendation and tracking system, not a procurement platform (see `INVENTORY_ENGINE.md` → System Scope).

```
┌─────────────────────────────────────────────────────────────┐
│                      DECISION ENGINE                         │
│                                                              │
│  [Sales History]   ──► DemandForecaster                     │
│                         (reads demand_rate from ML registry) │
│  [Stock Levels]    ──► InventoryPositionTracker              │
│  [Supplier Data]   ──► LeadTimeHandler                      │
│  [Budget / MOQ]    ──► ConstraintEngine                     │
│  [ABC/XYZ Class]   ──► AbcXyzClassifier                     │
│                              │                               │
│                              ▼                               │
│                        DecisionScorer                        │
│                              │                               │
│                              ▼                               │
│               ORDER NOW  |  WATCH  |  HOLD                   │
│                   + Recommended Quantity                      │
│                              │                               │
│                              ▼                               │
│         inventory_decisions (status = PENDING)               │
│                              │                               │
│                              ▼                               │
│    User action tracking: ACKNOWLEDGED / ORDERED /            │
│    RECEIVED / IGNORED / SUPERSEDED                           │
│                              │                               │
│                              ▼                               │
│         Feedback loop → forecasting tier + engine cal        │
└─────────────────────────────────────────────────────────────┘

Separate async flow (Python microservice via Laravel Job):
┌─────────────────────────────────────────────────────────────┐
│                  ML FORECASTING TIER                         │
│                                                              │
│  [Sales History] ──► 15-stage pipeline (see FORECASTING_ENGINE.md)
│                              │                               │
│                              ▼                               │
│              forecast_model_registry (MySQL)                 │
│                              │                               │
│                              ▼                               │
│           DemandForecaster reads demand_rate from here       │
└─────────────────────────────────────────────────────────────┘
```

---

## Module Specs

### 1. DemandForecaster

**Role:** Supply the expected demand rate for the reorder horizon.
**Input:** `sku_id`, `tenant_id`
**Output:** `ForecastResult { demand_rate, model_used, confidence_interval, forecast_horizon_days }`

**How it works:**
- Reads `demand_rate` from `forecast_model_registry` for the given SKU/tenant
- If no registry entry exists yet (new SKU, first run): falls back to weighted moving average (60% current year, 30% last year, 10% two years ago) as a safe default
- Does NOT compute ML models inline — that is the Python microservice's job
- Demand rate used for: reorder point calculation, days-of-cover, safety stock sizing

**Key method:** `forecast(sku_id, tenant_id): ForecastResult`

---

### 2. InventoryPositionTracker

**Role:** Know exactly where stock stands right now.
**Input:** On-hand quantity, in-transit (recommendations in `ORDERED` status), reserved (committed to open orders)
**Output:** `InventoryPosition { effective_position, days_of_cover }`

**Formula:**
```
Effective Position = On-hand + In-transit − Reserved
Days of Cover      = Effective Position ÷ Average Daily Demand
```

In-transit is computed from `inventory_decisions` where `status IN ('ORDERED', 'IN_TRANSIT')`, summing `ordered_qty`. `RECEIVED` rows are already reflected in on-hand.

**Key method:** `getPosition(sku_id, tenant_id): InventoryPosition`

---

### 3. LeadTimeHandler

**Role:** Model supplier unreliability.
**Input:** Historical lead times per supplier (derived from `ORDERED → RECEIVED` timestamps on `inventory_decisions`), current order dates
**Output:** `LeadTimeEstimate { expected_days, buffer_days, method_used }`

**Auto-selection logic:**
- If `count(historical_lead_times) >= 10` AND distribution is right-skewed: use **P90 method**
- Otherwise: use **mean + 1 standard deviation buffer**
- If history is sparse (< 5 data points): fallback to `supplier.stated_lead_time × 1.3`

**Key method:** `getLeadTimeWithBuffer(supplier_id): LeadTimeEstimate`

---

### 4. ConstraintEngine

**Role:** Apply real-world order constraints before any recommendation is made.
**Input:** Recommended quantity (raw), MOQ per SKU, budget remaining, warehouse capacity
**Output:** `ConstrainedQuantity { adjusted_qty, constraints_applied[], blocked_reason? }`

**Constraint priority (in order):**
1. Budget cap (hard — never exceed)
2. Warehouse capacity (hard — never exceed)
3. MOQ (hard — round up to nearest MOQ, never go below)
4. Max order quantity (soft — warn but allow override)

**Special output:** If budget blocks a critical ORDER NOW, emit `ORDER NOW — BUDGET BLOCKED` rather than silently downgrading to WATCH.

**Key method:** `applyConstraints(sku_id, raw_qty, budget_remaining, tenant_id): ConstrainedQuantity`

---

### 5. DecisionScorer

**Role:** Combine all signals into a final human-readable recommendation.
**Input:** Outputs from all four modules above
**Output:** `Decision { label, recommended_qty, constrained_qty, reasoning, days_of_cover, reorder_point }`

**Scoring logic:**
```
Reorder Point = (Avg Daily Demand × Lead Time) + Safety Stock

If Effective Position ≤ Reorder Point:
    → ORDER NOW (quantity = reorder quantity after constraints)

Elif Effective Position ≤ Reorder Point × 1.3:
    → WATCH (approaching reorder point, monitor daily)

Else:
    → HOLD (sufficient stock)
```

**Safety stock formula:**
```
Safety Stock = Z × σ_demand × √Lead Time × popularity_multiplier
             (Z = 1.65 for 95% service level, configurable)
             (popularity_multiplier from AbcXyzClassifier)
```

**Safety stock uses baseline variability only.** Promotional spikes are handled via the promotional calendar in DemandForecaster, not folded into σ_demand.

**Key method:** `score(sku_id, position, forecast, lead_time, constraints, classifier_result): Decision`

---

### 6. AbcXyzClassifier

**Role:** Classify each SKU by value (ABC) and demand variability (XYZ). Output feeds the safety stock multiplier.
**Input:** Sales history, unit cost per SKU
**Output:** `ClassificationResult { abc_class, xyz_class, safety_stock_multiplier }`

**Popularity multiplier logic (60/40 weighted score):**
- 60% weight: sales frequency (how often the SKU sells in a period)
- 40% weight: stockout frequency (how often the SKU has hit zero stock)
- Combined score maps to a multiplier applied to safety stock

**Key method:** `classify(sku_id, tenant_id): ClassificationResult`

---

### 7. InventoryEngineService (Orchestrator)

**Role:** Coordinate all modules, run for all SKUs in a tenant, persist results, supersede stale recommendations.
**Called by:** `RunInventoryEngineJob` dispatched on schedule or on-demand.

**Flow:**
```php
$classifier->classifyAll($tenant_id);  // pre-run classification

foreach ($skus as $sku) {
    $forecast    = $this->forecaster->forecast($sku->id, $tenant_id);
    $position    = $this->tracker->getPosition($sku->id, $tenant_id);
    $leadTime    = $this->leadTimeHandler->getLeadTimeWithBuffer($sku->supplier_id);
    $constrained = $this->constraints->applyConstraints($sku->id, $rawQty, $budget, $tenant_id);
    $classResult = $this->classifier->classify($sku->id, $tenant_id);
    $decision    = $this->scorer->score(...);

    // Supersede any PENDING/ACKNOWLEDGED decisions for this SKU
    $this->supersedeStaleDecisions($sku->id, $tenant_id, newDecisionId: $decision->id);

    // Persist EngineRun / InventoryDecision (status = PENDING)
}

// Bias drift check (excludes stockout-contaminated dates)
$this->checkBiasDriftAndDispatchForecast($tenant_id);

StockAlertEvent::dispatch() for ORDER NOW decisions;
```

---

## Recommendation Status Lifecycle

Full spec in `INVENTORY_ENGINE.md` → Recommendation Action Tracking. Architectural notes:

- `inventory_decisions.status` is **not** in `$fillable`. All transitions go through `DecisionStatusService::transition()` which validates, stamps audit columns, and appends to `status_history` JSON.
- Terminal statuses: `RECEIVED`, `IGNORED`, `SUPERSEDED` — no further transitions allowed.
- Engine-set transitions (`SUPERSEDED`) set `status_changed_by = null`.
- `RECEIVED` triggers a stock reconciliation hook that updates `skus.current_stock` and records the actual lead time into supplier history (feeding `LeadTimeHandler`).

---

## Feedback Loop Infrastructure

See `INVENTORY_ENGINE.md` → Recommendation Feedback Loop for the business logic. Infrastructure pieces:

- **`recommendation:analyze-feedback`** artisan command, scheduled **weekly**. Reads aggregated action-tracking data from `inventory_decisions`, computes the four feedback signals (IGNORED reasons, ordered-vs-recommended delta, received-vs-ordered delta, SUPERSEDED rate), writes results to diagnostic storage.
- **`feedback_drift`** re-evaluation trigger — fourth trigger alongside `anomaly_flag`, `bias_drift`, `new_sku`, `scheduled`. Dispatched by the feedback analysis job when per-SKU trust calibration exceeds threshold.
- **Diagnostic outputs** land in `storage/app/forecasting/reports/feedback/{tenant_id}/{YYYY-MM-DD}_summary.json`. Not client-facing; for engineer inspection and engine calibration.

---

## Database Schema

### Core Tables (all carry tenant_id)

```sql
tenants
  id, name, locale, currency, created_at, updated_at

skus
  id, tenant_id, name, sku_code, category, supplier_id, moq, unit_cost
  reorder_qty, current_stock, in_transit_qty, reserved_qty
  lead_time_days, abc_class, xyz_class, deleted_at

suppliers
  id, tenant_id, name, avg_lead_time_days, lead_time_stddev
  stated_lead_time_days, deleted_at

sales_history
  id, tenant_id, sku_id, sale_date, quantity_sold, is_promotion

inventory_decisions
  id, tenant_id, sku_id, engine_run_id, run_at

  -- Recommendation output
  decision (enum: order_now / watch / hold / order_now_budget_blocked)
  recommended_qty, constrained_qty, reasoning (json)
  forecast_demand, days_of_cover, reorder_point, safety_stock

  -- Status lifecycle
  status (enum: pending / acknowledged / ordered / in_transit / received / ignored / superseded)
  status_changed_at, status_changed_by (FK users nullable)
  status_history (json — append-only log)

  -- Action tracking
  ordered_qty (int nullable)
  ordered_at (timestamp nullable)
  expected_arrival (date nullable)
  supplier_id (FK suppliers nullable)
  received_qty (int nullable)
  received_at (timestamp nullable)
  ignored_reason (string nullable)
  superseded_by_decision_id (FK self nullable)

engine_runs
  id, tenant_id, status (enum: running/completed/failed)
  decisions_count, duration_ms, started_at, completed_at
```

Note: the `purchase_orders` table has been removed. Its role is absorbed into `inventory_decisions` status lifecycle. The system does not manage POs — it tracks recommendation outcomes.

### ML Forecasting Tables

```sql
sku_demand_profiles
  id, tenant_id, sku_id
  volume_tier (enum: high/medium/low)
  volatility (enum: stable/moderate/erratic)
  intermittency (enum: continuous/intermittent)
  seasonality_detected (boolean)
  trend_direction (enum: upward/flat/declining)
  classified_at, history_days_used

forecast_model_registry
  id, tenant_id, sku_id
  model_name (e.g. holt_winters, sarimax, prophet, lightgbm, croston, ets_fallback)
  demand_rate (decimal — the value DemandForecaster reads)
  forecast_horizon_days
  mae, rmse, bias, smape
  interval_lower, interval_upper, interval_confidence, interval_empirical_coverage
  transformation_applied (string)
  hyperparameters (json)
  selection_rationale (text)
  trained_at, next_review_at
  reeval_trigger (enum: scheduled / anomaly_flag / bias_drift / new_sku / feedback_drift)
  warnings (json)
```

### Promotional Calendar Tables

```sql
promotions
  id, tenant_id
  name (string)
  promotion_type (enum: seasonal / flash / clearance / bundle / other — nullable)
  start_date, end_date
  expected_uplift_pct (decimal — e.g. 40 for 40% uplift)
  affects_all_skus (boolean)
  applies_to_categories (json nullable — array of category strings when targeting by category,
                          e.g. ["equipment","accessory"]; empty/null when targeting all or specific SKUs)
  created_at, updated_at

-- Targeting logic:
--   affects_all_skus = true                         → all SKUs
--   affects_all_skus = false, applies_to_categories not empty → category targeting
--     (promotion_skus pivot is populated at save time by resolving matching SKU IDs)
--   affects_all_skus = false, applies_to_categories empty    → specific SKU targeting

promotion_skus
  id, promotion_id, sku_id
  (pivot — when affects_all_skus = false; also populated for category-targeting promotions)

regional_holidays
  id, country_code, holiday_name, holiday_date, year
  default_uplift_pct
  (not tenant-scoped — shared reference table, seeded annually)
```

### Data Ingestion Tables

```sql
data_ingestion_runs
  id, tenant_id, source (enum: csv_upload / shopify / woocommerce / salla / manual)
  importer (string — SkuImporter, SalesHistoryImporter, etc.)
  status (enum: running / completed / failed / partial)
  rows_processed, rows_succeeded, rows_failed
  error_log (json)
  started_at, completed_at
```

Full spec in `docs/DATA_INGESTION.md`.

### Settings Table

```sql
system_settings
  id, tenant_id, key, value (json), updated_at

-- Keys used by forecasting:
-- forecasting_thresholds.equipment.min_history_days         = 120
-- forecasting_thresholds.equipment.seasonality_min_days     = 365
-- forecasting_thresholds.equipment.intermittency_threshold  = 0.30
-- forecasting_thresholds.accessories.min_history_days      = 90
-- forecasting_thresholds.accessories.seasonality_min_days  = 365
-- forecasting_thresholds.accessories.intermittency_threshold = 0.20
-- forecasting_thresholds.bundles.min_history_days          = 90
-- forecasting_thresholds.bundles.seasonality_min_days      = 365
-- forecasting_thresholds.bundles.intermittency_threshold   = 0.25
-- forecasting.model_reeval_interval_days                   = 30
-- forecasting.bias_drift_threshold_pct                     = 15
-- forecasting.feedback_drift_threshold_pct                 = 20
```

---

## Inertia Page Map

| Route | Page Component | Description |
|---|---|---|
| `/dashboard` | `Dashboard/Index.vue` | Overview: recommendations summary, alerts, WMAPE card |
| `/skus` | `SKUs/Index.vue` | SKU list with current recommendation badges |
| `/skus/{id}` | `SKUs/Show.vue` | SKU detail: history, forecasts, recommendation timeline |
| `/engine/run` | (POST, no page) | Trigger engine run → dispatches Job |
| `/recommendations/{id}/transition` | (POST, no page) | Status transition endpoint |
| `/promotions` | `Promotions/Index.vue` | Client-managed promotional calendar |
| `/reports` | `Reports/Index.vue` | Per-SKU forecast model outcomes |
| `/ingestion` | `Ingestion/Index.vue` | CSV upload + Shopify connector management |
| `/settings` | `Settings/Index.vue` | Suppliers, budget, constraints, forecast thresholds |

---

## Real-Time Alerts (Reverb)

When engine runs and finds `ORDER NOW` recommendations:
1. `InventoryEngineService` fires `StockAlertEvent`
2. Reverb broadcasts to authenticated user's private channel (`inventory-alerts`)
3. `NotificationBell.vue` shows dropdown with ORDER NOW alerts without page refresh

---

## Queue Setup (Horizon)

Three queues:
- `default` — general application jobs
- `inventory` — `RunInventoryEngineJob` (daily schedule + on-demand)
- `forecasting` — `RunForecastJob` (per-SKU, event-driven triggers); workers require higher memory limits and longer timeouts

`RunForecastJob` runs on the `forecasting` queue so it never blocks engine runs on `inventory`.

---

## Python Forecasting Microservice

**Entry point:** `python/forecasting/main.py`
**Called by:** `RunForecastJob` via `Process::run()` with `FORECAST_PROCESS_TIMEOUT` (default 600s, from `config/forecasting.php`)
**Temp file path:** `storage/app/forecasting/tmp/{tenant_id}/{uuid}.json` — UUID only, no user-supplied path components
**Input schema:** see `docs/FORECASTING_ENGINE.md` → Input JSON Format
**Output:** JSON to stdout — Laravel parses and writes to `forecast_model_registry`

The Python microservice runs a 15-stage pipeline. Full spec in `docs/FORECASTING_ENGINE.md`.

Temp file cleanup: scheduled command removes orphan files older than 24h.

---

## Deployment Architecture

The system is deployed online with a subdomain, not locally. Full deployment prerequisites for the infrastructure team are in `docs/DEPLOYMENT_PREREQUISITES.md`. Architectural implications captured here:

**Python runtime on host**
- Python 3.11+ installed on the application server
- `python/forecasting/requirements.txt` pinned and installed in a venv
- A setup script provisions the venv on deploy
- `FORECAST_PROCESS_TIMEOUT` and other forecasting config are env-overridable

**Queue workers as daemons**
- `php artisan horizon` runs under a process supervisor (systemd or supervisor)
- Workers: `default`, `inventory`, `forecasting` — `forecasting` gets higher memory and timeout
- Worker restarts must not leave orphan Python subprocesses (subprocess cleanup on SIGTERM)

**Reverb WebSocket server**
- Own long-running process under supervisor
- Reverse-proxy rule with `Upgrade` / `Connection` headers for WebSocket
- `VITE_REVERB_HOST` points to public hostname over WSS

**Temp file handling**
- All temp files under `storage/app/forecasting/tmp/{tenant_id}/{uuid}.json`
- Paths constructed from UUID only — never from user-supplied strings
- Scheduled cleanup for orphans > 24h

**Database**
- Managed MySQL (not the dev MySQL used in development)
- Reports queries must be replica-safe (no writes mid-query) in case a read replica is added later

**Tenant isolation — pre-deployment audit**
- Every query touching `skus`, `sales_history`, `inventory_decisions`, `promotions`, `forecast_model_registry`, `sku_demand_profiles`, `engine_runs`, `data_ingestion_runs`, `system_settings` must respect `TenantScope`
- Any raw query bypassing the global scope must explicitly filter by `tenant_id`
- A single missing scope in production = cross-tenant data leak. This is a pre-deployment checklist item, not an afterthought.

---

## Security Baseline — Revisit Before Production

This is the minimum security posture for the application layer. A dedicated security pass is planned before production (tracked in `docs/TODO_PRE_PRODUCTION.md`).

**Rate limiting**
- Engine run endpoint: 5 requests/hour/user
- Forecast sweep endpoint: 1 request/hour/tenant
- Status transition endpoint: 60/minute/user (high enough for normal use, low enough to catch loops)
- Data ingestion upload endpoint: 10/hour/tenant

**Mass assignment**
- All models audited for `$fillable`
- `inventory_decisions.status` is **not** in `$fillable` — status changes go through `DecisionStatusService`
- `forecast_model_registry.*` — registry is internal; no direct user input paths
- `system_settings.value` — owner-only write via a dedicated controller

**Authorization**
- Every controller action uses policies or `authorize()`
- No exceptions; audited as part of pre-deployment checklist

**Python input validation**
- `main.py` validates input JSON schema before any pipeline stage runs
- Malformed input → exit code 1 with clear error to stdout; no partial execution

**Temp file path safety**
- Path construction uses UUIDs only
- Laravel job validates that the generated path resolves under `storage/app/forecasting/tmp/{tenant_id}/` before handing to Python (path traversal guard)

**Credential storage**
- Shopify API keys, other connector credentials stored encrypted at rest using Laravel's `encrypted` cast
- Never logged
- Never included in error output sent to the frontend

**Rate-limiting the Python subprocess**
- Per-tenant concurrency cap on `RunForecastJob` (default: 2 concurrent jobs per tenant)
- Prevents a bad input or runaway tuning loop from exhausting host resources

---

## Multi-Tenant Foundation

Already in place: `tenants` table; `tenant_id` on all core tables; `TenantScope` global Eloquent scope. Current client = `tenant_id = 1`.

**Tenant-scoped by global scope:**
`skus`, `sales_history`, `inventory_decisions`, `promotions`, `promotion_skus`, `forecast_model_registry`, `sku_demand_profiles`, `engine_runs`, `data_ingestion_runs`, `system_settings`.

**Not tenant-scoped (shared reference):**
`regional_holidays`.

**Tenant resolution:**
From authenticated user → `user.tenant_id`. Jobs and scheduled commands pass `tenant_id` explicitly; they must not rely on request context.
