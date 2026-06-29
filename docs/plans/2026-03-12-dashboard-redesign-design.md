# Dashboard Redesign — Design Document

**Goal:** Replace the current flat decisions table with a two-section insight view that surfaces urgency, stock breakdown, and decision context — giving the owner everything needed to understand stockout risk at a glance.

**Scope:** Read-only insight view. No financial data. No action buttons.

---

## Stat Cards (4 cards, top row)

| Card | Value | Color |
|---|---|---|
| Order Now | Count of `order` decisions | Red |
| Watch | Count of `watch` decisions | Yellow |
| Stockout Risk | SKUs where `days_of_cover < lead_time` | Dark red |
| Avg Days of Cover | Average across all SKUs | Gray |

---

## Section 1 — Needs Attention Panel

Compact list of ORDER NOW + WATCH SKUs only.

Each row shows:
- SKU code + name
- Decision badge
- Urgency gap: "X days stock / Y day lead time"
  - `days_of_cover < lead_time` → Critical (red)
  - `days_of_cover` between `lead_time` and `lead_time × 1.3` → Tight (yellow)
- Effective position (on-hand + in-transit − reserved)
- Daily demand forecast

Empty state: green "All stock levels healthy" message.

---

## Section 2 — Full SKU Table

All 11 SKUs, urgency-sorted:
1. ORDER NOW where `days_of_cover < lead_time` (critical — already late)
2. ORDER NOW where `days_of_cover >= lead_time`
3. WATCH
4. HOLD (sorted by `days_of_cover` ascending)

Columns: SKU, Name, Decision, On Hand, In Transit, Reserved, Effective Position, Daily Demand, Days of Cover, Lead Time, Safety Stock, Reorder Point.

---

## Data Changes

`DashboardController` needs to additionally pull per-decision row:
- `current_stock`, `in_transit_qty`, `reserved_qty`, `lead_time_days` from `skus`
- `forecast_demand` from `inventory_decisions`
- `safety_stock` from `reasoning` JSON field

No new migrations needed — all data already stored.
