# Dashboard Redesign Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the flat decisions table with a two-section insight dashboard — urgency-aware, stock-context-rich, no financial data.

**Architecture:** Two changes only — enrich `DashboardController` to send additional stock fields + sort by urgency, then rewrite `Dashboard/Index.vue` with the new two-section layout. No new migrations, models, or routes needed.

**Tech Stack:** Laravel 12, PHP 8.3, Vue 3 (`<script setup lang="ts">`), Inertia.js v2, Tailwind CSS v4, Pest 3

---

## Task 1: Enrich DashboardController

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php`
- Modify: `tests/Feature/Http/DashboardControllerTest.php`

**Background:** The controller currently sends 6 stats (including financial) and 7 fields per decision row. We need to:
- Remove all financial fields (`committed_spend`, `hold`, `budget_blocked` from stats)
- Add `stockout_risk` stat (SKUs where `days_of_cover < lead_time_days`)
- Add per-row: `current_stock`, `in_transit_qty`, `reserved_qty`, `effective_position`, `lead_time_days`, `forecast_demand`, `safety_stock` (from `reasoning` JSON)
- Sort decisions by urgency priority before sending

**Step 1: Update the test first**

Replace the entire contents of `tests/Feature/Http/DashboardControllerTest.php`:

```php
<?php

use App\Models\InventoryDecision;
use App\Models\Sku;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;

it('shows dashboard to authenticated users', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertStatus(200)
             ->assertInertia(fn ($page) => $page->component('Dashboard/Index'));
});

it('redirects guests to login', function () {
    $this->get('/')->assertRedirect('/login');
});

it('sends enriched decision data with stock fields', function () {
    $user     = User::factory()->create();
    $supplier = Supplier::factory()->create(['avg_lead_time_days' => 7, 'lead_time_stddev' => 1.0]);
    $sku      = Sku::factory()->create([
        'supplier_id'    => $supplier->id,
        'current_stock'  => 50,
        'in_transit_qty' => 10,
        'reserved_qty'   => 5,
        'lead_time_days' => 7,
    ]);

    InventoryDecision::create([
        'sku_id'          => $sku->id,
        'run_at'          => now(),
        'decision'        => 'order',
        'recommended_qty' => 24,
        'constrained_qty' => 24,
        'reasoning'       => ['safety_stock' => 4.37, 'reorder_point' => 25.37],
        'forecast_demand' => 3.2,
        'days_of_cover'   => 11.0,
        'reorder_point'   => 25.37,
    ]);

    $this->actingAs($user)->get('/')
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Index')
            ->has('stats.stockout_risk')
            ->has('stats.order_now')
            ->has('stats.watch')
            ->has('stats.avg_days_cover')
            ->missing('stats.committed_spend')
            ->has('decisions.0.current_stock')
            ->has('decisions.0.in_transit_qty')
            ->has('decisions.0.reserved_qty')
            ->has('decisions.0.effective_position')
            ->has('decisions.0.lead_time_days')
            ->has('decisions.0.forecast_demand')
            ->has('decisions.0.safety_stock')
        );
});

it('sorts decisions by urgency with critical order-now first', function () {
    $user     = User::factory()->create();
    $supplier = Supplier::factory()->create(['avg_lead_time_days' => 7, 'lead_time_stddev' => 1.0]);
    $runAt    = now();

    // HOLD sku — should appear last
    $skuHold = Sku::factory()->create(['supplier_id' => $supplier->id, 'lead_time_days' => 7]);
    InventoryDecision::create([
        'sku_id' => $skuHold->id, 'run_at' => $runAt, 'decision' => 'hold',
        'recommended_qty' => 0, 'constrained_qty' => 0,
        'reasoning' => [], 'forecast_demand' => 1.0, 'days_of_cover' => 30.0, 'reorder_point' => 10.0,
    ]);

    // ORDER NOW critical (days_of_cover < lead_time) — should appear first
    $skuCritical = Sku::factory()->create(['supplier_id' => $supplier->id, 'lead_time_days' => 7]);
    InventoryDecision::create([
        'sku_id' => $skuCritical->id, 'run_at' => $runAt, 'decision' => 'order',
        'recommended_qty' => 24, 'constrained_qty' => 24,
        'reasoning' => [], 'forecast_demand' => 3.2, 'days_of_cover' => 3.0, 'reorder_point' => 25.0,
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn ($page) => $page
        ->where('decisions.0.decision', 'order')
        ->where('decisions.0.days_of_cover', 3.0)
        ->where('decisions.1.decision', 'hold')
    );
});
```

**Step 2: Run tests to confirm they fail**

```bash
C:\Users\hp\.config\herd\bin\php.bat artisan test tests/Feature/Http/DashboardControllerTest.php
```
Expected: the two new tests FAIL (`has('stats.stockout_risk')` not found, `has('decisions.0.current_stock')` not found).

**Step 3: Rewrite DashboardController**

Replace the entire contents of `app/Http/Controllers/DashboardController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\InventoryDecision;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $latestRun = InventoryDecision::max('run_at');

        $decisions = InventoryDecision::where('run_at', $latestRun)
            ->with('sku')
            ->get();

        $mapped = $decisions->map(fn ($d) => [
            'id'                 => $d->id,
            'sku_code'           => $d->sku->sku_code,
            'sku_name'           => $d->sku->name,
            'decision'           => $d->decision,
            'constrained_qty'    => $d->constrained_qty,
            'days_of_cover'      => $d->days_of_cover,
            'reorder_point'      => $d->reorder_point,
            'forecast_demand'    => $d->forecast_demand,
            'safety_stock'       => $d->reasoning['safety_stock'] ?? 0,
            'current_stock'      => $d->sku->current_stock,
            'in_transit_qty'     => $d->sku->in_transit_qty,
            'reserved_qty'       => $d->sku->reserved_qty,
            'effective_position' => $d->sku->effective_position,
            'lead_time_days'     => $d->sku->lead_time_days,
            'run_at'             => $d->run_at,
        ]);

        $sorted = $mapped->sortBy([
            fn ($a, $b) => $this->urgencyPriority($a) <=> $this->urgencyPriority($b),
            fn ($a, $b) => $a['days_of_cover'] <=> $b['days_of_cover'],
        ])->values();

        $stockoutRisk = $mapped->filter(
            fn ($d) => $d['days_of_cover'] < $d['lead_time_days']
        )->count();

        return Inertia::render('Dashboard/Index', [
            'stats' => [
                'order_now'      => $decisions->where('decision', 'order')->count(),
                'watch'          => $decisions->where('decision', 'watch')->count(),
                'stockout_risk'  => $stockoutRisk,
                'avg_days_cover' => round($decisions->avg('days_of_cover'), 1),
            ],
            'decisions' => $sorted,
        ]);
    }

    private function urgencyPriority(array $d): int
    {
        if ($d['decision'] === 'order' && $d['days_of_cover'] < $d['lead_time_days']) return 0;
        if ($d['decision'] === 'order') return 1;
        if ($d['decision'] === 'order_budget_blocked') return 2;
        if ($d['decision'] === 'watch') return 3;
        return 4; // hold
    }
}
```

**Step 4: Run tests**

```bash
C:\Users\hp\.config\herd\bin\php.bat artisan test tests/Feature/Http/DashboardControllerTest.php
```
Expected: all 4 tests PASS.

**Step 5: Run the full suite to confirm no regressions**

```bash
C:\Users\hp\.config\herd\bin\php.bat artisan test
```
Expected: all tests PASS.

---

## Task 2: Rewrite Dashboard/Index.vue

**Files:**
- Modify: `resources/js/Pages/Dashboard/Index.vue`

**Background:** Full replacement of the Vue page. Two sections below the stat cards:
1. **Needs Attention** — ORDER NOW + WATCH + BUDGET BLOCKED rows only, with urgency indicator
2. **Full SKU Table** — all decisions, urgency-sorted (controller already sorted them), with stock breakdown

**Urgency indicator logic (compute in Vue):**
- `days_of_cover < lead_time_days` → label "Critical", color red
- `days_of_cover < lead_time_days * 1.3` → label "Tight", color yellow
- Otherwise → label "OK", color green (won't appear in Needs Attention panel, but needed for full table)

**Step 1: Replace the entire file**

Write the following to `resources/js/Pages/Dashboard/Index.vue`:

```vue
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';

interface DecisionRow {
    id: number;
    sku_code: string;
    sku_name: string;
    decision: 'order' | 'watch' | 'hold' | 'order_budget_blocked';
    constrained_qty: number;
    days_of_cover: number;
    reorder_point: number;
    forecast_demand: number;
    safety_stock: number;
    current_stock: number;
    in_transit_qty: number;
    reserved_qty: number;
    effective_position: number;
    lead_time_days: number;
    run_at: string;
}

interface Stats {
    order_now: number;
    watch: number;
    stockout_risk: number;
    avg_days_cover: number;
}

const props = defineProps<{
    stats: Stats;
    decisions: DecisionRow[];
}>();

const decisionBadgeClass: Record<string, string> = {
    order: 'bg-red-100 text-red-800',
    watch: 'bg-yellow-100 text-yellow-800',
    hold: 'bg-green-100 text-green-800',
    order_budget_blocked: 'bg-orange-100 text-orange-800',
};

function badgeLabel(decision: string): string {
    const labels: Record<string, string> = {
        order: 'ORDER NOW',
        watch: 'WATCH',
        hold: 'HOLD',
        order_budget_blocked: 'BUDGET BLOCKED',
    };
    return labels[decision] ?? decision.toUpperCase();
}

function urgency(row: DecisionRow): { label: string; classes: string } {
    if (row.days_of_cover < row.lead_time_days) {
        return { label: 'Critical', classes: 'text-red-700 font-semibold' };
    }
    if (row.days_of_cover < row.lead_time_days * 1.3) {
        return { label: 'Tight', classes: 'text-yellow-700 font-semibold' };
    }
    return { label: 'OK', classes: 'text-green-700' };
}

const needsAttention = computed(() =>
    props.decisions.filter(d =>
        d.decision === 'order' ||
        d.decision === 'watch' ||
        d.decision === 'order_budget_blocked'
    )
);

function runEngine() {
    router.post('/engine/run');
}
</script>

<template>
    <div class="min-h-screen bg-gray-50 p-8">
        <div class="max-w-7xl mx-auto space-y-8">

            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Inventory Engine</h1>
                    <p class="text-gray-500 mt-1">Stock replenishment decisions</p>
                </div>
                <button
                    @click="runEngine"
                    class="bg-blue-600 text-white px-5 py-2.5 rounded-lg font-medium hover:bg-blue-700 transition"
                >
                    Run Engine
                </button>
            </div>

            <!-- Stat cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-white rounded-xl p-5 shadow-sm border">
                    <p class="text-sm text-gray-500">Order Now</p>
                    <p class="text-3xl font-bold text-red-600">{{ stats.order_now }}</p>
                </div>
                <div class="bg-white rounded-xl p-5 shadow-sm border">
                    <p class="text-sm text-gray-500">Watch</p>
                    <p class="text-3xl font-bold text-yellow-500">{{ stats.watch }}</p>
                </div>
                <div class="bg-white rounded-xl p-5 shadow-sm border">
                    <p class="text-sm text-gray-500">Stockout Risk</p>
                    <p class="text-3xl font-bold" :class="stats.stockout_risk > 0 ? 'text-red-900' : 'text-gray-400'">
                        {{ stats.stockout_risk }}
                    </p>
                    <p class="text-xs text-gray-400 mt-1">cover &lt; lead time</p>
                </div>
                <div class="bg-white rounded-xl p-5 shadow-sm border">
                    <p class="text-sm text-gray-500">Avg Days of Cover</p>
                    <p class="text-3xl font-bold text-gray-800">{{ stats.avg_days_cover }}</p>
                </div>
            </div>

            <!-- Needs Attention panel -->
            <div class="bg-white rounded-xl shadow-sm border overflow-hidden">
                <div class="px-6 py-4 border-b flex items-center gap-2">
                    <span class="text-base font-semibold text-gray-900">Needs Attention</span>
                    <span
                        v-if="needsAttention.length > 0"
                        class="text-xs font-semibold bg-red-100 text-red-700 px-2 py-0.5 rounded-full"
                    >
                        {{ needsAttention.length }}
                    </span>
                </div>

                <!-- All clear -->
                <div v-if="needsAttention.length === 0" class="px-6 py-8 text-center text-green-600 font-medium">
                    ✓ All stock levels healthy — nothing to action today.
                </div>

                <!-- Attention rows -->
                <div v-else class="divide-y divide-gray-100">
                    <div
                        v-for="row in needsAttention"
                        :key="row.id"
                        class="px-6 py-4 flex flex-wrap items-center gap-x-6 gap-y-2"
                    >
                        <!-- SKU identity -->
                        <div class="min-w-[180px]">
                            <p class="font-medium text-gray-900 text-sm">{{ row.sku_name }}</p>
                            <p class="text-xs text-gray-400 font-mono">{{ row.sku_code }}</p>
                        </div>

                        <!-- Decision badge -->
                        <span
                            class="px-2.5 py-1 rounded-full text-xs font-semibold shrink-0"
                            :class="decisionBadgeClass[row.decision]"
                        >
                            {{ badgeLabel(row.decision) }}
                        </span>

                        <!-- Urgency -->
                        <div class="shrink-0">
                            <span :class="urgency(row).classes" class="text-sm">
                                {{ urgency(row).label }}
                            </span>
                            <p class="text-xs text-gray-400">
                                {{ row.days_of_cover }} days stock / {{ row.lead_time_days }} day lead time
                            </p>
                        </div>

                        <!-- Effective position -->
                        <div class="shrink-0 text-sm">
                            <p class="text-gray-700 font-medium">{{ row.effective_position }} units</p>
                            <p class="text-xs text-gray-400">effective position</p>
                        </div>

                        <!-- Daily demand -->
                        <div class="shrink-0 text-sm">
                            <p class="text-gray-700 font-medium">{{ row.forecast_demand }}/day</p>
                            <p class="text-xs text-gray-400">forecast demand</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Full SKU table -->
            <div class="bg-white rounded-xl shadow-sm border overflow-hidden">
                <div class="px-6 py-4 border-b">
                    <span class="text-base font-semibold text-gray-900">All SKUs</span>
                    <span class="text-xs text-gray-400 ml-2">sorted by urgency</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-500 uppercase text-xs">
                            <tr>
                                <th class="px-4 py-3 text-left">SKU</th>
                                <th class="px-4 py-3 text-left">Name</th>
                                <th class="px-4 py-3 text-left">Decision</th>
                                <th class="px-4 py-3 text-left">Urgency</th>
                                <th class="px-4 py-3 text-right">On Hand</th>
                                <th class="px-4 py-3 text-right">In Transit</th>
                                <th class="px-4 py-3 text-right">Reserved</th>
                                <th class="px-4 py-3 text-right">Effective</th>
                                <th class="px-4 py-3 text-right">Demand/day</th>
                                <th class="px-4 py-3 text-right">Days Cover</th>
                                <th class="px-4 py-3 text-right">Lead Time</th>
                                <th class="px-4 py-3 text-right">Safety Stock</th>
                                <th class="px-4 py-3 text-right">Reorder Point</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr
                                v-for="row in decisions"
                                :key="row.id"
                                class="hover:bg-gray-50"
                                :class="{ 'bg-red-50 hover:bg-red-100': row.days_of_cover < row.lead_time_days }"
                            >
                                <td class="px-4 py-3 font-mono text-gray-500 text-xs">{{ row.sku_code }}</td>
                                <td class="px-4 py-3 font-medium text-gray-900">{{ row.sku_name }}</td>
                                <td class="px-4 py-3">
                                    <span
                                        class="px-2.5 py-1 rounded-full text-xs font-semibold"
                                        :class="decisionBadgeClass[row.decision]"
                                    >
                                        {{ badgeLabel(row.decision) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-xs" :class="urgency(row).classes">
                                        {{ urgency(row).label }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right text-gray-700">{{ row.current_stock }}</td>
                                <td class="px-4 py-3 text-right text-gray-700">{{ row.in_transit_qty }}</td>
                                <td class="px-4 py-3 text-right text-gray-700">{{ row.reserved_qty }}</td>
                                <td class="px-4 py-3 text-right font-medium text-gray-900">{{ row.effective_position }}</td>
                                <td class="px-4 py-3 text-right text-gray-700">{{ row.forecast_demand }}</td>
                                <td class="px-4 py-3 text-right font-medium" :class="urgency(row).classes">
                                    {{ row.days_of_cover }}
                                </td>
                                <td class="px-4 py-3 text-right text-gray-500">{{ row.lead_time_days }}d</td>
                                <td class="px-4 py-3 text-right text-gray-500">{{ row.safety_stock }}</td>
                                <td class="px-4 py-3 text-right text-gray-500">{{ row.reorder_point }}</td>
                            </tr>

                            <tr v-if="decisions.length === 0">
                                <td colspan="13" class="px-4 py-8 text-center text-gray-400">
                                    No decisions yet. Run the engine to generate recommendations.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</template>
```

**Step 2: Run the Vite build**

```bash
PATH="/c/Program Files/nodejs:$PATH" "C:\Program Files\nodejs\npm.cmd" run build --prefix "C:\Users\hp\OneDrive\Desktop\Procurement_Project"
```
Expected: `✓ built in Xs` with 0 errors.

**Step 3: Run the full test suite**

```bash
C:\Users\hp\.config\herd\bin\php.bat artisan test
```
Expected: all tests PASS.
