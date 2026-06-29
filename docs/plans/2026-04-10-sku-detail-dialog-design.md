# SKU Detail Dialog — Design

**Date:** 2026-04-10  
**Status:** Approved

---

## Summary

Add a popup dialog that shows a current-state snapshot for any SKU. Triggered by clicking the SKU code or name in both the SKU Catalogue (`/skus`) and the Dashboard "All SKUs" tab. Data is fetched on demand via a lightweight JSON endpoint — not embedded in the page props — so the solution stays performant as the catalogue grows.

---

## Goals

- Give users a fast, in-context way to inspect a SKU without navigating away
- Keep page load payloads lean (on-demand fetch, not upfront)
- Work correctly at scale (payload per popup is constant regardless of catalogue size)
- Reuse a single shared component across both trigger surfaces

---

## Non-Goals

- No sales history in the popup (that stays on the full Show page)
- No decision history in the popup (latest decision only)
- No editing from within the popup

---

## Architecture

### Data Flow

```
User clicks SKU code or name
        ↓
SkuDetailDialog opens (spinner state)
        ↓
fetch('/skus/{id}/summary')
        ↓
SkuController@summary returns flat JSON
        ↓
Dialog renders snapshot
  "View full detail →" link navigates to /skus/{id}
        ↓
User closes (× button, backdrop click, or Esc key)
```

Plain `fetch` — no Inertia involvement. The endpoint sits inside the existing `auth` middleware group in `routes/web.php`.

---

## Backend Endpoint

**Route:** `GET /skus/{sku}/summary`  
**Controller:** `SkuController@summary`  
**Returns:** JSON (not an Inertia response)

### Response shape

```json
{
  "id": 4,
  "sku_code": "SKU-0042",
  "name": "Nike Grip Socks 8pk",
  "supplier_name": "Al Noor Trading",
  "unit_cost_sar": 12.50,
  "moq": 24,
  "lead_time_days": 14,
  "abc_class": "A",
  "xyz_class": "X",
  "current_stock": 123,
  "in_transit_qty": 10,
  "reserved_qty": 5,
  "effective_position": 128,
  "decision": "order",
  "constrained_qty": 60,
  "days_of_cover": 8,
  "reorder_point": 45,
  "forecast_demand": 4.2,
  "safety_stock": 12,
  "run_at": "2026-04-09 14:30:00"
}
```

If no decision record exists, all decision fields return `null`. The dialog handles this gracefully with a "No decision data yet" message.

---

## Frontend Component

**File:** `resources/js/Components/SkuDetailDialog.vue`

### Props
- `skuId: number | null` — `null` = closed; any SKU id = open and fetch

### Internal state
- `loading: boolean`
- `data: SkuSummary | null`
- `error: boolean`

### TypeScript interface

```ts
interface SkuSummary {
  id: number
  sku_code: string
  name: string
  supplier_name: string
  unit_cost_sar: number
  moq: number
  lead_time_days: number
  abc_class: 'A' | 'B' | 'C' | null
  xyz_class: 'X' | 'Y' | 'Z' | null
  current_stock: number
  in_transit_qty: number
  reserved_qty: number
  effective_position: number
  decision: 'order' | 'watch' | 'hold' | 'order_budget_blocked' | null
  constrained_qty: number | null
  days_of_cover: number | null
  reorder_point: number | null
  forecast_demand: number | null
  safety_stock: number | null
  run_at: string | null
}
```

### Layout

```
┌─────────────────────────────────────────────┐
│  SKU-0042  ·  A·X badge          [×]        │
│  Product Name                               │
│  Supplier Name                              │
├─────────────────────────────────────────────┤
│  INVENTORY                                  │
│  On Hand  In Transit  Reserved  Effective   │
│  123      10          5         128         │
├─────────────────────────────────────────────┤
│  LATEST DECISION                            │
│  [ORDER NOW]  Critical                      │
│  Days Cover  Lead Time  Reorder Pt  Safety  │
│  8           14d        45          12      │
│                                             │
│  Forecast Demand  Rec. Qty  Unit Cost  MOQ  │
│  4.2/day         60         12.50 SAR  24   │
├─────────────────────────────────────────────┤
│               View full detail →            │
└─────────────────────────────────────────────┘
```

### Behaviour
- Dark semi-transparent backdrop behind the dialog
- Clicking the backdrop closes the dialog
- `Esc` key closes the dialog
- While loading: spinner replaces content area
- On fetch error: error message with a retry option

---

## Trigger Points

### SKU Catalogue (`resources/js/Pages/SKUs/Index.vue`)
- SKU code and product name become `<button>` elements
- Existing `<Link>` on SKU code is removed (the "View full detail →" inside the dialog replaces it)
- `SkuDetailDialog` mounted once at bottom of template
- `selectedSkuId: Ref<number | null>` controls open/close

### Dashboard "All SKUs" tab (`resources/js/Pages/Dashboard/Index.vue`)
- Same treatment in the All SKUs table
- `SkuDetailDialog` mounted once at bottom of template
- Same `selectedSkuId` pattern

No changes to the Dashboard "Needs Attention" panel.

---

## Files Changed

| File | Change |
|---|---|
| `app/Http/Controllers/SkuController.php` | Add `summary()` method |
| `routes/web.php` | Add `GET /skus/{sku}/summary` route |
| `resources/js/Components/SkuDetailDialog.vue` | New component |
| `resources/js/Pages/SKUs/Index.vue` | Add trigger buttons + mount dialog |
| `resources/js/Pages/Dashboard/Index.vue` | Add trigger buttons + mount dialog |
