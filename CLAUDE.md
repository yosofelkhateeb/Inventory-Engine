# CLAUDE.md — Inventory Decision Engine Project

> This file is read automatically by Claude Code at session start.
> It provides full project context so you don't repeat yourself.

---

## Documentation Index

Read the relevant doc before touching a concern. All docs live in `docs/`.

| Doc | When to read it |
|---|---|
| `docs/ARCHITECTURE.md` | Anything touching engine modules, database schema, queues, deployment topology, security baseline, multi-tenancy |
| `docs/INVENTORY_ENGINE.md` | Business logic, scoring rules, promotional calendar, recommendation lifecycle, feedback loop |
| `docs/FORECASTING_ENGINE.md` | DemandForecaster, RunForecastJob, the Python pipeline, models, re-evaluation triggers, UI-consumable forecast fields |
| `docs/DATA_INGESTION.md` | CSV importers, Shopify connector, `IngestionSource` interface, onboarding flows |
| `docs/DEPLOYMENT_PREREQUISITES.md` | Application-side deploy requirements (runtime, processes, env vars). Partner/infra team owns the actual deploy. |
| `docs/TODO_PRE_PRODUCTION.md` | Deferred work: failure UX, backup, scale testing, security final pass. Check triggers before tackling items here. |
| `docs/GLOSSARY.md` | Reference for every user-facing metric and label. Mirrors `resources/js/composables/useGlossary.ts` — if you change a definition in one, change it in the other. |

Historical design docs live in `docs/plans/`. `docs/PARTNER_REVIEW_RESPONSE_2026-05.md` is a point-by-point log of the May 2026 partner review — read it for the reasoning behind recent feature work, not for current behaviour.

---

## Project Summary

Building a **hybrid inventory decision engine** for an e-commerce client selling football product SKUs, currently managing stock via Excel. The system replaces manual gut-feel ordering with a data-driven recommendation engine.

**Business goal:** Reduce stockouts and overstock, automate replenishment decisions, and deliver the output as a usable product — not a script.

**Scope:** The system is a **recommendation and tracking engine**, not a procurement platform. It monitors stock, forecasts demand, outputs `ORDER NOW` / `WATCH` / `HOLD` recommendations, and tracks whether recommendations were acted on externally. It does **not** generate POs, contact suppliers, or manage invoicing.

**SKU count:** ~30 SKUs in the synthetic dataset, matching the client's catalogue. The system must scale to any catalogue size — do not hardcode or assume a fixed SKU count anywhere in engine logic, model selection, or evaluation.

---

## Architecture Decision: Hybrid Decision Engine

We **rejected** a pure forecast-first approach (~51% MAPE was unacceptable). The current architecture treats forecasting as one **modular input** alongside:

| Module | Role |
|---|---|
| DemandForecaster | One signal among many — outputs demand_rate per SKU |
| InventoryPositionTracker | Current stock, in-transit, days of cover |
| LeadTimeHandler | Uncertainty buffer around supplier lead times |
| ConstraintEngine | MOQ enforcement, budget caps |
| DecisionScorer | Combines all signals → ORDER NOW / WATCH / HOLD |
| AbcXyzClassifier | SKU classification for safety stock multiplier |

**Decision output per SKU:** `ORDER NOW` / `WATCH` / `HOLD` + recommended quantity, written to `inventory_decisions` with a status lifecycle (`PENDING` → `ACKNOWLEDGED` → `ORDERED` → `RECEIVED` or `IGNORED` / `SUPERSEDED`).

See `docs/ARCHITECTURE.md` for module specs.
See `docs/INVENTORY_ENGINE.md` for the recommendation status lifecycle and feedback loop.
See `docs/FORECASTING_ENGINE.md` for the ML forecasting spec.

---

## Tech Stack

**Chosen stack:** Laravel 12 + Vue 3 + Inertia.js + MySQL

### Backend
- PHP 8.3 / Laravel 12
- Laravel Sanctum (API auth)
- Laravel Horizon (queue monitoring)
- Laravel Reverb (WebSockets — real-time stock alerts)
- Laravel Fortify (auth scaffolding)
- Laravel Wayfinder (typed route generation)
- Spatie Laravel Permissions (role-based access)
- Pest 4 (testing)
- Laravel Pint (code formatting)

### Python Forecasting Microservice
- Python 3.11+ (subprocess called from Laravel Jobs)
- statsmodels (Holt-Winters, SARIMAX, SimpleExpSmoothing)
- pmdarima (auto ARIMA order selection)
- prophet (optional — activated when installed)
- lightgbm (optional — activated when installed)
- pandas, numpy, scipy
- Lives in `python/forecasting/` at project root
- Called via `Process::run('python python/forecasting/main.py --input <path>')` from `RunForecastJob`
- 15-stage pipeline with externalised config in `python/forecasting/config/forecasting_config.yaml`
- Returns JSON to stdout — full schema in `docs/FORECASTING_ENGINE.md` (demand_rate, intervals, hyperparameters, selection_rationale, diagnostic outputs, warnings)

### Frontend
- Vue 3 (Composition API, `<script setup lang="ts">`)
- Inertia.js v2 (SPA bridge — no separate API layer)
- TypeScript
- Tailwind CSS v4
- vue-i18n (bilingual infrastructure exists; client language requirement is English only)
- Vite

Note: **shadcn-vue is NOT installed.** Dialog components use a Teleport/Tailwind pattern from `SkuDetailDialog`. Do not reach for shadcn-vue imports — they will fail.

### Infrastructure
- Laravel Herd (local dev on Windows)
- MySQL 8+ (dev and production)
- Redis 7+ (queues, caching, broadcasting)
- Online deployment with public subdomain (see `docs/DEPLOYMENT_PREREQUISITES.md`)

---

## Key Architectural Decisions (Locked)

These decisions were made deliberately. Do not reverse them without explicit instruction.

**1. Python subprocess for ML forecasting, not PHP-native.**
Python's statsmodels/Prophet/LightGBM ecosystem handles all ML model execution. Laravel dispatches `RunForecastJob` which calls `main.py --input <path>`. The rest of the engine consumes `demand_rate` without knowing which model produced it.

**2. DemandForecaster outputs demand_rate only.**
The ML tier determines which model wins per SKU and writes to the `forecast_model_registry` table. The PHP engine reads `demand_rate` from that registry. Clean interface — no PHP code knows about ARIMA or LightGBM internals.

**3. Multi-tenant foundation is built now.**
All core tables carry a `tenant_id` foreign key. A global Eloquent scope ensures tenant isolation. The current client runs as `tenant_id = 1`. `regional_holidays` is the only shared reference table (no tenant_id). Tenant isolation audit is a pre-deployment checklist item.

**4. Promotional calendar has two layers.**
Layer 1: `promotions` table — client-managed via UI, takes precedence.
Layer 2: `regional_holidays` table — seeded once per year from a standard Saudi/regional calendar, updated annually. Used as fallback when Layer 1 has no entry for a date. SARIMAX consumes both as exogenous variables; Holt-Winters applies uplift as post-model adjustment.

**5. Deployment is online with a subdomain, not localhost.**
Replaces the prior decision to ship as a localhost-only web app. The partner is responsible for infra (domain, TLS, managed DB, supervisor config). The application has requirements captured in `docs/DEPLOYMENT_PREREQUISITES.md`. Five long-running processes (web, Horizon, scheduler, Reverb, frontend build) are required.

**6. Classification thresholds are configurable per SKU category.**
Equipment, accessories, and bundles have separate threshold configs stored in `system_settings`, seeded by `ForecastSettingsSeeder` for `tenant_id = 1`. See `docs/FORECASTING_ENGINE.md` for defaults.

**7. The system is a recommendation engine, not a procurement platform.**
No purchase order creation, no supplier messaging, no invoicing. Recommendations are tracked through a status lifecycle on `inventory_decisions`. The client orders through their existing channels and records the outcome in the system. The feedback loop from action-tracking data back into the forecasting tier is a core design feature (see `docs/INVENTORY_ENGINE.md` → Recommendation Feedback Loop).

**8. Data ingestion is an adapter-pattern plug-in system.**
All ingestion sources (CSV upload, Shopify, future WooCommerce/Salla/direct DB) implement the `IngestionSource` interface. The orchestrator is source-agnostic. Phase 1: CSV upload (universal fallback). Phase 2: Shopify connector. See `docs/DATA_INGESTION.md`.

**9. Backtesting is backend-only. Never surfaced to the client.**
The walk-forward CV and held-out test evaluation inside the Python pipeline *is* the backtesting mechanism. Fold-level metrics are stored in the registry and in `python/forecasting/reports/` for engineer inspection. The Reports Inertia page surfaces model outcomes, not historical what-if comparisons. Do not build a "backtest dashboard" — if product thinking drifts that way, revisit this decision explicitly.

---

## Key Conventions

### Laravel
- Controllers are thin — logic lives in `app/Services/`
- Use Form Requests for validation
- Jobs go in `app/Jobs/`, dispatched via Horizon queues
- Inventory calculations live in `app/Services/InventoryEngine/`
- Ingestion code lives in `app/Services/Ingestion/`
- Python forecasting service lives in `python/forecasting/`
- Pest tests, not PHPUnit. Feature tests over unit where possible.
- Pint formatting runs on save — don't fight the style
- `inventory_decisions.status` is **not** in `$fillable`. Status transitions go through `DecisionStatusService` which validates, stamps audit columns, and appends to `status_history`.

### Vue / Inertia
- All pages in `resources/js/Pages/`
- Shared components in `resources/js/Components/`
- Composables in `resources/js/composables/`
- Use `<script setup lang="ts">` always — no Options API
- Props typed with TypeScript interfaces, not `any`
- Do not import from `shadcn-vue` — not installed in this project

### Database
- Migrations only — never edit tables directly
- Soft deletes on core models (SKUs, Suppliers)
- All monetary values stored as integers (halalas), displayed as decimals
- `tenant_id` required on all core tables — never omit it
- `regional_holidays` is the only tenant-agnostic table
- **NEVER run `php artisan config:cache` in local dev.** The config cache hardcodes the SQLite file path, which causes `phpunit.xml`'s `DB_DATABASE=:memory:` override to be ignored. Tests then run against the real `database.sqlite`, and `RefreshDatabase` wipes seed data. If the cache exists accidentally, run `php artisan config:clear` immediately.
- After any accidental data loss, run `php artisan db:seed` to restore demo data (owner@demo.test / password + 30 SKUs).

---

## Directory Structure (Target)

```
app/
  Services/
    InventoryEngine/
      DemandForecaster.php           ← reads from forecast_model_registry
      InventoryPositionTracker.php
      LeadTimeHandler.php
      ConstraintEngine.php
      DecisionScorer.php
      AbcXyzClassifier.php
      InventoryEngineService.php     ← orchestrator
      DecisionStatusService.php      ← validated status transitions, audit trail
    Ingestion/
      DataIngestionService.php       ← orchestrator, source-agnostic
      IngestionSource.php            ← interface
      Sources/
        CsvSource.php
        ShopifySource.php            ← Phase 2
      Importers/
        SkuImporter.php
        SupplierImporter.php
        SalesHistoryImporter.php
    Forecasting/
      ForecastPresenter.php          ← human_readable_forecast + confidence_label
  Models/
  Jobs/
    RunInventoryEngineJob.php
    RunForecastJob.php               ← dispatches Python subprocess per SKU
    RunCsvImportJob.php
    RunShopifyInitialLoadJob.php     ← Phase 2
    RunShopifyIncrementalSyncJob.php ← Phase 2
  Http/
    Controllers/
    Requests/
  Observers/
    SkuObserver.php                  ← dispatches RunForecastJob(new_sku)
  Console/
    Commands/
      ForecastSweepCommand.php       ← monthly scheduled sweep
      AnalyzeFeedbackCommand.php     ← recommendation:analyze-feedback, weekly
      IngestionCsvCommand.php        ← php artisan ingestion:csv
      IngestionCleanupUploadsCommand.php

python/
  forecasting/
    config/
      forecasting_config.yaml        ← all pipeline parameters (no magic numbers in code)
    pipeline/
      data_audit.py                  ← timestamp validation, gap classification, spike flagging
      preprocessing.py               ← imputation, outlier treatment, baseline/full series split
      eda.py                         ← trend, seasonality, ACF/PACF (scheduled/new_sku runs only)
      baselines.py                   ← naive, seasonal naive, moving average, weighted avg
      feature_engineering.py         ← lag, rolling, calendar, event features (leakage-asserted)
      validation.py                  ← walk-forward expanding window CV + held-out test set
      diagnostics.py                 ← residual analysis, Ljung-Box, event-period errors
      intervals.py                   ← prediction intervals + coverage validation + correction
      monitoring.py                  ← distribution shift, model staleness checks
    models/
      holt_winters.py
      sarimax.py
      prophet_model.py               ← optional, skipped if not installed
      lightgbm_model.py              ← optional, skipped if not installed
      croston.py
      ets_fallback.py
    classifier.py                    ← SKU demand profile classification
    evaluator.py                     ← MAE, RMSE, Bias, sMAPE, WMAPE (excludes imputed rows)
    selection.py                     ← multi-criteria winner selection + rationale
    registry.py                      ← formats output JSON for Laravel
    main.py                          ← entry point, called via --input <path>
    reports/                         ← EDA, diagnostics, tuning logs, audit reports
    requirements.txt

resources/js/
  Pages/
    Dashboard/
    SKUs/
    Reports/
    Promotions/                      ← promotional calendar management UI
    Ingestion/                       ← CSV upload + Shopify connector management
    Settings/
  Components/
  composables/

storage/app/
  forecasting/
    tmp/{tenant_id}/                 ← Python subprocess input JSON (UUID filenames)
    reports/                         ← per-SKU diagnostics
    reports/feedback/{tenant_id}/    ← weekly feedback analysis output
  ingestion/
    uploads/{tenant_id}/             ← uploaded CSVs (cleaned daily)
    templates/                       ← downloadable template CSVs

database/
  migrations/
  seeders/
    SyntheticDataSeeder.php
    RegionalHolidaySeeder.php        ← seeds regional_holidays for current year
    ForecastSettingsSeeder.php       ← per-category thresholds in system_settings
```

---

## Session Startup Checklist

**Run these at the start of every dev session before doing anything else:**

1. Start the Vite dev server (required — page will be blank without it):
   ```bash
   npm run dev
   ```
   Keep this terminal open, then serve the app with `php artisan serve`
   (default: http://localhost:8000).

2. Use whatever `php`, `composer`, `npm`, and `python` resolve on your PATH:
   ```
   php artisan <command>
   composer <command>
   npm <command>
   ```

3. Python forecasting subprocess (how Laravel invokes it; for manual testing):
   ```
   python python/forecasting/main.py --input path/to/input.json
   ```
   Input JSON schema: see `docs/FORECASTING_ENGINE.md` → Input JSON Format.

---

## Current Status

- [x] Laravel 12 project scaffolded
- [x] Vue 3 + Inertia.js + TypeScript wired up
- [x] Tailwind CSS v4 configured
- [x] Fortify auth + Login page
- [x] Spatie permissions, Sanctum, Reverb, Horizon installed
- [x] All base migrations run
- [x] Phase 1: Migrations + models + SyntheticDataSeeder (11 SKUs, 12 months sales)
- [x] Phase 2: All 5 engine service classes + InventoryEngineService orchestrator
- [x] Phase 2: AbcXyzClassifier integrated, safety stock multiplier applied
- [x] Phase 2: EngineRun logging (running/completed/failed, duration_ms)
- [x] Phase 2: RunInventoryEngineJob + StockAlertEvent
- [x] Phase 3: DashboardController, SkuController, EngineController + auth routes
- [x] Phase 4: Dashboard/Index.vue, SKUs/Index.vue, SKUs/Show.vue, SKU detail popup
- [x] Phase 5: Fortify auth fully wired
- [x] Real-time notifications: NotificationBell.vue, AppHeader.vue, Laravel Echo + Reverb
- [x] Multi-tenant foundation — tenant_id on all core tables, global Eloquent scope
- [x] Promotional calendar — promotions table + Promotions/Index.vue + regional_holidays seeder
- [x] forecasting_config.yaml — all pipeline parameters externalised
- [x] Python pipeline/ modules — data_audit, preprocessing, eda, baselines, feature_engineering, validation, diagnostics, intervals, monitoring
- [x] Python model modules — holt_winters, sarimax, prophet, lightgbm, croston, ets_fallback
- [x] classifier.py, evaluator.py, selection.py, registry.py — per FORECASTING_ENGINE.md spec
- [x] main.py — 15-stage pipeline; stdout = result JSON
- [x] RunForecastJob — Laravel Job dispatching Python subprocess per SKU (600s timeout via FORECAST_PROCESS_TIMEOUT)
- [x] forecast_model_registry table — per-SKU model winner + scores + intervals + hyperparameters + selection_rationale
- [x] sku_demand_profiles table — classification output per SKU
- [x] DemandForecaster upgrade — reads demand_rate from registry, falls back to weighted MA
- [x] Event-driven re-evaluation triggers — bias drift, new_sku observer, monthly sweep artisan command
- [x] forecasting_thresholds settings — per-category config in system_settings (ForecastSettingsSeeder)
- [x] End-to-end smoke test — all 11 SKUs through pipeline
- [x] Reports page — ReportsController, GET /reports, Reports/Index.vue with model badges/MAE/sMAPE/intervals/coverage/rationale/triggers
- [x] **Recommendation action tracking** — `DecisionStatusService` with validated transitions, `InventoryDecisionController` (`PATCH /inventory-decisions/{decision}/status`), `InvalidStatusTransitionException`, `status_history` JSON audit trail, `superseded_by_decision_id` back-linking in `persist()`. 5 tests.
- [x] **Recommendation feedback loop** — `AnalyzeRecommendationFeedbackJob` (weekly, queued), `feedback_metrics` table, `ForecastDriftDetected` event, 4 threshold settings keys. 3 tests.
- [x] **Forecast output for UI consumption** — `ForecastPresenter` service (`humanReadableForecast`, `confidenceLabel`), `InvalidForecastOutputException`, 3 confidence threshold settings keys. 4 tests.
- [x] **82 tests passing (313 assertions)**
- [x] **Data ingestion Phase 1 (CSV upload)** — `IngestionSource` interface, `CsvSource`, three importers (Supplier/Sku/SalesHistory), row-level validation with error reporting, `data_ingestion_runs` table, `Ingestion/Index.vue` upload UI, `RunCsvImportJob`, `ingestion:csv` artisan command, downloadable templates, `IngestionController`. 16 tests.
- [x] **98 tests passing (365 assertions)**

- [x] **Security baseline** — rate limiting (engine 10/min, ingestion upload 10/hr/tenant, decision transitions 60/min), `CsvSource` path guard (realpath + allowed-dir check), `RunForecastJob` reeval_trigger allowlist + Python output required-key validation.
- [x] **Settings page — threshold editor** — owner-only gate on `PATCH /settings`, full threshold UI (per-category, global, feedback loop, confidence labels), read-only mode for non-owners. 5 tests.
- [x] **Data ingestion Phase 2 (Shopify connector)** — `ingestion_credentials` + `sku_external_mappings` tables/models (encrypted credentials cast), `ShopifySource` (Link-header pagination, 429 backoff), `RunShopifyInitialLoadJob` (stock sync + 24-month order history), `RunShopifyIncrementalSyncJob` (cursor-based hourly sync), `ShopifyController` (connect/disconnect/sync, owner-only), Shopify section in `Ingestion/Index.vue`, hourly scheduler entry. 10 tests.
- [x] **113 tests passing (430 assertions)**

- [x] **HandleForecastDrift listener** — `ShouldQueue` listener, dispatches `RunForecastJob` with `bias_drift` trigger, `forecasting` log channel, `EventServiceProvider` registration. 3 tests.
- [x] **Mass assignment audit** — `StockAdjustment` tenant isolation gap closed (migration + `TenantScope` + `booted()` auto-stamp). Sensitive columns documented.
- [x] **RecommendationActions UI** — Vue component with optimistic-UI status transitions, fetch-based `PATCH`, spinner, terminal badge. Wired into Dashboard/Index.vue.
- [x] **Shopify as primary ingestion** — `docs/DATA_INGESTION.md` and `Ingestion/Index.vue` updated to position Shopify as primary, CSV as supplier-only fallback.
- [x] **Promotions — promotion_type + category targeting** — `promotion_type` enum column, `applies_to_categories` JSON column, three-way targeting (all / categories / specific SKUs) in controller (`syncTargeting()`), full form and table updates in `Promotions/Index.vue`. i18n keys added for EN + AR.
- [x] **116 tests passing (434 assertions)**

- [x] **Synthetic dataset milestone — 30-SKU SEA Shopify-shape demo** — replaced the 11-SKU / 1-year fixture with a 30-SKU South-East-Asia e-commerce dataset over a 913-day (~30-month) window. `SeaSeasonalCalendar` (SEA mega-sale days 9.9/10.10/11.11/12.12, CNY, Hari Raya Puasa/Haji, Songkran, BFCM, Christmas, monsoon dampening), `SeaPromotionCampaignGenerator` (~57 Brief-tagged promos, ≥30-day baseline-gap rule), `sea_sku_catalog.php` (5 suppliers 5–28d lead time, 30 SKUs across equipment/accessory/bundle with per-SKU pathology), `SeaDatasetSeeder` orchestrator wired into `SyntheticDataSeeder` + `RegionalHolidaySeeder` (2023–2026 holidays). End-to-end verification gate caught + fixed 3 latent production bugs (`MlLayer` hardcoded `python3`, `MIN_TRAINING_SAMPLES` PHP/Python mismatch, `SkuObserver` firing forecasts before sales history existed). Measured: seed ≈7.8s, forecast_demand avg ≈10.92. Retired `RetroactivePromotionTagSeeder` (superseded). 43 synthetic-dataset tests.

### Remaining Scope

_(All items complete. See Deferred section for pre-production work.)_

### Deferred (see `docs/TODO_PRE_PRODUCTION.md`)

- Failure UX (in-app status indicator, degraded-mode banner)
- Backup strategy (MySQL nightly, tested restore)
- Scale testing (100/500/1000 SKU benchmarks)
- Security final pass (third-party audit, dependency audit, tenant isolation tests)
- Operational runbook
- Data retention / deletion policy
- Monitoring and alerting
- Frontend polish beyond the Settings editor

---

## Working Style Preferences

- Direct, no padding. Skip "Great question!" preambles.
- Ask before assuming on ambiguous logic. Stop and ask before proceeding when blockers arise rather than guessing.
- Prefer explicit over magic (no over-clever PHP)
- When writing Vue, always TypeScript, always Composition API
- When writing tests, always Pest syntax
- Numerical parameters (thresholds, weights, multipliers, review periods) are treated as optimization levers — make them configurable in `system_settings` or `forecasting_config.yaml`. Do not hardcode.
- Check the relevant doc before making a change (see Documentation Index above)
- Push back firmly if results are substandard; senior-level standards throughout
- Configuration decisions are collaborative with rationale, not unilateral

---

# Global Rules

## Workflow Orchestration

### Plan Mode Default

- Enter plan mode for ANY non-trivial task (3+ steps or architectural decisions)
- If something goes sideways, STOP and re-plan immediately - don't keep pushing
- Use plan mode for verification steps, not just building
- Write detailed specs upfront to reduce ambiguity

### Subagent Strategy

- Offload research, exploration, and parallel analysis to subagents to keep main context window clean
- For complex problems, throw more compute at it via subagents
- One task per subagent for focused execution

### Verification Before Done

- Never mark a task complete without proving it works
- Diff behavior between main and your changes when relevant
- Ask yourself: "Would a staff engineer approve this?"
- Run tests, check logs, demonstrate correctness

### Demand Elegance (Balanced)

- For non-trivial changes: pause and ask "is there a more elegant way?"
- If a fix feels hacky: "Knowing everything I know now, implement the elegant solution"
- Skip this for simple, obvious fixes - don't over-engineer
- Challenge your own work before presenting it

### Autonomous Bug Fixing

- When given a bug report: just fix it. Don't ask for hand-holding
- Point at logs, errors, failing tests -> then resolve them
- Zero context switching required from the user
- Go fix failing CI tests without being told how

## Task Management

1. **Plan First**: Write plan with checkable items before starting
2. **Verify Plan**: Check in before starting implementation
3. **Track Progress**: Mark items complete as you go
4. **Explain Changes**: High-level summary at each step
5. **Document Results**: Add review notes when done
6. **Capture Lessons**: Update lessons file after corrections

## Session Discipline

- At session start: read the project's CHANGELOG.md and lessons file for context
- After ANY correction from the user: capture the lesson to prevent repeating it
- Write rules for yourself that prevent the same mistake
- Ruthlessly iterate on these lessons until mistake rate drops

## Changelog

- After completing any feature, bug fix, improvement, or notable change, update CHANGELOG.md at the project root
- Group entries by date (newest first), using `## YYYY-MM-DD` headings
- Keep entries simple and readable - one line per change, written in plain language

## Git Commit Discipline

- Commit logically grouped changes with a message describing what changed and why (not just "update")
- Don't leave large amounts of uncommitted work at the end of a task
- Configure your own git remote; nothing here is tied to a specific repository

## When to Ask vs. Act

- Small fixes, obvious bugs, formatting: just do it
- New dependencies, architectural decisions, structural changes: ask first
- Don't ask obvious questions - use your judgement
- If the answer is clearly implied by context, act on it

## Follow Existing Patterns

- Always check sibling files for conventions before creating new ones
- Match the codebase style - don't impose a different style
- Reuse existing components and utilities before writing new ones
- Stick to the existing directory structure; don't create new base folders without approval

## Skill routing

When the user's request matches an available skill, invoke it via the Skill tool. When in doubt, invoke the skill.

Key routing rules:
- Product ideas/brainstorming → invoke /office-hours
- Strategy/scope → invoke /plan-ceo-review
- Architecture → invoke /plan-eng-review
- Design system/plan review → invoke /design-consultation or /plan-design-review
- Full review pipeline → invoke /autoplan
- Bugs/errors → invoke /investigate
- QA/testing site behavior → invoke /qa or /qa-only
- Code review/diff check → invoke /review
- Visual polish → invoke /design-review
- Ship/deploy/PR → invoke /ship or /land-and-deploy
- Save progress → invoke /context-save
- Resume context → invoke /context-restore
