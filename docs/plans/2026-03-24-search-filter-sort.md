# Search, Filter & Sort — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add search, filter dropdowns, and sortable column headers to all three data tables (Dashboard Overview, Dashboard All SKUs, SKU Catalogue).

**Architecture:** A `useTableControls<T>` composable owns all state (search string, active filters, sort key + direction) and returns a `filtered` computed array. A shared `TableControls.vue` component renders the search box and filter dropdowns. Each table calls the composable independently. All filtering is client-side; migration to server-side requires only swapping the `computed()` for an `Inertia.get()` call — the component and all table markup stay unchanged.

**Tech Stack:** Vue 3 Composition API, TypeScript, Tailwind CSS v4

---

### Task 1: `useTableControls` composable

**Files:**
- Create: `resources/js/composables/useTableControls.ts`

**Step 1: Create the composable**

Create `resources/js/composables/useTableControls.ts` with the following content:

```typescript
import { computed, ref } from 'vue';

export interface FilterDefinition {
    key: string;
    label: string;
    options: { value: string; label: string }[];
}

export function useTableControls<T extends Record<string, unknown>>(
    source: () => T[],
    searchFields: (keyof T)[],
) {
    const search = ref('');
    const activeFilters = ref<Record<string, string>>({});
    const sortKey = ref<keyof T | null>(null);
    const sortDir = ref<'asc' | 'desc'>('asc');

    function setSort(key: keyof T): void {
        if (sortKey.value === key) {
            sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
        } else {
            sortKey.value = key;
            sortDir.value = 'asc';
        }
    }

    function setFilter(key: string, value: string): void {
        activeFilters.value = { ...activeFilters.value, [key]: value };
    }

    function clearAll(): void {
        search.value = '';
        activeFilters.value = {};
        sortKey.value = null;
        sortDir.value = 'asc';
    }

    const filtered = computed<T[]>(() => {
        let rows = source();

        // 1. Search
        const q = search.value.trim().toLowerCase();
        if (q) {
            rows = rows.filter(row =>
                searchFields.some(field => {
                    const val = row[field];
                    return val != null && String(val).toLowerCase().includes(q);
                }),
            );
        }

        // 2. Filters
        for (const [key, value] of Object.entries(activeFilters.value)) {
            if (!value || value === 'all') continue;
            rows = rows.filter(row => String(row[key as keyof T] ?? '') === value);
        }

        // 3. Sort
        if (sortKey.value !== null) {
            const key = sortKey.value;
            const dir = sortDir.value === 'asc' ? 1 : -1;
            rows = [...rows].sort((a, b) => {
                const av = a[key];
                const bv = b[key];
                if (av == null && bv == null) return 0;
                if (av == null) return 1;
                if (bv == null) return -1;
                if (typeof av === 'number' && typeof bv === 'number') {
                    return (av - bv) * dir;
                }
                return String(av).localeCompare(String(bv)) * dir;
            });
        }

        return rows;
    });

    const resultCount = computed(() => filtered.value.length);
    const totalCount = computed(() => source().length);

    return {
        search,
        activeFilters,
        sortKey,
        sortDir,
        setSort,
        setFilter,
        clearAll,
        filtered,
        resultCount,
        totalCount,
    };
}
```

**Step 2: Verify TypeScript compiles**

```bash
cd "C:\Users\hp\OneDrive\Desktop\Procurement_Project"
"C:\Program Files\nodejs\npm.cmd" run build 2>&1 | head -30
```

Expected: Build completes with no TypeScript errors (Vite may show the compiled output).

**Step 3: Commit**

```bash
git add resources/js/composables/useTableControls.ts
git commit -m "feat: add useTableControls composable for search/filter/sort"
git push
```

---

### Task 2: `TableControls.vue` component

**Files:**
- Create: `resources/js/Components/TableControls.vue`

**Step 1: Create the component**

Create `resources/js/Components/TableControls.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue';
import type { FilterDefinition } from '@/composables/useTableControls';

const props = defineProps<{
    search: string;
    filters?: FilterDefinition[];
    activeFilters: Record<string, string>;
    resultCount: number;
    totalCount: number;
}>();

const emit = defineEmits<{
    'update:search': [value: string];
    'update:filter': [payload: { key: string; value: string }];
    clear: [];
}>();

const hasActiveControls = computed(() =>
    props.search.trim() !== '' ||
    Object.values(props.activeFilters).some(v => v && v !== 'all'),
);
</script>

<template>
    <div class="flex flex-wrap items-center gap-3 px-4 py-3 border-b border-slate-100 bg-white">
        <!-- Search input -->
        <div class="relative">
            <svg
                class="absolute left-2.5 top-2 w-3.5 h-3.5 text-slate-400 pointer-events-none"
                fill="none" stroke="currentColor" viewBox="0 0 24 24"
            >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
            <input
                type="text"
                placeholder="Search..."
                :value="search"
                @input="emit('update:search', ($event.target as HTMLInputElement).value)"
                class="pl-8 pr-3 py-1.5 text-sm bg-slate-50 border border-slate-200 rounded-lg w-48 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent placeholder:text-slate-400"
            />
        </div>

        <!-- Filter dropdowns — one per FilterDefinition -->
        <select
            v-for="filter in (filters ?? [])"
            :key="filter.key"
            :value="activeFilters[filter.key] ?? 'all'"
            @change="emit('update:filter', { key: filter.key, value: ($event.target as HTMLSelectElement).value })"
            class="px-2.5 py-1.5 text-sm bg-slate-50 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 cursor-pointer"
            :class="activeFilters[filter.key] && activeFilters[filter.key] !== 'all'
                ? 'ring-2 ring-amber-400 border-amber-300'
                : ''"
        >
            <option value="all">{{ filter.label }}</option>
            <option v-for="opt in filter.options" :key="opt.value" :value="opt.value">
                {{ opt.label }}
            </option>
        </select>

        <div class="flex-1" />

        <!-- Result count + Clear all -->
        <span
            v-if="hasActiveControls || resultCount !== totalCount"
            class="text-xs text-slate-400"
        >
            Showing {{ resultCount }} of {{ totalCount }}
        </span>
        <button
            v-if="hasActiveControls"
            @click="emit('clear')"
            class="text-xs text-blue-600 hover:text-blue-800 hover:underline cursor-pointer"
        >
            Clear all
        </button>
    </div>
</template>
```

**Step 2: Verify TypeScript compiles**

```bash
"C:\Program Files\nodejs\npm.cmd" run build 2>&1 | head -30
```

Expected: No TypeScript errors.

**Step 3: Commit**

```bash
git add resources/js/Components/TableControls.vue
git commit -m "feat: add TableControls component (search, filter dropdowns, result count)"
git push
```

---

### Task 3: Wire Dashboard — Overview (Needs Attention table)

**Files:**
- Modify: `resources/js/Pages/Dashboard/Index.vue`

The Needs Attention table: search on sku_code and sku_name, no filter dropdowns, sortable columns: Days Cover, Effective Position, Demand/day, Lead Time.

**Step 1: Add imports**

At the top of `<script setup lang="ts">`, add after the existing imports:

```typescript
import TableControls from '@/Components/TableControls.vue';
import { useTableControls } from '@/composables/useTableControls';
```

The existing `import { computed, ref } from 'vue';` stays as-is.

**Step 2: Add composable instance for the overview table**

Add immediately after the `needsAttention` computed (around line 124):

```typescript
const overviewControls = useTableControls(
    () => needsAttention.value,
    ['sku_code', 'sku_name'] as (keyof DecisionRow)[],
);
```

**Step 3: Update the panel header badge**

The badge currently shows `needsAttention.length`. Find it (it looks like):

```html
<span
    v-if="needsAttention.length > 0"
    class="text-xs font-bold bg-red-100 text-red-700 px-2 py-0.5 rounded-full ring-1 ring-red-200"
>
    {{ needsAttention.length }}
</span>
```

Replace with (shows filtered count when search is active, total when not):

```html
<span
    v-if="overviewControls.totalCount.value > 0"
    class="text-xs font-bold bg-red-100 text-red-700 px-2 py-0.5 rounded-full ring-1 ring-red-200"
>
    {{ overviewControls.resultCount.value }}
</span>
```

**Step 4: Wrap the v-else block and insert TableControls**

Find the panel body:

```html
<div v-if="needsAttention.length === 0" class="px-6 py-10 text-center">
    ...empty state (unchanged)...
</div>

<div v-else class="overflow-x-auto">
    <table ...>
```

Replace the `v-else` block with a `<template>` wrapper that inserts `<TableControls>` before the table:

```html
<div v-if="needsAttention.length === 0" class="px-6 py-10 text-center">
    ...empty state (unchanged)...
</div>

<template v-else>
    <TableControls
        :search="overviewControls.search.value"
        :active-filters="overviewControls.activeFilters.value"
        :result-count="overviewControls.resultCount.value"
        :total-count="overviewControls.totalCount.value"
        @update:search="overviewControls.search.value = $event"
        @update:filter="overviewControls.setFilter($event.key, $event.value)"
        @clear="overviewControls.clearAll()"
    />
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            ...existing thead and tbody...
        </table>
    </div>
</template>
```

**Step 5: Replace the v-for source**

In the Overview tbody, change:

```html
v-for="row in needsAttention"
```

to:

```html
v-for="row in overviewControls.filtered.value"
```

**Step 6: Make four column headers sortable**

Find the Overview thead. The non-sortable columns are SKU, Decision, Urgency — leave those `<th>` elements unchanged.

Replace the four sortable headers (Eff. Position, Demand/day, Days Cover, Lead Time) with the pattern below. All four are right-aligned (`text-right`).

Eff. Position → field `effective_position`:
```html
<th
    class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wide whitespace-nowrap cursor-pointer hover:text-slate-700 select-none"
    @click="overviewControls.setSort('effective_position')"
>
    Eff. Position
    <span v-if="overviewControls.sortKey.value === 'effective_position'" class="ml-1 text-slate-400">
        {{ overviewControls.sortDir.value === 'asc' ? '↑' : '↓' }}
    </span>
</th>
```

Demand / day → field `forecast_demand`:
```html
<th
    class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wide whitespace-nowrap cursor-pointer hover:text-slate-700 select-none"
    @click="overviewControls.setSort('forecast_demand')"
>
    Demand / day
    <span v-if="overviewControls.sortKey.value === 'forecast_demand'" class="ml-1 text-slate-400">
        {{ overviewControls.sortDir.value === 'asc' ? '↑' : '↓' }}
    </span>
</th>
```

Days Cover → field `days_of_cover`:
```html
<th
    class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wide whitespace-nowrap cursor-pointer hover:text-slate-700 select-none"
    @click="overviewControls.setSort('days_of_cover')"
>
    Days Cover
    <span v-if="overviewControls.sortKey.value === 'days_of_cover'" class="ml-1 text-slate-400">
        {{ overviewControls.sortDir.value === 'asc' ? '↑' : '↓' }}
    </span>
</th>
```

Lead Time → field `lead_time_days`:
```html
<th
    class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wide whitespace-nowrap cursor-pointer hover:text-slate-700 select-none"
    @click="overviewControls.setSort('lead_time_days')"
>
    Lead Time
    <span v-if="overviewControls.sortKey.value === 'lead_time_days'" class="ml-1 text-slate-400">
        {{ overviewControls.sortDir.value === 'asc' ? '↑' : '↓' }}
    </span>
</th>
```

**Step 7: Smoke test in browser**

Ensure `npm run dev` is running, then open http://procurement_project.test.

1. Run the engine first if the Needs Attention panel is empty.
2. Dashboard → Overview tab → Needs Attention panel.
3. Type part of a SKU name in the search box — rows should filter live.
4. Click the "Days Cover" header — rows sort ascending, chevron ↑ appears.
5. Click "Days Cover" again — sorts descending, chevron changes to ↓.
6. Click a different header — sort switches, old chevron disappears.
7. Click "Clear all" — list resets to full unfiltered/unsorted state.
8. The badge count next to "Needs Attention" should match the visible row count.

**Step 8: Commit**

```bash
git add resources/js/Pages/Dashboard/Index.vue
git commit -m "feat: add search and sort to Dashboard Overview (Needs Attention) table"
git push
```

---

### Task 4: Wire Dashboard — All SKUs tab

**Files:**
- Modify: `resources/js/Pages/Dashboard/Index.vue`

All SKUs table: search on sku_code and sku_name, filter dropdowns for Decision / ABC Class / XYZ Class, sortable: Name, Rec. Qty, On Hand, Effective, Demand/day, Days Cover, Lead Time.

**Step 1: Import `FilterDefinition` type**

Update the composable import line added in Task 3 to also import the type:

```typescript
import { useTableControls, type FilterDefinition } from '@/composables/useTableControls';
```

**Step 2: Add composable instance and filter definitions for All SKUs**

Add after `overviewControls`:

```typescript
const allSkusControls = useTableControls(
    () => props.decisions,
    ['sku_code', 'sku_name'] as (keyof DecisionRow)[],
);

const allSkusFilters: FilterDefinition[] = [
    {
        key: 'decision',
        label: 'Decision',
        options: [
            { value: 'order', label: 'ORDER NOW' },
            { value: 'watch', label: 'WATCH' },
            { value: 'hold', label: 'HOLD' },
            { value: 'order_budget_blocked', label: 'BUDGET BLOCKED' },
        ],
    },
    {
        key: 'abc_class',
        label: 'ABC Class',
        options: [
            { value: 'A', label: 'A' },
            { value: 'B', label: 'B' },
            { value: 'C', label: 'C' },
        ],
    },
    {
        key: 'xyz_class',
        label: 'XYZ Class',
        options: [
            { value: 'X', label: 'X' },
            { value: 'Y', label: 'Y' },
            { value: 'Z', label: 'Z' },
        ],
    },
];
```

**Step 3: Insert TableControls above the All SKUs table**

The All SKUs panel currently looks like:

```html
<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 flex items-center gap-2">
        <span class="text-sm font-semibold text-slate-800">All SKUs</span>
        <span class="text-xs text-slate-400">— sorted by urgency</span>
    </div>
    <div class="overflow-x-auto">
```

Insert `<TableControls>` between the header div and the overflow div:

```html
<TableControls
    :search="allSkusControls.search.value"
    :filters="allSkusFilters"
    :active-filters="allSkusControls.activeFilters.value"
    :result-count="allSkusControls.resultCount.value"
    :total-count="allSkusControls.totalCount.value"
    @update:search="allSkusControls.search.value = $event"
    @update:filter="allSkusControls.setFilter($event.key, $event.value)"
    @clear="allSkusControls.clearAll()"
/>
```

**Step 4: Replace v-for source**

In the All SKUs tbody, change:

```html
v-for="row in decisions"
```

to:

```html
v-for="row in allSkusControls.filtered.value"
```

Also update the empty-state row condition from:
```html
<tr v-if="decisions.length === 0">
```
to:
```html
<tr v-if="allSkusControls.filtered.value.length === 0">
```

**Step 5: Make sortable column headers in the All SKUs thead**

Non-sortable columns: SKU (identifier), Decision (badge-only), Class (badge-only), Urgency, In Transit, Reserved, Safety Stock, Reorder Pt. — leave those `<th>` elements unchanged.

Sortable columns and their field names:
- Name → `sku_name` (left-aligned)
- Rec. Qty → `constrained_qty` (right-aligned)
- On Hand → `current_stock` (right-aligned)
- Effective → `effective_position` (right-aligned)
- Demand / day → `forecast_demand` (right-aligned)
- Days Cover → `days_of_cover` (right-aligned)
- Lead Time → `lead_time_days` (right-aligned)

Name (left-aligned):
```html
<th
    class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wide whitespace-nowrap cursor-pointer hover:text-slate-700 select-none"
    @click="allSkusControls.setSort('sku_name')"
>
    Name
    <span v-if="allSkusControls.sortKey.value === 'sku_name'" class="ml-1 text-slate-400">
        {{ allSkusControls.sortDir.value === 'asc' ? '↑' : '↓' }}
    </span>
</th>
```

Apply the same right-aligned pattern for the remaining six sortable headers (substitute field name and label in each):

Rec. Qty → `constrained_qty`, On Hand → `current_stock`, Effective → `effective_position`,
Demand / day → `forecast_demand`, Days Cover → `days_of_cover`, Lead Time → `lead_time_days`.

Right-aligned sortable pattern (example for Days Cover):
```html
<th
    class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wide whitespace-nowrap cursor-pointer hover:text-slate-700 select-none"
    @click="allSkusControls.setSort('days_of_cover')"
>
    Days Cover
    <span v-if="allSkusControls.sortKey.value === 'days_of_cover'" class="ml-1 text-slate-400">
        {{ allSkusControls.sortDir.value === 'asc' ? '↑' : '↓' }}
    </span>
</th>
```

**Step 6: Smoke test**

1. Dashboard → All SKUs tab.
2. Search part of a SKU name — rows filter live.
3. Filter Decision → ORDER NOW — shows only ORDER rows.
4. Add ABC Class → A — should combine both filters (AND logic).
5. Click "Days Cover" header — rows sort ascending.
6. Click "Clear all" — resets to full list.
7. "Showing X of Y" counter should update with each action.

**Step 7: Commit**

```bash
git add resources/js/Pages/Dashboard/Index.vue
git commit -m "feat: add search, filter, and sort to Dashboard All SKUs table"
git push
```

---

### Task 5: Wire SKU Catalogue (`/skus`)

**Files:**
- Modify: `resources/js/Pages/SKUs/Index.vue`

SKU Catalogue: search on sku_code, name, and supplier_name; filter dropdowns for Decision, ABC Class, and Supplier (supplier list built dynamically from data); sortable: Name, On Hand, Days Cover, Unit Cost (SAR).

**Step 1: Add imports**

At the top of `<script setup lang="ts">`, add:

```typescript
import { computed } from 'vue';
import TableControls from '@/Components/TableControls.vue';
import { useTableControls, type FilterDefinition } from '@/composables/useTableControls';
```

**Step 2: Bind defineProps to a variable**

The current file has:
```typescript
defineProps<{ skus: SkuRow[] }>();
```

Change to:
```typescript
const props = defineProps<{ skus: SkuRow[] }>();
```

This is needed so the composable can reference `props.skus` reactively in the script. In the template, `skus` still works directly by name (Vue auto-exposes props).

**Step 3: Add the composable and computed filter definitions**

Add after the existing helper functions (after `xyzBadgeStyle`):

```typescript
const skuControls = useTableControls(
    () => props.skus,
    ['sku_code', 'name', 'supplier_name'] as (keyof SkuRow)[],
);

const skuFilters = computed<FilterDefinition[]>(() => {
    const suppliers = [...new Set(props.skus.map(s => s.supplier_name).filter(Boolean))]
        .sort()
        .map(s => ({ value: s, label: s }));
    return [
        {
            key: 'latest_decision',
            label: 'Decision',
            options: [
                { value: 'order', label: 'ORDER NOW' },
                { value: 'watch', label: 'WATCH' },
                { value: 'hold', label: 'HOLD' },
                { value: 'order_budget_blocked', label: 'BUDGET BLOCKED' },
            ],
        },
        {
            key: 'abc_class',
            label: 'ABC Class',
            options: [
                { value: 'A', label: 'A' },
                { value: 'B', label: 'B' },
                { value: 'C', label: 'C' },
            ],
        },
        {
            key: 'supplier_name',
            label: 'Supplier',
            options: suppliers,
        },
    ];
});
```

**Step 4: Update the product count in the page header**

Find:
```html
<p class="text-sm text-slate-500 mt-0.5">{{ skus.length }} products</p>
```

Replace with:
```html
<p class="text-sm text-slate-500 mt-0.5">
    <template v-if="skuControls.resultCount.value !== skuControls.totalCount.value">
        {{ skuControls.resultCount.value }} of {{ skuControls.totalCount.value }} products
    </template>
    <template v-else>{{ skuControls.totalCount.value }} products</template>
</p>
```

**Step 5: Insert TableControls into the card**

The SKU Catalogue card currently looks like:

```html
<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="overflow-x-auto">
```

Insert `<TableControls>` between the card div and the overflow div:

```html
<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <TableControls
        :search="skuControls.search.value"
        :filters="skuFilters"
        :active-filters="skuControls.activeFilters.value"
        :result-count="skuControls.resultCount.value"
        :total-count="skuControls.totalCount.value"
        @update:search="skuControls.search.value = $event"
        @update:filter="skuControls.setFilter($event.key, $event.value)"
        @clear="skuControls.clearAll()"
    />
    <div class="overflow-x-auto">
```

**Step 6: Replace v-for source**

In the SKU tbody, change:

```html
v-for="sku in skus"
```

to:

```html
v-for="sku in skuControls.filtered.value"
```

Update the empty-state row from:
```html
<tr v-if="skus.length === 0">
```
to:
```html
<tr v-if="skuControls.filtered.value.length === 0">
```

**Step 7: Make four column headers sortable**

Non-sortable: SKU (code identifier), Supplier, Decision (badge-only), Class (badge-only).

Sortable columns and field names:
- Name → `name` (left-aligned)
- On Hand → `current_stock` (right-aligned)
- Days Cover → `days_of_cover` (right-aligned)
- Unit Cost (SAR) → `unit_cost_sar` (right-aligned)

Name (left-aligned):
```html
<th
    class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wide whitespace-nowrap cursor-pointer hover:text-slate-700 select-none"
    @click="skuControls.setSort('name')"
>
    Name
    <span v-if="skuControls.sortKey.value === 'name'" class="ml-1 text-slate-400">
        {{ skuControls.sortDir.value === 'asc' ? '↑' : '↓' }}
    </span>
</th>
```

On Hand (right-aligned):
```html
<th
    class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wide whitespace-nowrap cursor-pointer hover:text-slate-700 select-none"
    @click="skuControls.setSort('current_stock')"
>
    On Hand
    <span v-if="skuControls.sortKey.value === 'current_stock'" class="ml-1 text-slate-400">
        {{ skuControls.sortDir.value === 'asc' ? '↑' : '↓' }}
    </span>
</th>
```

Days Cover (right-aligned):
```html
<th
    class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wide whitespace-nowrap cursor-pointer hover:text-slate-700 select-none"
    @click="skuControls.setSort('days_of_cover')"
>
    Days Cover
    <span v-if="skuControls.sortKey.value === 'days_of_cover'" class="ml-1 text-slate-400">
        {{ skuControls.sortDir.value === 'asc' ? '↑' : '↓' }}
    </span>
</th>
```

Unit Cost (SAR) (right-aligned):
```html
<th
    class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wide whitespace-nowrap cursor-pointer hover:text-slate-700 select-none"
    @click="skuControls.setSort('unit_cost_sar')"
>
    Unit Cost (SAR)
    <span v-if="skuControls.sortKey.value === 'unit_cost_sar'" class="ml-1 text-slate-400">
        {{ skuControls.sortDir.value === 'asc' ? '↑' : '↓' }}
    </span>
</th>
```

**Step 8: Smoke test**

1. Open http://procurement_project.test/skus.
2. Search part of a product name — rows filter live.
3. Filter Decision → ORDER NOW — only ORDER rows visible.
4. Filter Supplier — select a supplier — further narrows results.
5. Click "Name" header → sorts A–Z; click again → Z–A.
6. Click "Unit Cost (SAR)" → sorts by price ascending.
7. Click "Clear all" → resets all filters and sort.
8. Page header count "X of Y products" updates as filters change; shows plain "Y products" when nothing is active.

**Step 9: Commit**

```bash
git add resources/js/Pages/SKUs/Index.vue
git commit -m "feat: add search, filter, and sort to SKU Catalogue"
git push
```

---

## Done

After Task 5 the feature is complete. All three tables have:
- Live search (client-side, instant)
- Filter dropdowns with amber highlight when active
- Sortable column headers with ↑/↓ indicator
- "Showing X of Y" count + "Clear all" link
