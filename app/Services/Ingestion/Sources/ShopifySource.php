<?php

namespace App\Services\Ingestion\Sources;

use App\Services\Ingestion\IngestionSource;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class ShopifySource implements IngestionSource
{
    private const API_VERSION = '2024-01';
    private const MAX_RETRIES = 3;

    public function __construct(
        private readonly string  $shopDomain,
        private readonly string  $accessToken,
        private readonly ?string $updatedAtMin = null,
    ) {}

    public function name(): string
    {
        return 'shopify';
    }

    public function supports(string $entity): bool
    {
        return in_array($entity, ['skus', 'sales_history'], true);
    }

    public function fetch(string $entity, array $options = []): iterable
    {
        return match ($entity) {
            'skus'          => $this->fetchProducts(),
            'sales_history' => $this->fetchOrderLineItems(),
            default         => [],
        };
    }

    public function naturalKey(string $entity): array
    {
        return match ($entity) {
            'skus'          => ['sku_code'],
            'sales_history' => ['sku_code', 'sale_date'],
            default         => [],
        };
    }

    public function transform(string $entity, array $raw): array
    {
        return match ($entity) {
            'skus'          => $this->transformVariant($raw),
            'sales_history' => $this->transformLineItem($raw),
            default         => $raw,
        };
    }

    private function fetchProducts(): \Generator
    {
        $url = "https://{$this->shopDomain}/admin/api/" . self::API_VERSION . '/products.json';
        $params = ['limit' => 250, 'fields' => 'id,title,product_type,variants'];

        while ($url !== null) {
            $response = $this->shopifyRequest('GET', $url, $params);
            $params = [];

            foreach ($response->json('products', []) as $product) {
                foreach ($product['variants'] ?? [] as $variant) {
                    if (empty($variant['sku'])) {
                        continue;
                    }
                    yield [
                        'sku_code'      => (string) $variant['sku'],
                        'name'          => $this->variantTitle($product, $variant),
                        'product_type'  => (string) ($product['product_type'] ?? ''),
                        'current_stock' => (int) ($variant['inventory_quantity'] ?? 0),
                        'price'         => (string) ($variant['price'] ?? '0'),
                    ];
                }
            }

            $url = $this->nextPageUrl($response->header('Link'));
        }
    }

    private function fetchOrderLineItems(): \Generator
    {
        $url = "https://{$this->shopDomain}/admin/api/" . self::API_VERSION . '/orders.json';
        $params = array_filter([
            'status'         => 'any',
            'limit'          => 250,
            'updated_at_min' => $this->updatedAtMin,
            'fields'         => 'created_at,discount_codes,line_items',
        ]);

        while ($url !== null) {
            $response = $this->shopifyRequest('GET', $url, $params);
            $params = [];

            foreach ($response->json('orders', []) as $order) {
                $saleDate    = Carbon::parse($order['created_at'])->format('Y-m-d');
                $isPromotion = ! empty($order['discount_codes']);

                foreach ($order['line_items'] ?? [] as $item) {
                    if (empty($item['sku'])) {
                        continue;
                    }
                    yield [
                        'sku_code'      => (string) $item['sku'],
                        'sale_date'     => $saleDate,
                        'quantity_sold' => max(0, (int) ($item['quantity'] ?? 0)),
                        'is_promotion'  => $isPromotion,
                    ];
                }
            }

            $url = $this->nextPageUrl($response->header('Link'));
        }
    }

    private function shopifyRequest(string $method, string $url, array $params = []): \Illuminate\Http\Client\Response
    {
        $retries = 0;
        $delay   = 1;

        while (true) {
            $response = Http::withHeaders(['X-Shopify-Access-Token' => $this->accessToken])
                ->{strtolower($method)}($url, $params);

            if ($response->status() === 429) {
                if ($retries >= self::MAX_RETRIES) {
                    $response->throw();
                }
                sleep($delay);
                $delay   *= 2;
                $retries++;
                continue;
            }

            $response->throw();

            return $response;
        }
    }

    private function nextPageUrl(?string $linkHeader): ?string
    {
        if (! $linkHeader) {
            return null;
        }

        if (! preg_match('/<([^>]+)>;\s*rel="next"/', $linkHeader, $matches)) {
            return null;
        }

        $candidate = $matches[1];

        // Defence-in-depth (CSO finding T1, 2026-05-02): if the Shopify
        // server (or anything in the TLS path between us and the connected
        // shop) returns a Link header pointing at a host that isn't the
        // shop_domain we authenticated against, refusing the redirect
        // prevents the X-Shopify-Access-Token from being sent to that host.
        // Today the regex on shop_domain at connect time pins it to
        // *.myshopify.com, so a malicious mirror would need to MITM TLS —
        // unlikely, but the check is two lines and removes the footgun.
        $host = parse_url($candidate, PHP_URL_HOST);
        if ($host === null || strcasecmp($host, $this->shopDomain) !== 0) {
            return null;
        }

        return $candidate;
    }

    private function variantTitle(array $product, array $variant): string
    {
        if (isset($variant['title']) && $variant['title'] !== 'Default Title') {
            return $product['title'] . ' – ' . $variant['title'];
        }

        return $product['title'] ?? '';
    }

    private function transformVariant(array $raw): array
    {
        return [
            'sku_code'      => $raw['sku_code'],
            'name'          => $raw['name'],
            'category'      => $this->mapCategory($raw['product_type']),
            'current_stock' => max(0, (int) $raw['current_stock']),
            'unit_cost'     => (int) round((float) str_replace(',', '', $raw['price']) * 100),
        ];
    }

    private function transformLineItem(array $raw): array
    {
        return [
            'sku_code'      => $raw['sku_code'],
            'sale_date'     => $raw['sale_date'],
            'quantity_sold' => max(0, (int) $raw['quantity_sold']),
            'is_promotion'  => (bool) $raw['is_promotion'],
        ];
    }

    private function mapCategory(string $productType): string
    {
        $type = strtolower(trim($productType));
        if (str_contains($type, 'equipment') || str_contains($type, 'boot')) {
            return 'equipment';
        }
        if (str_contains($type, 'bundle') || str_contains($type, 'kit')) {
            return 'bundle';
        }

        return 'accessory';
    }
}
