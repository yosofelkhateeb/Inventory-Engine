<?php

use App\Models\DataIngestionRun;
use App\Models\SalesHistory;
use App\Models\Sku;
use App\Models\Supplier;
use App\Services\Ingestion\Importers\SalesHistoryImporter;
use App\Services\Ingestion\Sources\CsvSource;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeSalesCsv(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'sal_') . '.csv';
    $handle = fopen($path, 'w');
    fputcsv($handle, ['sku_code', 'sale_date', 'quantity_sold', 'is_promotion']);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);
    return $path;
}

function makeSalesRun(): DataIngestionRun
{
    return DataIngestionRun::withoutGlobalScopes()->create([
        'tenant_id'  => 1,
        'source'     => 'csv_upload',
        'importer'   => 'SalesHistoryImporter',
        'status'     => 'running',
        'started_at' => now(),
    ]);
}

function seedSku(string $code = 'FB-001'): Sku
{
    $supplier = Supplier::withoutGlobalScopes()->firstOrCreate(
        ['tenant_id' => 1, 'name' => 'Acme'],
        ['avg_lead_time_days' => 7],
    );

    return Sku::withoutGlobalScopes()->create([
        'tenant_id'      => 1,
        'supplier_id'    => $supplier->id,
        'sku_code'       => $code,
        'name'           => 'Test SKU',
        'category'       => 'accessory',
        'moq'            => 10,
        'unit_cost'      => 1000,
        'reorder_qty'    => 10,
        'current_stock'  => 50,
        'lead_time_days' => 7,
    ]);
}

it('imports valid sales history rows', function () {
    seedSku();

    $path = makeSalesCsv([
        ['FB-001', '2024-04-01', '5', 'false'],
        ['FB-001', '2024-04-02', '3', 'false'],
    ]);
    $run = makeSalesRun();

    (new SalesHistoryImporter())->import(new CsvSource($path), $run, 1);

    $run->refresh();
    expect($run->status)->toBe('completed')
        ->and($run->rows_succeeded)->toBe(2);

    expect(SalesHistory::withoutGlobalScopes()->where('tenant_id', 1)->count())->toBe(2);

    unlink($path);
});

it('aggregates multiple rows for the same SKU and date', function () {
    seedSku();

    $path = makeSalesCsv([
        ['FB-001', '2024-04-01', '3', 'false'],
        ['FB-001', '2024-04-01', '2', 'false'],  // same SKU, same date → sum = 5
    ]);
    $run = makeSalesRun();

    (new SalesHistoryImporter())->import(new CsvSource($path), $run, 1);

    $run->refresh();
    // 2 raw rows → 1 aggregated upsert
    expect($run->rows_succeeded)->toBe(1);

    $row = SalesHistory::withoutGlobalScopes()
        ->where('tenant_id', 1)
        ->where('sale_date', '2024-04-01')
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->quantity_sold)->toBe(5);

    unlink($path);
});

it('rejects rows with unknown sku_code', function () {
    $path = makeSalesCsv([['UNKNOWN-SKU', '2024-04-01', '2', 'false']]);
    $run = makeSalesRun();

    (new SalesHistoryImporter())->import(new CsvSource($path), $run, 1);

    $run->refresh();
    expect($run->status)->toBe('failed')
        ->and($run->rows_failed)->toBe(1)
        ->and($run->error_log[0]['errors'][0])->toContain('not found');

    unlink($path);
});

it('is idempotent — re-importing the same date row produces no duplicate', function () {
    seedSku();

    $path = makeSalesCsv([['FB-001', '2024-04-01', '5', 'false']]);

    (new SalesHistoryImporter())->import(new CsvSource($path), makeSalesRun(), 1);
    (new SalesHistoryImporter())->import(new CsvSource($path), makeSalesRun(), 1);

    expect(
        SalesHistory::withoutGlobalScopes()->where('tenant_id', 1)->where('sale_date', '2024-04-01')->count()
    )->toBe(1);

    unlink($path);
});
