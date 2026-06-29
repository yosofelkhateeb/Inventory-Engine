<?php

namespace App\Services\Ingestion;

interface IngestionSource
{
    public function name(): string;

    public function supports(string $entity): bool;

    /** @return iterable<array<string, string>> */
    public function fetch(string $entity, array $options = []): iterable;

    /** @return string[] */
    public function naturalKey(string $entity): array;

    /** @return array<string, mixed> */
    public function transform(string $entity, array $raw): array;
}
