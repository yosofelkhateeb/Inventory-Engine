<?php

use App\Jobs\RunForecastJob;
use App\Models\ForecastModelRegistry;
use App\Models\SalesHistory;
use App\Models\Sku;
use App\Models\Supplier;
use Carbon\Carbon;
use Database\Seeders\ForecastSettingsSeeder;
use Illuminate\Support\Facades\Process;

/**
 * Golden-SKU WMAPE regression guard.
 *
 * Seeds a deterministic weekly-seasonal pattern (365 days), runs the real
 * Python pipeline, and asserts the resulting sMAPE stays under a loose
 * ceiling. The three silent-wrong bugs we fixed in 2026-04 would all have
 * failed this test — data_audit zero-padding, seeder race, and Gate 1
 * over-rejection would each have pushed the number into triple digits.
 *
 * Slow by design (real subprocess). Gated on a reachable Python binary
 * so it skips cleanly in environments that can't run it.
 */

beforeEach(function () {
    $pythonBin = config('forecasting.python_bin', 'python');
    $script    = base_path('python/forecasting/main.py');

    $probe = Process::run([$pythonBin, '-c', 'print("ok")']);

    if (! $probe->successful() || ! file_exists($script)) {
        $this->markTestSkipped("Python binary '{$pythonBin}' not reachable; skipping golden WMAPE test");
    }

    (new ForecastSettingsSeeder())->run();
});

it('forecasts a deterministic weekly-seasonal SKU within the sanity ceiling', function () {
    $supplier = Supplier::factory()->create();
    $sku      = Sku::factory()->create([
        'supplier_id' => $supplier->id,
        'category'    => 'accessory',
        'name'        => 'Golden Regression SKU',
        'sku_code'    => 'GOLD-0001',
    ]);

    // Deterministic pattern: base 10 + weekly sinusoidal amplitude 3 + tiny
    // noise from a seeded PRNG. Any competent model (even ets_fallback)
    // should land well under 20% sMAPE on this.
    $days  = 365;
    $start = Carbon::today()->subDays($days);
    $rng   = new \Random\Randomizer(new \Random\Engine\Mt19937(42));

    $rows = [];
    for ($i = 0; $i < $days; $i++) {
        $dayOfWeek = $i % 7;
        $seasonal  = 3 * sin(2 * M_PI * $dayOfWeek / 7);
        // integer noise in [-1, 1]
        $noise = $rng->getInt(0, 2) - 1;
        $qty   = max(0, (int) round(10 + $seasonal + $noise));

        $rows[] = [
            'tenant_id'     => 1,
            'sku_id'        => $sku->id,
            'sale_date'     => $start->copy()->addDays($i)->toDateString(),
            'quantity_sold' => $qty,
            'is_promotion'  => false,
            'created_at'    => now(),
            'updated_at'    => now(),
        ];
    }

    SalesHistory::insert($rows);

    // Call handle() directly rather than dispatchSync so any Python exit
    // surfaces as a proper test failure instead of being swallowed by the
    // queue container.
    (new RunForecastJob($sku->id, 1, 'scheduled'))->handle();

    $registry = ForecastModelRegistry::withoutGlobalScopes()
        ->where('sku_id', $sku->id)
        ->where('tenant_id', 1)
        ->first();

    expect($registry)->not->toBeNull('Python pipeline did not write a registry row');

    // sMAPE sanity ceiling — loose (25%) so legitimate model-selection
    // variation doesn't cause false failures, but tight enough that a
    // 100%-WMAPE class regression blows right through it.
    expect((float) $registry->smape)
        ->toBeLessThan(25.0, "sMAPE {$registry->smape}% exceeds regression ceiling — {$registry->selection_rationale}");

    // Demand rate should orbit the true mean (~10). Width is wide because
    // we only care about catching "flat zero" or "wildly off" regressions.
    expect((float) $registry->demand_rate)
        ->toBeGreaterThan(6.0, "demand_rate {$registry->demand_rate} is suspiciously low (series mean is ~10)")
        ->toBeLessThan(14.0, "demand_rate {$registry->demand_rate} is suspiciously high (series mean is ~10)");

    // Guardrail: the trailing-zero assertion we added must not be firing
    // on this clean series — if it does, something upstream is silently
    // padding the series again.
    $warnings = $registry->warnings ?? [];
    $trailingZeroHit = collect($warnings)->first(fn ($w) => is_string($w) && str_contains($w, 'trailing_zero_run'));
    expect($trailingZeroHit)->toBeNull("Unexpected trailing-zero warning on clean golden series: {$trailingZeroHit}");
})->group('slow', 'python-integration');
