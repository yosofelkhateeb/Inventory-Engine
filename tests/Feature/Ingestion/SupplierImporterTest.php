<?php

use App\Models\DataIngestionRun;
use App\Models\Supplier;
use App\Services\Ingestion\Importers\SupplierImporter;
use App\Services\Ingestion\Sources\CsvSource;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeSupplierCsv(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'sup_') . '.csv';
    $handle = fopen($path, 'w');
    fputcsv($handle, ['name', 'stated_lead_time_days', 'notes']);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);
    return $path;
}

function makeRun(): DataIngestionRun
{
    return DataIngestionRun::withoutGlobalScopes()->create([
        'tenant_id'  => 1,
        'source'     => 'csv_upload',
        'importer'   => 'SupplierImporter',
        'status'     => 'running',
        'started_at' => now(),
    ]);
}

it('imports valid suppliers and marks run completed', function () {
    $path = makeSupplierCsv([['Acme', '7', 'notes'], ['Beta Supply', '14', '']]);
    $run = makeRun();

    (new SupplierImporter())->import(new CsvSource($path), $run, 1);

    expect(Supplier::withoutGlobalScopes()->where('tenant_id', 1)->count())->toBe(2);
    $run->refresh();
    expect($run->status)->toBe('completed')
        ->and($run->rows_processed)->toBe(2)
        ->and($run->rows_succeeded)->toBe(2)
        ->and($run->rows_failed)->toBe(0);

    unlink($path);
});

it('is idempotent — re-importing the same file produces no duplicates', function () {
    $path = makeSupplierCsv([['Acme', '7', '']]);
    $run1 = makeRun();
    $run2 = makeRun();

    (new SupplierImporter())->import(new CsvSource($path), $run1, 1);
    (new SupplierImporter())->import(new CsvSource($path), $run2, 1);

    expect(Supplier::withoutGlobalScopes()->where('tenant_id', 1)->count())->toBe(1);

    unlink($path);
});

it('reports validation errors per row without failing the whole import', function () {
    $path = makeSupplierCsv([
        ['', '7', ''],          // missing name
        ['Good Supplier', '5', ''],
    ]);
    $run = makeRun();

    (new SupplierImporter())->import(new CsvSource($path), $run, 1);

    $run->refresh();
    expect($run->status)->toBe('partial')
        ->and($run->rows_failed)->toBe(1)
        ->and($run->rows_succeeded)->toBe(1)
        ->and($run->error_log[0]['row'])->toBe(1);

    unlink($path);
});

it('dry-run validates without writing', function () {
    $path = makeSupplierCsv([['Acme', '7', '']]);
    $run = makeRun();

    (new SupplierImporter())->import(new CsvSource($path), $run, 1, ['dry_run' => true]);

    expect(Supplier::withoutGlobalScopes()->where('tenant_id', 1)->count())->toBe(0);
    $run->refresh();
    expect($run->rows_succeeded)->toBe(0)
        ->and($run->rows_processed)->toBe(1);

    unlink($path);
});
