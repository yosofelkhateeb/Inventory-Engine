# Phase 1 Improvements — Design Document

**Date:** 2026-03-17
**Scope:** Quick-win additions to the inventory engine MVP. All items are Low effort. Medium-effort items deferred to Phase 2.

---

## Features in Scope

1. Engine run feedback + daily scheduler
2. Stock adjustments tab (separate, low priority, may be removed later)
3. ABC-XYZ SKU classification (badge in UI + glossary)
4. Real-time alerts — notification bell (Reverb frontend)
5. Dead stock flag on dashboard (30-day threshold)

---

## 1. Engine Run Feedback + Scheduler

### Backend

**New table: `engine_runs`**

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `triggered_by` | bigint FK → users, nullable | null = scheduled run |
| `run_at` | timestamp | when the run started |
| `status` | enum: running / completed / failed | |
| `decisions_count` | int | total SKU decisions produced |
| `duration_ms` | int, nullable | wall-clock time for the run |
| `timestamps` | created_at / updated_at | |

- `InventoryEngineService::run()` creates an `EngineRun` record with `status = running` at the start, updates it to `completed` (or `failed` on exception) when done.
- `RunInventoryEngineJob` passes the authenticated user ID (or `null` for scheduled) into the service.
- Laravel Scheduler in `app/Console/Kernel.php` dispatches `RunInventoryEngineJob` daily at **06:00**.
- `EngineController` passes the authenticated user's ID when dispatching manually.

### Frontend

`DashboardController` reads the latest `engine_runs` record and passes it as `lastRun` to the Inertia page:

```php
'lastRun' => EngineRun::latest('run_at')->first()?->only(['run_at', 'decisions_count', 'status'])
```

The Run Engine button area shows:

```
[↻ Run Engine]   Last run: 3 hours ago · 11 decisions   ✓
```

- Timestamp formatted as relative time ("3 hours ago", "just now")
- Decisions count shown after the dot
- Status chip: green checkmark for completed, red X for failed, spinner for running
- Hidden entirely if no run has ever been recorded

---

## 2. Stock Adjustments Tab

**Status: Low priority — separate tab in the system, may be removed in future.**

A standalone page at `/adjustments`. Not linked from the main navigation for now — accessible by direct URL.

### Backend

**New table: `stock_adjustments`**

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `sku_id` | bigint FK → skus | |
| `user_id` | bigint FK → users | who submitted it |
| `old_qty` | int | `current_stock` before adjustment |
| `new_qty` | int | `current_stock` after adjustment |
| `delta` | int (computed) | new_qty − old_qty |
| `reason_code` | enum | see below |
| `notes` | text, nullable | freetext optional |
| `adjusted_at` | timestamp | |
| `timestamps` | created_at / updated_at | |

**Reason codes:** `cycle_count`, `damage_writeoff`, `customer_return`, `supplier_short_ship`, `data_entry_correction`, `internal_use`

On submit, `skus.current_stock` is updated to `new_qty` and an adjustment record is inserted atomically (DB transaction).

### Frontend

Simple form: SKU selector → current qty shown read-only → new qty input → reason code dropdown → optional notes → submit. Table below showing adjustment history for the selected SKU.

---

## 3. ABC-XYZ SKU Classification

### Classification Logic

Runs at the start of every `InventoryEngineService::run()` call, before individual SKU scoring. Results written to `skus.abc_class` and `skus.xyz_class`.

**ABC — by 90-day revenue contribution (unit_cost × units sold):**
- Rank all SKUs by descending revenue
- Cumulative share: 0–70% = A, 70–90% = B, 90–100% = C

**XYZ — by coefficient of variation (CV) of daily sales over 90 days:**
- CV = stddev ÷ mean
- CV < 0.5 → X (stable)
- CV 0.5–1.0 → Y (variable)
- CV > 1.0 → Z (erratic)
- If demand = 0 (no history) → Z by default

**New columns on `skus`:**

| Column | Type |
|---|---|
| `abc_class` | enum: A / B / C, nullable |
| `xyz_class` | enum: X / Y / Z, nullable |

### Badge Design

Displayed as a compact two-part pill: `A · X`

Color scheme:

| ABC | Background | Text |
|---|---|---|
| A | red-100 | red-700 |
| B | amber-100 | amber-700 |
| C | slate-100 | slate-600 |

XYZ expressed through badge style:
- X → solid fill (full opacity)
- Y → medium opacity (75%)
- Z → outlined/ring only, no fill

Appears on:
- All SKUs tab in the dashboard (new column after Decision)
- SKU Catalogue (`/skus` index) — new column after Decision

### Engine Behavior by Class

The classification adjusts safety stock multiplier in `DecisionScorer`:

| Class | Safety Stock Multiplier |
|---|---|
| A·Z | ×1.5 (extra buffer — high stakes, erratic) |
| A·Y | ×1.2 |
| C·Z | ×0.8 (fixed buffer, don't over-invest) |
| all others | ×1.0 (standard formula) |

---

## 4. Real-Time Alerts — Notification Bell

### Backend (already built)

`StockAlertEvent` fires after each engine run if any ORDER NOW decisions exist. Broadcasts on `private-inventory-alerts.{userId}` via Reverb. Payload includes array of `{ sku_code, sku_name, days_of_cover, lead_time_days }`.

### Frontend

**New `NotificationBell.vue` component** in `resources/js/Components/`.

- Bell icon (Heroicons `BellIcon`) in the top-right of every authenticated page header
- Subscribes to the Reverb private channel on mount using `window.Echo`
- Incoming events appended to a local `alerts` ref array (kept in memory, not persisted)
- Clicking the bell opens a dropdown panel showing the alerts list:
  - Each row: SKU name + code, "ORDER NOW", days of cover vs lead time
  - "No alerts" empty state when list is empty
  - "Clear all" button to reset the list
- No counter badge — bell icon only, no number overlay
- Dropdown closes on outside click

**App layout:** A shared header bar is extracted into `resources/js/Components/AppHeader.vue`, included in all authenticated page templates (Dashboard, SKUs/Index, SKUs/Show). The bell lives in this shared header.

---

## 5. Dead Stock Flag

### Definition

A SKU is flagged as dead stock if it has **zero units sold in the last 30 days** AND `current_stock > 0`. SKUs with zero stock are excluded (nothing to flag).

### Where it appears

On the **Overview tab** of the dashboard, between the stat cards and the Needs Attention panel:

- **Shown:** compact amber alert panel listing the dead stock SKUs by name + days since last sale
- **Hidden:** entirely if no SKUs qualify
- Not a separate stat card — it's a contextual warning, not a permanent metric

### Data

`DashboardController` computes dead stock SKUs by querying `sales_history` for the last 30 days and cross-referencing `skus.current_stock > 0`. Passed as `deadStock` array to the Inertia page.

---

## Glossary Additions

New entries added to the existing alphabetically-sorted glossary in `Dashboard/Index.vue`:

**ABC Classification** — Ranks SKUs by their share of total sales revenue over 90 days. A = top 70% of revenue (your most critical products), B = next 20%, C = bottom 10%.

**XYZ Classification** — Ranks SKUs by how predictable their daily demand is. X = stable and consistent, Y = variable but patterned, Z = erratic and hard to forecast.

**ABC-XYZ Combinations** — shown as a sub-table in the glossary:

| Label | What it means | How to treat it |
|---|---|---|
| A·X | High revenue, predictable demand | Tight safety stock, trust the engine fully |
| A·Y | High revenue, variable demand | Moderate buffer, monitor weekly |
| A·Z | High revenue, erratic demand | Large buffer — most dangerous SKU type |
| B·X | Mid revenue, predictable | Standard engine logic, routine monitoring |
| B·Y | Mid revenue, variable | Slightly elevated safety stock |
| B·Z | Mid revenue, erratic | Fixed buffer, review manually if flagged |
| C·X | Low revenue, predictable | Minimal safety stock, low review frequency |
| C·Y | Low revenue, variable | Low priority, watch for trend changes |
| C·Z | Low revenue, erratic | Consider discontinuing — sporadic demand, low value |

---

## Pages & Routes Summary

| Change | Type |
|---|---|
| `engine_runs` migration + model | New |
| `stock_adjustments` migration + model | New |
| `skus.abc_class`, `skus.xyz_class` columns | Migration (addColumn) |
| `InventoryEngineService` — ABC-XYZ compute + run logging | Modify |
| `RunInventoryEngineJob` — pass user ID | Modify |
| `EngineController` — pass user ID | Modify |
| `DashboardController` — lastRun + deadStock | Modify |
| `app/Console/Kernel.php` — daily scheduler | Modify |
| `Dashboard/Index.vue` — lastRun display, dead stock panel, ABC-XYZ in All SKUs tab, glossary additions | Modify |
| `SKUs/Index.vue` — ABC-XYZ badge column | Modify |
| `Components/AppHeader.vue` — shared header with bell | New |
| `Components/NotificationBell.vue` — Reverb listener + dropdown | New |
| `Pages/Adjustments/Index.vue` + controller + route | New (low priority) |

---

## Out of Scope (Phase 2)

- Settings page (service level, reorder horizon, supplier edits)
- Holt-Winters seasonal forecasting
- Reports page
- Supplier overview page
