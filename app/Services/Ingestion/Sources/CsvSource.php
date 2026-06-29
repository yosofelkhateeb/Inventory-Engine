<?php

namespace App\Services\Ingestion\Sources;

use App\Services\Ingestion\IngestionSource;
use InvalidArgumentException;
use RuntimeException;

class CsvSource implements IngestionSource
{
    private const SUPPORTED = ['skus', 'suppliers', 'sales_history'];

    public function __construct(private readonly string $filePath)
    {
        $this->guardPath($filePath);
    }

    private function guardPath(string $path): void
    {
        $real = realpath($path);

        if ($real === false) {
            throw new InvalidArgumentException("CSV file does not exist: {$path}");
        }

        $tmpDir = realpath(sys_get_temp_dir());
        $storageDir = realpath(storage_path('app'));

        $underTmp     = $tmpDir     && str_starts_with($real, $tmpDir . DIRECTORY_SEPARATOR);
        $underStorage = $storageDir && str_starts_with($real, $storageDir . DIRECTORY_SEPARATOR);

        if (! $underTmp && ! $underStorage) {
            throw new InvalidArgumentException("CSV path is outside allowed directories: {$path}");
        }
    }

    public function name(): string
    {
        return 'csv_upload';
    }

    public function supports(string $entity): bool
    {
        return in_array($entity, self::SUPPORTED, true);
    }

    public function fetch(string $entity, array $options = []): iterable
    {
        $handle = @fopen($this->filePath, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open CSV file: {$this->filePath}");
        }

        try {
            $rawHeaders = fgetcsv($handle);

            if ($rawHeaders === false || $rawHeaders === null) {
                return;
            }

            $headers = array_map(fn (string $h) => strtolower(trim($h)), $rawHeaders);

            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) === 1 && $row[0] === null) {
                    continue;
                }

                $padded = array_pad($row, count($headers), '');
                yield array_combine($headers, $padded);
            }
        } finally {
            fclose($handle);
        }
    }

    public function naturalKey(string $entity): array
    {
        return match ($entity) {
            'suppliers'     => ['name'],
            'skus'          => ['sku_code'],
            'sales_history' => ['sku_code', 'sale_date'],
            default         => [],
        };
    }

    public function transform(string $entity, array $raw): array
    {
        return match ($entity) {
            'suppliers'     => $this->transformSupplier($raw),
            'skus'          => $this->transformSku($raw),
            'sales_history' => $this->transformSalesHistory($raw),
            default         => $raw,
        };
    }

    private function transformSupplier(array $raw): array
    {
        return [
            'name'               => trim($raw['name'] ?? ''),
            'avg_lead_time_days' => (int) ($raw['stated_lead_time_days'] ?? 0),
        ];
    }

    private function transformSku(array $raw): array
    {
        return [
            'sku_code'      => trim($raw['sku_code'] ?? ''),
            'name'          => trim($raw['name'] ?? ''),
            'category'      => strtolower(trim($raw['category'] ?? '')),
            'supplier_name' => trim($raw['supplier_name'] ?? ''),
            'moq'           => (int) ($raw['moq'] ?? 0),
            'unit_cost'     => (int) round((float) ($raw['unit_cost'] ?? 0) * 100), // SAR → halalas
            'current_stock' => (int) ($raw['current_stock'] ?? 0),
            'lead_time_days' => (int) ($raw['lead_time_days'] ?? 0),
        ];
    }

    private function transformSalesHistory(array $raw): array
    {
        $rawPromo = strtolower(trim($raw['is_promotion'] ?? 'false'));

        return [
            'sku_code'      => trim($raw['sku_code'] ?? ''),
            'sale_date'     => trim($raw['sale_date'] ?? ''),
            'quantity_sold' => (int) ($raw['quantity_sold'] ?? 0),
            'is_promotion'  => in_array($rawPromo, ['true', '1', 'yes'], true),
        ];
    }
}
