# Search, Filter & Sort — Design Document

**Date:** 2026-03-24
**Scope:** All three tables in the system

---

## Goal

Add search, filter, and sortable column headers to all three data tables. Built client-side now, designed for a clean migration to server-side when the dataset grows beyond a few hundred SKUs.

---

## Tables in Scope

| Table | Location |
|---|---|
| Needs Attention | Dashboard → Overview tab |
| All Decisions | Dashboard → All SKUs tab |
| SKU Catalogue | `/skus` page |

---

## Architecture

A `useTableControls` composable owns all state (search string, active filters, sort key + direction). It takes a reactive data source and returns a `filtered` computed array. Each table calls the composable with its own data and field config.

A shared `TableControls.vue` component renders the search box and filter dropdowns. Each table passes its own filter definitions as props.

### Migration path to server-side
Replace the `computed()` filtering logic in the composable with an `Inertia.get()` call that passes the same state as URL query params. The `TableControls.vue` component and all table markup remain unchanged.

---

## Composable: `useTableControls<T>`

**Location:** `resources/js/composables/useTableControls.ts`

**Inputs:**
- `source: () => T[]` — reactive data array
- `searchFields: (keyof T)[]` — which fields to match search string against
- `filters: FilterDefinition[]` — filter key + allowed values

**State:**
- `search: ref<string>`
- `activeFilters: ref<Record<string, string>>` — e.g. `{ decision: 'order', abc_class: 'A' }`
- `sortKey: ref<keyof T | null>`
- `sortDir: ref<'asc' | 'desc'>`

**Outputs:**
- `filtered: ComputedRef<T[]>` — search → filter → sort applied in order
- `setSort(key: keyof T)` — toggles direction if same key, resets to `asc` on new key
- `resultCount: ComputedRef<number>` — count of filtered rows
- `totalCount: ComputedRef<number>` — count of source rows

---

## Component: `TableControls.vue`

**Location:** `resources/js/Components/TableControls.vue`

**Layout:**
```
[ 🔍 Search...__________ ]  [ Decision ▾ ]  [ Class ▾ ]    Showing X of Y  [Clear all]
```

- Search input on the left
- Filter dropdowns — one per filter definition, only rendered when filters are passed
- Active filter: amber ring highlight (matches existing badge design language)
- Result count: right-aligned, `text-slate-400`, hidden when no controls are active
- "Clear all" link: appears only when search or any filter is active

**Props:**
```ts
search: string
filters: FilterDefinition[]          // [{ key, label, options: [{value, label}] }]
activeFilters: Record<string, string>
resultCount: number
totalCount: number
```

**Emits:**
```ts
update:search
update:filter  // { key, value }
clear
```

---

## Filter Options Per Table

### Needs Attention (Dashboard Overview)
- **Search:** SKU code, SKU name
- **Filters:** none (already a pre-filtered urgency view)
- **Sort:** Days Cover, Effective Position, Demand/day, Lead Time

### All Decisions (Dashboard All SKUs tab)
- **Search:** SKU code, SKU name
- **Filters:** Decision (all / ORDER / WATCH / HOLD), ABC Class (all / A / B / C), XYZ Class (all / X / Y / Z)
- **Sort:** Name, Days Cover, Rec. Qty, On Hand, Effective, Demand/day, Lead Time

### SKU Catalogue (`/skus`)
- **Search:** SKU code, name, supplier name
- **Filters:** Decision (all / ORDER / WATCH / HOLD), ABC Class (all / A / B / C), Supplier (dynamic from data)
- **Sort:** Name, On Hand, Days Cover, Unit Cost

---

## Sortable Column Headers

- Sortable `<th>` elements get `cursor-pointer` and a hover highlight
- Active sort column shows a chevron: `↑` (asc) or `↓` (desc) in `text-slate-400`
- Non-sortable columns (badge-only, actions): no indicator, no hover effect
- SKU code column is intentionally non-sortable (identifier, not a ranking axis)

---

## What Is Not In Scope

- Server-side pagination (future)
- URL query param persistence (future, part of server-side migration)
- Column visibility toggles (future)
- Export/download filtered results (future)
