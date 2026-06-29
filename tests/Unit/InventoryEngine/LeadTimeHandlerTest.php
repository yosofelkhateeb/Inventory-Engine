<?php

use App\Models\LeadTimeObservation;
use App\Models\Sku;
use App\Models\Supplier;
use App\Services\InventoryEngine\DTOs\LeadTimeEstimate;
use App\Services\InventoryEngine\LeadTimeHandler;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// ── Static fallback (rung 3) — no observations ────────────────────────────

it('returns buffered lead time using supplier stddev when no observations exist', function () {
    $supplier = Supplier::factory()->create([
        'avg_lead_time_days' => 7,
        'lead_time_stddev'   => 2.0,
    ]);
    $estimate = (new LeadTimeHandler())->getLeadTimeWithBuffer($supplier->id);
    expect($estimate)->toBeInstanceOf(LeadTimeEstimate::class);
    expect($estimate->expected_days)->toBe(7);
    expect($estimate->buffered_days)->toBe(9);  // ceil(7 + 2)
    expect($estimate->source)->toBe('static');
    expect($estimate->p95)->toBeNull();
});

it('falls back to stated lead time × 1.3 when supplier stddev is zero', function () {
    $supplier = Supplier::factory()->create([
        'avg_lead_time_days' => 10,
        'lead_time_stddev'   => 0.0,
    ]);
    $estimate = (new LeadTimeHandler())->getLeadTimeWithBuffer($supplier->id);
    expect($estimate->buffered_days)->toBe(13);  // ceil(10 * 1.3)
    expect($estimate->source)->toBe('static');
});

// ── Supplier-level observations (rung 2) ──────────────────────────────────

it('uses supplier-level observations when SKU-level is insufficient', function () {
    $supplier = Supplier::factory()->create([
        'avg_lead_time_days' => 10,        // static value should NOT be used
        'lead_time_stddev'   => 0.0,
    ]);
    $sku = Sku::factory()->create(['supplier_id' => $supplier->id]);

    // 6 supplier-level observations (no sku_id), all 5 days
    foreach (range(1, 6) as $i) {
        LeadTimeObservation::create([
            'tenant_id'         => 1,
            'supplier_id'       => $supplier->id,
            'sku_id'            => null,
            'order_placed_at'   => Carbon::today()->subDays(20 + $i),
            'order_received_at' => Carbon::today()->subDays(15 + $i),
            'days_actual'       => 5,
            'source'            => 'synthetic',
        ]);
    }

    $estimate = (new LeadTimeHandler())->getLeadTimeWithBuffer($supplier->id, $sku->id, 1);

    expect($estimate->source)->toBe('observations_supplier');
    expect($estimate->expected_days)->toBe(5);   // mean of [5,5,5,5,5,5]
    expect($estimate->stddev)->toBe(0.0);
    expect($estimate->p95)->toBe(5.0);
});

// ── SKU-level observations (rung 1) ───────────────────────────────────────

it('uses SKU-level observations when sample is sufficient', function () {
    $supplier = Supplier::factory()->create([
        'avg_lead_time_days' => 10,
        'lead_time_stddev'   => 1.0,
    ]);
    $sku = Sku::factory()->create(['supplier_id' => $supplier->id]);

    // 5 SKU-level observations: 4, 5, 6, 7, 8 days. Mean=6, p95≈7.8
    foreach ([4, 5, 6, 7, 8] as $i => $days) {
        LeadTimeObservation::create([
            'tenant_id'         => 1,
            'supplier_id'       => $supplier->id,
            'sku_id'            => $sku->id,
            'order_placed_at'   => Carbon::today()->subDays(20 + $i),
            'order_received_at' => Carbon::today()->subDays(20 + $i)->addDays($days),
            'days_actual'       => $days,
            'source'            => 'synthetic',
        ]);
    }

    $estimate = (new LeadTimeHandler())->getLeadTimeWithBuffer($supplier->id, $sku->id, 1);

    expect($estimate->source)->toBe('observations_sku');
    expect($estimate->expected_days)->toBe(6);    // round(mean of 4..8)
    expect($estimate->stddev)->toBeGreaterThan(1.0)->toBeLessThan(2.0);
    expect($estimate->p95)->toBeGreaterThan(7.0)->toBeLessThanOrEqual(8.0);
});

// ── Observation window respect ────────────────────────────────────────────

it('ignores observations outside the trailing window', function () {
    $supplier = Supplier::factory()->create([
        'avg_lead_time_days' => 10,
        'lead_time_stddev'   => 0.0,
    ]);
    $sku = Sku::factory()->create(['supplier_id' => $supplier->id]);

    // 6 observations from 2 years ago (outside default 365-day window)
    foreach (range(1, 6) as $i) {
        LeadTimeObservation::create([
            'tenant_id'         => 1,
            'supplier_id'       => $supplier->id,
            'sku_id'            => $sku->id,
            'order_placed_at'   => Carbon::today()->subDays(800 + $i),
            'order_received_at' => Carbon::today()->subDays(795 + $i),
            'days_actual'       => 3,
            'source'            => 'synthetic',
        ]);
    }

    $estimate = (new LeadTimeHandler())->getLeadTimeWithBuffer($supplier->id, $sku->id, 1);

    // None of the old observations should count → falls back to static
    expect($estimate->source)->toBe('static');
    expect($estimate->expected_days)->toBe(10);
});
