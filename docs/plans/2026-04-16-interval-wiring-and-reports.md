# Interval Wiring + Reports Page Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Verify the end-to-end pipeline on all 11 SKUs, fix the interval/selection field wiring that was missed when the migration was added, then build a Reports page showing per-SKU model performance.

**Architecture:** Three sequential tasks. Task 1 is a smoke test (no code changes — commands only). Task 2 is a targeted bug fix: `ForecastModelRegistry.$fillable` + casts + `RunForecastJob` upsert + tests. Task 3 is a new read-only Inertia page following the exact same pattern as `Promotions/Index.vue`.

**Tech Stack:** PHP 8.3 / Laravel 12 / Pest 4 / Vue 3 + TypeScript + Inertia.js / Tailwind CSS v4

---

## Context: Known Issues Going In

Before reading further, understand what the code review found:

**In `app/Models/ForecastModelRegistry.php`:**
- `$fillable` only lists 12 columns — missing `interval_lower`, `interval_upper`, `interval_confidence`, `interval_empirical_coverage`, `selection_rationale`, `transformation_applied`, `hyperparameters`
- `casts()` similarly missing those fields

**In `app/Jobs/RunForecastJob.php` lines 152–167:**
- The `updateOrCreate` call only writes the original 12 columns
- The 7 new columns from the Python output are silently dropped

**Python output (`registry.py`)** already emits all 7 new fields correctly — the gap is entirely on the PHP side.

---

## Task 1: End-to-End Smoke Test (All 11 SKUs)

No code changes. Run commands, check DB, document any failures.

**Files:** none — read-only verification

**Step 1: Confirm the Python binary works**

```bash
py python/forecasting/main.py --help
```

Expected: prints argparse usage. If it fails, check `PYTHON_BIN=py` in `.env`.

**Step 2: Dispatch forecast sweep for tenant 1**

```bash
"/c/Users/hp/.config/herd/bin/php83/php.exe" artisan forecast:sweep --tenant=1
```

`QUEUE_CONNECTION=sync` (already set in `.env`) means all 11 jobs run synchronously in this process. Expected output:
```
Dispatched 11 forecast jobs.
```

If any job throws, it surfaces here. If Python fails for a SKU, you'll see the error in the terminal. Note which SKUs fail and why.

**Step 3: Verify forecast_model_registry has 11 rows**

```bash
"/c/Users/hp/.config/herd/bin/php83/php.exe" artisan tinker --execute="echo App\Models\ForecastModelRegistry::withoutGlobalScopes()->count();"
```

Expected: `11`

**Step 4: Spot-check a registry row**

```bash
"/c/Users/hp/.config/herd/bin/php83/php.exe" artisan tinker --execute="print_r(App\Models\ForecastModelRegistry::withoutGlobalScopes()->first()->toArray());"
```

Expected: row with `model_name`, `demand_rate`, `mae`, etc. Note that `interval_lower` / `interval_upper` / `selection_rationale` will be **null** at this point — that's the bug Task 2 fixes.

**Step 5: Verify sku_demand_profiles has 11 rows**

```bash
"/c/Users/hp/.config/herd/bin/php83/php.exe" artisan tinker --execute="echo App\Models\SkuDemandProfile::withoutGlobalScopes()->count();"
```

Expected: `11`

**Step 6: Run the inventory engine**

```bash
"/c/Users/hp/.config/herd/bin/php83/php.exe" artisan tinker --execute="(new App\Services\InventoryEngine\InventoryEngineService(new App\Services\InventoryEngine\DemandForecaster, new App\Services\InventoryEngine\InventoryPositionTracker, new App\Services\InventoryEngine\LeadTimeHandler, new App\Services\InventoryEngine\ConstraintEngine, new App\Services\InventoryEngine\DecisionScorer, new App\Services\InventoryEngine\AbcXyzClassifier))->run(tenantId: 1);"
```

Expected: no exception. Decisions written to `inventory_decisions` table.

**Step 7: Verify decisions used registry demand_rate (not fallback)**

Open http://procurement_project.test in the browser. Dashboard should show non-zero decisions. If `DemandForecaster` fell back to weighted moving average, the `method` column in decisions would show `weighted_moving_average`. Check:

```bash
"/c/Users/hp/.config/herd/bin/php83/php.exe" artisan tinker --execute="print_r(App\Models\InventoryDecision::withoutGlobalScopes()->orderByDesc('run_at')->limit(5)->get(['sku_id','decision','forecast_demand'])->toArray());"
```

Document the smoke test result (pass/fail per SKU) before moving to Task 2.

---

## Task 2: Fix Interval Field Wiring

**Files:**
- Modify: `app/Models/ForecastModelRegistry.php`
- Modify: `app/Jobs/RunForecastJob.php:152–167`
- Modify: `tests/Feature/Jobs/RunForecastJobTest.php` (update mock outputs + add new test)

### Step 1: Fix ForecastModelRegistry $fillable

In `app/Models/ForecastModelRegistry.php`, replace the `$fillable` array:

```php
protected $fillable = [
    'tenant_id',
    'sku_id',
    'model_name',
    'demand_rate',
    'forecast_horizon_days',
    'mae',
    'rmse',
    'bias',
    'smape',
    'interval_lower',
    'interval_upper',
    'interval_confidence',
    'interval_empirical_coverage',
    'selection_rationale',
    'transformation_applied',
    'hyperparameters',
    'reeval_trigger',
    'warnings',
    'trained_at',
    'next_review_at',
];
```

### Step 2: Fix ForecastModelRegistry casts()

In the same file, replace the `casts()` method:

```php
protected function casts(): array
{
    return [
        'demand_rate'                  => 'float',
        'forecast_horizon_days'        => 'integer',
        'mae'                          => 'float',
        'rmse'                         => 'float',
        'bias'                         => 'float',
        'smape'                        => 'float',
        'interval_lower'               => 'float',
        'interval_upper'               => 'float',
        'interval_confidence'          => 'float',
        'interval_empirical_coverage'  => 'float',
        'hyperparameters'              => 'array',
        'warnings'                     => 'array',
        'trained_at'                   => 'datetime',
        'next_review_at'               => 'datetime',
    ];
}
```

### Step 3: Fix RunForecastJob upsert

In `app/Jobs/RunForecastJob.php`, replace the `updateOrCreate` call (lines 152–167) with:

```php
ForecastModelRegistry::withoutGlobalScopes()->updateOrCreate(
    ['tenant_id' => $this->tenantId, 'sku_id' => $this->skuId],
    [
        'model_name'                    => $output['model_name'],
        'demand_rate'                   => $output['demand_rate'],
        'forecast_horizon_days'         => $output['forecast_horizon_days'] ?? 30,
        'mae'                           => $output['mae'] ?? null,
        'rmse'                          => $output['rmse'] ?? null,
        'bias'                          => $output['bias'] ?? null,
        'smape'                         => $output['smape'] ?? null,
        'interval_lower'                => $output['interval_lower'] ?? null,
        'interval_upper'                => $output['interval_upper'] ?? null,
        'interval_confidence'           => $output['interval_confidence'] ?? 0.95,
        'interval_empirical_coverage'   => $output['interval_empirical_coverage'] ?? null,
        'selection_rationale'           => $output['selection_rationale'] ?? null,
        'transformation_applied'        => $output['transformation_applied'] ?? 'none',
        'hyperparameters'               => $output['hyperparameters'] ?? [],
        'reeval_trigger'                => $output['reeval_trigger'],
        'warnings'                      => $output['warnings'] ?? [],
        'trained_at'                    => $output['trained_at'],
        'next_review_at'                => $output['next_review_at'],
    ],
);
```

### Step 4: Write the failing test

Open `tests/Feature/Jobs/RunForecastJobTest.php`. Add this test at the end of the file:

```php
it('persists interval and selection fields from Python output', function () {
    $supplier = Supplier::factory()->create();
    $sku      = Sku::factory()->create(['supplier_id' => $supplier->id]);

    $fakeOutput = json_encode([
        'sku_id'                        => $sku->id,
        'tenant_id'                     => 1,
        'model_name'                    => 'holt_winters',
        'demand_rate'                   => 3.1200,
        'forecast_horizon_days'         => 30,
        'mae'                           => 0.8,
        'rmse'                          => 1.1,
        'bias'                          => -0.05,
        'smape'                         => 14.2,
        'interval_lower'                => 2.4000,
        'interval_upper'                => 3.8000,
        'interval_confidence'           => 0.95,
        'interval_empirical_coverage'   => 0.923,
        'selection_rationale'           => 'holt_winters beat baseline (CV MAE 0.8 vs 1.2)',
        'transformation_applied'        => 'none',
        'hyperparameters'               => ['alpha' => 0.3, 'beta' => 0.1],
        'reeval_trigger'                => 'scheduled',
        'warnings'                      => [],
        'trained_at'                    => '2026-04-16T10:00:00Z',
        'next_review_at'                => '2026-05-16T10:00:00Z',
        'demand_profile'                => [
            'volume_tier'          => 'medium',
            'volatility'           => 'stable',
            'intermittency'        => 'continuous',
            'seasonality_detected' => false,
            'trend_direction'      => 'flat',
            'history_days_used'    => 180,
        ],
    ]);

    Process::fake([
        '*' => Process::result(output: $fakeOutput, exitCode: 0),
    ]);

    (new RunForecastJob($sku->id, 1, 'scheduled'))->handle();

    $registry = ForecastModelRegistry::withoutGlobalScopes()
        ->where('sku_id', $sku->id)
        ->first();

    expect($registry->interval_lower)->toBe(2.4);
    expect($registry->interval_upper)->toBe(3.8);
    expect($registry->interval_confidence)->toBe(0.95);
    expect($registry->interval_empirical_coverage)->toBe(0.923);
    expect($registry->selection_rationale)->toBe('holt_winters beat baseline (CV MAE 0.8 vs 1.2)');
    expect($registry->transformation_applied)->toBe('none');
    expect($registry->hyperparameters)->toBe(['alpha' => 0.3, 'beta' => 0.1]);
});
```

### Step 5: Run the new test to verify it FAILS (before fix is complete)

At this point we've already made the fix in Steps 1-3. The test should pass. Run:

```bash
"/c/Users/hp/.config/herd/bin/php83/php.exe" artisan test --filter="persists interval and selection fields" --compact
```

Expected: PASS (since we already made the model + job fixes).

### Step 6: Run full test suite to verify nothing regressed

```bash
"/c/Users/hp/.config/herd/bin/php83/php.exe" artisan test --compact
```

Expected: 64 passed (was 63 — one new test added).

### Step 7: Re-run smoke test with fixed wiring

```bash
"/c/Users/hp/.config/herd/bin/php83/php.exe" artisan forecast:sweep --tenant=1
"/c/Users/hp/.config/herd/bin/php83/php.exe" artisan tinker --execute="print_r(App\Models\ForecastModelRegistry::withoutGlobalScopes()->first(['interval_lower','interval_upper','selection_rationale'])->toArray());"
```

Expected: `interval_lower` and `interval_upper` are now numeric (not null), `selection_rationale` has text from the Python selection logic.

### Step 8: Commit

```bash
git add app/Models/ForecastModelRegistry.php app/Jobs/RunForecastJob.php tests/Feature/Jobs/RunForecastJobTest.php
git commit -m "fix: wire interval/selection fields through RunForecastJob into forecast_model_registry

- ForecastModelRegistry: add 7 new columns to \$fillable and casts()
- RunForecastJob: include interval_lower/upper/confidence/coverage,
  selection_rationale, transformation_applied, hyperparameters in upsert
- New test: persists interval and selection fields from Python output
- 64 tests passing"
```

---

## Task 3: Reports Page

**Files:**
- Create: `app/Http/Controllers/ReportsController.php`
- Modify: `routes/web.php`
- Create: `resources/js/Pages/Reports/Index.vue`
- Modify: `resources/js/Components/AppHeader.vue`
- Modify: `resources/js/locales/en.ts`
- Modify: `resources/js/locales/ar.ts`

### Step 1: Write the failing feature test

Create `tests/Feature/Reports/ReportsPageTest.php`:

```php
<?php

use App\Models\ForecastModelRegistry;
use App\Models\Sku;
use App\Models\Supplier;
use App\Models\User;

it('renders the reports page with registry rows', function () {
    $user = User::factory()->create(['tenant_id' => 1]);

    $supplier = Supplier::factory()->create();
    $sku = Sku::factory()->create(['supplier_id' => $supplier->id, 'tenant_id' => 1]);

    ForecastModelRegistry::withoutGlobalScopes()->create([
        'tenant_id'           => 1,
        'sku_id'              => $sku->id,
        'model_name'          => 'holt_winters',
        'demand_rate'         => 3.5,
        'mae'                 => 0.8,
        'smape'               => 14.2,
        'selection_rationale' => 'holt_winters beat baseline',
        'trained_at'          => now(),
        'next_review_at'      => now()->addDays(30),
    ]);

    $this->actingAs($user)
         ->get('/reports')
         ->assertOk()
         ->assertInertia(fn ($page) => $page
             ->component('Reports/Index')
             ->has('rows', 1)
             ->where('rows.0.model_name', 'holt_winters')
             ->where('rows.0.mae', 0.8)
         );
});

it('redirects guests to login', function () {
    $this->get('/reports')->assertRedirect('/login');
});
```

Run to confirm FAIL:

```bash
"/c/Users/hp/.config/herd/bin/php83/php.exe" artisan test --filter="ReportsPage" --compact
```

Expected: FAIL — route doesn't exist yet.

### Step 2: Create the controller

Create `app/Http/Controllers/ReportsController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\ForecastModelRegistry;
use App\Models\SalesHistory;
use Carbon\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ReportsController extends Controller
{
    public function __invoke(): Response
    {
        $tenantId = auth()->user()->tenant_id;

        $since = Carbon::today()->subDays(30);

        $registries = ForecastModelRegistry::with('sku')
            ->where('tenant_id', $tenantId)
            ->orderBy('sku_id')
            ->get();

        // Portfolio WMAPE (same logic as DashboardController)
        $wmape = $this->computeWmape($registries, $tenantId, $since);

        $rows = $registries->map(fn ($r) => [
            'sku_id'                      => $r->sku_id,
            'sku_code'                    => $r->sku->sku_code,
            'sku_name'                    => $r->sku->name,
            'model_name'                  => $r->model_name,
            'demand_rate'                 => (float) $r->demand_rate,
            'mae'                         => $r->mae !== null ? (float) $r->mae : null,
            'smape'                       => $r->smape !== null ? (float) $r->smape : null,
            'interval_lower'              => $r->interval_lower !== null ? (float) $r->interval_lower : null,
            'interval_upper'              => $r->interval_upper !== null ? (float) $r->interval_upper : null,
            'interval_confidence'         => $r->interval_confidence !== null ? (float) $r->interval_confidence : null,
            'interval_empirical_coverage' => $r->interval_empirical_coverage !== null ? (float) $r->interval_empirical_coverage : null,
            'selection_rationale'         => $r->selection_rationale,
            'reeval_trigger'              => $r->reeval_trigger,
            'warnings'                    => $r->warnings ?? [],
            'trained_at'                  => $r->trained_at?->toDateTimeString(),
            'next_review_at'              => $r->next_review_at?->toDateTimeString(),
        ])->values();

        return Inertia::render('Reports/Index', [
            'rows'  => $rows,
            'wmape' => $wmape,
        ]);
    }

    private function computeWmape($registries, int $tenantId, Carbon $since): ?float
    {
        if ($registries->isEmpty()) {
            return null;
        }

        $totalActual = 0.0;
        $totalError  = 0.0;

        foreach ($registries as $reg) {
            $actualAvg = SalesHistory::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('sku_id', $reg->sku_id)
                ->where('sale_date', '>=', $since)
                ->avg('quantity_sold') ?? 0.0;

            $totalActual += abs($actualAvg);
            $totalError  += abs((float) $reg->demand_rate - $actualAvg);
        }

        if ($totalActual <= 0) {
            return null;
        }

        return round(($totalError / $totalActual) * 100, 1);
    }
}
```

### Step 3: Register the route

In `routes/web.php`, add inside the `auth` middleware group (after the `/settings` line):

```php
use App\Http\Controllers\ReportsController;

Route::get('/reports', ReportsController::class)->name('reports.index');
```

Also add the `use` statement at the top with the other controller imports.

### Step 4: Run the test again — expect PASS

```bash
"/c/Users/hp/.config/herd/bin/php83/php.exe" artisan test --filter="ReportsPage" --compact
```

Expected: 2 passed.

### Step 5: Add i18n keys (EN)

In `resources/js/locales/en.ts`, add a `reports` section before the closing `}`:

```ts
reports: {
    title: 'Forecast Reports',
    subtitle: 'Per-SKU model performance and accuracy metrics',
    sku: 'SKU',
    model: 'Model',
    demand_rate: 'Demand Rate',
    mae: 'MAE',
    smape: 'sMAPE',
    interval: 'Interval (95%)',
    coverage: 'Coverage',
    rationale: 'Selection Rationale',
    trigger: 'Trigger',
    trained_at: 'Trained',
    next_review: 'Next Review',
    warnings: 'Warnings',
    no_data: 'No forecast data yet. Run a forecast sweep to populate.',
    units_per_day: 'units/day',
},
```

### Step 6: Add i18n keys (AR)

In `resources/js/locales/ar.ts`, add the same section in Arabic:

```ts
reports: {
    title: 'تقارير التنبؤ',
    subtitle: 'أداء النموذج ومقاييس الدقة لكل منتج',
    sku: 'المنتج',
    model: 'النموذج',
    demand_rate: 'معدل الطلب',
    mae: 'MAE',
    smape: 'sMAPE',
    interval: 'النطاق (95%)',
    coverage: 'التغطية',
    rationale: 'مبرر الاختيار',
    trigger: 'المحفّز',
    trained_at: 'تاريخ التدريب',
    next_review: 'المراجعة التالية',
    warnings: 'تحذيرات',
    no_data: 'لا توجد بيانات تنبؤ بعد. قم بتشغيل مسح التنبؤ.',
    units_per_day: 'وحدة/يوم',
},
```

### Step 7: Create the Vue page

Create `resources/js/Pages/Reports/Index.vue`:

```vue
<script setup lang="ts">
import AppHeader from '@/Components/AppHeader.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface ReportRow {
    sku_id: number;
    sku_code: string;
    sku_name: string;
    model_name: string;
    demand_rate: number;
    mae: number | null;
    smape: number | null;
    interval_lower: number | null;
    interval_upper: number | null;
    interval_confidence: number | null;
    interval_empirical_coverage: number | null;
    selection_rationale: string | null;
    reeval_trigger: string | null;
    warnings: string[];
    trained_at: string | null;
    next_review_at: string | null;
}

defineProps<{
    rows: ReportRow[];
    wmape: number | null;
}>();

function modelBadgeClass(model: string): string {
    const map: Record<string, string> = {
        holt_winters: 'bg-blue-100 text-blue-700',
        sarimax:      'bg-purple-100 text-purple-700',
        lightgbm:     'bg-emerald-100 text-emerald-700',
        croston:      'bg-amber-100 text-amber-700',
        prophet:      'bg-rose-100 text-rose-700',
        ets_fallback: 'bg-slate-100 text-slate-600',
    };
    return map[model] ?? 'bg-slate-100 text-slate-600';
}

function fmt(val: number | null, decimals = 4): string {
    if (val === null) return '—';
    return val.toFixed(decimals);
}

function intervalLabel(row: ReportRow): string {
    if (row.interval_lower === null || row.interval_upper === null) return '—';
    return `${row.interval_lower.toFixed(2)} – ${row.interval_upper.toFixed(2)}`;
}

function coverageLabel(row: ReportRow): string {
    if (row.interval_empirical_coverage === null) return '—';
    const pct = (row.interval_empirical_coverage * 100).toFixed(1);
    const target = row.interval_confidence !== null ? (row.interval_confidence * 100).toFixed(0) : '95';
    const ok = row.interval_empirical_coverage >= (row.interval_confidence ?? 0.95) - 0.03;
    return `${pct}% ${ok ? '✓' : '↓'} (target ${target}%)`;
}

function triggerBadgeClass(trigger: string | null): string {
    const map: Record<string, string> = {
        scheduled:  'bg-slate-100 text-slate-500',
        new_sku:    'bg-emerald-100 text-emerald-700',
        bias_drift: 'bg-amber-100 text-amber-700',
        manual:     'bg-blue-100 text-blue-600',
    };
    return map[trigger ?? ''] ?? 'bg-slate-100 text-slate-500';
}
</script>

<template>
    <AppHeader />

    <main class="max-w-7xl mx-auto px-6 lg:px-8 py-8">
        <!-- Page header + WMAPE -->
        <div class="flex items-start justify-between mb-6">
            <div>
                <h1 class="text-xl font-bold text-slate-800">{{ t('reports.title') }}</h1>
                <p class="text-sm text-slate-500 mt-0.5">{{ t('reports.subtitle') }}</p>
            </div>
            <div class="text-right">
                <p class="text-xs text-slate-400 uppercase tracking-wide font-semibold">{{ t('wmape.label') }}</p>
                <p class="text-2xl font-bold text-slate-800 tabular-nums mt-0.5">
                    {{ wmape !== null ? wmape + '%' : t('wmape.no_data') }}
                </p>
                <p class="text-xs text-slate-400 mt-0.5">{{ t('wmape.tooltip') }}</p>
            </div>
        </div>

        <!-- Table -->
        <div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
            <div v-if="rows.length === 0" class="py-16 text-center text-sm text-slate-400">
                {{ t('reports.no_data') }}
            </div>

            <div v-else class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 bg-slate-50">
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wide">{{ t('reports.sku') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wide">{{ t('reports.model') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wide">{{ t('reports.demand_rate') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wide">{{ t('reports.mae') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wide">{{ t('reports.smape') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wide">{{ t('reports.interval') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wide">{{ t('reports.coverage') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wide">{{ t('reports.trigger') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wide">{{ t('reports.rationale') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wide">{{ t('reports.trained_at') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr
                            v-for="row in rows"
                            :key="row.sku_id"
                            class="hover:bg-slate-50 transition-colors"
                        >
                            <!-- SKU -->
                            <td class="px-4 py-3">
                                <span class="font-mono text-xs text-slate-400">{{ row.sku_code }}</span>
                                <span class="block text-slate-700 font-medium leading-tight">{{ row.sku_name }}</span>
                            </td>

                            <!-- Model badge -->
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold"
                                    :class="modelBadgeClass(row.model_name)"
                                >
                                    {{ row.model_name }}
                                </span>
                            </td>

                            <!-- Demand rate -->
                            <td class="px-4 py-3 text-right font-mono text-slate-700 tabular-nums">
                                {{ fmt(row.demand_rate) }}
                                <span class="text-xs text-slate-400 ml-0.5">{{ t('reports.units_per_day') }}</span>
                            </td>

                            <!-- MAE -->
                            <td class="px-4 py-3 text-right font-mono text-slate-700 tabular-nums">
                                {{ fmt(row.mae, 4) }}
                            </td>

                            <!-- sMAPE -->
                            <td class="px-4 py-3 text-right font-mono tabular-nums"
                                :class="row.smape !== null && row.smape > 50 ? 'text-amber-600 font-semibold' : 'text-slate-700'"
                            >
                                {{ row.smape !== null ? row.smape.toFixed(1) + '%' : '—' }}
                            </td>

                            <!-- Interval -->
                            <td class="px-4 py-3 text-right font-mono text-slate-600 tabular-nums text-xs">
                                {{ intervalLabel(row) }}
                            </td>

                            <!-- Coverage -->
                            <td class="px-4 py-3 text-right text-xs"
                                :class="row.interval_empirical_coverage !== null && row.interval_empirical_coverage < (row.interval_confidence ?? 0.95) - 0.03 ? 'text-amber-600 font-semibold' : 'text-slate-600'"
                            >
                                {{ coverageLabel(row) }}
                            </td>

                            <!-- Trigger -->
                            <td class="px-4 py-3">
                                <span
                                    v-if="row.reeval_trigger"
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold"
                                    :class="triggerBadgeClass(row.reeval_trigger)"
                                >
                                    {{ row.reeval_trigger }}
                                </span>
                                <span v-else class="text-slate-300">—</span>
                            </td>

                            <!-- Selection rationale -->
                            <td class="px-4 py-3 text-xs text-slate-500 max-w-xs truncate" :title="row.selection_rationale ?? ''">
                                {{ row.selection_rationale || '—' }}
                            </td>

                            <!-- Trained at -->
                            <td class="px-4 py-3 text-xs text-slate-500 tabular-nums whitespace-nowrap">
                                {{ row.trained_at ? row.trained_at.substring(0, 10) : '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</template>
```

### Step 8: Add Reports link to AppHeader.vue

In `resources/js/Components/AppHeader.vue`, add the Reports link after the Promotions link:

```vue
<Link
    href="/reports"
    class="px-3 py-1.5 text-sm text-slate-500 hover:text-slate-800 hover:bg-slate-100 rounded-lg transition-colors duration-150"
>
    Reports
</Link>
```

### Step 9: Run full test suite

```bash
"/c/Users/hp/.config/herd/bin/php83/php.exe" artisan test --compact
```

Expected: 66 passed (63 original + 1 interval wiring + 2 reports page).

### Step 10: Manual UI check

Make sure Vite dev server is running (`npm run dev` in a separate terminal). Open http://procurement_project.test/reports in browser. Verify:
- Table shows all SKUs with forecast data
- Model names render as colored badges
- sMAPE > 50% shows amber color
- Interval range shows in `X.XX – X.XX` format
- Coverage check mark/down-arrow renders correctly
- WMAPE card shows in top-right

If `interval_lower` is null for all rows (because the smoke test ran before the wiring fix), re-run the sweep:

```bash
"/c/Users/hp/.config/herd/bin/php83/php.exe" artisan forecast:sweep --tenant=1
```

Then refresh the page.

### Step 11: Commit

```bash
git add app/Http/Controllers/ReportsController.php routes/web.php resources/js/Pages/Reports/Index.vue resources/js/Components/AppHeader.vue resources/js/locales/en.ts resources/js/locales/ar.ts tests/Feature/Reports/ReportsPageTest.php
git commit -m "feat: Reports page — per-SKU model performance table

- ReportsController: queries forecast_model_registry, computes portfolio WMAPE
- GET /reports route wired
- Reports/Index.vue: table with model badges, MAE, sMAPE, intervals,
  coverage check, selection rationale, trigger badges
- AppHeader: Reports nav link added
- i18n: reports section added to en.ts and ar.ts
- 2 new Pest tests (render + guest redirect)
- 66 tests passing"
```

---

## Execution Order Summary

| Task | Code changes | Tests added | Gate |
|------|-------------|-------------|------|
| 1 — Smoke test | None | None | 11 SKUs in registry, decisions rendered |
| 2 — Interval wiring | `ForecastModelRegistry`, `RunForecastJob` | +1 | 64 tests pass |
| 3 — Reports page | Controller, route, Vue, AppHeader, i18n | +2 | 66 tests pass, UI verified |
