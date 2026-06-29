# INVENTORY_ENGINE.md — Business Logic & Data Spec

## Client Context

- **Client type:** E-commerce, football products
- **Current system:** Excel (manual ordering)
- **SKU count:** ~30 SKUs in synthetic dataset, matching the client's catalogue. Production may have more — system must scale.
- **Pain points:** Stockouts, overstock, no data-driven reorder triggers
- **Users:** Owner + warehouse staff (role separation required)
- **Language:** English (bilingual infrastructure exists but not a client requirement)

---

## System Scope

This system is a **recommendation engine**, not a procurement platform. It:

- Monitors stock levels and demand patterns
- Runs a hybrid decision engine (forecasting + inventory position + lead time + constraints + scoring)
- Outputs `ORDER NOW` / `WATCH` / `HOLD` recommendations per SKU
- Tracks whether recommendations were **acted on externally** (e.g. ordered through the client's normal supplier process)
- Feeds acted-on outcomes back into the forecasting tier as training signal

It does **not**:
- Generate or send purchase orders to suppliers
- Manage supplier communication, invoicing, or payment
- Replace the client's procurement workflow

The client continues to place orders through their existing channels. This system tells them *what to order and when*, and captures the outcome for continuous improvement.

---

## SKU Catalogue (Synthetic Dataset)

Reference dataset used for dev/testing. Replace with client's real data at onboarding.

| SKU | Name | Category | MOQ | Unit Cost (SAR) | Avg Lead (days) | Avg Daily Sales |
|---|---|---|---|---|---|---|
| FB-001 | Nike Grip Socks 8pk | accessory | 24 | 45 | 7 | 3.2 |
| FB-002 | Adidas Grip Socks 8pk | accessory | 24 | 32 | 7 | 2.8 |
| FB-003 | Nike Phantom GX Elite Boots | equipment | 6 | 380 | 14 | 0.4 |
| FB-004 | Adidas Predator Elite Boots | equipment | 6 | 290 | 14 | 0.5 |
| FB-005 | Nike Academy Team Socks | accessory | 48 | 12 | 5 | 8.1 |
| FB-006 | Mitre Training Bibs (Single) | accessory | 48 | 10 | 5 | 7.4 |
| FB-007 | Puma Shin Guards (5pk) | accessory | 36 | 18 | 10 | 1.9 |
| FB-008 | Adidas Player Starter Kit | accessory | 12 | 85 | 12 | 0.8 |
| FB-009 | Nike Boot Conditioner 100ml | accessory | 24 | 22 | 7 | 2.1 |
| FB-010 | Adidas Premium Boot Care Oil | accessory | 12 | 65 | 10 | 0.6 |
| FB-011 | Mitre Training Cones 10pk | accessory | 60 | 8 | 5 | 9.3 |

**Notes on synthetic data:**
- Boots (FB-003, FB-004) are low velocity, high value — constraint engine must handle budget impact
- Socks, bibs, and cones (FB-005, FB-006, FB-011) are high velocity — high reorder frequency
- MOQs are realistic to common supplier minimums
- Categories (equipment/accessory/bundle) govern which classification thresholds apply

---

## Business Rules

### Reorder Logic
- Target service level: **95%** (Z = 1.65), configurable
- Review period: Daily (engine runs once per day)
- Reorder horizon: 30 days (default, configurable per SKU)
- Safety stock is SKU-level, not global
- Safety stock uses **baseline demand variability only** — promotional spikes are handled via the promotional calendar, not σ_demand

### Decision Output: ORDER / WATCH / HOLD

After computing the reorder point (ROP), the engine maps each SKU to one of:

- **ORDER** (or `order_budget_blocked`): `effective_position ≤ reorder_point`. Action required.
- **WATCH**: position is above ROP but the buffer is small enough that we want advance warning.
- **HOLD**: position is comfortably above ROP. No action.

The watch decision is the non-obvious one. It is computed in **days of demand**, not as a percentage above ROP. This is dataset-independent: a high-velocity catalogue and a slow-mover catalogue both get correctly classified.

```
buffer_days = (effective_position - reorder_point) / daily_demand

watch_threshold_days =
    lead_time_mean   × k_lead       (baseline — half a lead time of buffer is the typical review-period warning)
  + lead_time_stddev × k_ltv        (supplier reliability — wider window when arrival timing is uncertain)
  + sMAPE × lead_time_mean × k_smape  (forecast uncertainty — distinct from demand variance, which lives in safety stock)
  + trend_factor     × k_trend      (±days for growing / declining demand; disabled at cold start)

threshold = clip(threshold, min_floor, min(global_ceiling, 2 × lead_time_mean))

if buffer_days ≤ threshold → WATCH
```

`trend_factor`: +1 for upward demand, -1 for declining, 0 for flat. Pulled from `sku_demand_profiles.trend_direction` written by the Python pipeline.

#### Cold-start defaults (priors, awaiting calibration)

| Coefficient | Default | Source |
|---|---|---|
| `k_lead`  | 0.5  | Silver, Pyke, Peterson, *Inventory Management and Production Planning and Scheduling*, 3rd ed., Wiley 1998, ch. 7 — typical review-period buffer for periodic-review systems |
| `k_ltv`   | 1.65 | Standard 95%-confidence z-score; mirrors safety-stock z so watch and safety speak the same probability language |
| `k_smape` | 0.5  | Half-weight on forecast error; full weight would double-count demand variance already absorbed by safety stock |
| `k_trend` | 0.0  | Disabled at cold start. Trend signal is noisy on short windows; enabled after calibration shows it adds value |
| `min_days` | 1.0 | Floor for instant-supply SKUs; threshold never collapses below 1 day |
| `global_ceiling_days` | 90.0 | Sanity rail; 3-month buffer is comfortable regardless of supply chain |

The per-SKU ceiling uses `p95(historical_lead_times)` from `lead_time_observations` when available (populated by the synthetic simulator and, in production, by the Shopify connector when an order transitions from "ordered" to "received"). Falls back to `2 × lead_time_mean` when observation count is below the minimum sample threshold.

#### Calibration (Chunk 3)

These coefficients are *priors*, not the right answer. Once real client data is connected (target: 3 years of history), an auto-recalibration job (bi-weekly default) re-fits them from observed (decision, outcome) pairs:

- A `watch` flag is a true positive if the SKU subsequently needed a reorder within `watch_threshold_days`.
- A `hold` is a false negative if the SKU dropped below ROP unexpectedly within that window.

Optimization (initial: grid search over the 4-dim parameter space) maximizes true-positive rate subject to a tenant-configurable false-positive ceiling. Results write back to `system_settings`. The Settings UI surfaces them as live tunables.

This is the meaningful sense in which the system is "trained on the dataset": defaults exist for cold start; the steady-state values come from the data itself.

### Lead Time: Observation-Driven

`LeadTimeHandler` resolves a SKU's lead-time estimate using a fallback ladder, most-precise to least:

1. **SKU-level observations** — `lead_time_observations` rows matching the trailing window with sample size ≥ `lead_time.min_observations_for_dynamic`. Captures supplier behaviour specific to this SKU (drop-ship vs warehouse-routed products from the same supplier). Source: `observations_sku`.
2. **Supplier-level observations** — same query without the `sku_id` filter. Picks up supplier reliability before an individual SKU has accumulated enough orders. Source: `observations_supplier`.
3. **Static fallback** — `Supplier::avg_lead_time_days` and `Supplier::lead_time_stddev`, set at supplier creation. Used at cold start before any observations exist. Source: `static`.

Configuration (`system_settings`):
- `lead_time.observation_window_days` (default 365) — captures supplier seasonality
- `lead_time.min_observations_for_dynamic` (default 5) — minimum sample size to trust the dynamic estimate

The returned `LeadTimeEstimate` carries:
- `expected_days`, `buffered_days`, `stddev` — same as before
- `p95` — used by `DecisionScorer` for the per-SKU watch ceiling
- `source` — visible in `inventory_decisions.reasoning` for ops debugging

This closes the last data-derivation gap in the engine. Per the architectural principle: any quantity that can be observed should derive itself from observations, with statics surviving only as cold-start fallbacks. Lead time was the final hold-out.

### Demand Rate Calculation

**Baseline demand (weighted average):**
```
demand_rate = (current_year_avg × 0.60) +
              (last_year_avg    × 0.30) +
              (two_years_ago    × 0.10)
```
This weighting applies when the ML registry has no entry yet (new SKU or first run). Once the ML tier is active, it overrides this with the model-computed demand_rate.

**Baseline excludes promotional days.** Only non-promotional dates feed the baseline calculation. Promotional uplift is added as a separate adjustment when a promotion is active or upcoming within the reorder horizon.

### Promotional Calendar Logic

Two-layer system:
1. **Client promotions** (`promotions` table): client-managed via UI. Contains custom events, sales, campaigns with expected uplift %. Takes precedence over Layer 2.
2. **Regional holidays** (`regional_holidays` table): Saudi/regional calendar seeded once per year. Used as fallback when no client promotion covers a given date. Default uplift % per holiday type.

**How promotions affect demand_rate:**
- When SARIMAX is the active model, the promotional calendar feeds it as an exogenous variable — the model learns promotion-adjusted demand directly
- When Holt-Winters or fallback ETS is active, promotional uplift is applied as a post-model adjustment: `adjusted_demand = base_demand × (1 + uplift_pct / 100)` for dates within the reorder horizon that have a promotion entry

### Budget Constraints
- Monthly budget set by owner in Settings
- Budget is consumed as recommendations are marked `ORDERED` (committed spend)
- Engine never recommends exceeding available budget
- If budget blocks a critical ORDER NOW, it flags it as `ORDER NOW — BUDGET BLOCKED`

### Role Permissions (Spatie)

| Role | Capabilities |
|---|---|
| `owner` | Full access: run engine, adjust settings, manage promotions, act on recommendations, view reports |
| `warehouse` | View recommendations, mark recommendations as ordered/received, update stock counts |
| `viewer` | Read-only: dashboard + reports |

---

## Recommendation Action Tracking

The engine writes recommendations to `inventory_decisions`. Each recommendation carries a status that the user updates as they act on it outside the system. The system does **not** create or send purchase orders — it records whether and how a recommendation was followed.

### Status Lifecycle

```
PENDING → ACKNOWLEDGED → ORDERED → RECEIVED
           ↓
         IGNORED
           ↓
        SUPERSEDED  (set automatically when a later engine run produces a new recommendation for the same SKU)
```

| Status | Meaning | Set By |
|---|---|---|
| `PENDING` | Engine generated recommendation, no user action yet | Engine (default) |
| `ACKNOWLEDGED` | User has seen it and intends to act | User (owner / warehouse) |
| `ORDERED` | User placed the order externally; records ordered quantity, supplier, expected arrival | User |
| `IN_TRANSIT` | Optional intermediate state between ORDERED and RECEIVED; set manually or on supplier confirmation | User |
| `RECEIVED` | Stock arrived; records received quantity, actual receipt date; triggers stock reconciliation | User (warehouse) |
| `IGNORED` | User explicitly dismissed the recommendation; records reason | User |
| `SUPERSEDED` | A later engine run produced a new recommendation for the same SKU before this one was acted on | Engine (automatic) |

### Fields on `inventory_decisions`

Status and audit columns (additions to whatever already exists):

| Column | Type | Purpose |
|---|---|---|
| `status` | enum | Current state from the lifecycle above |
| `status_changed_at` | timestamp | When the current status was set |
| `status_changed_by` | FK users nullable | Who set it (null for engine-set statuses) |
| `status_history` | JSON | Append-only log: `[{status, at, by, notes, metadata}]` |
| `ordered_qty` | int nullable | Set when status → `ORDERED` |
| `ordered_at` | timestamp nullable | Set when status → `ORDERED` |
| `expected_arrival` | date nullable | Set when status → `ORDERED` |
| `supplier_id` | FK suppliers nullable | Set when status → `ORDERED` |
| `received_qty` | int nullable | Set when status → `RECEIVED` |
| `received_at` | timestamp nullable | Set when status → `RECEIVED` |
| `ignored_reason` | string nullable | Set when status → `IGNORED` |
| `superseded_by_decision_id` | FK self nullable | Set when status → `SUPERSEDED` |

### Rules

- `status` is **not** in `$fillable`. Status transitions go through a dedicated service method that validates the transition, stamps `status_changed_at` / `status_changed_by`, and appends to `status_history`.
- Engine runs automatically set any PENDING / ACKNOWLEDGED recommendations for an SKU to `SUPERSEDED` when emitting a new recommendation for the same SKU. The new recommendation's `id` is written to `superseded_by_decision_id` on the old row.
- ORDERED and RECEIVED decisions are **never superseded** — they are historical records of real-world actions.
- `IGNORED` requires a reason; the UI enforces this.
- Transition validation: `PENDING → IGNORED` is allowed; `RECEIVED → anything` is not (terminal).

---

## Recommendation Feedback Loop

The action tracking data is the feedback signal back into the forecasting tier and engine calibration. This is why we capture `IGNORED` reasons and received-qty deltas — not for UI convenience.

**Data science consumption of this data:**

1. **IGNORED reasons as label noise correction** — when the user dismisses a recommendation with reason "already ordered elsewhere," the recommendation wasn't wrong; when reason is "disagree with forecast," the forecast may be systematically biased for that SKU or category. Reasons are categorical and analyzed during the monthly scheduled sweep.

2. **Ordered-vs-recommended delta as trust calibration** — if the user consistently orders 20% less than the engine recommends and stockouts don't follow, the engine is over-ordering; this is a bias signal distinct from forecast bias (which measures forecast vs actual sales). Tracked per SKU, aggregated to category.

3. **Received-vs-ordered delta as lead-time / supplier reliability signal** — shortfalls feed `LeadTimeHandler` and supplier scoring.

4. **SUPERSEDED rate per SKU as engine instability signal** — a high rate means the engine is changing its mind day-to-day, which usually indicates noisy input data or an unstable model. Monitored and surfaced as a warning in the monthly sweep diagnostics.

These signals are computed by a scheduled job (`recommendation:analyze-feedback`, run weekly) and written to internal diagnostic tables. They are **not** client-facing. They feed:
- `forecast_model_registry` re-evaluation triggers (adds a `feedback_drift` trigger alongside `anomaly_flag` and `bias_drift`)
- `LeadTimeHandler` calibration
- Monthly sweep diagnostic reports written to `storage/app/forecasting/reports/`

---

## Forecasting: Why the ML Tier Exists

**Historical context:** Early forecasting experiments on synthetic data reached 51.7% MAPE at best. This was acceptable in the MVP because the engine treated forecasting as one signal, with safety buffers absorbing forecast error. With real production data, significantly better accuracy is achievable.

**Production data changes the situation.** When connected to the client's live database, the ML tier has access to real daily sales history across all SKUs. Model selection becomes meaningful rather than theoretical — the data sufficiency gate fires on real records, and evaluation metrics reflect actual demand patterns.

**The ML tier does not change the engine's fundamental architecture.** `DemandForecaster` still outputs `demand_rate` to the decision engine. The rest of the engine (safety stock, lead time, constraints, scoring) is unchanged. The ML tier improves the quality of that single input.

Full ML tier specification: `docs/FORECASTING_ENGINE.md`.

### SKU Classification Thresholds (per category, configurable in system_settings)

| Threshold | Equipment | Accessories | Bundles |
|---|---|---|---|
| Min history for any classification | 120 days | 90 days | 90 days |
| Min history for seasonality detection | 365 days | 365 days | 365 days |
| Intermittency threshold (% zero-sales days) | ≥ 30% | ≥ 20% | ≥ 25% |

Equipment gets a longer minimum because daily volume is low enough that 90 days may be statistically sparse. All values are stored in `system_settings` and editable without code changes.

---

## Anomaly Detection (Two-Tier)

**Tier 1 — Watch alert (3-day):**
- Recent 3-day average deviates from baseline by ≥ 15% (low sensitivity threshold)
- Action: flag SKU for monitoring, do not trigger immediate reorder

**Tier 2 — Confirmed action alert (7-day):**
- Recent 7-day average deviates from baseline by ≥ 30% (medium) or ≥ 60% (high sensitivity)
- Action: trigger demand re-evaluation for this SKU + emit alert via Reverb

**Anomaly detection → forecast re-evaluation link:**
When a Tier 2 alert fires for an SKU, `RunForecastJob` is dispatched for that SKU with `reeval_trigger = 'anomaly_flag'`. This updates the `forecast_model_registry` with a fresh model competition result before the next engine run.

All thresholds are configurable in `system_settings`.

---

## Key Dashboard Metrics

| Metric | Definition |
|---|---|
| SKUs needing order | Count of `ORDER NOW` recommendations currently in `PENDING` or `ACKNOWLEDGED` status |
| SKUs to watch | Count of `WATCH` recommendations currently in `PENDING` or `ACKNOWLEDGED` status |
| Days of cover (avg) | Average across all SKUs |
| Committed spend | Sum of recommendations in `ORDERED` status not yet `RECEIVED`, at ordered quantity × unit cost |
| Budget remaining | Monthly budget − committed spend |
| Stockout risk | SKUs with days of cover < lead time |
| Dead stock | SKUs with current stock > 0 but no sales in 30 days |
| Last engine run | Timestamp + status of most recent EngineRun |

---

## Seeder Instructions

`SyntheticDataSeeder.php` generates:
- 12 months of daily sales history per SKU (with realistic noise and promotional patterns)
- Random lead time history (5–20 entries per supplier)
- Starting stock levels (some SKUs near reorder point for demo interest)
- 2 users: owner@demo.test / warehouse@demo.test (password: password)
- 1 tenant (id: 1, name: "Demo Client")

`RegionalHolidaySeeder.php` generates:
- Saudi public holidays for the current calendar year
- Default uplift % per holiday (e.g. Eid Al-Fitr: 35%, National Day: 20%)
- Runs once; updates annually via scheduled artisan command

`ForecastSettingsSeeder.php` generates:
- Per-category forecasting thresholds for `tenant_id = 1` (see FORECASTING_ENGINE.md)
- Seeded into `system_settings`

---

## Known Limitations

1. **Single warehouse** — no multi-location inventory
2. **Single currency (SAR)** — no multi-currency
3. **No supplier diversity** — 1 active supplier per SKU
4. **No returns/adjustments flow** — stock corrections are manual input
5. **No demand sensing** — engine uses historical sales only, not forward orders
6. **Python runtime required on host** — ML tier depends on Python 3.11+ being installed on the application server
7. **No procurement workflow** — system tracks recommendations acted on externally; it does not generate POs, send orders to suppliers, or manage invoicing. The client places orders through their existing channels and records the outcome in the system.

These are documented as scope decisions and future enhancements, not bugs.
