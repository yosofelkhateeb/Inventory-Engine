# FORECASTING_ENGINE.md — ML Forecasting Tier Specification

> Read this file before touching `DemandForecaster.php`, `RunForecastJob.php`, or anything in `python/forecasting/`.

---

## Overview

The ML forecasting tier is a **Python microservice** invoked asynchronously from a Laravel Job. It is fully decoupled from the PHP decision engine. The engine consumes only `demand_rate` from the `forecast_model_registry` table — it does not know or care which model produced it.

**Do not run forecasting inline.** It runs as a background job on the `forecasting` queue so it never blocks engine runs on the `inventory` queue.

**All pipeline parameters are externalised** to `python/forecasting/config/forecasting_config.yaml`. CV fold counts, window sizes, tuning budgets, interval coverage targets, drift thresholds, and diagnostic cutoffs all live in the YAML. No magic numbers in Python source. Update the YAML, not the code.

---

## Architecture

```
RunForecastJob (PHP)
    │
    ├─ reads sku_id + tenant_id + sales history + promotions + holidays from DB
    ├─ writes temp JSON to storage/app/forecasting/tmp/{tenant_id}/{uuid}.json
    ▼
Process::run('python python/forecasting/main.py --input <path>')
    │                             timeout: FORECAST_PROCESS_TIMEOUT (default 600s)
    ▼
Python: main.py (orchestrates the 15-stage pipeline)
    │
    ├─ pipeline/data_audit.py           → timestamps, gap classification, spike flagging
    ├─ pipeline/preprocessing.py        → stockout imputation, outlier capping, baseline/full split
    ├─ classifier.py                    → demand profile (volume / volatility / intermittency / seasonality / trend)
    ├─ pipeline/eda.py                  → trend, seasonality, structural breaks  (scheduled/new_sku only)
    ├─ pipeline/baselines.py            → naive, seasonal naive, MA, weighted historical
    ├─ pipeline/feature_engineering.py  → lag, rolling, calendar, event features  (leakage-asserted)
    ├─ models/*.py                      → candidate model training (shortlist from profile)
    ├─ pipeline/validation.py           → walk-forward CV + held-out 30-day test set
    ├─ pipeline/diagnostics.py          → residuals, Ljung-Box, period-specific MAE, directional accuracy
    ├─ pipeline/intervals.py            → prediction intervals + empirical coverage + correction factor
    ├─ selection.py                     → multi-criteria winner selection
    ├─ pipeline/monitoring.py           → distribution shift + staleness
    └─ registry.py                      → formats result JSON, writes to stdout
    │
    ▼
RunForecastJob (PHP)
    ├─ parses stdout JSON
    ├─ upserts forecast_model_registry (unique key: sku_id + tenant_id)
    ├─ upserts sku_demand_profiles
    └─ deletes temp input file
```

---

## Pipeline Stages

The pipeline has 15 stages. Stages are skipped when not applicable — EDA is skipped on `anomaly_flag` and `bias_drift` triggers; model competition is skipped when history is below `min_history_days`.

### Stage 1 — Input parsing & schema validation (`main.py`)

- Parses `--input` JSON path
- Validates schema (sku_id, tenant_id, sales_history shape, etc.)
- Rejects malformed input with exit code 1 before any downstream work

### Stage 2 — Data audit (`pipeline/data_audit.py`)

- Timestamp validation (parseable, timezone-consistent)
- Duplicate detection
- Continuous date index check
- Missing period classification:
  - `true_zero` — no sale occurred; legitimate zero demand
  - `stockout_gap` — inferred stockout from context (zero sales during promotion, or sustained zero after high-velocity period)
  - `reporting_gap` — row missing entirely (not a recorded zero)
- Spike flagging (z-score based, before promotion-aware outlier handling)
- Writes audit report to `python/forecasting/reports/{sku_id}_audit.json`

### Stage 3 — Preprocessing (`pipeline/preprocessing.py`)

- Complete the date index (fill `reporting_gap` with NaN before imputation)
- Stockout imputation: replace `stockout_gap` values with 7-day pre-stockout mean
- Forward-fill `reporting_gap` rows
- Promotion-aware outlier capping:
  - Spikes *during* promotion / holiday windows are **not capped**
  - Spikes *outside* promotion windows are capped at the 99th percentile of baseline days
- Split into two series:
  - **Baseline series** — promotion / holiday periods removed; used for feature engineering rolling stats and baseline fitting
  - **Full series** — everything retained; used for model training
- Non-negativity clamp on all imputed/capped values
- Imputed rows are flagged for exclusion from metric computation downstream

### Stage 4 — Classifier (`classifier.py`)

See **Tier 1 — SKU Demand Profile Classification** below.

### Stage 5 — EDA (`pipeline/eda.py`)

**Runs on `reeval_trigger in ['scheduled', 'new_sku']` only.** Skipped on `anomaly_flag` and `bias_drift` — these triggers have already identified the issue, re-running EDA adds no information and costs time.

- OLS trend estimate with significance test
- STL seasonal decomposition
- ACF / PACF inspection
- Variance stability check (rolling std ratio)
- Structural break detection
- Plain-language summary written to `reports/{sku_id}_eda.txt`

### Stage 6 — Baselines (`pipeline/baselines.py`)

Baselines compete against candidate models and must be beaten:

- Naive (yesterday's value)
- Seasonal naive (7-day lag)
- Moving average (7, 14, 30)
- Weighted historical average (60/30/10 across last three periods)

Evaluated on the **same CV folds** as candidate models. The best baseline becomes the floor the winning candidate must clear (enforced in Stage 13).

### Stage 7 — Feature engineering (`pipeline/feature_engineering.py`)

- Lag features (7, 14, 30 days)
- Rolling features (mean, std over 7 / 14 / 30 windows) — **computed on the baseline series** to prevent promotional spikes from leaking into rolling stats
- Calendar features (day-of-week, month, weekend, month-end)
- Event features: `is_promotion`, `is_holiday`, `is_ramadan`, `is_eid`, `days_since_last_promotion`, `days_until_next_promotion`, `promotion_type_encoded`
- **Leakage assertion**: the stage raises an exception and aborts the pipeline if any feature row is computed using data later than its target timestamp. This is a code bug if triggered — not a data problem — and must not silently continue.

### Stage 8 — Candidate model training (`models/`)

Candidate shortlist is determined by profile (see **Tier 2** below). Each candidate fits on the training window. Model-specific details in **Model Specifications** below.

### Stage 9 — Hyperparameter tuning

- **Holt-Winters** — α/β/γ grid search over ranges defined in YAML
- **SARIMAX** — `auto_arima` order selection + regressor parsimony test
- **LightGBM** — Optuna, 50 trials (configurable)
- **Prophet** — no tuning (default priors)
- **Croston, ETS fallback** — no tuning

Tuning uses CV folds only; the held-out 30-day test set remains untouched.

### Stage 10 — Validation (`pipeline/validation.py`)

- **Walk-forward expanding-window CV**, minimum 3 folds
- 30-day validation window per fold
- Final **held-out 30-day test set** kept untouched through training and tuning
- CV results feed tuning
- Test set is scored exactly once per candidate, used for final selection

### Stage 11 — Diagnostics (`pipeline/diagnostics.py`)

Computed per candidate on test-set residuals:

- Residual mean / std / skew
- Ljung-Box test for residual autocorrelation
- Seasonal residual pattern check
- Period-specific MAE breakdown: promotion vs non-promotion days; holiday vs non-holiday
- Directional accuracy (% of days where forecast and actual move the same direction)

Written to `reports/{sku_id}_diagnostics.json`.

Diagnostic failures **penalise** the model in Stage 13 selection — they do not disqualify outright. A model with Ljung-Box p < 0.05 gets a configurable penalty factor applied to its test MAE, which may flip the winner to a simpler runner-up.

### Stage 12 — Prediction intervals (`pipeline/intervals.py`)

Each candidate produces an interval alongside its point forecast:

- **Statistical models (ETS, Holt-Winters, SARIMAX, Prophet)** — native intervals from the library
- **LightGBM** — residual bootstrap (1000 samples from training residuals)
- **Croston, ETS fallback** — residual standard deviation proxy (`point ± z × σ_residuals`)

**Coverage validation**: on the held-out test set, compute the empirical coverage of the 80% interval (what fraction of actuals fell inside). If empirical coverage < target (default 80%), apply a correction factor that widens the interval until coverage meets the target. Both `interval_confidence` (target) and `interval_empirical_coverage` (observed after correction) are written to the registry.

### Stage 13 — Model selection (`selection.py`)

Multi-criteria selection, in order:

1. **Beat-the-baseline gate** — the candidate's test MAE must be lower than the best baseline's test MAE. If no candidate beats the best baseline, `ets_fallback` wins and `selection_rationale` records which baseline was the floor.
2. **Lowest test MAE** among eligible candidates.
3. **Bias tiebreaker** — if two candidates are within 5% MAE of each other, the one with lower `|bias|` wins.
4. **Diagnostic penalty** — candidates with failing Ljung-Box (p < 0.05) or poor directional accuracy get a configurable penalty multiplier applied to their MAE before re-ranking.
5. **Simplicity preference** — within 5% MAE after penalties, prefer simpler models in order: `ets_fallback > holt_winters > sarimax > prophet > lightgbm`.
6. **Runtime warning** — if the winner's training time exceeded `runtime_warning_seconds` (default 60), append a warning to `warnings[]` but do not disqualify.

A `selection_rationale` string is written to the registry explaining the outcome, e.g. `"holt_winters: beat seasonal_naive baseline by 18% test MAE; preferred over sarimax within 5% tolerance (simplicity)"`. This string is rendered on the Reports page verbatim.

### Stage 14 — Monitoring (`pipeline/monitoring.py`)

- **Distribution shift** — compare mean and std of the last 30 days vs the training window. If mean shift > 25% or std shift > 40%, append warning.
- **Model staleness** — if `trained_at` is older than 60 days, append warning. (The monthly scheduled sweep makes this rare; the flag catches sweep failures.)

### Stage 15 — Registry output (`registry.py`)

Formats the full output JSON (see **Output JSON Format** below) and writes to stdout. Laravel parses and upserts. Temp input file is deleted by Laravel after successful parse — Python does not touch the temp file.

---

## Tier 1 — SKU Demand Profile Classification

Before any model runs, classify the SKU. Classification gates which models compete.

### Classification Dimensions

**Volume Tier** (average daily units, trailing 90 days):
- `high`: > 5 units/day
- `medium`: 1–5 units/day
- `low`: < 1 unit/day

**Volatility** (CV = σ / μ, trailing 90 days, **baseline series only**):
- `stable`: CV < 0.3
- `moderate`: CV 0.3–0.6
- `erratic`: CV > 0.6

**Intermittency** (% of days with zero sales, trailing history):
- `continuous`: below category threshold
- `intermittent`: at or above category threshold

**Seasonality** (requires `seasonality_min_days` history):
- STL decomposition + ACF at seasonal lag
- `detected` / `none`

**Trend** (OLS slope over trailing 180 days):
- `upward` / `flat` / `declining` (by significance test)

### Category Thresholds

Read from `system_settings` table, seeded by `ForecastSettingsSeeder` for `tenant_id = 1`. Defaults:

| Setting Key | Equipment | Accessories | Bundles |
|---|---|---|---|
| `min_history_days` | 120 | 90 | 90 |
| `seasonality_min_days` | 365 | 365 | 365 |
| `intermittency_threshold` | 0.30 | 0.20 | 0.25 |
| `bias_drift_threshold_pct` | 15 | 15 | 15 |

If history is below `min_history_days`: skip classification and model competition entirely. Assign `ets_fallback` and set `next_review_at` = 30 days from now.

---

## Tier 2 — Candidate Model Shortlist

Profile dimensions map to a candidate shortlist. Do not run all models against all SKUs.

| Profile Match | Candidate Models |
|---|---|
| Intermittent (any volume) | Croston, ETS fallback |
| Low volume, continuous, no seasonality | Holt-Winters, ETS fallback |
| Low volume, continuous, seasonality detected | Holt-Winters, Prophet |
| Medium/High volume, stable, no seasonality | Holt-Winters, SARIMAX |
| Medium/High volume, stable, seasonality detected | Holt-Winters, SARIMAX, Prophet |
| Medium/High volume, erratic | SARIMAX, LightGBM |
| Any volume, erratic, seasonality detected | SARIMAX, LightGBM, Prophet |

**Croston** is always in the shortlist for intermittent SKUs.

**Baselines and `ets_fallback`** are always in the competition regardless of profile. Stage 13 enforces the beat-the-baseline gate.

---

## Model Specifications

### Holt-Winters (Triple Exponential Smoothing)
- Library: `statsmodels.tsa.holtwinters.ExponentialSmoothing`
- Parameters: additive trend; additive or multiplicative seasonality (selected by AIC); seasonal period = 7
- Tuning: α/β/γ grid search
- Handles: level + trend + seasonality

### SARIMAX (Seasonal ARIMA with Exogenous Variables)
- Library: `pmdarima.auto_arima` for order selection, `statsmodels.tsa.statespace.SARIMAX` for fitting
- Exogenous: promotional calendar indicator, holiday indicator
- **Regressor parsimony test** — drop exogenous columns that fail significance to avoid overfitting
- Handles: autocorrelation, seasonality, exogenous events

### Prophet
- Library: `prophet`
- Configuration: Saudi national holidays passed as `holidays` dataframe from `regional_holidays` table; client promotions added as additional regressors
- **Optional** — skip gracefully if not installed; warning logged, candidate removed from shortlist
- Handles: complex seasonality, changepoints, named holiday effects

### LightGBM
- Library: `lightgbm`
- Features: full matrix from Stage 7
- Target: next-day demand; iterated for multi-step forecasting
- Tuning: Optuna, 50 trials (configurable)
- Feature importance written to `reports/{sku_id}_lightgbm_importance.json`
- **Optional** — skip gracefully if not installed
- Handles: non-linear feature interactions

### Croston's Method
- Manual implementation (statsmodels' behavior differs across versions)
- Handles: sporadic demand; standard ETS and ARIMA perform poorly here

### ETS Fallback
- Library: `statsmodels.tsa.holtwinters.SimpleExpSmoothing`
- Always available; always in the competition
- Assigned directly when history < `min_history_days`

---

## Evaluation Metrics

Computed on the **held-out 30-day test set** (untouched through training and tuning). CV folds are used for tuning and diagnostics; the test set is scored once for selection.

**Imputed rows are excluded from all metric computation.** Stockout-imputed days are flagged in Stage 3 and dropped from metric denominators.

### Primary selection metric — MAE
```
MAE = mean(|actual - forecast|)
```
Robust to promotional outliers; interpretable in units/day.

### Secondary diagnostic — RMSE
```
RMSE = sqrt(mean((actual - forecast)²))
```
Not used for selection. If RMSE >> MAE for the winner, log warning (occasional large misses).

### Mandatory operational metric — forecast bias
```
Bias = mean(forecast - actual)
```
Computed trailing 30 days from operational data (`inventory_decisions.forecast_demand` vs `sales_history`), **excluding stockout-contaminated dates** (days where stock-on-hand was zero). Drives the `bias_drift` re-evaluation trigger at `|Bias| > bias_drift_threshold_pct` (default 15%).

### Cross-SKU reporting — sMAPE
```
sMAPE = mean(2 × |actual - forecast| / (|actual| + |forecast|)) × 100
```
More stable than MAPE when zero-sales days exist.

### Portfolio reporting — WMAPE
```
WMAPE = sum(|actual - forecast|) / sum(|actual|) × 100
```
Tenant-level, trailing 30-day operational window. Rendered on the Dashboard WMAPE card.

### Metrics NOT used
- **Standard MAPE** — zero-sales division errors, inflated scores
- **F1 / Accuracy** — classification metrics, not applicable to continuous demand

---

## Tier 3 — Re-evaluation Triggers

Event-driven. Do not re-evaluate all SKUs on a fixed daily/weekly schedule.

**Trigger 1 — Anomaly flag** (`reeval_trigger = 'anomaly_flag'`)
Tier 2 confirmed alert from the two-tier anomaly system dispatches `RunForecastJob`. EDA skipped.

**Trigger 2 — Bias drift** (`reeval_trigger = 'bias_drift'`)
After each engine run, `InventoryEngineService::run()` computes trailing 30-day operational bias per SKU, **excluding stockout-contaminated dates**. If `|Bias| > bias_drift_threshold_pct`, dispatch `RunForecastJob`. EDA skipped.

**Trigger 3 — New SKU** (`reeval_trigger = 'new_sku'`)
`SkuObserver` dispatches on SKU creation. If history is below threshold, assigns `ets_fallback` and sets `next_review_at` = 30 days. EDA runs.

**Trigger 4 — Scheduled monthly sweep** (`reeval_trigger = 'scheduled'`)
`forecast:sweep` artisan command, registered monthly in the console kernel. Iterates all active SKUs per tenant regardless of trigger conditions. Safety net for slow drift. EDA runs.

---

## Backtesting — Backend Only, No Client UI

**Backtesting is an internal model-selection concern. It is not surfaced to the client and has no Inertia page.**

The pipeline's walk-forward CV (Stage 10) and held-out test evaluation (Stages 10, 13) are the backtesting mechanism. Every `RunForecastJob` invocation performs a backtest as part of selection — this is how candidate models compete.

- Fold-level and test-set MAE / RMSE / bias are stored in `forecast_model_registry` (primary fields + `selection_rationale` + `warnings`)
- Per-SKU diagnostic reports are written to `python/forecasting/reports/` for engineer inspection, not client consumption
- The Reports Inertia page surfaces the **outcome** of selection (winning model, metrics, rationale) — never historical what-if comparisons, never fold-by-fold timelines, never candidate-vs-candidate charts

Do not build a "backtest dashboard" or client-facing historical comparison. If product thinking drifts in that direction, revisit this section and justify the exception explicitly.

---

## Forecast Output for UI Consumption

The Python pipeline writes statistical quantities to the registry. It is the **Laravel layer's** job to translate those into human-readable strings for the frontend. This keeps statistical logic in Python and gives Vue dumb fields to render.

Computed server-side in Laravel (model accessor or dedicated service) from `forecast_model_registry` values:

### `human_readable_forecast` — plain-language point and interval
- Template: `"Expected ~{point} {units} per {period} (likely between {lower} and {upper})"`
- `point` = `demand_rate × horizon_days`, rounded to nearest integer
- `lower` / `upper` from `interval_lower` / `interval_upper`, scaled to the same horizon
- `period` configurable (default: week)
- `units` from the SKU's unit label (default: units)

### `confidence_label` — `high` / `medium` / `low`
Derived from `interval_empirical_coverage` vs `interval_confidence` target and interval width relative to the point forecast:

| Condition | Label |
|---|---|
| `empirical_coverage ≥ target` **AND** interval width < 50% of point | `high` |
| `empirical_coverage ≥ target` **AND** interval width 50–100% of point | `medium` |
| `empirical_coverage ≥ target` **AND** interval width > 100% of point | `low` |
| `empirical_coverage < target` | `low` |

Both fields are exposed via Eloquent accessors on the `ForecastModelRegistry` model, or computed in a dedicated `ForecastPresenter` service. The Vue layer renders them literally — **no statistical interpretation in the frontend.**

---

## Python File Structure

```
python/
  forecasting/
    main.py                       ← entry point; orchestrates the 15-stage pipeline
    classifier.py                 ← demand profile classification (Stage 4)
    evaluator.py                  ← metric computation; excludes imputed rows
    selection.py                  ← multi-criteria winner selection (Stage 13)
    registry.py                   ← output JSON formatter (Stage 15)
    requirements.txt              ← pinned versions; used by deployment
    README.md                     ← setup, Python version (3.11+), standalone test instructions
    config/
      forecasting_config.yaml     ← ALL pipeline parameters (CV folds, windows, tuning budgets, thresholds, penalties)
    pipeline/
      data_audit.py               ← Stage 2
      preprocessing.py            ← Stage 3
      eda.py                      ← Stage 5  (scheduled / new_sku only)
      baselines.py                ← Stage 6
      feature_engineering.py      ← Stage 7  (leakage-asserted)
      validation.py               ← Stage 10
      diagnostics.py              ← Stage 11
      intervals.py                ← Stage 12
      monitoring.py               ← Stage 14
    models/
      ets_fallback.py
      holt_winters.py             ← includes tune() for α/β/γ grid search
      sarimax.py
      prophet_model.py            ← try/except import; skips gracefully if not installed
      lightgbm_model.py           ← try/except import; skips gracefully if not installed
      croston.py
    reports/                      ← per-SKU audit, EDA, diagnostics, feature importance outputs
```

---

## RunForecastJob (PHP) Responsibilities

```php
class RunForecastJob implements ShouldQueue
{
    public string $queue = 'forecasting';
    public int $tries = 3;
    public int $timeout; // from config('forecasting.process_timeout'), default 600s

    public function handle(): void
    {
        // 1. Load sales history + promotions + regional holidays for sku + tenant
        // 2. Write temp JSON to storage/app/forecasting/tmp/{tenant_id}/{uuid}.json
        //    Path uses UUID only — no user-supplied strings
        // 3. Process::run('python python/forecasting/main.py --input {path}')
        //    with FORECAST_PROCESS_TIMEOUT from config/forecasting.php
        // 4. Non-zero exit code → log stderr, fail job (retries up to 3×)
        // 5. Parse JSON stdout
        // 6. Upsert forecast_model_registry (unique: sku_id + tenant_id)
        // 7. Upsert sku_demand_profiles
        // 8. Delete temp file
    }
}
```

A scheduled cleanup command removes orphan temp files older than 24 hours.

---

## Input JSON Format (Laravel → Python)

```json
{
  "sku_id": 3,
  "tenant_id": 1,
  "sku_category": "equipment",
  "reeval_trigger": "anomaly_flag",
  "thresholds": {
    "min_history_days": 120,
    "seasonality_min_days": 365,
    "intermittency_threshold": 0.30
  },
  "sales_history": [
    { "date": "2025-04-01", "quantity_sold": 1, "is_promotion": false },
    { "date": "2025-04-02", "quantity_sold": 0, "is_promotion": false }
  ],
  "promotions": [
    { "start_date": "2025-12-20", "end_date": "2025-12-31", "uplift_pct": 40 }
  ],
  "regional_holidays": [
    { "date": "2025-04-21", "name": "Eid Al-Fitr", "uplift_pct": 35 }
  ]
}
```

---

## Output JSON Format (Python → Laravel)

```json
{
  "sku_id": 3,
  "tenant_id": 1,
  "model_name": "holt_winters",
  "demand_rate": 0.47,
  "forecast_horizon_days": 30,

  "mae": 0.21,
  "rmse": 0.29,
  "bias": -0.03,
  "smape": 22.1,

  "interval_lower": 0.18,
  "interval_upper": 0.82,
  "interval_confidence": 0.80,
  "interval_empirical_coverage": 0.83,

  "transformation_applied": "none",
  "hyperparameters": {
    "alpha": 0.35,
    "beta": 0.12,
    "gamma": 0.05,
    "seasonal": "additive",
    "seasonal_periods": 7
  },
  "selection_rationale": "holt_winters: beat seasonal_naive baseline by 18% test MAE; preferred over sarimax within 5% tolerance (simplicity)",

  "demand_profile": {
    "volume_tier": "low",
    "volatility": "moderate",
    "intermittency": "continuous",
    "seasonality_detected": false,
    "trend_direction": "flat",
    "history_days_used": 180
  },

  "trained_at": "2026-04-13T10:00:00Z",
  "next_review_at": "2026-05-13T10:00:00Z",
  "reeval_trigger": "anomaly_flag",
  "warnings": []
}
```

All top-level fields map to `forecast_model_registry` columns. `hyperparameters` and `demand_profile` are stored as JSON columns. `demand_profile` is additionally upserted into `sku_demand_profiles`.

---

## Error Handling

- Python exits code 1 on any unhandled exception. Laravel catches, fails the job, retries up to 3×.
- **Prophet / LightGBM not installed** → log to `warnings[]`, remove from shortlist, continue.
- **All candidate models fail** → assign `ets_fallback`, add warning, exit 0 (partial success is valid).
- **History below `min_history_days`** → assign `ets_fallback` immediately, skip competition, exit 0.
- **Feature leakage assertion (Stage 7)** → exit 1. This is a code bug, not a data problem; it must not silently continue.
- **Interval coverage below target after correction** → warning logged, best-effort interval written, continue.
- **Forecasting failure never blocks an engine run.** If no registry entry exists or `demand_rate` is null, `DemandForecaster` falls back to the 60/30/10 weighted moving average.
