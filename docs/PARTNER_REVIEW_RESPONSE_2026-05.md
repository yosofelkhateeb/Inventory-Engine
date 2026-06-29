# Partner Review — Response & Implementation Log

> Companion document to the early-May 2026 partner review meeting.
> Each of the 11 review points is paired with the work that addressed it,
> with commit hashes for traceability.

**Review date:** early May 2026
**Implementation window:** 2026-05-04 → 2026-05-19
**Status:** all 11 points addressed and shipped to `main`.

---

## Point 1 — Recommender has to calculate the number of items to be ordered

**Status:** ✅ Shipped.

The decision engine has been computing `recommended_qty` per SKU since the MVP (via `DecisionScorer` in `app/Services/InventoryEngine/`). After the review, the number was promoted to a first-class field across the operator surfaces so it's visible without drilling in:

- New **Rec. Qty** column on the Dashboard tier tables (Order Now / Order Soon / Watchlist).
- **Recommended Qty** shown in the SKU detail dialog and `/skus/{id}` page.
- Carried through the recommendation exports (CSV + XLSX) so the order quantity flows out with the recommendation.

**Commits:** `c6ac66e` (Dashboard Rec. Qty column + qty entry on Mark Ordered/Received).

---

## Point 2 — Each SKU to have configuration settings to enter/edit

**Status:** ✅ Shipped.

A per-SKU edit form was added at `/skus/{id}/edit` (owner-only). Editable fields:

- Reorder point, safety stock multiplier override (lets the operator tighten or relax safety stock per SKU without changing the category default).
- Lead time, MOQ, unit cost, supplier — moved from "seeded once at ingestion" to live operator control.
- Category (equipment / accessory / bundle) — drives which forecast threshold band the SKU lives in.

The override lives on the SKU row and stacks cleanly on top of `AbcXyzClassifier`'s class-driven multiplier — operators can pin a stubborn SKU without touching the global thresholds.

**Commits:** `ee5624e` (per-SKU edit form with safety-stock multiplier override).

> **Related:** Point 5 (edit SKU details) is the same feature from a different angle — see below.

---

## Point 3 — When SKU is acknowledged, user should enter # of items ordered to be auto-updated

**Status:** ✅ Shipped.

The recommendation status flow now captures the operator's actual quantities at the moments they matter:

- **Mark Ordered** prompts for the actual order quantity and persists it to `inventory_decisions.ordered_qty` + `ordered_at`.
- **Mark Received** prompts for the actual received quantity → `received_qty` + `received_at`.
- **Ignore** prompts for a free-text reason → `ignored_reason`.

All three are written through `DecisionStatusService` so they appear in the audit trail (`status_history` JSON) and feed the weekly feedback-loop analysis (`AnalyzeRecommendationFeedbackJob`) that detects drift between what the engine recommended and what actually got ordered/received.

**Commits:** `c6ac66e` (qty entry on Mark Ordered/Received + reason on Ignore).

> **Related to point 1** — the recommended quantity is the default value pre-filled into the prompt.

---

## Point 4 — Recommendation → Audit Log doesn't update when pressing buttons

**Status:** ✅ Fixed.

Three issues were combined under this symptom:

1. **Tab navigation was a client-side toggle** — switching from Active Recommendations to Audit Log didn't re-hit the server, so the audit log only had data when the user landed on `/decisions/audit` directly. Switched both tabs to `router.visit` so the controller hydrates the right prop set per tab.
2. **`onStatusUpdated` only wrote to the local optimistic map** — after a status change, nothing reloaded server data. Added `router.reload({ preserveScroll, preserveState })` so the audit log, dashboard tier counts, and recent activity feed all reflect the new status without a full navigation.
3. **SKU detail page** — the `RecommendationActions` component wasn't wired to a status-updated handler, so the history table never refreshed after an action. Wired up the same way.

User-visible effect: clicking Acknowledge / Mark Ordered / Mark Received / Ignore now updates every dependent surface (status badge, lifecycle pill, audit log, dashboard counts, history table) without the user having to hard-refresh.

**Commits:** `4bb711b` (refresh data after recommendation status mutations).

---

## Point 5 — Should be able to edit SKU details (order, lead time, etc.)

**Status:** ✅ Shipped (same feature as point 2).

`/skus/{id}/edit` covers reorder point, lead time, MOQ, unit cost, safety-stock multiplier override, supplier, and category. Form is owner-only; non-owners see the read-only SKU detail page.

**Commits:** `ee5624e`.

---

## Point 6 — Hide technical details into a separate view (pressing a button)

**Status:** ✅ Shipped.

Operators were drowning in forecast diagnostics while trying to make daily ordering decisions. The agreed split:

**Always visible** to operators: decision, recommended qty, days of cover, urgency, lead time, MOQ, buffer.

**Behind the toggle** on SKU Detail (`SKUs/Show.vue`): model name, MAE, sMAPE, prediction intervals, last trained, next review, reeval trigger, selection rationale, demand profile (volume / volatility / intermittency / seasonality / trend), forecast warnings, feedback metrics table.

The toggle reads **"Show Technical Details"** when collapsed (default) and **"Hide Technical Details"** when expanded.

Same toggle was extended to the **Reports** page so an operator who lands there can collapse it down to SKU + Demand Rate.

**Tables — technical columns dropped entirely** from operator-facing list views:

- Recommendations table (`/decisions`): demand/day column removed.
- Dashboard tier tables: demand/day column removed from mobile cards.
- SKU detail dialog popup: demand/day item removed.

> **Course correction:** during implementation we initially removed Demand/Day from everywhere. The partner pushed back: an operator asking "roughly how many do I sell per day?" is doing inventory work, not ML work. Demand/Day was restored to operator surfaces; the rest of the technical bundle stayed behind the toggle. The Reports page now defaults to operator view as well.

**Commits:** `7bb5f4e` (initial hide), `42aa777` (restore Demand/Day + extend toggle to Reports), `4789ea6` (Reports defaults to operator view).

---

## Point 7 — Removing technical details from CSV export

**Status:** ✅ Shipped.

Three exports were touched:

- `decisions.csv` (operator-facing) — dropped `demand_per_day`. Remaining columns: `sku_code, sku_name, decision, status, confidence, days_cover, recommended_qty, last_updated`.
- `<SKU_CODE>-summary.xlsx` (SKU summary) — technical columns (`demand_rate`, interval bounds, MAE, sMAPE, model name, `trained_at`) stripped. See point 8 for the structural fix.
- `forecast-report.csv` (Reports page) — unchanged. Reports is the **analyst surface** by design and technical fields belong there. When the Reports operator-toggle is collapsed, however, the CSV export mirrors the visible view (collapsed CSV ships only `sku_code, sku_name, demand_rate`).

**Commits:** `41c3066` (SKU summary technical strip), `7bb5f4e` (decisions.csv strip), `4789ea6` (Reports CSV mirrors view).

---

## Point 8 — The export summary doesn't aggregate

**Status:** ✅ Shipped.

Two structural problems, both fixed:

1. **Duplicate rows on summary.** Previous behavior produced one populated summary row plus N blank-suffix duplicate rows (one per past engine run), with technical columns filled only on the first. Replaced with a two-section export:
   - **Section 1 — Summary:** one row of current SKU state (stock, in-transit, reserved, effective position, MOQ, lead time, ABC·XYZ, current decision, recommended qty, days of cover, buffer, urgency, status, last run).
   - **Section 2 — Recommendation History:** one row per past engine run, each a self-contained snapshot (decision, status, recommended qty at time of run, days of cover at time of run) plus the operator's actuals (`ordered_qty, received_qty, ignored_reason`).

2. **Format upgrade.** First pass shipped a CSV with section dividers; the partner asked for proper structured sheets. Switched to **`.xlsx`** with two named sheets ("Summary" and "Recommendation History") using `exceljs`. Shared columns use identical headers in both sheets so the consumer can paste them together if needed. Headers are bold; columns sized to content.

Filename: `<SKU_CODE>-summary.xlsx`.

**Commits:** `41c3066` (CSV restructure + dedupe + technical strip + history snapshot), `9db90f1` (CSV → XLSX with two named sheets).

---

## Point 9 — "Uplift %" to be investigated in Promotions

**Status:** ✅ Shipped — three-tier hybrid prediction engine.

The investigation surfaced a deeper problem than the operator just typing a guess. Asking for `expected_uplift_pct` puts the operator on the wrong side of the prediction — their knowledge sits on the **cause** side (discount depth, channel mix, ad spend, audience, lead time), not the **effect** side. So the work landed in two phases:

### Phase 1 — Engine-suggested uplift (2026-05-06)

First response: keep the existing single-number input, but compute a suggestion. New `UpliftSuggester` service fell back broadest-to-narrowest:

1. SKU match → past promos targeting any of the same SKUs.
2. Category match → past promos targeting any of the same categories.
3. Type match → past promos of the same promotion_type.
4. Settings default for the type (flash 50 / clearance 40 / seasonal 25 / bundle 20 / other 15).

New `GET /promotions/suggest-uplift` endpoint. UI gained a badge under the input: **"Suggested by engine"** (blue) when the input matched the suggestion, **"Manual override — engine suggests N%"** (amber) with one-click "use suggestion" link when it differed.

**Commits:** `3e34839`.

### Phase 2 — Campaign Brief + Layered Prediction Engine (2026-05-09 → 2026-05-10)

The right answer was to replace the single typed number with a structured **Campaign Brief** (7 cause-side fields) feeding a **three-layer hybrid prediction engine**:

| Layer | Active when | Method |
|---|---|---|
| **Layer 1 — Rule-based** | Day 1, always on | Configurable rule coefficients per Brief field, multiplied to produce a baseline estimate. Generalises the old `uplift_default.*` settings. |
| **Layer 2 — Nearest-neighbour** | After ≥ N tagged campaigns (`uplift.min_nn_samples`, default 5) | Cosine distance over normalised Brief features against past campaigns; returns median + IQR of their actual lift. |
| **Layer 3 — LightGBM regression** | After ≥ N tagged campaigns (`uplift.min_ml_samples`, default 50, with per-category gate at 10) | LightGBM trained on (Brief features + SKU classification + historical context) → lift per SKU. Quantile bands for confidence. |

The Brief fields (replacing the typed uplift): `discount_pct`, `discount_type`, `channel_mix[]`, `ad_spend_band`, `audience`, `lead_announcement_days`, `run_window_days` (existing).

**UI:** Promotion form now shows the seven Brief fields, a live **Engine Prediction Panel** with the point estimate + confidence band + layer attribution ("rule-based" / "based on N similar past campaigns" / "model trained on N campaigns"), and a collapsed "Adjust prediction" override flow that captures both the manual value and a free-text override reason.

**Backfill:** to avoid cold-start, a retroactive promotion-tag seeder backfilled 24 months of synthetic history so Layer 2 has comparable campaigns to draw from on day 1.

The narrowing confidence band over time is the visible "the system is learning" signal to the client.

**Commits:** `32c6f68` (Brief schema + templates), `e75283a` (Layer 1), `3c342bc` (Layer 2), `eef2a8c` (PredictionEngine orchestrator), `8138074` (PromotionController wiring, UpliftSuggester retired), `6308fc0` (Vue Brief form + live prediction panel + override flow), `edb1982` ("Predicted Uplift" rename + tooltip), `ec512a4` (Layer 3 LightGBM scaffold + threshold-gated routing), `b222534` (retroactive tag seeder — 24 months backfill), `d012634` (widen baseline gap fix).

---

## Point 10 — Lock "Forecast Settings". Add warning message before editing

**Status:** ✅ Shipped.

Settings drive how the engine generates recommendations. An accidental keypress on `bias_drift_threshold` or `k_smape` silently retrains the engine's behaviour. Added a deliberate **two-step gate** before any save commits:

1. Settings page lands with **all inputs disabled** even for owners. A blue **"Settings are locked"** notice with an **"Unlock to Edit"** button sits at the top.
2. Clicking Unlock enables every input and replaces the notice with an **amber "Editing unlocked"** warning showing a live count of pending changes plus a **"Discard & Lock"** affordance to revert.
3. The **Save Settings** button is disabled while locked, or while no fields differ from server-side values (no accidental no-op POSTs).
4. Clicking Save opens a **confirmation modal**: short rationale of the impact, a count of changed fields, an "**I understand these changes will affect forecast behavior and downstream recommendations**" checkbox, and an **"Agree & Save"** button that stays disabled until the checkbox is checked.
5. After a successful save, the page **auto-relocks** so the next round of edits requires a fresh Unlock — prevents drift from changes left half-typed in a still-unlocked form.
6. **Esc** closes the dialog. Backdrop click cancels.

Owner-only gate at the controller layer (`PATCH /settings` checks `hasRole('owner')`) is **defence in depth** — the guardrail sits on top of it, not in place of it.

**Commits:** `82d5ce4`.

---

## Point 11 — Forecast model retraining should take place more frequently

**Status:** ✅ Shipped.

The retraining cadence has three triggers, and we tightened the one that was lagging:

| Trigger | Before | After |
|---|---|---|
| **Monthly safety-net sweep** | 1st of the month, 03:00 | **Every other Saturday, 03:00** (bi-weekly) |
| **Bias drift check** | Daily at 07:00 (unchanged) | Daily at 07:00 |
| **Weekly feedback analysis** | Weekly (unchanged) | Weekly |
| **Event-driven (new SKU)** | On SKU create via `SkuObserver` (unchanged) | On SKU create |

The week parity is anchored to a fixed Saturday so the bi-weekly cadence stays stable across year boundaries. The off-peak weekend slot avoids contention with the daily 06:00 engine run.

In production the scheduler daemon fires this automatically and Horizon processes the dispatched jobs — no manual step.

**Net effect:** every SKU gets a fresh model fit at least every ~14 days, plus event-driven re-evaluation whenever bias drifts beyond threshold or the feedback loop flags a discrepancy between recommendations and operator actuals. Auto-updates now keep step with live data far more aggressively than the prior monthly cadence.

**Commits:** `edffafa` (bi-weekly forecast sweep), `dc3b8d3` (local queue tuning revert — production stays Redis + Horizon, local stays sync for fast iteration).

---

## Summary

| # | Point | Commits |
|---|---|---|
| 1 | Recommender calculates order quantity | `c6ac66e` |
| 2 | Per-SKU config editable | `ee5624e` |
| 3 | Capture ordered/received qty on status change | `c6ac66e` |
| 4 | Audit log refreshes on action | `4bb711b` |
| 5 | Edit SKU details (= point 2) | `ee5624e` |
| 6 | Hide technical details behind toggle | `7bb5f4e`, `42aa777`, `4789ea6` |
| 7 | Strip technical columns from CSV exports | `41c3066`, `7bb5f4e`, `4789ea6` |
| 8 | Export summary doesn't aggregate / multi-sheet XLSX | `41c3066`, `9db90f1` |
| 9 | Uplift % redesign — Campaign Brief + 3-layer prediction | `3e34839`, `32c6f68`, `e75283a`, `3c342bc`, `eef2a8c`, `8138074`, `6308fc0`, `edb1982`, `ec512a4`, `b222534`, `d012634` |
| 10 | Settings lock + warning before edit | `82d5ce4` |
| 11 | More frequent retraining (bi-weekly) | `edffafa`, `dc3b8d3` |

All 11 points were shipped to `main` between 2026-05-04 and 2026-05-19.

The full commit log is available at `git log --oneline --since=2026-05-04 --until=2026-05-19 main`.
