<?php

use App\Models\DataIngestionRun;
use App\Models\Sku;
use App\Models\Supplier;
use App\Services\Ingestion\Importers\SkuImporter;
use App\Services\Ingestion\Sources\CsvSource;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeSkuCsv(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'sku_') . '.csv';
    $handle = fopen($path, 'w');
    fputcsv($handle, ['sku_code', 'name', 'category', 'supplier_name', 'moq', 'unit_cost', 'current_stock', 'lead_time_days']);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);
    return $path;
}

function makeSkuRun(): DataIngestionRun
{
    return DataIngestionRun::withoutGlobalScopes()->create([
        'tenant_id'  => 1,
        'source'     => 'csv_upload',
        'importer'   => 'SkuImporter',
        'status'     => 'running',
        'started_at' => now(),
    ]);
}

it('imports a valid SKU and converts unit_cost to halalas', function () {
    Supplier::withoutGlobalScopes()->create(['tenant_id' => 1, 'name' => 'Acme', 'avg_lead_time_days' => 7]);

    $path = makeSkuCsv([['FB-001', 'Grip Socks', 'accessory', 'Acme', '24', '45.00', '120', '7']]);
    $run = makeSkuRun();

    (new SkuImporter())->import(new CsvSource($path), $run, 1);

    $run->refresh();
    expect($run->status)->toBe('completed');

    $sku = Sku::withoutGlobalScopes()->where('sku_code', 'FB-001')->first();
    expect($sku)->not->toBeNull()
        ->and($sku->unit_cost)->toBe(4500)  // 45.00 SAR × 100
        ->and($sku->moq)->toBe(24)
        ->and($sku->category)->toBe('accessory');

    unlink($path);
});

it('rejects a row when supplier_name is not found', function () {
    $path = makeSkuCsv([['FB-002', 'Boot Spray', 'accessory', 'Unknown Supplier', '12', '10.00', '50', '5']]);
    $run = makeSkuRun();

    (new SkuImporter())->import(new CsvSource($path), $run, 1);

    $run->refresh();
    expect($run->status)->toBe('failed')
        ->and($run->rows_failed)->toBe(1)
        ->and($run->error_log[0]['errors'][0])->toContain('not found');

    expect(Sku::withoutGlobalScopes()->count())->toBe(0);

    unlink($path);
});

it('is idempotent — re-importing the same SKU updates the record', function () {
    Supplier::withoutGlobalScopes()->create(['tenant_id' => 1, 'name' => 'Acme', 'avg_lead_time_days' => 7]);

    $path = makeSkuCsv([['FB-001', 'Grip Socks', 'accessory', 'Acme', '24', '45.00', '120', '7']]);

    (new SkuImporter())->import(new CsvSource($path), makeSkuRun(), 1);
    (new SkuImporter())->import(new CsvSource($path), makeSkuRun(), 1);

    expect(Sku::withoutGlobalScopes()->where('sku_code', 'FB-001')->count())->toBe(1);

    unlink($path);
});
