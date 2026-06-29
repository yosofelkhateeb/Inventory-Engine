# Inventory Decision Engine — System Guidebook

> **Purpose of this document.** A bird's-eye reference you can return to when the day-to-day work starts pulling you into the weeds. It describes what the system *is*, who it's *for*, what it *does*, and what it should *feel like* when it's done right. It is not a spec. For specs, see the `docs/` folder.

---

## 1. What This System Is

A **hybrid inventory decision engine** — a productized SaaS offering that replaces gut-feel reordering with data-driven recommendations for small-to-mid e-commerce businesses.

It is a **recommendation and action-tracking engine**, not a procurement platform. It monitors stock, forecasts demand, outputs `ORDER NOW` / `WATCH` / `HOLD` per SKU with a recommended quantity and confidence, and tracks whether the user acted on the recommendation externally. It does **not** generate POs, contact suppliers, or handle invoicing.

The first client is an e-commerce business selling football products (~30 SKUs) currently managing inventory via Excel. The system is built to scale to any catalogue size — nothing about the SKU count is hardcoded anywhere.

---

## 2. Why This System Exists

Small-to-mid e-commerce operators lose money in two directions at once:

- **Stockouts** → lost sales, disappointed customers, channel penalties
- **Overstock** → tied-up cash, storage cost, obsolescence risk

Most operators manage this by eyeballing spreadsheets, using supplier reps' advice, or applying static reorder points that don't adapt to seasonality, promotions, or lead-time variability. Off-the-shelf tools (Inventory Planner, Skubana, etc.) exist but are often too expensive, too complex, or built for a different operational shape.

The opportunity: a **simpler, smarter, more affordable system** that gives operators a defensible answer to "what should I order today, and how much?" — and learns from whether they agreed with it.

---

## 3. Core Philosophy

Four principles shape every design decision:

**Decision-engine-first, not forecast-first.** Forecasting is one input among many. The engine combines forecast + inventory position + lead-time uncertainty + constraints + SKU classification to produce a decision. A 30% forecast error does not break the system because safety stock and lead-time buffers absorb it.

**Advisory and read-only.** The system never triggers a purchase. Every action outside the engine (placing the PO, contacting the supplier) stays with the user. The engine's job is to make the decision easy to *defend*, not to automate it.

**Every numerical parameter is a configurable lever.** Service level, safety multipliers, sensitivity thresholds, review periods, MOQ, weighting across years — all externalised in YAML or the settings table. The system has an opinionated default, but every default can be overridden and optimised per client.

**Transparent by design.** The user must be able to see *why* a recommendation was made. Confidence labels, reasoning traces, and drift indicators are first-class citizens — not hidden in logs.

---

## 4. Who Uses It, and What They Need

The system serves three distinct user roles, each with different needs from the same data.

**The owner / operator.** Wants a high-confidence answer to "what do I need to do this week?" Cares about inventory health across the catalogue, cash tied up in stock, stockout risk, and whether the system's recommendations have been trustworthy over time. Spends most of their time on the dashboard and the recommendations list.

**The warehouse / procurement staff.** Acts on recommendations day-to-day. Needs to acknowledge, mark as ordered, record what actually arrived, and flag when something was ignored and why. Should never have to leave a recommendation view to act on it.

**The analyst (internal or external).** Calibrates the engine: inspects drift, adjusts thresholds, reviews the feedback loop, looks at audit logs. May be Youssef during onboarding, may be a client-side data person later.

Current role model (Spatie): `owner`, `warehouse`, `viewer`.

---

## 5. What the System Does (End-to-End)

**Data ingestion.** Plug-in adapter system (`IngestionSource` interface). Phase 1 is CSV upload. Phase 2 is Shopify integration. Observability table tracks every ingestion attempt.

**Forecasting.** A 15-stage Python pipeline per SKU: data audit, gap classification, preprocessing, EDA, baselines, feature engineering, candidate model competition (Holt-Winters, SARIMAX, Prophet, LightGBM, ETS fallback), walk-forward cross-validation, prediction intervals with empirical coverage correction, diagnostics, selection. Every parameter lives in `forecasting_config.yaml`. Backtesting runs are backend-only — never surfaced to clients.

**Inventory position tracking.** On-hand + in-transit + commitments, projected forward over the lead-time window.

**Lead-time handling.** Uses P90 of historical lead times (or promised-vs-actual delta when that data exists), per-supplier. Adjustable by SKU priority.

**Constraints.** MOQ enforcement, budget caps.

**Classification.** ABC/XYZ per SKU — drives safety stock multiplier.

**Decision scoring.** Combines all signals into `ORDER NOW` / `WATCH` / `HOLD` + recommended quantity + confidence label + reasoning trace.

**Action tracking.** Every recommendation has a status lifecycle:
```
PENDING → ACKNOWLEDGED → ORDERED → IN_TRANSIT → RECEIVED
                                              ↘ IGNORED
                                              ↘ SUPERSEDED
```
All transitions are audited (`status_changed_at`, `status_changed_by`, `status_notes`). Terminal statuses cannot transition further. `SUPERSEDED` happens when a new engine run replaces an unresolved recommendation.

**Feedback loop.** Weekly `recommendation:analyze-feedback` job reads action-tracking data, computes four signals (IGNORED reasons, ordered-vs-recommended delta, received-vs-ordered delta, SUPERSEDED rate), and feeds them back into forecasting calibration via a `feedback_drift` re-evaluation trigger.

**Re-evaluation triggers.** Forecasts are re-run on four signals: `scheduled` (monthly sweep), `new_sku`, `anomaly_flag` (sustained demand spike), `bias_drift` (trailing 30-day, stockout dates excluded), `feedback_drift`.

---

## 6. What the User Should See

The UI's job is to put the right information in front of the right user at the right moment. Every page must answer: *what decision does the user make here, and what do they need to make it confidently?*

**Dashboard.** Inventory health at a glance. Status distribution, SKUs at risk, confidence overview, recent activity. First thing the client sees on login.

**Recommendations.** Full list. Filterable by status, SKU, confidence. Sortable by urgency or days of cover. Inline action buttons — user acts without leaving the view. Each row shows: forecasted demand, current stock, days of cover, reorder point, suggested quantity, confidence label, last action.

**SKU Detail.** Per-SKU deep dive. Forecast chart with prediction intervals. Recommendation history. Action history. Drift indicators. Configurable parameters.

**Ingestion.** CSV upload flow with clear error surfacing. Shopify connection status, last sync time, ingestion history.

**Audit Log.** Full status transition history. Filterable. Exportable. Low-effort, high client trust.

**Settings.** Threshold editor for all configurable keys. Snapshot exportable for backup or handoff.

**Reports.** Consolidated views for export and sharing.

---

## 7. Exportable / Shareable Outputs

Because the system does not generate POs, its outputs must flow cleanly into the user's external procurement workflow:

- **Recommendations list** → CSV (for sending to suppliers, sharing with team)
- **Per-SKU forecast summary** → PDF or CSV (client-facing summary)
- **Audit log of status transitions** → CSV (for client records, supplier communication)
- **Settings snapshot** → JSON/YAML (backup, handoff, or onboarding a new client)

---

## 8. Design Principles for the UI

**Functionality before aesthetics.** Most pages already exist. The work is not a visual refresh — it's a functional redesign around the user's workflow.

**Confidence must be visually meaningful.** Not just a "high/medium/low" label in text. A user should be able to scan a list and instantly see which recommendations they can trust more.

**Surface drift and overrides.** Where the model has drifted or been frequently ignored, that fact belongs on the dashboard — not buried in a settings page.

**Enable pattern recognition.** The user needs to see *across* SKUs: which are healthy, which are at risk, which have a history of ignored recommendations.

**Action without context-switching.** A user should act on a recommendation without navigating away from where they saw it.

---

## 9. Architecture at a Glance

**Application shell.** Laravel 12 + Vue 3 + Inertia.js + MySQL 8 + Redis 7. TypeScript + Composition API for Vue. Pest for tests.

**Forecasting brain.** Python 3.11 microservice, called by `RunForecastJob` via subprocess. Config in `forecasting_config.yaml`. Output JSON is upserted into `forecast_model_registry` and `sku_demand_profiles`.

**Real-time layer.** Laravel Reverb broadcasts `StockAlertEvent` on ORDER NOW decisions. Horizon manages queues.

**Multi-tenant from day one.** `tenants` table, `tenant_id` on all core tables, global `TenantScope` Eloquent scope. Current client = `tenant_id 1`.

**Long-running processes (five).** Web server, queue worker (inventory), queue worker (forecasting), scheduler, Reverb.

**Deployment.** Online via public subdomain. No localhost dependency.

For depth: `docs/ARCHITECTURE.md`, `docs/FORECASTING_ENGINE.md`, `docs/INVENTORY_ENGINE.md`, `docs/DATA_INGESTION.md`, `docs/DEPLOYMENT_PREREQUISITES.md`.

---

## 10. Locked Architectural Decisions

These are settled. Revisit only with a strong reason.

1. **Online deployment** via public subdomain. Not localhost.
2. **Recommendation engine only** — no PO generation, no supplier contact, no invoicing.
3. **Data ingestion is a plug-in adapter system** (`IngestionSource` interface). CSV is Phase 1, Shopify is Phase 2.
4. **Backtesting is backend-only.** Never surface a backtest UI to clients.
5. **`confidence_label` three-band heuristic** (high / medium / low based on coverage × interval width).
6. **`feedback_drift` trigger** as a fourth re-evaluation signal, fed by the weekly feedback job.
7. **`bias_drift` reused** for material sales-history corrections (no new trigger added).
8. **Shopify `current_stock` overrides `RECEIVED` status tracking** when both are active.

---

## 11. What's Explicitly Out of Scope

These were considered and dropped — don't let them creep back in without a deliberate decision.

- PO generation, supplier contact, invoicing
- Client-facing backtest UI
- Arabic RTL support
- Multi-warehouse / multi-location inventory
- Multi-currency
- Returns and adjustments flow
- Demand sensing from forward orders (system is historical-sales only)
- Commercial framing and pricing (separate concern)

---

## 12. Deferred to Pre-Production

Tracked in `TODO_PRE_PRODUCTION.md` with trigger conditions — not dropped, just not now:

- Failure UX
- Backup strategy
- Scale testing
- Security final pass
- Frontend polish

---

## 13. Guiding Principles When Working on This System

**Numerical parameters are levers, not constants.** If you catch yourself hardcoding a threshold, stop — it belongs in config.

**Defer items explicitly, never silently.** If something is out of scope for today, it goes in `TODO_PRE_PRODUCTION.md` with a trigger condition. Don't just drop it and hope no-one notices.

**Respect scope boundaries.** Deployment infrastructure, commercial framing, and frontend polish are tracked separately from engine and data-science work. Don't let them bleed into the core.

**Senior-level standards.** If it feels hacky, it is. Stop and ask instead of guessing when a blocker appears.

**The system is the product.** Not the forecast, not the dashboard, not the script — the combination of forecasting + inventory logic + actionable recommendations + action tracking + feedback loop. That's what makes it defensible and valuable.

---

## 14. North Star

When a user opens the dashboard Monday morning, they should be able to:

1. See in under 10 seconds what needs their attention this week.
2. Act on each recommendation in one click without leaving the view.
3. Trust that the system has already accounted for lead-time uncertainty, promotions, seasonality, and their own past overrides.
4. Export what they need to share with their supplier or team, in the format that workflow expects.
5. Know, at a glance, when the system's confidence in itself has changed — and why.

Everything else is in service of that.
