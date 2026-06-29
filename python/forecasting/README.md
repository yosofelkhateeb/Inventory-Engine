# Python Forecasting Microservice

Standalone Python service called by Laravel's `RunForecastJob` via `Process::run()`.

## Requirements

- **Python 3.11+** (tested on 3.13)
- Core dependencies (all required): `statsmodels`, `pmdarima`, `lightgbm`, `pandas`, `numpy`, `scipy`, `scikit-learn`
- Optional: `prophet` (skipped gracefully if not installed)

## Setup

```bash
cd python/forecasting
pip install -r requirements.txt
# prophet is optional:
pip install prophet
```

## Running standalone

```bash
cd python/forecasting
python main.py --input /tmp/test_input.json
```

The script prints a single JSON object to stdout and exits 0 on success, 1 on error.

## Input format

```json
{
  "sku_id": 3,
  "tenant_id": 1,
  "sku_category": "equipment",
  "reeval_trigger": "scheduled",
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
    { "date": "2026-03-20", "name": "Hari Raya Aidilfitri", "uplift_pct": 35 }
  ]
}
```

## Output format

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
  "reeval_trigger": "scheduled",
  "warnings": []
}
```

## Architecture

```
main.py
  └─ classifier.py     → demand profile (volume, volatility, intermittency, seasonality, trend)
  └─ model selector    → candidate shortlist from profile
  └─ model runners     → holt_winters, sarimax, prophet, lightgbm, croston, ets_fallback
  └─ evaluator.py      → MAE, RMSE, Bias, sMAPE per model (held-out last 20% ≥ 30 days)
  └─ winner selection  → lowest MAE wins
  └─ registry.py       → formats output JSON for Laravel to parse
```

## Error handling

- Prophet not installed → warning logged to stderr, skipped from candidates
- LightGBM model fails → warning logged, skipped
- All models fail → `ets_fallback` assigned, exit code 0
- Insufficient history → `ets_fallback` assigned immediately, no competition
- Unhandled exception → error JSON to stdout, exit code 1
