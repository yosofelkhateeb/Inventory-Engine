# SKU Detail Dialog Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a popup dialog that shows a current-state snapshot for any SKU, triggered by clicking the SKU code or name in both the SKU Catalogue and Dashboard "All SKUs" tab.

**Architecture:** On-demand fetch via `GET /skus/{sku}/summary` (JSON endpoint, no Inertia) so page-load payload stays constant as the catalogue grows. A single shared `SkuDetailDialog.vue` component is mounted once per page and controlled by a `selectedSkuId` ref.

**Tech Stack:** Laravel 12 (PHP), Vue 3 + TypeScript + Tailwind CSS v4, Pest 4 tests.

---

## Task 1: Backend — route + controller summary method

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/SkuController.php`

### Step 1: Add the route

In `routes/web.php`, add the summary route **before** the resource line (route specificity):

```php
Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class);
    Route::get('skus/{sku}/summary', [SkuController::class, 'summary'])->name('skus.summary');
    Route::resource('skus', SkuController::class)->only(['index', 'show']);
    Route::post('/engine/run', EngineController::class)->name('engine.run');
});
```

### Step 2: Add `summary()` to `SkuController`

Add this method after `show()` in `app/Http/Controllers/SkuController.php`:

```php
public function summary(Sku $sku): \Illuminate\Http\JsonResponse
{
    $sku->load('supplier');
    $latest = $sku->decisions()->latest('run_at')->first();

    return response()->json([
        'id'                 => $sku->id,
        'sku_code'           => $sku->sku_code,
        'name'               => $sku->name,
        'supplier_name'      => $sku->supplier->name,
        'unit_cost_sar'      => $sku->unit_cost_sar,
        'moq'                => $sku->moq,
        'lead_time_days'     => $sku->lead_time_days,
        'abc_class'          => $sku->abc_class,
        'xyz_class'          => $sku->xyz_class,
        'current_stock'      => $sku->current_stock,
        'in_transit_qty'     => $sku->in_transit_qty,
        'reserved_qty'       => $sku->reserved_qty,
        'effective_position' => $sku->effective_position,
        'decision'           => $latest?->decision,
        'constrained_qty'    => $latest?->constrained_qty,
        'days_of_cover'      => $latest ? (float) $latest->days_of_cover : null,
        'reorder_point'      => $latest?->reorder_point,
        'forecast_demand'    => $latest?->forecast_demand,
        'safety_stock'       => $latest ? ($latest->reasoning['safety_stock'] ?? null) : null,
        'run_at'             => $latest?->run_at?->toDateTimeString(),
    ]);
}
```

Note: `safety_stock` lives in `$latest->reasoning` (a JSON column cast to array), not a dedicated column.

### Step 3: Commit

```bash
git add routes/web.php app/Http/Controllers/SkuController.php
git commit -m "feat: add GET /skus/{sku}/summary JSON endpoint"
```

---

## Task 2: Backend tests

**Files:**
- Modify: `tests/Feature/Http/SkuControllerTest.php`

### Step 1: Write the failing tests

Append to `tests/Feature/Http/SkuControllerTest.php`:

```php
it('returns json summary for an authenticated user', function () {
    $user = User::factory()->create();
    $sku  = Sku::factory()->create();

    $this->actingAs($user)
         ->getJson("/skus/{$sku->id}/summary")
         ->assertStatus(200)
         ->assertJsonStructure([
             'id', 'sku_code', 'name', 'supplier_name',
             'unit_cost_sar', 'moq', 'lead_time_days',
             'abc_class', 'xyz_class',
             'current_stock', 'in_transit_qty', 'reserved_qty', 'effective_position',
             'decision', 'constrained_qty', 'days_of_cover',
             'reorder_point', 'forecast_demand', 'safety_stock', 'run_at',
         ]);
});

it('summary returns null decision fields when no decision exists', function () {
    $user = User::factory()->create();
    $sku  = Sku::factory()->create();

    $response = $this->actingAs($user)
                     ->getJson("/skus/{$sku->id}/summary")
                     ->assertStatus(200)
                     ->json();

    expect($response['decision'])->toBeNull()
        ->and($response['days_of_cover'])->toBeNull()
        ->and($response['safety_stock'])->toBeNull();
});

it('summary endpoint redirects guests to login', function () {
    $sku = Sku::factory()->create();

    $this->get("/skus/{$sku->id}/summary")
         ->assertRedirect('/login');
});
```

### Step 2: Run tests to verify they fail

```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan test tests/Feature/Http/SkuControllerTest.php
```

Expected: the two new tests fail (route not found / method not found). The existing two pass.

### Step 3: Run tests again after Task 1 is done

```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan test tests/Feature/Http/SkuControllerTest.php
```

Expected: all 4 tests pass.

### Step 4: Run full test suite to confirm nothing broken

```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan test
```

Expected: 26+ tests pass, 0 failures.

### Step 5: Commit

```bash
git add tests/Feature/Http/SkuControllerTest.php
git commit -m "test: add coverage for SKU summary endpoint"
```

---

## Task 3: Frontend — SkuDetailDialog component

**Files:**
- Create: `resources/js/Components/SkuDetailDialog.vue`

### Step 1: Create the component

Create `resources/js/Components/SkuDetailDialog.vue` with this exact content:

```vue
<script setup lang="ts">
import { ref, watch, onMounted, onUnmounted } from 'vue';

interface SkuSummary {
    id: number;
    sku_code: string;
    name: string;
    supplier_name: string;
    unit_cost_sar: number;
    moq: number;
    lead_time_days: number;
    abc_class: 'A' | 'B' | 'C' | null;
    xyz_class: 'X' | 'Y' | 'Z' | null;
    current_stock: number;
    in_transit_qty: number;
    reserved_qty: number;
    effective_position: number;
    decision: 'order' | 'watch' | 'hold' | 'order_budget_blocked' | null;
    constrained_qty: number | null;
    days_of_cover: number | null;
    reorder_point: number | null;
    forecast_demand: number | null;
    safety_stock: number | null;
    run_at: string | null;
}

const props = defineProps<{ skuId: number | null }>();
const emit = defineEmits<{ close: [] }>();

const loading = ref(false);
const error = ref(false);
const data = ref<SkuSummary | null>(null);

watch(() => props.skuId, async (id) => {
    if (id === null) {
        data.value = null;
        return;
    }
    loading.value = true;
    error.value = false;
    try {
        const res = await fetch(`/skus/${id}/summary`, {
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) throw new Error();
        data.value = await res.json();
    } catch {
        error.value = true;
    } finally {
        loading.value = false;
    }
});

function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Escape') emit('close');
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onUnmounted(() => document.removeEventListener('keydown', onKeydown));

const decisionBadgeClass: Record<string, string> = {
    order: 'bg-red-100 text-red-700 ring-1 ring-red-200',
    watch: 'bg-amber-100 text-amber-700 ring-1 ring-amber-200',
    hold: 'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-200',
    order_budget_blocked: 'bg-orange-100 text-orange-700 ring-1 ring-orange-200',
};

function decisionLabel(d: string | null): string {
    if (!d) return 'No Data';
    const map: Record<string, string> = {
        order: 'ORDER NOW',
        watch: 'WATCH',
        hold: 'HOLD',
        order_budget_blocked: 'BUDGET BLOCKED',
    };
    return map[d] ?? d.toUpperCase();
}

function urgency(d: SkuSummary): { label: string; classes: string } {
    if (d.days_of_cover === null) return { label: '—', classes: 'text-slate-400' };
    if (d.days_of_cover < d.lead_time_days) return { label: 'Critical', classes: 'text-red-600 font-semibold' };
    if (d.days_of_cover < d.lead_time_days * 1.3) return { label: 'Tight', classes: 'text-amber-600 font-semibold' };
    return { label: 'OK', classes: 'text-emerald-600 font-medium' };
}

function abcBadgeClass(abc: string | null): string {
    if (abc === 'A') return 'bg-red-100 text-red-700';
    if (abc === 'B') return 'bg-amber-100 text-amber-700';
    return 'bg-slate-100 text-slate-600';
}

function formatDemand(val: number | null): string {
    if (val === null) return '—';
    return val < 1 ? val.toFixed(2) : Math.round(val).toString();
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="skuId !== null"
            class="fixed inset-0 z-50 flex items-center justify-center p-4"
        >
            <!-- Backdrop -->
            <div
                class="absolute inset-0 bg-black/40"
                @click="$emit('close')"
            />

            <!-- Dialog panel -->
            <div class="relative z-10 w-full max-w-lg bg-white rounded-xl shadow-2xl border border-slate-200 overflow-hidden">

                <!-- Loading -->
                <div v-if="loading" class="flex items-center justify-center h-48">
                    <svg class="w-6 h-6 text-blue-500 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                    </svg>
                </div>

                <!-- Error -->
                <div v-else-if="error" class="flex flex-col items-center justify-center h-48 gap-3">
                    <p class="text-sm text-red-600">Failed to load SKU data.</p>
                    <button
                        class="text-xs text-blue-600 hover:underline cursor-pointer"
                        @click="$emit('close')"
                    >
                        Close
                    </button>
                </div>

                <!-- Content -->
                <template v-else-if="data">

                    <!-- Header -->
                    <div class="px-5 py-4 border-b border-slate-100 flex items-start justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-mono text-xs text-slate-400">{{ data.sku_code }}</span>
                                <span
                                    v-if="data.abc_class && data.xyz_class"
                                    class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-bold font-mono"
                                    :class="abcBadgeClass(data.abc_class)"
                                >
                                    {{ data.abc_class }}·{{ data.xyz_class }}
                                </span>
                            </div>
                            <h2 class="text-base font-bold text-slate-800 mt-0.5">{{ data.name }}</h2>
                            <p class="text-xs text-slate-400 mt-0.5">{{ data.supplier_name }}</p>
                        </div>
                        <button
                            @click="$emit('close')"
                            class="text-slate-400 hover:text-slate-600 transition-colors cursor-pointer mt-0.5 shrink-0"
                            aria-label="Close dialog"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <!-- Inventory -->
                    <div class="px-5 py-4 border-b border-slate-100">
                        <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-widest mb-3">Inventory</p>
                        <div class="grid grid-cols-4 gap-4">
                            <div>
                                <p class="text-[10px] text-slate-400 uppercase tracking-wide">On Hand</p>
                                <p class="text-sm font-bold text-slate-800 mt-0.5 tabular-nums">{{ data.current_stock }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] text-slate-400 uppercase tracking-wide">In Transit</p>
                                <p class="text-sm font-bold text-slate-800 mt-0.5 tabular-nums">{{ data.in_transit_qty }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] text-slate-400 uppercase tracking-wide">Reserved</p>
                                <p class="text-sm font-bold text-slate-800 mt-0.5 tabular-nums">{{ data.reserved_qty }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] text-slate-400 uppercase tracking-wide">Effective</p>
                                <p class="text-sm font-bold text-slate-800 mt-0.5 tabular-nums">{{ data.effective_position }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Latest Decision -->
                    <div class="px-5 py-4 border-b border-slate-100">
                        <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-widest mb-3">Latest Decision</p>

                        <div v-if="!data.decision" class="text-xs text-slate-400 italic">
                            No decision data yet. Run the engine first.
                        </div>

                        <template v-else>
                            <div class="flex items-center gap-3 mb-4">
                                <span
                                    class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-semibold"
                                    :class="decisionBadgeClass[data.decision] ?? 'bg-slate-100 text-slate-500'"
                                >
                                    {{ decisionLabel(data.decision) }}
                                </span>
                                <span class="text-sm" :class="urgency(data).classes">
                                    {{ urgency(data).label }}
                                </span>
                            </div>

                            <div class="grid grid-cols-4 gap-4 mb-4">
                                <div>
                                    <p class="text-[10px] text-slate-400 uppercase tracking-wide">Days Cover</p>
                                    <p class="text-sm font-bold tabular-nums mt-0.5" :class="urgency(data).classes">
                                        {{ data.days_of_cover ?? '—' }}
                                    </p>
                                </div>
                                <div>
                                    <p class="text-[10px] text-slate-400 uppercase tracking-wide">Lead Time</p>
                                    <p class="text-sm font-bold text-slate-800 mt-0.5 tabular-nums">{{ data.lead_time_days }}d</p>
                                </div>
                                <div>
                                    <p class="text-[10px] text-slate-400 uppercase tracking-wide">Reorder Pt.</p>
                                    <p class="text-sm font-bold text-slate-800 mt-0.5 tabular-nums">{{ data.reorder_point ?? '—' }}</p>
                                </div>
                                <div>
                                    <p class="text-[10px] text-slate-400 uppercase tracking-wide">Safety Stock</p>
                                    <p class="text-sm font-bold text-slate-800 mt-0.5 tabular-nums">{{ data.safety_stock ?? '—' }}</p>
                                </div>
                            </div>

                            <div class="grid grid-cols-4 gap-4">
                                <div>
                                    <p class="text-[10px] text-slate-400 uppercase tracking-wide">Demand/day</p>
                                    <p class="text-sm font-bold text-slate-800 mt-0.5 font-mono tabular-nums">
                                        {{ formatDemand(data.forecast_demand) }}
                                    </p>
                                </div>
                                <div>
                                    <p class="text-[10px] text-slate-400 uppercase tracking-wide">Rec. Qty</p>
                                    <p class="text-sm font-bold text-slate-800 mt-0.5 tabular-nums">{{ data.constrained_qty ?? '—' }}</p>
                                </div>
                                <div>
                                    <p class="text-[10px] text-slate-400 uppercase tracking-wide">Unit Cost</p>
                                    <p class="text-sm font-bold text-slate-800 mt-0.5 font-mono tabular-nums">
                                        {{ data.unit_cost_sar.toFixed(2) }} SAR
                                    </p>
                                </div>
                                <div>
                                    <p class="text-[10px] text-slate-400 uppercase tracking-wide">MOQ</p>
                                    <p class="text-sm font-bold text-slate-800 mt-0.5 tabular-nums">{{ data.moq }}</p>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Footer -->
                    <div class="px-5 py-3 flex justify-end bg-slate-50">
                        <a
                            :href="`/skus/${data.id}`"
                            class="text-xs text-blue-600 hover:text-blue-800 hover:underline transition-colors font-medium"
                        >
                            View full detail →
                        </a>
                    </div>
                </template>
            </div>
        </div>
    </Teleport>
</template>
```

### Step 2: Verify Vite compiles without errors

With `npm run dev` running, check the terminal for TypeScript/Vite errors after saving the file.

Expected: no errors.

### Step 3: Commit

```bash
git add resources/js/Components/SkuDetailDialog.vue
git commit -m "feat: add SkuDetailDialog component with on-demand fetch"
```

---

## Task 4: Wire dialog into SKU Catalogue (SKUs/Index.vue)

**Files:**
- Modify: `resources/js/Pages/SKUs/Index.vue`

### Step 1: Update the script block

Replace the entire `<script setup lang="ts">` block with:

```ts
<script setup lang="ts">
import { ref } from 'vue';
import AppHeader from '@/Components/AppHeader.vue';
import SkuDetailDialog from '@/Components/SkuDetailDialog.vue';

interface SkuRow {
    id: number;
    sku_code: string;
    name: string;
    current_stock: number;
    effective_position: number;
    unit_cost_sar: number;
    moq: number;
    supplier_name: string;
    latest_decision: 'order' | 'watch' | 'hold' | 'order_budget_blocked' | null;
    days_of_cover: number | null;
    abc_class: 'A' | 'B' | 'C' | null;
    xyz_class: 'X' | 'Y' | 'Z' | null;
}

defineProps<{ skus: SkuRow[] }>();

const selectedSkuId = ref<number | null>(null);

const badgeClass: Record<string, string> = {
    order: 'bg-red-100 text-red-700 ring-1 ring-red-200',
    watch: 'bg-amber-100 text-amber-700 ring-1 ring-amber-200',
    hold: 'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-200',
    order_budget_blocked: 'bg-orange-100 text-orange-700 ring-1 ring-orange-200',
};

function decisionLabel(d: string | null): string {
    if (!d) return 'No Data';
    const map: Record<string, string> = {
        order: 'ORDER NOW',
        watch: 'WATCH',
        hold: 'HOLD',
        order_budget_blocked: 'BUDGET BLOCKED',
    };
    return map[d] ?? d.toUpperCase();
}

function abcBadgeClass(abc: string | null): string {
    if (abc === 'A') return 'bg-red-100 text-red-700';
    if (abc === 'B') return 'bg-amber-100 text-amber-700';
    return 'bg-slate-100 text-slate-600';
}

function xyzBadgeStyle(xyz: string | null): string {
    if (xyz === 'X') return 'opacity-100';
    if (xyz === 'Y') return 'opacity-75';
    return 'opacity-50 ring-1 ring-current';
}
</script>
```

### Step 2: Replace the SKU code cell (was a `<Link>`, now a `<button>`)

Find:
```html
<td class="px-4 py-3 whitespace-nowrap">
    <Link
        :href="`/skus/${sku.id}`"
        class="font-mono text-xs text-blue-600 hover:text-blue-800 hover:underline transition-colors duration-100"
    >
        {{ sku.sku_code }}
    </Link>
</td>
```

Replace with:
```html
<td class="px-4 py-3 whitespace-nowrap">
    <button
        @click="selectedSkuId = sku.id"
        class="font-mono text-xs text-blue-600 hover:text-blue-800 hover:underline transition-colors duration-100 cursor-pointer"
    >
        {{ sku.sku_code }}
    </button>
</td>
```

### Step 3: Make the name cell a button too

Find:
```html
<td class="px-4 py-3 font-semibold text-slate-800 whitespace-nowrap">{{ sku.name }}</td>
```

Replace with:
```html
<td class="px-4 py-3 whitespace-nowrap">
    <button
        @click="selectedSkuId = sku.id"
        class="font-semibold text-slate-800 hover:text-blue-600 transition-colors duration-100 cursor-pointer text-left"
    >
        {{ sku.name }}
    </button>
</td>
```

### Step 4: Mount the dialog at the end of the template

Just before the closing `</div>` of the root element (after the table card `</div>`), add:

```html
<SkuDetailDialog :sku-id="selectedSkuId" @close="selectedSkuId = null" />
```

### Step 5: Verify in browser

Navigate to `http://procurement_project.test/skus`. Click any SKU code or name. The dialog should open with a spinner then show the snapshot. Press Esc or click the backdrop to close. The "View full detail →" link should navigate to the Show page.

### Step 6: Commit

```bash
git add resources/js/Pages/SKUs/Index.vue
git commit -m "feat: wire SkuDetailDialog into SKU Catalogue"
```

---

## Task 5: Wire dialog into Dashboard + add sku_id to decisions

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php`
- Modify: `resources/js/Pages/Dashboard/Index.vue`

### Step 1: Add `sku_id` to the mapped decisions in DashboardController

In `app/Http/Controllers/DashboardController.php`, inside the `$mapped` closure, add `'sku_id' => $d->sku_id,` after the `'id'` line:

```php
$mapped = $decisions->map(fn ($d) => [
    'id'                 => $d->id,
    'sku_id'             => $d->sku_id,   // ← add this line
    'sku_code'           => $d->sku->sku_code,
    // ... rest unchanged
]);
```

### Step 2: Add `sku_id` to the `DecisionRow` interface in Dashboard/Index.vue

In `resources/js/Pages/Dashboard/Index.vue`, update the `DecisionRow` interface:

```ts
interface DecisionRow {
    id: number;
    sku_id: number;   // ← add this line
    sku_code: string;
    sku_name: string;
    // ... rest unchanged
}
```

### Step 3: Add the dialog import + selectedSkuId ref to Dashboard/Index.vue

In the `<script setup lang="ts">` block, add imports and the ref at the top:

```ts
import SkuDetailDialog from '@/Components/SkuDetailDialog.vue';
// (ref is already imported)

const selectedSkuId = ref<number | null>(null);
```

### Step 4: Make SKU code and name clickable in the "All SKUs" table

Find in the All SKUs table body (the `v-show="activeTab === 'skus'"` section):

```html
<td class="px-4 py-3 font-mono text-slate-500 text-xs whitespace-nowrap">{{ row.sku_code }}</td>
<td class="px-4 py-3 font-semibold text-slate-800 whitespace-nowrap">{{ row.sku_name }}</td>
```

Replace with:

```html
<td class="px-4 py-3 whitespace-nowrap">
    <button
        @click="selectedSkuId = row.sku_id"
        class="font-mono text-xs text-blue-600 hover:text-blue-800 hover:underline transition-colors duration-100 cursor-pointer"
    >
        {{ row.sku_code }}
    </button>
</td>
<td class="px-4 py-3 whitespace-nowrap">
    <button
        @click="selectedSkuId = row.sku_id"
        class="font-semibold text-slate-800 hover:text-blue-600 transition-colors duration-100 cursor-pointer text-left"
    >
        {{ row.sku_name }}
    </button>
</td>
```

### Step 5: Mount the dialog at the end of the Dashboard template

Just before the closing `</div>` of the root element, add:

```html
<SkuDetailDialog :sku-id="selectedSkuId" @close="selectedSkuId = null" />
```

### Step 6: Verify in browser

Navigate to `http://procurement_project.test`. Go to "All SKUs" tab. Click any SKU code or name. Dialog opens, shows data. Esc/backdrop closes it.

### Step 7: Commit

```bash
git add app/Http/Controllers/DashboardController.php resources/js/Pages/Dashboard/Index.vue
git commit -m "feat: wire SkuDetailDialog into Dashboard All SKUs tab"
```

---

## Task 6: Final verification + push

### Step 1: Run full test suite

```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan test
```

Expected: all tests pass (26+ tests, 0 failures).

### Step 2: Smoke test both pages in browser

- `/skus` → click SKU code → dialog opens → click backdrop to close
- `/skus` → click product name → dialog opens → press Esc to close
- `/skus` → dialog "View full detail →" → navigates to Show page
- `/` → All SKUs tab → click SKU code → dialog opens
- `/` → All SKUs tab → click product name → dialog opens

### Step 3: Update CHANGELOG.md

Add entry under today's date:

```markdown
## 2026-04-10
- Add SKU detail popup dialog: clicking any SKU code or name in the catalogue or dashboard opens a snapshot showing inventory position and latest decision metrics
```

### Step 4: Push

```bash
git push origin main
```
