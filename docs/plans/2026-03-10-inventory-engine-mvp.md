# Inventory Engine MVP Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a full-stack hybrid inventory decision engine with Laravel backend, Vue 3 frontend, and real-time alerts — outputting ORDER NOW / WATCH / HOLD per SKU.

**Architecture:** Five PHP service classes (DemandForecaster, InventoryPositionTracker, LeadTimeHandler, ConstraintEngine, DecisionScorer) are orchestrated by InventoryEngineService, dispatched via a queued Job. Inertia.js bridges the Laravel backend to a Vue 3 TypeScript frontend with no separate API layer.

**Tech Stack:** Laravel 12, PHP 8.3, Vue 3 (Composition API + TypeScript), Inertia.js v2, Tailwind CSS v4, SQLite (local dev), Pest 3, Spatie Permissions, Laravel Reverb (WebSockets)

---

## Phase 1: Database & Models

### Task 1: Suppliers migration + model

**Files:**
- Create: `database/migrations/TIMESTAMP_create_suppliers_table.php`
- Create: `app/Models/Supplier.php`
- Test: `tests/Feature/Models/SupplierTest.php`

**Step 1: Write the failing test**

```php
// tests/Feature/Models/SupplierTest.php
<?php

use App\Models\Supplier;

it('can create a supplier', function () {
    $supplier = Supplier::factory()->create([
        'name' => 'Gulf Trading Co',
        'avg_lead_time_days' => 7,
        'lead_time_stddev' => 1.5,
    ]);

    expect($supplier->name)->toBe('Gulf Trading Co')
        ->and($supplier->avg_lead_time_days)->toBe(7)
        ->and($supplier->lead_time_stddev)->toBe(1.5);
});
```

**Step 2: Run test to verify it fails**

```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan test tests/Feature/Models/SupplierTest.php
```
Expected: FAIL — table doesn't exist

**Step 3: Create migration**

```php
// database/migrations/TIMESTAMP_create_suppliers_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('avg_lead_time_days')->default(7);
            $table->decimal('lead_time_stddev', 5, 2)->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
```

**Step 4: Create model + factory**

```php
// app/Models/Supplier.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'avg_lead_time_days', 'lead_time_stddev'];

    protected $casts = [
        'avg_lead_time_days' => 'integer',
        'lead_time_stddev' => 'float',
    ];

    public function skus()
    {
        return $this->hasMany(Sku::class);
    }
}
```

```php
// database/factories/SupplierFactory.php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class SupplierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'avg_lead_time_days' => $this->faker->numberBetween(5, 14),
            'lead_time_stddev' => $this->faker->randomFloat(2, 0.5, 3.0),
        ];
    }
}
```

**Step 5: Run migration and test**

```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan migrate
C:\Users\hp\.config\herd\bin\php83\php.exe artisan test tests/Feature/Models/SupplierTest.php
```
Expected: PASS

**Step 6: Commit**
```bash
git add database/migrations/ app/Models/Supplier.php database/factories/SupplierFactory.php tests/Feature/Models/SupplierTest.php
git commit -m "feat: add Supplier model, migration, and factory"
```

---

### Task 2: SKUs migration + model

**Files:**
- Create: `database/migrations/TIMESTAMP_create_skus_table.php`
- Create: `app/Models/Sku.php`
- Create: `database/factories/SkuFactory.php`
- Test: `tests/Feature/Models/SkuTest.php`

**Step 1: Write the failing test**

```php
// tests/Feature/Models/SkuTest.php
<?php

use App\Models\Sku;
use App\Models\Supplier;

it('can create a sku with supplier relationship', function () {
    $supplier = Supplier::factory()->create();
    $sku = Sku::factory()->create(['supplier_id' => $supplier->id]);

    expect($sku->supplier->id)->toBe($supplier->id)
        ->and($sku->current_stock)->toBeInt()
        ->and($sku->moq)->toBeInt();
});

it('calculates effective position', function () {
    $sku = Sku::factory()->create([
        'current_stock' => 100,
        'in_transit_qty' => 20,
        'reserved_qty' => 10,
    ]);

    expect($sku->effective_position)->toBe(110);
});
```

**Step 2: Run to verify failure**
```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan test tests/Feature/Models/SkuTest.php
```

**Step 3: Create migration**

```php
// database/migrations/TIMESTAMP_create_skus_table.php
Schema::create('skus', function (Blueprint $table) {
    $table->id();
    $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->string('sku_code')->unique();
    $table->unsignedInteger('moq')->default(1);
    $table->unsignedBigInteger('unit_cost'); // stored in halalas (× 100)
    $table->unsignedInteger('reorder_qty')->default(0);
    $table->unsignedInteger('current_stock')->default(0);
    $table->unsignedInteger('in_transit_qty')->default(0);
    $table->unsignedInteger('reserved_qty')->default(0);
    $table->unsignedInteger('lead_time_days')->default(7); // fallback
    $table->softDeletes();
    $table->timestamps();
});
```

**Step 4: Create model**

```php
// app/Models/Sku.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sku extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'supplier_id', 'name', 'sku_code', 'moq', 'unit_cost',
        'reorder_qty', 'current_stock', 'in_transit_qty', 'reserved_qty',
        'lead_time_days',
    ];

    protected $casts = [
        'moq' => 'integer',
        'unit_cost' => 'integer',
        'reorder_qty' => 'integer',
        'current_stock' => 'integer',
        'in_transit_qty' => 'integer',
        'reserved_qty' => 'integer',
        'lead_time_days' => 'integer',
    ];

    // Computed: on-hand + in-transit - reserved
    public function getEffectivePositionAttribute(): int
    {
        return $this->current_stock + $this->in_transit_qty - $this->reserved_qty;
    }

    // unit_cost stored as integer halalas, displayed as SAR
    public function getUnitCostSarAttribute(): float
    {
        return $this->unit_cost / 100;
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function salesHistory()
    {
        return $this->hasMany(SalesHistory::class);
    }

    public function decisions()
    {
        return $this->hasMany(InventoryDecision::class);
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
```

**Step 5: Run migration and tests**
```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan migrate
C:\Users\hp\.config\herd\bin\php83\php.exe artisan test tests/Feature/Models/SkuTest.php
```
Expected: PASS

**Step 6: Commit**
```bash
git commit -m "feat: add Sku model, migration, factory, and effective_position accessor"
```

---

### Task 3: Remaining migrations + models (SalesHistory, InventoryDecision, PurchaseOrder)

**Files:**
- Create: `database/migrations/TIMESTAMP_create_sales_history_table.php`
- Create: `database/migrations/TIMESTAMP_create_inventory_decisions_table.php`
- Create: `database/migrations/TIMESTAMP_create_purchase_orders_table.php`
- Create: `app/Models/SalesHistory.php`
- Create: `app/Models/InventoryDecision.php`
- Create: `app/Models/PurchaseOrder.php`

**Step 1: Write failing tests**

```php
// tests/Feature/Models/InventoryDecisionTest.php
<?php

use App\Models\InventoryDecision;
use App\Models\Sku;

it('stores a decision with reasoning json', function () {
    $sku = Sku::factory()->create();

    $decision = InventoryDecision::create([
        'sku_id' => $sku->id,
        'run_at' => now(),
        'decision' => 'order',
        'recommended_qty' => 48,
        'constrained_qty' => 48,
        'reasoning' => ['reorder_point' => 22, 'effective_position' => 15],
        'forecast_demand' => 3.2,
        'days_of_cover' => 4.7,
        'reorder_point' => 22,
    ]);

    expect($decision->decision)->toBe('order')
        ->and($decision->reasoning)->toBeArray()
        ->and($decision->reasoning['reorder_point'])->toBe(22);
});
```

**Step 2: Run to verify failure**
```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan test tests/Feature/Models/InventoryDecisionTest.php
```

**Step 3: Create all three migrations**

```php
// sales_history
Schema::create('sales_history', function (Blueprint $table) {
    $table->id();
    $table->foreignId('sku_id')->constrained()->cascadeOnDelete();
    $table->date('sale_date');
    $table->unsignedInteger('quantity_sold');
    $table->timestamps();
    $table->index(['sku_id', 'sale_date']);
});

// inventory_decisions
Schema::create('inventory_decisions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('sku_id')->constrained()->cascadeOnDelete();
    $table->timestamp('run_at');
    $table->enum('decision', ['order', 'watch', 'hold', 'order_budget_blocked']);
    $table->unsignedInteger('recommended_qty')->default(0);
    $table->unsignedInteger('constrained_qty')->default(0);
    $table->json('reasoning');
    $table->decimal('forecast_demand', 8, 2)->default(0);
    $table->decimal('days_of_cover', 8, 2)->default(0);
    $table->decimal('reorder_point', 8, 2)->default(0);
    $table->timestamps();
    $table->index(['sku_id', 'run_at']);
});

// purchase_orders
Schema::create('purchase_orders', function (Blueprint $table) {
    $table->id();
    $table->foreignId('sku_id')->constrained()->cascadeOnDelete();
    $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
    $table->unsignedInteger('ordered_qty');
    $table->timestamp('ordered_at')->nullable();
    $table->timestamp('expected_delivery_at')->nullable();
    $table->timestamp('received_at')->nullable();
    $table->enum('status', ['recommended', 'approved', 'ordered', 'in_transit', 'received'])
          ->default('recommended');
    $table->softDeletes();
    $table->timestamps();
});
```

**Step 4: Create models**

```php
// app/Models/SalesHistory.php
class SalesHistory extends Model {
    protected $fillable = ['sku_id', 'sale_date', 'quantity_sold'];
    protected $casts = ['sale_date' => 'date', 'quantity_sold' => 'integer'];
    public function sku() { return $this->belongsTo(Sku::class); }
}

// app/Models/InventoryDecision.php
class InventoryDecision extends Model {
    protected $fillable = [
        'sku_id', 'run_at', 'decision', 'recommended_qty',
        'constrained_qty', 'reasoning', 'forecast_demand', 'days_of_cover', 'reorder_point',
    ];
    protected $casts = [
        'run_at' => 'datetime',
        'reasoning' => 'array',
        'forecast_demand' => 'float',
        'days_of_cover' => 'float',
        'reorder_point' => 'float',
    ];
    public function sku() { return $this->belongsTo(Sku::class); }
}

// app/Models/PurchaseOrder.php
class PurchaseOrder extends Model {
    use SoftDeletes;
    protected $fillable = [
        'sku_id', 'supplier_id', 'ordered_qty', 'ordered_at',
        'expected_delivery_at', 'received_at', 'status',
    ];
    protected $casts = [
        'ordered_at' => 'datetime',
        'expected_delivery_at' => 'datetime',
        'received_at' => 'datetime',
    ];
    public function sku() { return $this->belongsTo(Sku::class); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
}
```

**Step 5: Run migrations and tests**
```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan migrate
C:\Users\hp\.config\herd\bin\php83\php.exe artisan test tests/Feature/Models/
```
Expected: all PASS

**Step 6: Commit**
```bash
git commit -m "feat: add SalesHistory, InventoryDecision, PurchaseOrder models and migrations"
```

---

### Task 4: Synthetic data seeder

**Files:**
- Create: `database/seeders/SyntheticDataSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`

**Goal:** Seed 2 suppliers, 11 SKUs, 12 months of daily sales, 2 users (owner + warehouse).

**Step 1: Create seeder**

```php
// database/seeders/SyntheticDataSeeder.php
<?php

namespace Database\Seeders;

use App\Models\InventoryDecision;
use App\Models\Sku;
use App\Models\Supplier;
use App\Models\User;
use App\Models\SalesHistory;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class SyntheticDataSeeder extends Seeder
{
    public function run(): void
    {
        // Roles
        foreach (['owner', 'warehouse', 'viewer'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        // Users
        $owner = User::firstOrCreate(['email' => 'owner@demo.test'], [
            'name' => 'Demo Owner',
            'password' => Hash::make('password'),
        ]);
        $owner->assignRole('owner');

        $warehouse = User::firstOrCreate(['email' => 'warehouse@demo.test'], [
            'name' => 'Warehouse Staff',
            'password' => Hash::make('password'),
        ]);
        $warehouse->assignRole('warehouse');

        // Suppliers
        $supplier1 = Supplier::firstOrCreate(['name' => 'Gulf FMCG Distributors'], [
            'avg_lead_time_days' => 7,
            'lead_time_stddev' => 1.2,
        ]);
        $supplier2 = Supplier::firstOrCreate(['name' => 'Electronics Arabia'], [
            'avg_lead_time_days' => 14,
            'lead_time_stddev' => 2.5,
        ]);

        // SKU catalogue from INVENTORY_ENGINE.md
        $skus = [
            ['FB-001', 'Nike Grip Socks 8pk',    $supplier1->id, 24, 4500,  24,  7,  3.2],
            ['FB-002', 'Adidas Grip Socks 8pk',     $supplier1->id, 24, 3200,  24,  7,  2.8],
            ['FB-003', 'Nike Phantom GX Elite Boots',    $supplier2->id,  6, 38000,  6, 14,  0.4],
            ['FB-004', 'Adidas Predator Elite Boots',              $supplier2->id,  6, 29000,  6, 14,  0.5],
            ['FB-005', 'Nike Academy Team Socks',       $supplier1->id, 48, 1200,  48,  5,  8.1],
            ['FB-006', 'Mitre Training Bibs (Single)',       $supplier1->id, 48, 1000,  48,  5,  7.4],
            ['FB-007', 'Puma Shin Guards 5pk',         $supplier1->id, 36, 1800,  36, 10,  1.9],
            ['FB-008', "Adidas Player Starter Kit",         $supplier1->id, 12, 8500,  12, 12,  0.8],
            ['FB-009', 'Nike Boot Conditioner 100ml', $supplier1->id, 24, 2200,  24,  7,  2.1],
            ['FB-010', 'Adidas Premium Boot Care Oil',  $supplier1->id, 12, 6500,  12, 10,  0.6],
            ['FB-011', 'Mitre Training Cones 10pk',        $supplier1->id, 60,  800,  60,  5,  9.3],
        ];

        foreach ($skus as [$code, $name, $supplierId, $moq, $unitCost, $reorderQty, $leadTime, $avgDaily]) {
            // Set some SKUs near reorder point for demo interest
            $daysOfStock = in_array($code, ['FB-001', 'FB-005', 'FB-011'])
                ? rand(3, 8)   // near reorder point
                : rand(15, 45); // healthy stock

            $currentStock = (int) round($avgDaily * $daysOfStock);

            $sku = Sku::firstOrCreate(['sku_code' => $code], [
                'supplier_id'   => $supplierId,
                'name'          => $name,
                'moq'           => $moq,
                'unit_cost'     => $unitCost,
                'reorder_qty'   => $reorderQty,
                'current_stock' => $currentStock,
                'in_transit_qty' => 0,
                'reserved_qty'  => 0,
                'lead_time_days' => $leadTime,
            ]);

            // 12 months of daily sales history with realistic noise
            $this->seedSalesHistory($sku, $avgDaily);
        }
    }

    private function seedSalesHistory(Sku $sku, float $avgDaily): void
    {
        $start = Carbon::today()->subMonths(12);
        $end   = Carbon::yesterday();

        $records = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            // Add noise: ±40% variation, minimum 0
            $noise = $avgDaily * (rand(-40, 40) / 100);
            $qty   = max(0, (int) round($avgDaily + $noise));

            // Weekend dip for non-consumables
            if ($date->isWeekend() && $avgDaily < 5) {
                $qty = (int) round($qty * 0.7);
            }

            $records[] = [
                'sku_id'        => $sku->id,
                'sale_date'     => $date->toDateString(),
                'quantity_sold' => $qty,
                'created_at'    => now(),
                'updated_at'    => now(),
            ];
        }

        // Batch insert
        foreach (array_chunk($records, 500) as $chunk) {
            SalesHistory::insertOrIgnore($chunk);
        }
    }
}
```

**Step 2: Register in DatabaseSeeder**

```php
// database/seeders/DatabaseSeeder.php
public function run(): void
{
    $this->call(SyntheticDataSeeder::class);
}
```

**Step 3: Run seeder**
```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan db:seed
```
Expected: completes without errors

**Step 4: Verify data**
```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan tinker --execute="echo App\Models\Sku::count() . ' SKUs, ' . App\Models\SalesHistory::count() . ' sales records';"
```
Expected: `11 SKUs, ~4000 sales records`

**Step 5: Commit**
```bash
git commit -m "feat: add SyntheticDataSeeder with 11 SKUs and 12 months sales history"
```

---

## Phase 2: Inventory Engine Services

### Task 5: DemandForecaster

**Files:**
- Create: `app/Services/InventoryEngine/DemandForecaster.php`
- Create: `app/Services/InventoryEngine/DTOs/ForecastResult.php`
- Test: `tests/Unit/InventoryEngine/DemandForecasterTest.php`

**Step 1: Write failing tests**

```php
// tests/Unit/InventoryEngine/DemandForecasterTest.php
<?php

use App\Models\Sku;
use App\Models\SalesHistory;
use App\Services\InventoryEngine\DemandForecaster;
use App\Services\InventoryEngine\DTOs\ForecastResult;
use Carbon\Carbon;

it('returns a forecast result with positive demand', function () {
    $sku = Sku::factory()->create(['lead_time_days' => 7]);

    // Seed 30 days of history
    for ($i = 30; $i >= 1; $i--) {
        SalesHistory::create([
            'sku_id' => $sku->id,
            'sale_date' => Carbon::today()->subDays($i),
            'quantity_sold' => 5,
        ]);
    }

    $forecaster = new DemandForecaster();
    $result = $forecaster->forecast($sku->id, 30);

    expect($result)->toBeInstanceOf(ForecastResult::class)
        ->and($result->daily_demand)->toBeGreaterThan(0)
        ->and($result->demand_stddev)->toBeGreaterThanOrEqual(0)
        ->and($result->horizon_demand)->toBeGreaterThan(0);
});

it('uses moving average for low velocity skus', function () {
    $sku = Sku::factory()->create();

    // 0.5 avg daily = low velocity
    for ($i = 30; $i >= 1; $i--) {
        SalesHistory::create([
            'sku_id' => $sku->id,
            'sale_date' => Carbon::today()->subDays($i),
            'quantity_sold' => $i % 2 === 0 ? 1 : 0,
        ]);
    }

    $forecaster = new DemandForecaster();
    $result = $forecaster->forecast($sku->id, 30);

    expect($result->daily_demand)->toBeFloat()
        ->and($result->daily_demand)->toBeLessThan(2);
});

it('returns zero demand when no history exists', function () {
    $sku = Sku::factory()->create();
    $forecaster = new DemandForecaster();
    $result = $forecaster->forecast($sku->id, 30);

    expect($result->daily_demand)->toBe(0.0);
});
```

**Step 2: Run to verify failure**
```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan test tests/Unit/InventoryEngine/DemandForecasterTest.php
```

**Step 3: Create DTO**

```php
// app/Services/InventoryEngine/DTOs/ForecastResult.php
<?php

namespace App\Services\InventoryEngine\DTOs;

readonly class ForecastResult
{
    public function __construct(
        public float $daily_demand,
        public float $demand_stddev,
        public float $horizon_demand,
        public int   $horizon_days,
        public string $method, // 'moving_average' | 'exponential_smoothing'
    ) {}
}
```

**Step 4: Create DemandForecaster**

```php
// app/Services/InventoryEngine/DemandForecaster.php
<?php

namespace App\Services\InventoryEngine;

use App\Models\SalesHistory;
use App\Services\InventoryEngine\DTOs\ForecastResult;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class DemandForecaster
{
    private const HIGH_VELOCITY_THRESHOLD = 5.0; // units/day
    private const SMOOTHING_ALPHA = 0.3;
    private const LOOKBACK_DAYS = 90;

    public function forecast(int $skuId, int $horizonDays): ForecastResult
    {
        $history = $this->getHistory($skuId);

        if ($history->isEmpty()) {
            return new ForecastResult(0.0, 0.0, 0.0, $horizonDays, 'no_data');
        }

        $dailyQtys = $history->pluck('quantity_sold')->map(fn ($q) => (float) $q);
        $avgDaily  = $dailyQtys->average();

        if ($avgDaily >= self::HIGH_VELOCITY_THRESHOLD) {
            $dailyDemand = $this->exponentialSmoothing($dailyQtys);
            $method = 'exponential_smoothing';
        } else {
            $dailyDemand = $avgDaily;
            $method = 'moving_average';
        }

        $stddev = $this->stddev($dailyQtys, $dailyDemand);

        return new ForecastResult(
            daily_demand: round($dailyDemand, 4),
            demand_stddev: round($stddev, 4),
            horizon_demand: round($dailyDemand * $horizonDays, 2),
            horizon_days: $horizonDays,
            method: $method,
        );
    }

    private function getHistory(int $skuId): Collection
    {
        return SalesHistory::where('sku_id', $skuId)
            ->where('sale_date', '>=', Carbon::today()->subDays(self::LOOKBACK_DAYS))
            ->orderBy('sale_date')
            ->get();
    }

    private function exponentialSmoothing(Collection $qtys): float
    {
        $smoothed = $qtys->first();
        foreach ($qtys->skip(1) as $qty) {
            $smoothed = self::SMOOTHING_ALPHA * $qty + (1 - self::SMOOTHING_ALPHA) * $smoothed;
        }
        return $smoothed;
    }

    private function stddev(Collection $qtys, float $mean): float
    {
        if ($qtys->count() < 2) return 0.0;

        $variance = $qtys->map(fn ($q) => pow($q - $mean, 2))->average();
        return sqrt($variance);
    }
}
```

**Step 5: Run tests**
```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan test tests/Unit/InventoryEngine/DemandForecasterTest.php
```
Expected: all PASS

**Step 6: Commit**
```bash
git commit -m "feat: add DemandForecaster with exponential smoothing and moving average"
```

---

### Task 6: InventoryPositionTracker

**Files:**
- Create: `app/Services/InventoryEngine/InventoryPositionTracker.php`
- Create: `app/Services/InventoryEngine/DTOs/InventoryPosition.php`
- Test: `tests/Unit/InventoryEngine/InventoryPositionTrackerTest.php`

**Step 1: Write failing tests**

```php
// tests/Unit/InventoryEngine/InventoryPositionTrackerTest.php
<?php

use App\Models\Sku;
use App\Services\InventoryEngine\InventoryPositionTracker;
use App\Services\InventoryEngine\DTOs\InventoryPosition;

it('calculates effective position correctly', function () {
    $sku = Sku::factory()->create([
        'current_stock'  => 100,
        'in_transit_qty' => 20,
        'reserved_qty'   => 10,
    ]);

    $tracker  = new InventoryPositionTracker();
    $position = $tracker->getPosition($sku->id, 3.2);

    expect($position)->toBeInstanceOf(InventoryPosition::class)
        ->and($position->effective_position)->toBe(110)
        ->and($position->days_of_cover)->toBeCloseTo(34.375, 1);
});

it('returns zero days of cover when demand is zero', function () {
    $sku     = Sku::factory()->create(['current_stock' => 50]);
    $tracker = new InventoryPositionTracker();

    $position = $tracker->getPosition($sku->id, 0.0);
    expect($position->days_of_cover)->toBe(0.0);
});
```

**Step 2: Run to verify failure**
```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan test tests/Unit/InventoryEngine/InventoryPositionTrackerTest.php
```

**Step 3: Implement**

```php
// app/Services/InventoryEngine/DTOs/InventoryPosition.php
readonly class InventoryPosition
{
    public function __construct(
        public int   $on_hand,
        public int   $in_transit,
        public int   $reserved,
        public int   $effective_position,
        public float $days_of_cover,
    ) {}
}

// app/Services/InventoryEngine/InventoryPositionTracker.php
class InventoryPositionTracker
{
    public function getPosition(int $skuId, float $avgDailyDemand): InventoryPosition
    {
        $sku = \App\Models\Sku::findOrFail($skuId);

        $effective = $sku->current_stock + $sku->in_transit_qty - $sku->reserved_qty;
        $daysOfCover = $avgDailyDemand > 0
            ? round($effective / $avgDailyDemand, 2)
            : 0.0;

        return new InventoryPosition(
            on_hand: $sku->current_stock,
            in_transit: $sku->in_transit_qty,
            reserved: $sku->reserved_qty,
            effective_position: $effective,
            days_of_cover: $daysOfCover,
        );
    }
}
```

**Step 4: Run tests**
```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan test tests/Unit/InventoryEngine/InventoryPositionTrackerTest.php
```
Expected: PASS

**Step 5: Commit**
```bash
git commit -m "feat: add InventoryPositionTracker"
```

---

### Task 7: LeadTimeHandler

**Files:**
- Create: `app/Services/InventoryEngine/LeadTimeHandler.php`
- Create: `app/Services/InventoryEngine/DTOs/LeadTimeEstimate.php`
- Test: `tests/Unit/InventoryEngine/LeadTimeHandlerTest.php`

**Step 1: Write failing tests**

```php
// tests/Unit/InventoryEngine/LeadTimeHandlerTest.php
<?php

use App\Models\Supplier;
use App\Services\InventoryEngine\LeadTimeHandler;
use App\Services\InventoryEngine\DTOs\LeadTimeEstimate;

it('returns buffered lead time using supplier stddev', function () {
    $supplier = Supplier::factory()->create([
        'avg_lead_time_days' => 7,
        'lead_time_stddev'   => 2.0,
    ]);

    $handler  = new LeadTimeHandler();
    $estimate = $handler->getLeadTimeWithBuffer($supplier->id);

    // Buffer = 1 stddev = 2.0, so buffered = 7 + 2 = 9
    expect($estimate)->toBeInstanceOf(LeadTimeEstimate::class)
        ->and($estimate->expected_days)->toBe(7)
        ->and($estimate->buffered_days)->toBe(9);
});

it('falls back to stated lead time × 1.3 when stddev is zero', function () {
    $supplier = Supplier::factory()->create([
        'avg_lead_time_days' => 10,
        'lead_time_stddev'   => 0.0,
    ]);

    $handler  = new LeadTimeHandler();
    $estimate = $handler->getLeadTimeWithBuffer($supplier->id);

    // Fallback: 10 * 1.3 = 13
    expect($estimate->buffered_days)->toBe(13);
});
```

**Step 2: Run to verify failure**
```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan test tests/Unit/InventoryEngine/LeadTimeHandlerTest.php
```

**Step 3: Implement**

```php
// app/Services/InventoryEngine/DTOs/LeadTimeEstimate.php
readonly class LeadTimeEstimate
{
    public function __construct(
        public int $expected_days,
        public int $buffered_days,
        public float $stddev,
    ) {}
}

// app/Services/InventoryEngine/LeadTimeHandler.php
class LeadTimeHandler
{
    private const FALLBACK_MULTIPLIER = 1.3;

    public function getLeadTimeWithBuffer(int $supplierId): LeadTimeEstimate
    {
        $supplier = \App\Models\Supplier::findOrFail($supplierId);

        $expected = $supplier->avg_lead_time_days;
        $stddev   = $supplier->lead_time_stddev;

        $buffered = $stddev > 0
            ? (int) ceil($expected + $stddev)
            : (int) ceil($expected * self::FALLBACK_MULTIPLIER);

        return new LeadTimeEstimate(
            expected_days: $expected,
            buffered_days: $buffered,
            stddev: $stddev,
        );
    }
}
```

**Step 4: Run tests**
```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan test tests/Unit/InventoryEngine/LeadTimeHandlerTest.php
```
Expected: PASS

**Step 5: Commit**
```bash
git commit -m "feat: add LeadTimeHandler with stddev-based buffering"
```

---

### Task 8: ConstraintEngine

**Files:**
- Create: `app/Services/InventoryEngine/ConstraintEngine.php`
- Create: `app/Services/InventoryEngine/DTOs/ConstrainedQuantity.php`
- Test: `tests/Unit/InventoryEngine/ConstraintEngineTest.php`

**Step 1: Write failing tests**

```php
// tests/Unit/InventoryEngine/ConstraintEngineTest.php
<?php

use App\Models\Sku;
use App\Services\InventoryEngine\ConstraintEngine;
use App\Services\InventoryEngine\DTOs\ConstrainedQuantity;

it('rounds up raw qty to nearest MOQ', function () {
    $sku = Sku::factory()->create(['moq' => 24, 'unit_cost' => 4500]);

    $engine = new ConstraintEngine();
    $result = $engine->applyConstraints($sku->id, 30, 99999900);

    // 30 → rounds up to 48 (next multiple of 24)
    expect($result->final_qty)->toBe(48)
        ->and($result->budget_blocked)->toBeFalse();
});

it('flags budget blocked when cost exceeds remaining budget', function () {
    $sku = Sku::factory()->create(['moq' => 6, 'unit_cost' => 38000]);

    $engine = new ConstraintEngine();
    // Budget: 5000 halalas = 50 SAR. Cost per unit: 380 SAR. Can't afford MOQ.
    $result = $engine->applyConstraints($sku->id, 6, 500000);

    expect($result->budget_blocked)->toBeTrue()
        ->and($result->final_qty)->toBe(0);
});

it('never goes below MOQ when budget allows', function () {
    $sku = Sku::factory()->create(['moq' => 12, 'unit_cost' => 1000]);

    $engine = new ConstraintEngine();
    $result = $engine->applyConstraints($sku->id, 5, 99999900);

    expect($result->final_qty)->toBe(12);
});
```

**Step 2: Run to verify failure**
```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan test tests/Unit/InventoryEngine/ConstraintEngineTest.php
```

**Step 3: Implement**

```php
// app/Services/InventoryEngine/DTOs/ConstrainedQuantity.php
readonly class ConstrainedQuantity
{
    public function __construct(
        public int   $raw_qty,
        public int   $final_qty,
        public bool  $budget_blocked,
        public array $constraint_notes,
    ) {}
}

// app/Services/InventoryEngine/ConstraintEngine.php
class ConstraintEngine
{
    public function applyConstraints(int $skuId, int $rawQty, int $budgetRemainingHalalas): ConstrainedQuantity
    {
        $sku   = \App\Models\Sku::findOrFail($skuId);
        $notes = [];

        // Round up to MOQ
        $qty = max($rawQty, $sku->moq);
        if ($qty % $sku->moq !== 0) {
            $qty = (int) (ceil($qty / $sku->moq) * $sku->moq);
            $notes[] = "Rounded up to MOQ multiple: {$qty}";
        }

        // Budget check: cost in halalas
        $totalCost = $qty * $sku->unit_cost;
        if ($totalCost > $budgetRemainingHalalas) {
            // Can we afford at least 1 MOQ?
            $moqCost = $sku->moq * $sku->unit_cost;
            if ($moqCost > $budgetRemainingHalalas) {
                $notes[] = 'Budget insufficient for minimum order';
                return new ConstrainedQuantity($rawQty, 0, true, $notes);
            }

            // Fit as many MOQ multiples as budget allows
            $qty = (int) (floor($budgetRemainingHalalas / $sku->unit_cost / $sku->moq) * $sku->moq);
            $notes[] = "Reduced to {$qty} due to budget constraint";
        }

        return new ConstrainedQuantity($rawQty, $qty, false, $notes);
    }
}
```

**Step 4: Run tests**
```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan test tests/Unit/InventoryEngine/ConstraintEngineTest.php
```
Expected: PASS

**Step 5: Commit**
```bash
git commit -m "feat: add ConstraintEngine with MOQ rounding and budget enforcement"
```

---

### Task 9: DecisionScorer

**Files:**
- Create: `app/Services/InventoryEngine/DecisionScorer.php`
- Create: `app/Services/InventoryEngine/DTOs/Decision.php`
- Test: `tests/Unit/InventoryEngine/DecisionScorerTest.php`

**Step 1: Write failing tests**

```php
// tests/Unit/InventoryEngine/DecisionScorerTest.php
<?php

use App\Services\InventoryEngine\DecisionScorer;
use App\Services\InventoryEngine\DTOs\Decision;
use App\Services\InventoryEngine\DTOs\ForecastResult;
use App\Services\InventoryEngine\DTOs\InventoryPosition;
use App\Services\InventoryEngine\DTOs\LeadTimeEstimate;
use App\Services\InventoryEngine\DTOs\ConstrainedQuantity;

function makePosition(int $effective, float $daysOfCover): InventoryPosition {
    return new InventoryPosition(
        on_hand: $effective,
        in_transit: 0,
        reserved: 0,
        effective_position: $effective,
        days_of_cover: $daysOfCover,
    );
}

function makeForecast(float $daily, float $stddev = 1.0): ForecastResult {
    return new ForecastResult($daily, $stddev, $daily * 30, 30, 'moving_average');
}

it('returns ORDER when position is at or below reorder point', function () {
    // daily=3, lead=7 buffered, safety stock = 1.65 * 1.0 * sqrt(7) ≈ 4.37
    // reorder_point = 3 * 7 + 4.37 ≈ 25.37
    // effective = 20 < 25.37 → ORDER
    $scorer = new DecisionScorer();
    $result = $scorer->score(
        position: makePosition(20, 6.7),
        forecast: makeForecast(3.0),
        leadTime: new LeadTimeEstimate(7, 7, 1.5),
        constraints: new ConstrainedQuantity(24, 24, false, []),
    );

    expect($result->decision)->toBe('order');
});

it('returns WATCH when position is between reorder point and 1.3x', function () {
    // reorder_point ≈ 25.37, 1.3x ≈ 32.98. effective = 28 → WATCH
    $scorer = new DecisionScorer();
    $result = $scorer->score(
        position: makePosition(28, 9.3),
        forecast: makeForecast(3.0),
        leadTime: new LeadTimeEstimate(7, 7, 1.5),
        constraints: new ConstrainedQuantity(24, 24, false, []),
    );

    expect($result->decision)->toBe('watch');
});

it('returns HOLD when position is well above reorder point', function () {
    $scorer = new DecisionScorer();
    $result = $scorer->score(
        position: makePosition(100, 33.3),
        forecast: makeForecast(3.0),
        leadTime: new LeadTimeEstimate(7, 7, 1.5),
        constraints: new ConstrainedQuantity(24, 24, false, []),
    );

    expect($result->decision)->toBe('hold');
});

it('returns ORDER_BUDGET_BLOCKED when budget prevents order', function () {
    $scorer = new DecisionScorer();
    $result = $scorer->score(
        position: makePosition(10, 3.3),
        forecast: makeForecast(3.0),
        leadTime: new LeadTimeEstimate(7, 7, 1.5),
        constraints: new ConstrainedQuantity(24, 0, true, ['Budget insufficient']),
    );

    expect($result->decision)->toBe('order_budget_blocked');
});
```

**Step 2: Run to verify failure**
```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan test tests/Unit/InventoryEngine/DecisionScorerTest.php
```

**Step 3: Implement**

```php
// app/Services/InventoryEngine/DTOs/Decision.php
readonly class Decision
{
    public function __construct(
        public string $decision,        // order|watch|hold|order_budget_blocked
        public int    $recommended_qty,
        public int    $constrained_qty,
        public float  $reorder_point,
        public float  $safety_stock,
        public array  $reasoning,
    ) {}
}

// app/Services/InventoryEngine/DecisionScorer.php
class DecisionScorer
{
    private const Z_SCORE = 1.65; // 95% service level
    private const WATCH_BUFFER = 1.3;

    public function score(
        InventoryPosition  $position,
        ForecastResult     $forecast,
        LeadTimeEstimate   $leadTime,
        ConstrainedQuantity $constraints,
    ): Decision {
        $safetyStock  = self::Z_SCORE * $forecast->demand_stddev * sqrt($leadTime->buffered_days);
        $reorderPoint = ($forecast->daily_demand * $leadTime->buffered_days) + $safetyStock;

        $effective = $position->effective_position;
        $reasoning = [
            'effective_position' => $effective,
            'reorder_point'      => round($reorderPoint, 2),
            'safety_stock'       => round($safetyStock, 2),
            'daily_demand'       => $forecast->daily_demand,
            'buffered_lead_time' => $leadTime->buffered_days,
            'constraint_notes'   => $constraints->constraint_notes,
        ];

        if ($effective <= $reorderPoint) {
            if ($constraints->budget_blocked) {
                return new Decision('order_budget_blocked', $constraints->raw_qty, 0, $reorderPoint, $safetyStock, $reasoning);
            }
            return new Decision('order', $constraints->raw_qty, $constraints->final_qty, $reorderPoint, $safetyStock, $reasoning);
        }

        if ($effective <= $reorderPoint * self::WATCH_BUFFER) {
            return new Decision('watch', 0, 0, $reorderPoint, $safetyStock, $reasoning);
        }

        return new Decision('hold', 0, 0, $reorderPoint, $safetyStock, $reasoning);
    }
}
```

**Step 4: Run tests**
```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan test tests/Unit/InventoryEngine/DecisionScorerTest.php
```
Expected: PASS

**Step 5: Commit**
```bash
git commit -m "feat: add DecisionScorer with reorder point logic and safety stock"
```

---

### Task 10: InventoryEngineService (Orchestrator) + Job

**Files:**
- Create: `app/Services/InventoryEngine/InventoryEngineService.php`
- Create: `app/Jobs/RunInventoryEngineJob.php`
- Create: `app/Events/StockAlertEvent.php`
- Test: `tests/Feature/InventoryEngine/EngineRunTest.php`

**Step 1: Write failing test**

```php
// tests/Feature/InventoryEngine/EngineRunTest.php
<?php

use App\Models\Sku;
use App\Models\Supplier;
use App\Models\SalesHistory;
use App\Services\InventoryEngine\InventoryEngineService;
use Carbon\Carbon;

it('runs engine for all skus and stores decisions', function () {
    $supplier = Supplier::factory()->create(['avg_lead_time_days' => 7, 'lead_time_stddev' => 1.0]);
    $sku = Sku::factory()->create([
        'supplier_id'   => $supplier->id,
        'current_stock' => 10,
        'moq'           => 6,
        'unit_cost'     => 1000,
        'reorder_qty'   => 24,
    ]);

    for ($i = 30; $i >= 1; $i--) {
        SalesHistory::create([
            'sku_id'        => $sku->id,
            'sale_date'     => Carbon::today()->subDays($i),
            'quantity_sold' => 3,
        ]);
    }

    $service = app(InventoryEngineService::class);
    $results = $service->run(budgetRemainingHalalas: 99999900);

    expect($results)->toHaveCount(1)
        ->and($results[0]->decision)->toBeIn(['order', 'watch', 'hold', 'order_budget_blocked']);

    // Persisted to DB
    expect(\App\Models\InventoryDecision::count())->toBe(1);
});
```

**Step 2: Run to verify failure**
```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan test tests/Feature/InventoryEngine/EngineRunTest.php
```

**Step 3: Implement InventoryEngineService**

```php
// app/Services/InventoryEngine/InventoryEngineService.php
<?php

namespace App\Services\InventoryEngine;

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
    ) {}

    /** @return Decision[] */
    public function run(int $budgetRemainingHalalas = PHP_INT_MAX): array
    {
        $skus    = Sku::with('supplier')->get();
        $results = [];

        foreach ($skus as $sku) {
            $forecast   = $this->forecaster->forecast($sku->id, horizon_days: 30);
            $position   = $this->tracker->getPosition($sku->id, $forecast->daily_demand);
            $leadTime   = $this->leadTimeHandler->getLeadTimeWithBuffer($sku->supplier_id);
            $rawQty     = max($sku->reorder_qty, $sku->moq);
            $constrained = $this->constraints->applyConstraints($sku->id, $rawQty, $budgetRemainingHalalas);
            $decision   = $this->scorer->score($position, $forecast, $leadTime, $constrained);

            $this->persist($sku->id, $decision, $forecast, $position);

            if ($decision->decision === 'order') {
                $budgetRemainingHalalas -= $decision->constrained_qty * $sku->unit_cost;
            }

            $results[] = $decision;
        }

        return $results;
    }

    private function persist(int $skuId, Decision $decision, $forecast, $position): void
    {
        InventoryDecision::create([
            'sku_id'          => $skuId,
            'run_at'          => now(),
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

**Step 4: Bind in AppServiceProvider**

```php
// app/Providers/AppServiceProvider.php — in register():
$this->app->bind(\App\Services\InventoryEngine\InventoryEngineService::class, function ($app) {
    return new \App\Services\InventoryEngine\InventoryEngineService(
        new \App\Services\InventoryEngine\DemandForecaster(),
        new \App\Services\InventoryEngine\InventoryPositionTracker(),
        new \App\Services\InventoryEngine\LeadTimeHandler(),
        new \App\Services\InventoryEngine\ConstraintEngine(),
        new \App\Services\InventoryEngine\DecisionScorer(),
    );
});
```

**Step 5: Create Job and Event**

```php
// app/Jobs/RunInventoryEngineJob.php
<?php

namespace App\Jobs;

use App\Events\StockAlertEvent;
use App\Models\Setting;
use App\Services\InventoryEngine\InventoryEngineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunInventoryEngineJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function handle(InventoryEngineService $engine): void
    {
        $budget  = config('inventory.monthly_budget_halalas', PHP_INT_MAX);
        $results = $engine->run($budget);

        $orderNow = collect($results)->filter(fn ($d) => $d->decision === 'order');
        if ($orderNow->isNotEmpty()) {
            StockAlertEvent::dispatch($orderNow->count());
        }
    }
}

// app/Events/StockAlertEvent.php
<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class StockAlertEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly int $orderCount) {}

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

**Step 6: Run all engine tests**
```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan test tests/Feature/InventoryEngine/ tests/Unit/InventoryEngine/
```
Expected: all PASS

**Step 7: Commit**
```bash
git commit -m "feat: add InventoryEngineService orchestrator, RunInventoryEngineJob, and StockAlertEvent"
```

---

## Phase 3: Backend Controllers & Routes

### Task 11: Dashboard controller

**Files:**
- Create: `app/Http/Controllers/DashboardController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Http/DashboardControllerTest.php`

**Step 1: Write failing test**

```php
// tests/Feature/Http/DashboardControllerTest.php
<?php

use App\Models\User;

it('shows dashboard to authenticated users', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertStatus(200)
             ->assertInertia(fn ($page) => $page->component('Dashboard/Index'));
});

it('redirects guests to login', function () {
    $this->get('/')->assertRedirect('/login');
});
```

**Step 2: Run to verify failure**
```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan test tests/Feature/Http/DashboardControllerTest.php
```

**Step 3: Create controller**

```php
// app/Http/Controllers/DashboardController.php
<?php

namespace App\Http\Controllers;

use App\Models\InventoryDecision;
use App\Models\PurchaseOrder;
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

        $committedSpend = PurchaseOrder::whereIn('status', ['approved', 'ordered', 'in_transit'])
            ->join('skus', 'purchase_orders.sku_id', '=', 'skus.id')
            ->selectRaw('SUM(purchase_orders.ordered_qty * skus.unit_cost) as total')
            ->value('total') ?? 0;

        return Inertia::render('Dashboard/Index', [
            'stats' => [
                'order_now'       => $decisions->where('decision', 'order')->count(),
                'watch'           => $decisions->where('decision', 'watch')->count(),
                'hold'            => $decisions->where('decision', 'hold')->count(),
                'budget_blocked'  => $decisions->where('decision', 'order_budget_blocked')->count(),
                'avg_days_cover'  => round($decisions->avg('days_of_cover'), 1),
                'committed_spend' => $committedSpend,
            ],
            'decisions' => $decisions->map(fn ($d) => [
                'id'            => $d->id,
                'sku_code'      => $d->sku->sku_code,
                'sku_name'      => $d->sku->name,
                'decision'      => $d->decision,
                'constrained_qty' => $d->constrained_qty,
                'days_of_cover' => $d->days_of_cover,
                'reorder_point' => $d->reorder_point,
                'run_at'        => $d->run_at,
            ]),
        ]);
    }
}
```

**Step 4: Update routes and add auth middleware**

```php
// routes/web.php
<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SkuController;
use App\Http\Controllers\EngineController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class);
    Route::resource('skus', SkuController::class)->only(['index', 'show']);
    Route::post('/engine/run', EngineController::class)->name('engine.run');
});

require __DIR__.'/auth.php';
```

**Step 5: Run tests**
```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan test tests/Feature/Http/DashboardControllerTest.php
```
Expected: PASS

**Step 6: Commit**
```bash
git commit -m "feat: add DashboardController with inventory stats"
```

---

### Task 12: SKU controller + Engine controller

**Files:**
- Create: `app/Http/Controllers/SkuController.php`
- Create: `app/Http/Controllers/EngineController.php`
- Test: `tests/Feature/Http/SkuControllerTest.php`

**Step 1: Write failing tests**

```php
// tests/Feature/Http/SkuControllerTest.php
<?php

use App\Models\Sku;
use App\Models\Supplier;
use App\Models\User;

it('lists all skus for authenticated user', function () {
    $user = User::factory()->create();
    Sku::factory(3)->create();

    $this->actingAs($user)
         ->get('/skus')
         ->assertStatus(200)
         ->assertInertia(fn ($page) => $page
             ->component('SKUs/Index')
             ->has('skus', 3)
         );
});

it('shows a single sku with its latest decision', function () {
    $user = User::factory()->create();
    $sku  = Sku::factory()->create();

    $this->actingAs($user)
         ->get("/skus/{$sku->id}")
         ->assertStatus(200)
         ->assertInertia(fn ($page) => $page->component('SKUs/Show'));
});
```

**Step 2: Run to verify failure**

**Step 3: Implement controllers**

```php
// app/Http/Controllers/SkuController.php
<?php

namespace App\Http\Controllers;

use App\Models\Sku;
use Inertia\Inertia;

class SkuController extends Controller
{
    public function index()
    {
        $skus = Sku::with(['supplier', 'decisions' => fn ($q) => $q->latest('run_at')->limit(1)])
            ->get()
            ->map(fn ($sku) => [
                'id'              => $sku->id,
                'sku_code'        => $sku->sku_code,
                'name'            => $sku->name,
                'current_stock'   => $sku->current_stock,
                'effective_position' => $sku->effective_position,
                'unit_cost_sar'   => $sku->unit_cost_sar,
                'moq'             => $sku->moq,
                'supplier_name'   => $sku->supplier->name,
                'latest_decision' => $sku->decisions->first()?->decision,
                'days_of_cover'   => $sku->decisions->first()?->days_of_cover,
            ]);

        return Inertia::render('SKUs/Index', compact('skus'));
    }

    public function show(Sku $sku)
    {
        $sku->load('supplier');

        $decisions = $sku->decisions()->latest('run_at')->limit(30)->get();
        $salesHistory = $sku->salesHistory()
            ->orderBy('sale_date', 'desc')
            ->limit(90)
            ->get(['sale_date', 'quantity_sold']);

        return Inertia::render('SKUs/Show', [
            'sku'          => $sku,
            'decisions'    => $decisions,
            'salesHistory' => $salesHistory,
        ]);
    }
}

// app/Http/Controllers/EngineController.php
<?php

namespace App\Http\Controllers;

use App\Jobs\RunInventoryEngineJob;
use Illuminate\Http\RedirectResponse;

class EngineController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        RunInventoryEngineJob::dispatch()->onQueue('inventory');

        return back()->with('success', 'Engine run dispatched.');
    }
}
```

**Step 4: Run tests**
```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan test tests/Feature/Http/
```
Expected: PASS

**Step 5: Commit**
```bash
git commit -m "feat: add SkuController, EngineController, and complete web routes"
```

---

## Phase 4: Frontend Vue Pages

### Task 13: Dashboard/Index.vue — decision summary

**Files:**
- Modify: `resources/js/Pages/Dashboard/Index.vue`

**Implementation:**

```vue
<script setup lang="ts">
import { useI18n } from 'vue-i18n';

interface DecisionRow {
    id: number;
    sku_code: string;
    sku_name: string;
    decision: 'order' | 'watch' | 'hold' | 'order_budget_blocked';
    constrained_qty: number;
    days_of_cover: number;
    reorder_point: number;
    run_at: string;
}

interface Stats {
    order_now: number;
    watch: number;
    hold: number;
    budget_blocked: number;
    avg_days_cover: number;
    committed_spend: number;
}

const props = defineProps<{
    stats: Stats;
    decisions: DecisionRow[];
}>();

const { t } = useI18n();

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
        order_budget_blocked: 'ORDER — BUDGET BLOCKED',
    };
    return labels[decision] ?? decision.toUpperCase();
}

function runEngine() {
    // Inertia POST to /engine/run
    (window as any).$inertia?.post('/engine/run');
}
</script>

<template>
    <div class="min-h-screen bg-gray-50 p-8">
        <div class="max-w-7xl mx-auto">

            <!-- Header -->
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">{{ t('dashboard.title') }}</h1>
                    <p class="text-gray-500 mt-1">{{ t('dashboard.subtitle') }}</p>
                </div>
                <button
                    @click="runEngine"
                    class="bg-blue-600 text-white px-5 py-2.5 rounded-lg font-medium hover:bg-blue-700 transition"
                >
                    Run Engine
                </button>
            </div>

            <!-- Stats cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                <div class="bg-white rounded-xl p-5 shadow-sm border">
                    <p class="text-sm text-gray-500">Order Now</p>
                    <p class="text-3xl font-bold text-red-600">{{ stats.order_now }}</p>
                </div>
                <div class="bg-white rounded-xl p-5 shadow-sm border">
                    <p class="text-sm text-gray-500">Watch</p>
                    <p class="text-3xl font-bold text-yellow-500">{{ stats.watch }}</p>
                </div>
                <div class="bg-white rounded-xl p-5 shadow-sm border">
                    <p class="text-sm text-gray-500">Avg Days of Cover</p>
                    <p class="text-3xl font-bold text-gray-800">{{ stats.avg_days_cover }}</p>
                </div>
                <div class="bg-white rounded-xl p-5 shadow-sm border">
                    <p class="text-sm text-gray-500">Committed Spend</p>
                    <p class="text-3xl font-bold text-gray-800">
                        {{ (stats.committed_spend / 100).toFixed(0) }} SAR
                    </p>
                </div>
            </div>

            <!-- Decisions table -->
            <div class="bg-white rounded-xl shadow-sm border overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                        <tr>
                            <th class="px-4 py-3 text-left">SKU</th>
                            <th class="px-4 py-3 text-left">Name</th>
                            <th class="px-4 py-3 text-left">Decision</th>
                            <th class="px-4 py-3 text-right">Qty</th>
                            <th class="px-4 py-3 text-right">Days Cover</th>
                            <th class="px-4 py-3 text-right">Reorder Point</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="row in decisions" :key="row.id" class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-mono text-gray-600">{{ row.sku_code }}</td>
                            <td class="px-4 py-3 font-medium text-gray-900">{{ row.sku_name }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="px-2.5 py-1 rounded-full text-xs font-semibold"
                                    :class="decisionBadgeClass[row.decision]"
                                >
                                    {{ badgeLabel(row.decision) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right text-gray-700">{{ row.constrained_qty }}</td>
                            <td class="px-4 py-3 text-right text-gray-700">{{ row.days_of_cover }}</td>
                            <td class="px-4 py-3 text-right text-gray-700">{{ row.reorder_point }}</td>
                        </tr>
                        <tr v-if="decisions.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-gray-400">
                                No decisions yet. Run the engine to generate recommendations.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
```

**Verify build:**
```bash
"C:\Program Files\nodejs\npm.cmd" run build
```
Expected: ✓ built successfully

**Commit:**
```bash
git commit -m "feat: build Dashboard/Index.vue with stats cards and decisions table"
```

---

### Task 14: SKUs/Index.vue and SKUs/Show.vue

**Files:**
- Create: `resources/js/Pages/SKUs/Index.vue`
- Create: `resources/js/Pages/SKUs/Show.vue`

**SKUs/Index.vue** — table of all SKUs with decision badges and stock levels. Each row links to Show page.

**SKUs/Show.vue** — detail page showing:
- SKU info (supplier, cost, MOQ, stock)
- Last 30 decision history (mini timeline)
- Last 90 days of sales (simple number table)

Key pattern: use `<Link :href="route('skus.show', sku.id)">` from `@inertiajs/vue3` for navigation.

**Verify build:**
```bash
"C:\Program Files\nodejs\npm.cmd" run build
```

**Commit:**
```bash
git commit -m "feat: add SKUs/Index.vue and SKUs/Show.vue"
```

---

## Phase 5: Auth + Fortify

### Task 15: Wire up Fortify authentication

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Create: `resources/js/Pages/Auth/Login.vue`
- Run: `php artisan vendor:publish --provider="Laravel\Fortify\FortifyServiceProvider"`

**Steps:**
1. Publish Fortify config: `php artisan vendor:publish --provider="Laravel\Fortify\FortifyServiceProvider"`
2. Register FortifyServiceProvider in `bootstrap/providers.php`
3. In `FortifyServiceProvider::boot()`, set `Fortify::loginView(fn () => Inertia::render('Auth/Login'))`
4. Create `resources/js/Pages/Auth/Login.vue` — simple email/password form using `useForm` from `@inertiajs/vue3`
5. Test login works with `owner@demo.test / password`

**Commit:**
```bash
git commit -m "feat: wire Fortify auth with Inertia login page"
```

---

## Final Verification

Run the full test suite:
```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan test
```
Expected: all tests PASS

Run the engine manually to verify end-to-end:
```bash
C:\Users\hp\.config\herd\bin\php83\php.exe artisan tinker --execute="app(App\Services\InventoryEngine\InventoryEngineService::class)->run();"
```
Expected: array of Decision objects, decisions written to DB

Rebuild frontend:
```bash
"C:\Program Files\nodejs\npm.cmd" run build
```
Expected: ✓ built successfully
