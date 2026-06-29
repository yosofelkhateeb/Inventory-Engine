# Phase 1 Improvements Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add engine run logging, ABC-XYZ SKU classification, dead stock flag, real-time notification bell, and daily scheduler to the inventory engine MVP.

**Architecture:** Ten focused tasks. Tasks 1–6 are backend-only (migrations, models, service classes, job/scheduler updates). Tasks 7–9 are frontend (dashboard + SKU pages). Task 10 is the low-priority stock adjustments tab. Each task is independently testable and committed before the next begins.

**Tech Stack:** Laravel 12, PHP 8.3, Vue 3 (`<script setup lang="ts">`), Inertia.js v2, Tailwind CSS v4, Pest 3, Laravel Reverb (WebSockets), laravel-echo, pusher-js

**PHP binary:** `C:\Users\hp\.config\herd\bin\php.bat`
**npm binary:** `"C:\Program Files\nodejs\npm.cmd"`
**Run tests:** `C:\Users\hp\.config\herd\bin\php.bat artisan test`
**Run Vite build:** `PATH="/c/Program Files/nodejs:$PATH" "C:\Program Files\nodejs\npm.cmd" run build --prefix "C:\Users\hp\OneDrive\Desktop\Procurement_Project"`

---

## Task 1: Migrations — engine_runs, abc/xyz columns on skus, stock_adjustments

**Files:**
- Create: `database/migrations/2026_03_17_000001_create_engine_runs_table.php`
- Create: `database/migrations/2026_03_17_000002_add_classification_to_skus_table.php`
- Create: `database/migrations/2026_03_17_000003_create_stock_adjustments_table.php`

**Step 1: Create the engine_runs migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engine_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('run_at');
            $table->enum('status', ['running', 'completed', 'failed'])->default('running');
            $table->unsignedInteger('decisions_count')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engine_runs');
    }
};
```

**Step 2: Create the abc/xyz columns migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skus', function (Blueprint $table) {
            $table->enum('abc_class', ['A', 'B', 'C'])->nullable()->after('lead_time_days');
            $table->enum('xyz_class', ['X', 'Y', 'Z'])->nullable()->after('abc_class');
        });
    }

    public function down(): void
    {
        Schema::table('skus', function (Blueprint $table) {
            $table->dropColumn(['abc_class', 'xyz_class']);
        });
    }
};
```

**Step 3: Create the stock_adjustments migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sku_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('old_qty');
            $table->integer('new_qty');
            $table->integer('delta')->virtualAs('new_qty - old_qty');
            $table->enum('reason_code', [
                'cycle_count',
                'damage_writeoff',
                'customer_return',
                'supplier_short_ship',
                'data_entry_correction',
                'internal_use',
            ]);
            $table->text('notes')->nullable();
            $table->timestamp('adjusted_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};
```

**Step 4: Run migrations**

```bash
C:\Users\hp\.config\herd\bin\php.bat artisan migrate
```
Expected: three new tables created, no errors.

**Step 5: Commit**

```bash
git add database/migrations/
git commit -m "feat: add engine_runs, stock_adjustments migrations and abc/xyz columns on skus"
```

---

## Task 2: Models — EngineRun, StockAdjustment, update Sku

**Files:**
- Create: `app/Models/EngineRun.php`
- Create: `app/Models/StockAdjustment.php`
- Modify: `app/Models/Sku.php`
- Create: `tests/Feature/Models/EngineRunTest.php`

**Step 1: Write the failing test**

```php
<?php

use App\Models\EngineRun;
use App\Models\User;

it('can create an engine run record', function () {
    $user = User::factory()->create();

    $run = EngineRun::create([
        'triggered_by'    => $user->id,
        'run_at'          => now(),
        'status'          => 'completed',
        'decisions_count' => 11,
        'duration_ms'     => 430,
    ]);

    expect($run->status)->toBe('completed')
        ->and($run->decisions_count)->toBe(11)
        ->and($run->triggeredBy->id)->toBe($user->id);
});

it('allows null triggered_by for scheduled runs', function () {
    $run = EngineRun::create([
        'triggered_by'    => null,
        'run_at'          => now(),
        'status'          => 'completed',
        'decisions_count' => 11,
    ]);

    expect($run->triggered_by)->toBeNull();
});
```

**Step 2: Run test to confirm it fails**

```bash
C:\Users\hp\.config\herd\bin\php.bat artisan test tests/Feature/Models/EngineRunTest.php
```
Expected: FAIL — `EngineRun` class not found.

**Step 3: Create EngineRun model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngineRun extends Model
{
    protected $fillable = [
        'triggered_by',
        'run_at',
        'status',
        'decisions_count',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'run_at'          => 'datetime',
            'decisions_count' => 'integer',
            'duration_ms'     => 'integer',
        ];
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
```

**Step 4: Create StockAdjustment model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAdjustment extends Model
{
    protected $fillable = [
        'sku_id',
        'user_id',
        'old_qty',
        'new_qty',
        'reason_code',
        'notes',
        'adjusted_at',
    ];

    protected function casts(): array
    {
        return [
            'old_qty'      => 'integer',
            'new_qty'      => 'integer',
            'adjusted_at'  => 'datetime',
        ];
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

**Step 5: Update Sku model — add abc_class/xyz_class to fillable and casts**

In `app/Models/Sku.php`, add `'abc_class'` and `'xyz_class'` to the `$fillable` array:

```php
protected $fillable = [
    'supplier_id',
    'name',
    'sku_code',
    'moq',
    'unit_cost',
    'reorder_qty',
    'current_stock',
    'in_transit_qty',
    'reserved_qty',
    'lead_time_days',
    'abc_class',
    'xyz_class',
];
```

No cast needed — they are plain strings.

**Step 6: Run tests**

```bash
C:\Users\hp\.config\herd\bin\php.bat artisan test tests/Feature/Models/EngineRunTest.php
```
Expected: 2 tests PASS.

```bash
C:\Users\hp\.config\herd\bin\php.bat artisan test
```
Expected: all existing tests still PASS.

**Step 7: Commit**

```bash
git add app/Models/EngineRun.php app/Models/StockAdjustment.php app/Models/Sku.php tests/Feature/Models/EngineRunTest.php
git commit -m "feat: add EngineRun and StockAdjustment models, add abc/xyz to Sku"
```

---

## Task 3: AbcXyzClassifier service

**Files:**
- Create: `app/Services/InventoryEngine/AbcXyzClassifier.php`
- Create: `tests/Unit/InventoryEngine/AbcXyzClassifierTest.php`

**Step 1: Write failing tests**

```php
<?php

use App\Models\Sku;
use App\Models\Supplier;
use App\Models\SalesHistory;
use App\Services\InventoryEngine\AbcXyzClassifier;
use Carbon\Carbon;

it('classifies high-revenue sku as A', function () {
    $supplier = Supplier::factory()->create();

    // Expensive SKU with high sales → A class
    $skuA = Sku::factory()->create(['supplier_id' => $supplier->id, 'unit_cost' => 50000]); // 500 SAR
    // Cheap SKU with low sales → C class
    $skuC = Sku::factory()->create(['supplier_id' => $supplier->id, 'unit_cost' => 800]);  // 8 SAR

    $since = Carbon::today()->subDays(89);
    foreach (range(0, 89) as $i) {
        SalesHistory::create(['sku_id' => $skuA->id, 'sale_date' => $since->copy()->addDays($i), 'quantity_sold' => 5]);
        SalesHistory::create(['sku_id' => $skuC->id, 'sale_date' => $since->copy()->addDays($i), 'quantity_sold' => 1]);
    }

    $skus = Sku::whereIn('id', [$skuA->id, $skuC->id])->get();
    (new AbcXyzClassifier())->classify($skus);

    expect(Sku::find($skuA->id)->abc_class)->toBe('A');
    expect(Sku::find($skuC->id)->abc_class)->toBe('C');
});

it('classifies stable demand sku as X', function () {
    $supplier = Supplier::factory()->create();
    $sku = Sku::factory()->create(['supplier_id' => $supplier->id, 'unit_cost' => 1000]);

    $since = Carbon::today()->subDays(89);
    // Perfectly stable demand → CV = 0 → X
    foreach (range(0, 89) as $i) {
        SalesHistory::create(['sku_id' => $sku->id, 'sale_date' => $since->copy()->addDays($i), 'quantity_sold' => 5]);
    }

    $skus = Sku::where('id', $sku->id)->get();
    (new AbcXyzClassifier())->classify($skus);

    expect(Sku::find($sku->id)->xyz_class)->toBe('X');
});

it('classifies erratic demand sku as Z', function () {
    $supplier = Supplier::factory()->create();
    $sku = Sku::factory()->create(['supplier_id' => $supplier->id, 'unit_cost' => 1000]);

    $since = Carbon::today()->subDays(89);
    // Highly variable demand → high CV → Z
    foreach (range(0, 89) as $i) {
        SalesHistory::create([
            'sku_id' => $sku->id,
            'sale_date' => $since->copy()->addDays($i),
            'quantity_sold' => $i % 10 === 0 ? 50 : 0, // spikes every 10 days
        ]);
    }

    $skus = Sku::where('id', $sku->id)->get();
    (new AbcXyzClassifier())->classify($skus);

    expect(Sku::find($sku->id)->xyz_class)->toBe('Z');
});

it('classifies sku with no sales as Z', function () {
    $supplier = Supplier::factory()->create();
    $sku = Sku::factory()->create(['supplier_id' => $supplier->id]);

    $skus = Sku::where('id', $sku->id)->get();
    (new AbcXyzClassifier())->classify($skus);

    expect(Sku::find($sku->id)->xyz_class)->toBe('Z');
});

it('returns correct safety stock multiplier per class combination', function () {
    $classifier = new AbcXyzClassifier();

    expect($classifier->getSafetyStockMultiplier('A', 'Z'))->toBe(1.5);
    expect($classifier->getSafetyStockMultiplier('A', 'Y'))->toBe(1.2);
    expect($classifier->getSafetyStockMultiplier('C', 'Z'))->toBe(0.8);
    expect($classifier->getSafetyStockMultiplier('A', 'X'))->toBe(1.0);
    expect($classifier->getSafetyStockMultiplier('B', 'Y'))->toBe(1.0);
});
```

**Step 2: Run tests to confirm they fail**

```bash
C:\Users\hp\.config\herd\bin\php.bat artisan test tests/Unit/InventoryEngine/AbcXyzClassifierTest.php
```
Expected: FAIL — `AbcXyzClassifier` not found.

**Step 3: Create AbcXyzClassifier**

```php
<?php

namespace App\Services\InventoryEngine;

use App\Models\SalesHistory;
use App\Models\Sku;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AbcXyzClassifier
{
    private const LOOKBACK_DAYS = 90;

    public function classify(Collection $skus): void
    {
        $revenues  = $this->computeRevenues($skus);
        $total     = $revenues->sum();
        $abcClasses = $this->assignAbcClasses($revenues, $total);

        foreach ($skus as $sku) {
            $sku->abc_class = $abcClasses[$sku->id] ?? 'C';
            $sku->xyz_class = $this->computeXyzClass($sku->id);
            $sku->save();
        }
    }

    public function getSafetyStockMultiplier(string $abc, string $xyz): float
    {
        return match ("{$abc}{$xyz}") {
            'AZ' => 1.5,
            'AY' => 1.2,
            'CZ' => 0.8,
            default => 1.0,
        };
    }

    private function computeRevenues(Collection $skus): Collection
    {
        $since = Carbon::today()->subDays(self::LOOKBACK_DAYS);

        return $skus->mapWithKeys(function (Sku $sku) use ($since) {
            $units = SalesHistory::where('sku_id', $sku->id)
                ->where('sale_date', '>=', $since)
                ->sum('quantity_sold');

            return [$sku->id => $units * $sku->unit_cost];
        });
    }

    private function assignAbcClasses(Collection $revenues, int|float $total): array
    {
        $sorted     = $revenues->sortDesc();
        $cumulative = 0;
        $classes    = [];

        foreach ($sorted as $skuId => $revenue) {
            $cumulative += $revenue;
            $share = $total > 0 ? $cumulative / $total : 1.0;

            $classes[$skuId] = match (true) {
                $share <= 0.70 => 'A',
                $share <= 0.90 => 'B',
                default        => 'C',
            };
        }

        return $classes;
    }

    private function computeXyzClass(int $skuId): string
    {
        $since = Carbon::today()->subDays(self::LOOKBACK_DAYS);

        $qtys = SalesHistory::where('sku_id', $skuId)
            ->where('sale_date', '>=', $since)
            ->pluck('quantity_sold')
            ->map(fn ($q) => (float) $q);

        if ($qtys->isEmpty()) {
            return 'Z';
        }

        $mean = $qtys->average();

        if ($mean == 0.0) {
            return 'Z';
        }

        $variance = $qtys->average(fn ($q) => ($q - $mean) ** 2);
        $stddev   = sqrt($variance);
        $cv       = $stddev / $mean;

        return match (true) {
            $cv < 0.5  => 'X',
            $cv <= 1.0 => 'Y',
            default    => 'Z',
        };
    }
}
```

**Step 4: Run tests**

```bash
C:\Users\hp\.config\herd\bin\php.bat artisan test tests/Unit/InventoryEngine/AbcXyzClassifierTest.php
```
Expected: 5 tests PASS.

```bash
C:\Users\hp\.config\herd\bin\php.bat artisan test
```
Expected: all tests PASS.

**Step 5: Commit**

```bash
git add app/Services/InventoryEngine/AbcXyzClassifier.php tests/Unit/InventoryEngine/AbcXyzClassifierTest.php
git commit -m "feat: add AbcXyzClassifier service with safety stock multiplier logic"
```

---

## Task 4: Update DecisionScorer — safety stock multiplier

**Files:**
- Modify: `app/Services/InventoryEngine/DecisionScorer.php`
- Modify: `tests/Unit/InventoryEngine/DecisionScorerTest.php`

**Step 1: Update the existing DecisionScorer test** — add one new test case, leave all existing tests unchanged

Add this test to `tests/Unit/InventoryEngine/DecisionScorerTest.php`:

```php
it('applies safety stock multiplier to reorder point calculation', function () {
    $scorer = new \App\Services\InventoryEngine\DecisionScorer();

    $position = new \App\Services\InventoryEngine\DTOs\InventoryPosition(
        effective_position: 20,
        days_of_cover: 5.0,
    );
    $forecast = new \App\Services\InventoryEngine\DTOs\ForecastResult(
        daily_demand: 2.0,
        demand_stddev: 1.0,
        horizon_demand: 60.0,
        horizon_days: 30,
        method: 'moving_average',
    );
    $leadTime = new \App\Services\InventoryEngine\DTOs\LeadTimeEstimate(
        stated_days: 7,
        buffered_days: 8.0,
    );
    $constrained = new \App\Services\InventoryEngine\DTOs\ConstrainedQuantity(
        raw_qty: 24,
        final_qty: 24,
        budget_blocked: false,
        constraint_notes: [],
    );

    // With multiplier = 1.5, safety stock grows → reorder point grows
    $decisionHighMultiplier = $scorer->score($position, $forecast, $leadTime, $constrained, 1.5);
    $decisionDefault        = $scorer->score($position, $forecast, $leadTime, $constrained, 1.0);

    expect($decisionHighMultiplier->safety_stock)->toBeGreaterThan($decisionDefault->safety_stock);
});
```

**Step 2: Run the new test to confirm it fails**

```bash
C:\Users\hp\.config\herd\bin\php.bat artisan test tests/Unit/InventoryEngine/DecisionScorerTest.php --filter "applies safety stock multiplier"
```
Expected: FAIL — `score()` does not accept a 5th argument.

**Step 3: Update DecisionScorer**

Add `float $safetyStockMultiplier = 1.0` as a new parameter and apply it to the safety stock formula:

```php
public function score(
    InventoryPosition   $position,
    ForecastResult      $forecast,
    LeadTimeEstimate    $leadTime,
    ConstrainedQuantity $constraints,
    float               $safetyStockMultiplier = 1.0,
): Decision {
    $safetyStock  = self::Z_SCORE * $forecast->demand_stddev * sqrt($leadTime->buffered_days) * $safetyStockMultiplier;
    $reorderPoint = ($forecast->daily_demand * $leadTime->buffered_days) + $safetyStock;

    // ... rest of method unchanged
```

Only the `$safetyStock` line changes. Everything else in the method body stays exactly the same.

**Step 4: Run full test suite**

```bash
C:\Users\hp\.config\herd\bin\php.bat artisan test
```
Expected: all tests PASS (default value = 1.0 means all existing tests are unaffected).

**Step 5: Commit**

```bash
git add app/Services/InventoryEngine/DecisionScorer.php tests/Unit/InventoryEngine/DecisionScorerTest.php
git commit -m "feat: add safetyStockMultiplier parameter to DecisionScorer"
```

---

## Task 5: Update InventoryEngineService — classifier + run logging

**Files:**
- Modify: `app/Services/InventoryEngine/InventoryEngineService.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `tests/Feature/InventoryEngine/EngineRunTest.php`

**Step 1: Add an assertion to the existing engine test** — check that an EngineRun record is created

Open `tests/Feature/InventoryEngine/EngineRunTest.php` and add to the existing test:

```php
use App\Models\EngineRun;

// At the end of the existing 'it runs engine for all skus' test, add:
expect(EngineRun::count())->toBe(1);
expect(EngineRun::first()->status)->toBe('completed');
expect(EngineRun::first()->decisions_count)->toBeGreaterThan(0);
```

**Step 2: Run the updated test to confirm it fails**

```bash
C:\Users\hp\.config\herd\bin\php.bat artisan test tests/Feature/InventoryEngine/EngineRunTest.php
```
Expected: FAIL — EngineRun count is 0.

**Step 3: Update InventoryEngineService**

Replace the entire file:

```php
<?php

namespace App\Services\InventoryEngine;

use App\Models\EngineRun;
use App\Models\InventoryDecision;
use App\Models\Sku;
use App\Services\InventoryEngine\DTOs\Decision;

class InventoryEngineService
{
    public function __construct(
        private readonly DemandForecaster         $forecaster,
        private readonly InventoryPositionTracker $tracker,
        private readonly LeadTimeHandler          $leadTimeHandler,
        private readonly ConstraintEngine         $constraints,
        private readonly DecisionScorer           $scorer,
        private readonly AbcXyzClassifier         $classifier,
    ) {}

    /** @return Decision[] */
    public function run(int $budgetRemainingHalalas = PHP_INT_MAX, ?int $triggeredBy = null): array
    {
        $engineRun = EngineRun::create([
            'triggered_by'    => $triggeredBy,
            'run_at'          => now(),
            'status'          => 'running',
            'decisions_count' => 0,
        ]);

        $startMs = (int) (microtime(true) * 1000);

        try {
            $skus    = Sku::with('supplier')->get();
            $runAt   = now();
            $results = [];

            $this->classifier->classify($skus);
            // Reload abc_class / xyz_class from DB after classifier writes them
            $skus = Sku::with('supplier')->get();

            foreach ($skus as $sku) {
                $forecast    = $this->forecaster->forecast($sku->id, 30);
                $position    = $this->tracker->getPosition($sku->id, $forecast->daily_demand);
                $leadTime    = $this->leadTimeHandler->getLeadTimeWithBuffer($sku->supplier_id);
                $rawQty      = max($sku->reorder_qty, $sku->moq);
                $constrained = $this->constraints->applyConstraints($sku->id, $rawQty, $budgetRemainingHalalas);
                $multiplier  = $this->classifier->getSafetyStockMultiplier(
                    $sku->abc_class ?? 'C',
                    $sku->xyz_class ?? 'Z',
                );
                $decision    = $this->scorer->score($position, $forecast, $leadTime, $constrained, $multiplier);

                $this->persist($sku->id, $decision, $forecast, $position, $runAt);

                if ($decision->decision === 'order') {
                    $budgetRemainingHalalas -= $decision->constrained_qty * $sku->unit_cost;
                }

                $results[] = $decision;
            }

            $engineRun->update([
                'status'          => 'completed',
                'decisions_count' => count($results),
                'duration_ms'     => (int) (microtime(true) * 1000) - $startMs,
            ]);

            return $results;
        } catch (\Throwable $e) {
            $engineRun->update([
                'status'      => 'failed',
                'duration_ms' => (int) (microtime(true) * 1000) - $startMs,
            ]);
            throw $e;
        }
    }

    private function persist(int $skuId, Decision $decision, $forecast, $position, $runAt): void
    {
        InventoryDecision::create([
            'sku_id'          => $skuId,
            'run_at'          => $runAt,
            'decision'        => $decision->decision,
            'recommended_qty' => $decision->recommended_qty,
            'constrained_qty' => $decision->constrained_qty,
            'reasoning'       => $decision->reasoning,
            'forecast_demand' => $forecast->daily_demand,
            'days_of_cover'   => $position->days_of_cover,
            'reorder_point'   => $decision->reorder_point,
        ]);
    }
}
```

**Step 4: Update AppServiceProvider** — inject AbcXyzClassifier

```php
$this->app->bind(\App\Services\InventoryEngine\InventoryEngineService::class, function ($app) {
    return new \App\Services\InventoryEngine\InventoryEngineService(
        new \App\Services\InventoryEngine\DemandForecaster(),
        new \App\Services\InventoryEngine\InventoryPositionTracker(),
        new \App\Services\InventoryEngine\LeadTimeHandler(),
        new \App\Services\InventoryEngine\ConstraintEngine(),
        new \App\Services\InventoryEngine\DecisionScorer(),
        new \App\Services\InventoryEngine\AbcXyzClassifier(),
    );
});
```

**Step 5: Run tests**

```bash
C:\Users\hp\.config\herd\bin\php.bat artisan test
```
Expected: all tests PASS including the updated EngineRunTest.

**Step 6: Commit**

```bash
git add app/Services/InventoryEngine/InventoryEngineService.php app/Providers/AppServiceProvider.php tests/Feature/InventoryEngine/EngineRunTest.php
git commit -m "feat: integrate ABC-XYZ classifier and engine run logging into InventoryEngineService"
```

---

## Task 6: Update Job + EngineController + Scheduler

**Files:**
- Modify: `app/Jobs/RunInventoryEngineJob.php`
- Modify: `app/Http/Controllers/EngineController.php`
- Modify: `app/Events/StockAlertEvent.php`
- Modify: `routes/console.php`

**Step 1: Update RunInventoryEngineJob** — accept user ID, pass to engine, broadcast richer alert data

```php
<?php

namespace App\Jobs;

use App\Events\StockAlertEvent;
use App\Models\InventoryDecision;
use App\Services\InventoryEngine\InventoryEngineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunInventoryEngineJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly ?int $triggeredBy = null) {}

    public function handle(InventoryEngineService $engine): void
    {
        $budget  = config('inventory.monthly_budget_halalas', PHP_INT_MAX);
        $results = $engine->run($budget, $this->triggeredBy);

        $orderNow = collect($results)->filter(fn ($d) => $d->decision === 'order');

        if ($orderNow->isNotEmpty()) {
            // Query the just-persisted decisions to get SKU details for the alert
            $alerts = InventoryDecision::where('run_at', InventoryDecision::max('run_at'))
                ->where('decision', 'order')
                ->with('sku')
                ->get()
                ->map(fn ($d) => [
                    'sku_code'      => $d->sku->sku_code,
                    'sku_name'      => $d->sku->name,
                    'days_of_cover' => $d->days_of_cover,
                    'lead_time_days'=> $d->sku->lead_time_days,
                ])
                ->toArray();

            StockAlertEvent::dispatch($alerts);
        }
    }
}
```

**Step 2: Update StockAlertEvent** — broadcast SKU alert array instead of just a count

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class StockAlertEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly array $alerts) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('inventory-alerts')];
    }

    public function broadcastAs(): string
    {
        return 'stock.alert';
    }
}
```

**Step 3: Update EngineController** — pass authenticated user ID to job

```php
<?php

namespace App\Http\Controllers;

use App\Jobs\RunInventoryEngineJob;
use Illuminate\Http\RedirectResponse;

class EngineController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        RunInventoryEngineJob::dispatch(auth()->id())->onQueue('inventory');

        return back()->with('success', 'Engine run dispatched.');
    }
}
```

**Step 4: Add scheduler to routes/console.php**

```php
<?php

use App\Jobs\RunInventoryEngineJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new RunInventoryEngineJob(null))
    ->dailyAt('06:00')
    ->onQueue('inventory')
    ->name('daily-inventory-engine-run')
    ->withoutOverlapping();
```

**Step 5: Run full test suite**

```bash
C:\Users\hp\.config\herd\bin\php.bat artisan test
```
Expected: all tests PASS.

**Step 6: Commit**

```bash
git add app/Jobs/RunInventoryEngineJob.php app/Http/Controllers/EngineController.php app/Events/StockAlertEvent.php routes/console.php
git commit -m "feat: pass triggeredBy to engine job, enrich stock alert with SKU data, add daily scheduler"
```

---

## Task 7: Update DashboardController — lastRun + deadStock

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php`
- Modify: `tests/Feature/Http/DashboardControllerTest.php`

**Step 1: Add new tests for lastRun and deadStock**

Add to `tests/Feature/Http/DashboardControllerTest.php`:

```php
it('sends lastRun data when an engine run exists', function () {
    $user = User::factory()->create();

    \App\Models\EngineRun::create([
        'triggered_by'    => $user->id,
        'run_at'          => now()->subHours(2),
        'status'          => 'completed',
        'decisions_count' => 11,
        'duration_ms'     => 500,
    ]);

    $this->actingAs($user)->get('/')
        ->assertInertia(fn ($page) => $page
            ->has('lastRun.run_at')
            ->has('lastRun.decisions_count')
            ->has('lastRun.status')
        );
});

it('sends null lastRun when no runs exist', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')
        ->assertInertia(fn ($page) => $page->where('lastRun', null));
});

it('sends dead stock skus with zero sales in last 30 days', function () {
    $user     = User::factory()->create();
    $supplier = Supplier::factory()->create(['avg_lead_time_days' => 7, 'lead_time_stddev' => 1.0]);

    // SKU with stock but no recent sales = dead stock
    $deadSku = Sku::factory()->create([
        'supplier_id'   => $supplier->id,
        'current_stock' => 20,
    ]);

    // SKU with recent sales = NOT dead stock
    $liveSku = Sku::factory()->create([
        'supplier_id'   => $supplier->id,
        'current_stock' => 30,
    ]);
    \App\Models\SalesHistory::create([
        'sku_id'         => $liveSku->id,
        'sale_date'      => now()->subDays(5),
        'quantity_sold'  => 3,
    ]);

    $this->actingAs($user)->get('/')
        ->assertInertia(fn ($page) => $page
            ->has('deadStock', 1)
            ->where('deadStock.0.id', $deadSku->id)
        );
});
```

**Step 2: Run new tests to confirm they fail**

```bash
C:\Users\hp\.config\herd\bin\php.bat artisan test tests/Feature/Http/DashboardControllerTest.php
```
Expected: 3 new tests FAIL — `lastRun` and `deadStock` keys not present.

**Step 3: Update DashboardController**

Replace the entire file:

```php
<?php

namespace App\Http\Controllers;

use App\Models\EngineRun;
use App\Models\InventoryDecision;
use App\Models\SalesHistory;
use App\Models\Sku;
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
            'days_of_cover'      => (float) $d->days_of_cover,
            'reorder_point'      => $d->reorder_point,
            'forecast_demand'    => $d->forecast_demand,
            'safety_stock'       => $d->reasoning['safety_stock'] ?? 0,
            'current_stock'      => $d->sku->current_stock,
            'in_transit_qty'     => $d->sku->in_transit_qty,
            'reserved_qty'       => $d->sku->reserved_qty,
            'effective_position' => $d->sku->effective_position,
            'lead_time_days'     => $d->sku->lead_time_days,
            'abc_class'          => $d->sku->abc_class,
            'xyz_class'          => $d->sku->xyz_class,
            'run_at'             => $d->run_at,
        ]);

        $sorted = $mapped->sortBy([
            fn ($a, $b) => $this->urgencyPriority($a) <=> $this->urgencyPriority($b),
            fn ($a, $b) => $a['days_of_cover'] <=> $b['days_of_cover'],
        ])->values();

        $stockoutRisk = $mapped->filter(
            fn ($d) => $d['days_of_cover'] < $d['lead_time_days']
        )->count();

        $lastRun = EngineRun::where('status', '!=', 'running')
            ->latest('run_at')
            ->first(['run_at', 'decisions_count', 'status']);

        $deadStock = $this->getDeadStockSkus();

        return Inertia::render('Dashboard/Index', [
            'stats' => [
                'order_now'      => $mapped->where('decision', 'order')->count(),
                'watch'          => $mapped->where('decision', 'watch')->count(),
                'stockout_risk'  => $stockoutRisk,
                'avg_days_cover' => round($mapped->avg('days_of_cover'), 1),
            ],
            'decisions' => $sorted,
            'lastRun'   => $lastRun,
            'deadStock' => $deadStock,
        ]);
    }

    private function getDeadStockSkus(): array
    {
        $thirtyDaysAgo = now()->subDays(30);

        $recentlySoldIds = SalesHistory::where('sale_date', '>=', $thirtyDaysAgo)
            ->distinct()
            ->pluck('sku_id');

        return Sku::where('current_stock', '>', 0)
            ->whereNotIn('id', $recentlySoldIds)
            ->get(['id', 'name', 'sku_code', 'current_stock'])
            ->toArray();
    }

    private function urgencyPriority(array $d): int
    {
        return match (true) {
            $d['decision'] === 'order' && $d['days_of_cover'] < $d['lead_time_days'] => 0,
            $d['decision'] === 'order'                                                => 1,
            $d['decision'] === 'order_budget_blocked'                                 => 2,
            $d['decision'] === 'watch'                                                => 3,
            default                                                                   => 4,
        };
    }
}
```

**Step 4: Run tests**

```bash
C:\Users\hp\.config\herd\bin\php.bat artisan test
```
Expected: all tests PASS.

**Step 5: Commit**

```bash
git add app/Http/Controllers/DashboardController.php tests/Feature/Http/DashboardControllerTest.php
git commit -m "feat: add lastRun and deadStock to DashboardController, include abc/xyz per decision row"
```

---

## Task 8: Frontend — Echo setup + AppHeader + NotificationBell

**Files:**
- Modify: `package.json` (install laravel-echo + pusher-js)
- Modify: `resources/js/bootstrap.js`
- Create: `resources/js/Components/AppHeader.vue`
- Create: `resources/js/Components/NotificationBell.vue`
- Modify: `resources/js/Pages/Dashboard/Index.vue` (add AppHeader)
- Modify: `resources/js/Pages/SKUs/Index.vue` (add AppHeader)
- Modify: `resources/js/Pages/SKUs/Show.vue` (add AppHeader)

**Step 1: Install laravel-echo and pusher-js**

```bash
PATH="/c/Program Files/nodejs:$PATH" "C:\Program Files\nodejs\npm.cmd" install --save laravel-echo pusher-js --prefix "C:\Users\hp\OneDrive\Desktop\Procurement_Project"
```
Expected: packages added to `node_modules` and `package.json`.

**Step 2: Update bootstrap.js with Echo setup**

Replace the entire `resources/js/bootstrap.js`:

```js
import axios from 'axios';
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// Only initialise Echo when Reverb config is present in env
if (import.meta.env.VITE_REVERB_APP_KEY) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST ?? '127.0.0.1',
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
        forceTLS: false,
        enabledTransports: ['ws'],
    });
}
```

**Step 3: Create NotificationBell.vue**

```vue
<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';

interface Alert {
    sku_code: string;
    sku_name: string;
    days_of_cover: number;
    lead_time_days: number;
}

const alerts = ref<Alert[]>([]);
const open   = ref(false);

function toggle() {
    open.value = !open.value;
}

function clearAll() {
    alerts.value = [];
    open.value   = false;
}

function handleClickOutside(e: MouseEvent) {
    const el = document.getElementById('notification-bell');
    if (el && !el.contains(e.target as Node)) {
        open.value = false;
    }
}

onMounted(() => {
    document.addEventListener('click', handleClickOutside);

    // Subscribe only if Echo is available (Reverb running)
    if (window.Echo) {
        window.Echo.private('inventory-alerts')
            .listen('.stock.alert', (e: { alerts: Alert[] }) => {
                alerts.value.unshift(...e.alerts);
            });
    }
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
    if (window.Echo) {
        window.Echo.leave('inventory-alerts');
    }
});
</script>

<template>
    <div id="notification-bell" class="relative">
        <button
            @click.stop="toggle"
            class="relative p-2 text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors duration-150 cursor-pointer"
            aria-label="Notifications"
        >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
            </svg>
            <span
                v-if="alerts.length > 0"
                class="absolute top-1.5 right-1.5 w-2 h-2 rounded-full bg-red-500"
            ></span>
        </button>

        <!-- Dropdown -->
        <div
            v-if="open"
            class="absolute right-0 top-full mt-2 w-80 bg-white rounded-xl shadow-lg border border-slate-200 z-50 overflow-hidden"
        >
            <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between">
                <span class="text-sm font-semibold text-slate-800">Stock Alerts</span>
                <button
                    v-if="alerts.length > 0"
                    @click="clearAll"
                    class="text-xs text-slate-400 hover:text-slate-600 cursor-pointer transition-colors duration-150"
                >
                    Clear all
                </button>
            </div>

            <div v-if="alerts.length === 0" class="px-4 py-6 text-center text-sm text-slate-400">
                No alerts — all stock levels healthy.
            </div>

            <ul v-else class="divide-y divide-slate-100 max-h-64 overflow-y-auto">
                <li v-for="(alert, i) in alerts" :key="i" class="px-4 py-3">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <p class="text-sm font-semibold text-slate-800">{{ alert.sku_name }}</p>
                            <p class="text-xs font-mono text-slate-400 mt-0.5">{{ alert.sku_code }}</p>
                        </div>
                        <span class="text-xs font-semibold bg-red-100 text-red-700 px-2 py-0.5 rounded-full ring-1 ring-red-200 shrink-0 mt-0.5">
                            ORDER NOW
                        </span>
                    </div>
                    <p class="text-xs text-slate-500 mt-1.5">
                        {{ alert.days_of_cover }}d cover · {{ alert.lead_time_days }}d lead time
                    </p>
                </li>
            </ul>
        </div>
    </div>
</template>
```

**Step 4: Create AppHeader.vue**

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import NotificationBell from './NotificationBell.vue';
</script>

<template>
    <header class="bg-white border-b border-slate-200 sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-6 lg:px-8 h-14 flex items-center justify-between">
            <Link href="/" class="text-sm font-bold text-[#1E293B] tracking-tight hover:text-blue-600 transition-colors duration-150">
                Inventory Engine
            </Link>
            <div class="flex items-center gap-1">
                <Link
                    href="/"
                    class="px-3 py-1.5 text-sm text-slate-500 hover:text-slate-800 hover:bg-slate-100 rounded-lg transition-colors duration-150"
                >
                    Dashboard
                </Link>
                <Link
                    href="/skus"
                    class="px-3 py-1.5 text-sm text-slate-500 hover:text-slate-800 hover:bg-slate-100 rounded-lg transition-colors duration-150"
                >
                    SKUs
                </Link>
                <NotificationBell />
            </div>
        </div>
    </header>
</template>
```

**Step 5: Add AppHeader to Dashboard/Index.vue, SKUs/Index.vue, SKUs/Show.vue**

In each of the three page files, add the import and place `<AppHeader />` just inside the outermost `<div>`, before the page content wrapper. Example for Dashboard:

```vue
<script setup lang="ts">
import AppHeader from '@/Components/AppHeader.vue';
// ... rest of existing imports
</script>

<template>
    <div class="min-h-screen bg-[#F8FAFC]">
        <AppHeader />
        <div class="max-w-7xl mx-auto px-6 lg:px-8 py-8 space-y-6">
            <!-- ... rest of template unchanged ... -->
        </div>
    </div>
</template>
```

Note: remove the `<h1>Inventory Engine</h1>` heading from the Dashboard page content since the header now carries the app name and navigation. Keep the "Stock replenishment decisions" subtitle and Run Engine button.

Also add `/// <reference types="vite/client" />` to ensure `import.meta.env` types are available, and declare the `window.Echo` global type by adding to `resources/js/bootstrap.js` or a `resources/js/types.d.ts`:

```ts
// resources/js/types.d.ts
import type Echo from 'laravel-echo';
declare global {
    interface Window {
        Echo?: Echo;
        Pusher: unknown;
    }
}
```

**Step 6: Vite build to verify no TypeScript errors**

```bash
PATH="/c/Program Files/nodejs:$PATH" "C:\Program Files\nodejs\npm.cmd" run build --prefix "C:\Users\hp\OneDrive\Desktop\Procurement_Project"
```
Expected: `✓ built in Xs` with 0 errors.

**Step 7: Commit**

```bash
git add resources/js/bootstrap.js resources/js/Components/ resources/js/Pages/ resources/js/types.d.ts package.json package-lock.json
git commit -m "feat: add AppHeader with navigation, NotificationBell with Reverb listener"
```

---

## Task 9: Update Dashboard/Index.vue — lastRun, dead stock, ABC-XYZ, glossary

**Files:**
- Modify: `resources/js/Pages/Dashboard/Index.vue`

**Step 1: Update the script section** — add new props and helpers

Add to the `DecisionRow` interface:
```ts
abc_class: 'A' | 'B' | 'C' | null;
xyz_class: 'X' | 'Y' | 'Z' | null;
```

Add new prop interfaces and `defineProps`:
```ts
interface LastRun {
    run_at: string;
    decisions_count: number;
    status: 'completed' | 'failed';
}

interface DeadStockSku {
    id: number;
    name: string;
    sku_code: string;
    current_stock: number;
}

const props = defineProps<{
    stats: Stats;
    decisions: DecisionRow[];
    lastRun: LastRun | null;
    deadStock: DeadStockSku[];
}>();
```

Add helpers for ABC-XYZ badges:
```ts
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

function formatLastRun(lastRun: LastRun | null): string {
    if (!lastRun) return '';
    const date = new Date(lastRun.run_at);
    const diffMs = Date.now() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    if (diffMins < 1)   return 'just now';
    if (diffMins < 60)  return `${diffMins}m ago`;
    const diffHrs = Math.floor(diffMins / 60);
    if (diffHrs < 24)   return `${diffHrs}h ago`;
    return `${Math.floor(diffHrs / 24)}d ago`;
}
```

**Step 2: Update the template**

**Run Engine button area** — add last run line below the button:
```html
<div class="flex items-center justify-between">
    <div>
        <p class="text-sm text-slate-500 mt-0.5">Stock replenishment decisions</p>
    </div>
    <div class="flex flex-col items-end gap-1">
        <button @click="runEngine" class="...existing classes...">
            <svg ...>...</svg>
            Run Engine
        </button>
        <p v-if="lastRun" class="text-xs text-slate-400">
            Last run: {{ formatLastRun(lastRun) }} · {{ lastRun.decisions_count }} decisions
            <span v-if="lastRun.status === 'failed'" class="text-red-500 ml-1">· failed</span>
        </p>
    </div>
</div>
```

**Dead stock panel** — add inside the Overview `v-show` div, between stat cards and Needs Attention:
```html
<!-- Dead stock alert -->
<div
    v-if="deadStock.length > 0"
    class="bg-amber-50 border border-amber-200 rounded-xl px-5 py-4"
>
    <div class="flex items-center gap-2 mb-2">
        <svg class="w-4 h-4 text-amber-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
        </svg>
        <span class="text-sm font-semibold text-amber-800">Dead Stock — No sales in 30 days</span>
    </div>
    <div class="flex flex-wrap gap-2">
        <span
            v-for="sku in deadStock"
            :key="sku.id"
            class="text-xs bg-amber-100 text-amber-800 px-2.5 py-1 rounded-full ring-1 ring-amber-200"
        >
            {{ sku.name }} ({{ sku.current_stock }} units)
        </span>
    </div>
</div>
```

**ABC-XYZ column in All SKUs tab** — add after the Decision column header:
```html
<th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wide whitespace-nowrap">Class</th>
```

And in the row cells, after the decision badge cell:
```html
<td class="px-4 py-3">
    <span
        v-if="row.abc_class && row.xyz_class"
        class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold font-mono"
        :class="[abcBadgeClass(row.abc_class), xyzBadgeStyle(row.xyz_class)]"
    >
        {{ row.abc_class }}·{{ row.xyz_class }}
    </span>
    <span v-else class="text-xs text-slate-300">—</span>
</td>
```

**Glossary additions** — add the following entries to the `glossaryTerms` array (maintaining alphabetical order):

```ts
{ term: 'ABC Classification',       definition: 'Ranks SKUs by their share of total sales revenue over 90 days. A = top 70% of revenue (most critical products), B = next 20%, C = bottom 10%.' },
{ term: 'ABC-XYZ: A·X',             definition: 'High revenue, stable demand. Tight safety stock — trust the engine fully. Highest priority SKU.' },
{ term: 'ABC-XYZ: A·Y',             definition: 'High revenue, variable demand. Moderate buffer — monitor weekly.' },
{ term: 'ABC-XYZ: A·Z',             definition: 'High revenue, erratic demand. Large safety buffer — most dangerous SKU type. Do not rely on forecast alone.' },
{ term: 'ABC-XYZ: B·X',             definition: 'Mid revenue, stable demand. Standard engine logic, routine monitoring.' },
{ term: 'ABC-XYZ: B·Y',             definition: 'Mid revenue, variable demand. Slightly elevated safety stock.' },
{ term: 'ABC-XYZ: B·Z',             definition: 'Mid revenue, erratic demand. Fixed buffer — review manually if flagged.' },
{ term: 'ABC-XYZ: C·X',             definition: 'Low revenue, stable demand. Minimal safety stock, low review frequency.' },
{ term: 'ABC-XYZ: C·Y',             definition: 'Low revenue, variable demand. Low priority — watch for trend changes.' },
{ term: 'ABC-XYZ: C·Z',             definition: 'Low revenue, erratic demand. Consider discontinuing — sporadic demand, low value.' },
{ term: 'XYZ Classification',       definition: 'Ranks SKUs by demand predictability over 90 days using coefficient of variation. X = stable (CV < 0.5), Y = variable (CV 0.5–1.0), Z = erratic (CV > 1.0).' },
```

**Step 3: Vite build**

```bash
PATH="/c/Program Files/nodejs:$PATH" "C:\Program Files\nodejs\npm.cmd" run build --prefix "C:\Users\hp\OneDrive\Desktop\Procurement_Project"
```
Expected: `✓ built` with 0 errors.

**Step 4: Run full tests**

```bash
C:\Users\hp\.config\herd\bin\php.bat artisan test
```
Expected: all tests PASS.

**Step 5: Commit**

```bash
git add resources/js/Pages/Dashboard/Index.vue
git commit -m "feat: add lastRun display, dead stock panel, ABC-XYZ badges and glossary to dashboard"
```

---

## Task 10: Update SKUs/Index.vue — ABC-XYZ badge column

**Files:**
- Modify: `resources/js/Pages/SKUs/Index.vue`

**Step 1: Add helpers and update props**

Add to `<script setup>`:
```ts
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
```

Update `SkuRow` interface to include:
```ts
abc_class: 'A' | 'B' | 'C' | null;
xyz_class: 'X' | 'Y' | 'Z' | null;
```

**Step 2: Add Class column to the table** — after the Decision column:

Header:
```html
<th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wide whitespace-nowrap">Class</th>
```

Cell:
```html
<td class="px-4 py-3">
    <span
        v-if="sku.abc_class && sku.xyz_class"
        class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold font-mono"
        :class="[abcBadgeClass(sku.abc_class), xyzBadgeStyle(sku.xyz_class)]"
    >
        {{ sku.abc_class }}·{{ sku.xyz_class }}
    </span>
    <span v-else class="text-xs text-slate-300">—</span>
</td>
```

**Step 3: Update SkuController to include abc_class/xyz_class in the mapped data**

Open `app/Http/Controllers/SkuController.php` and add `'abc_class'` and `'xyz_class'` to the mapped array in the `index` method so the frontend receives the values.

**Step 4: Vite build + full test run**

```bash
PATH="/c/Program Files/nodejs:$PATH" "C:\Program Files\nodejs\npm.cmd" run build --prefix "C:\Users\hp\OneDrive\Desktop\Procurement_Project"
C:\Users\hp\.config\herd\bin\php.bat artisan test
```
Expected: build clean, all tests PASS.

**Step 5: Commit + push**

```bash
git add resources/js/Pages/SKUs/Index.vue app/Http/Controllers/SkuController.php
git commit -m "feat: add ABC-XYZ class badge to SKU catalogue"
git push origin main
```

---

## Task 11 (Low Priority): Stock Adjustments Page

> **Note:** This feature is separate and may be removed in future. Implement only after Tasks 1–10 are complete and stable.

**Files:**
- Create: `app/Http/Controllers/StockAdjustmentController.php`
- Create: `resources/js/Pages/Adjustments/Index.vue`
- Modify: `routes/web.php`

**Step 1: Create controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Sku;
use App\Models\StockAdjustment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StockAdjustmentController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Adjustments/Index', [
            'skus'        => Sku::orderBy('name')->get(['id', 'name', 'sku_code', 'current_stock']),
            'adjustments' => StockAdjustment::with('sku', 'user')
                ->latest('adjusted_at')
                ->limit(50)
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sku_id'      => ['required', 'exists:skus,id'],
            'new_qty'     => ['required', 'integer', 'min:0'],
            'reason_code' => ['required', 'in:cycle_count,damage_writeoff,customer_return,supplier_short_ship,data_entry_correction,internal_use'],
            'notes'       => ['nullable', 'string', 'max:500'],
        ]);

        $sku = Sku::findOrFail($data['sku_id']);

        DB::transaction(function () use ($sku, $data, $request) {
            StockAdjustment::create([
                'sku_id'      => $sku->id,
                'user_id'     => $request->user()->id,
                'old_qty'     => $sku->current_stock,
                'new_qty'     => $data['new_qty'],
                'reason_code' => $data['reason_code'],
                'notes'       => $data['notes'] ?? null,
                'adjusted_at' => now(),
            ]);

            $sku->update(['current_stock' => $data['new_qty']]);
        });

        return back()->with('success', 'Stock adjusted.');
    }
}
```

**Step 2: Add route** — in `routes/web.php`, inside the `auth` middleware group:

```php
Route::get('/adjustments', [\App\Http\Controllers\StockAdjustmentController::class, 'index'])->name('adjustments.index');
Route::post('/adjustments', [\App\Http\Controllers\StockAdjustmentController::class, 'store'])->name('adjustments.store');
```

**Step 3: Create Adjustments/Index.vue** — form + history table. Follow the same slate design system as the other pages.

**Step 4: Commit**

```bash
git commit -m "feat: add stock adjustments page (low priority, separate tab)"
git push origin main
```
