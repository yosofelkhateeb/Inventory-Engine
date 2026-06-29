<?php

namespace Tests\Fixtures;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Shopify order-shape fixture factory.
 *
 * Produces order JSON arrays that match Shopify's Admin REST `orders.json`
 * payload (the subset consumed by `ShopifySource::fetchOrderLineItems`).
 * Fields we populate are the ones the pipeline actually sees after
 * `ShopifySource::transform`:
 *   - created_at   → sale_date
 *   - line_items[].sku
 *   - line_items[].quantity
 *   - discount_codes (non-empty → is_promotion = true)
 *   - refunds[].refund_line_items[].quantity (negative net qty)
 *
 * We synthesize these, not Shopify's full schema — shipping addresses,
 * tax lines, etc. are irrelevant to forecasting. Everything is seeded
 * from a fixed RNG so each pathology is reproducible.
 *
 * Pathologies model the real-world shapes Shopify syncs can produce:
 *   - clean          baseline weekly-seasonal demand
 *   - sparse         low-volume intermittent (Croston territory)
 *   - stockout_gaps  long zero runs inside the series
 *   - promo_spike    steady demand with 3× spikes on discount-code days
 *   - returns_heavy  ~15% of orders partially/fully refunded
 *   - stopped_selling active for ~60% of the window, then dies
 *   - new_sku        only the last 30 days present
 */
final class ShopifyOrderFactory
{
    public const PATHOLOGIES = [
        'clean',
        'sparse',
        'stockout_gaps',
        'promo_spike',
        'returns_heavy',
        'stopped_selling',
        'new_sku',
    ];

    private Randomizer $rng;

    public function __construct(int $seed = 42)
    {
        $this->rng = new Randomizer(new Mt19937($seed));
    }

    /**
     * Build a list of Shopify-shape orders for a given SKU over a date range
     * that expresses the named pathology.
     *
     * @param  string                            $pathology One of self::PATHOLOGIES
     * @param  string                            $skuCode   SKU the orders reference
     * @param  \DateTimeInterface                $from      Inclusive start date
     * @param  \DateTimeInterface                $to        Inclusive end date
     * @param  int                               $baseLevel Base daily quantity (scales the pattern)
     * @return array<int, array<string, mixed>>             Array of order JSON arrays
     */
    public function ordersFor(
        string $pathology,
        string $skuCode,
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        int $baseLevel = 10,
    ): array {
        if (! in_array($pathology, self::PATHOLOGIES, true)) {
            throw new \InvalidArgumentException("Unknown pathology: {$pathology}");
        }

        $days = $this->dateRange($from, $to);

        $quantities = match ($pathology) {
            'clean'           => $this->cleanSeries($days, $baseLevel),
            'sparse'          => $this->sparseSeries($days),
            'stockout_gaps'   => $this->stockoutSeries($days, $baseLevel),
            'promo_spike'     => $this->promoSpikeSeries($days, $baseLevel),
            'returns_heavy'   => $this->cleanSeries($days, $baseLevel),
            'stopped_selling' => $this->stoppedSellingSeries($days, $baseLevel),
            'new_sku'         => $this->newSkuSeries($days, $baseLevel),
        };

        $orders = [];
        $orderId = 1_000_000 + $this->rng->getInt(0, 999_999);

        // Promo days: only for promo_spike pathology
        $promoDays = $pathology === 'promo_spike'
            ? $this->promoWindowDays($days)
            : [];

        foreach ($days as $i => $day) {
            $qty = $quantities[$i];

            if ($qty <= 0) {
                continue;
            }

            $isPromo = in_array($day->format('Y-m-d'), $promoDays, true);
            $order   = $this->buildOrder($orderId++, $day, $skuCode, $qty, $isPromo);

            // returns_heavy: randomly refund ~15% of orders, full or partial
            if ($pathology === 'returns_heavy' && $this->rng->getInt(1, 100) <= 15) {
                $refundQty = $this->rng->getInt(1, $qty);
                $order     = $this->addRefund($order, $refundQty);
            }

            $orders[] = $order;
        }

        return $orders;
    }

    /**
     * Convert Shopify-shape orders into sales_history rows via the same
     * transform the production connector uses. Guarantees the fixture
     * path matches prod.
     *
     * @param  array<int, array<string, mixed>> $orders
     * @return array<int, array{sku_code:string, sale_date:string, quantity_sold:int, is_promotion:bool}>
     */
    public function toSalesHistory(array $orders): array
    {
        $byKey = [];

        foreach ($orders as $order) {
            $saleDate    = (new \DateTimeImmutable($order['created_at']))->format('Y-m-d');
            $isPromotion = ! empty($order['discount_codes']);

            $refundedByLine = [];
            foreach ($order['refunds'] ?? [] as $refund) {
                foreach ($refund['refund_line_items'] ?? [] as $rli) {
                    $refundedByLine[$rli['line_item_id']] =
                        ($refundedByLine[$rli['line_item_id']] ?? 0) + (int) $rli['quantity'];
                }
            }

            foreach ($order['line_items'] ?? [] as $item) {
                if (empty($item['sku'])) {
                    continue;
                }
                $refunded = $refundedByLine[$item['id']] ?? 0;
                $netQty   = max(0, (int) $item['quantity'] - (int) $refunded);

                if ($netQty === 0) {
                    continue;
                }

                $key = $item['sku'] . '|' . $saleDate;
                if (! isset($byKey[$key])) {
                    $byKey[$key] = [
                        'sku_code'      => (string) $item['sku'],
                        'sale_date'     => $saleDate,
                        'quantity_sold' => 0,
                        'is_promotion'  => $isPromotion,
                    ];
                }
                $byKey[$key]['quantity_sold'] += $netQty;
                $byKey[$key]['is_promotion']   = $byKey[$key]['is_promotion'] || $isPromotion;
            }
        }

        return array_values($byKey);
    }

    // ── Series generators ──────────────────────────────────────────────────

    /** @return array<int, int> */
    private function cleanSeries(array $days, int $baseLevel): array
    {
        $qtys = [];
        foreach ($days as $i => $day) {
            $dow      = (int) $day->format('w');
            $seasonal = (int) round(0.3 * $baseLevel * sin(2 * M_PI * $dow / 7));
            $noise    = $this->rng->getInt(-1, 1);
            $qtys[]   = max(0, $baseLevel + $seasonal + $noise);
        }
        return $qtys;
    }

    /** @return array<int, int> */
    private function sparseSeries(array $days): array
    {
        // ~15% of days have a sale of 1-3 units, rest zero
        $qtys = [];
        foreach ($days as $_) {
            $qtys[] = $this->rng->getInt(1, 100) <= 15 ? $this->rng->getInt(1, 3) : 0;
        }
        return $qtys;
    }

    /** @return array<int, int> */
    private function stockoutSeries(array $days, int $baseLevel): array
    {
        $qtys = $this->cleanSeries($days, $baseLevel);
        $n    = count($qtys);

        // Two stockout windows: 10-14 days each, at ~30% and ~70% through the series
        $windows = [
            [(int) round($n * 0.30), $this->rng->getInt(10, 14)],
            [(int) round($n * 0.70), $this->rng->getInt(10, 14)],
        ];
        foreach ($windows as [$start, $len]) {
            for ($i = $start; $i < min($n, $start + $len); $i++) {
                $qtys[$i] = 0;
            }
        }
        return $qtys;
    }

    /** @return array<int, int> */
    private function promoSpikeSeries(array $days, int $baseLevel): array
    {
        $qtys      = $this->cleanSeries($days, $baseLevel);
        $promoDays = $this->promoWindowDays($days);
        $index     = [];
        foreach ($days as $i => $day) {
            $index[$day->format('Y-m-d')] = $i;
        }
        foreach ($promoDays as $iso) {
            if (isset($index[$iso])) {
                $qtys[$index[$iso]] = (int) round($qtys[$index[$iso]] * 3);
            }
        }
        return $qtys;
    }

    /** @return array<int, int> */
    private function stoppedSellingSeries(array $days, int $baseLevel): array
    {
        $qtys = $this->cleanSeries($days, $baseLevel);
        $n    = count($qtys);
        // Active for ~60%, then dies completely. The trailing-zero guardrail
        // we added in data_audit.py should fire on this shape.
        $cutoff = (int) round($n * 0.60);
        for ($i = $cutoff; $i < $n; $i++) {
            $qtys[$i] = 0;
        }
        return $qtys;
    }

    /** @return array<int, int> */
    private function newSkuSeries(array $days, int $baseLevel): array
    {
        $qtys = array_fill(0, count($days), 0);
        $n    = count($qtys);
        $start = max(0, $n - 30);
        for ($i = $start; $i < $n; $i++) {
            $dow     = $i % 7;
            $qtys[$i] = max(0, $baseLevel + (int) round(0.3 * $baseLevel * sin(2 * M_PI * $dow / 7)) + $this->rng->getInt(-1, 1));
        }
        return $qtys;
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** @return array<int, \DateTimeImmutable> */
    private function dateRange(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $days    = [];
        $current = \DateTimeImmutable::createFromFormat('Y-m-d', $from->format('Y-m-d'));
        $stop    = \DateTimeImmutable::createFromFormat('Y-m-d', $to->format('Y-m-d'));

        while ($current <= $stop) {
            $days[]  = $current;
            $current = $current->modify('+1 day');
        }
        return $days;
    }

    /**
     * Returns ISO dates covering three 5-day promo windows spaced through
     * the series at ~25%, 50%, 75%.
     *
     * @param  array<int, \DateTimeImmutable> $days
     * @return array<int, string>
     */
    private function promoWindowDays(array $days): array
    {
        $n       = count($days);
        $anchors = [(int) round($n * 0.25), (int) round($n * 0.50), (int) round($n * 0.75)];
        $out     = [];
        foreach ($anchors as $start) {
            for ($i = $start; $i < min($n, $start + 5); $i++) {
                $out[] = $days[$i]->format('Y-m-d');
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function buildOrder(int $orderId, \DateTimeInterface $day, string $skuCode, int $qty, bool $isPromo): array
    {
        $lineItemId = $orderId * 10 + 1;

        return [
            'id'             => $orderId,
            'name'           => '#' . $orderId,
            'created_at'     => $day->format('Y-m-d') . 'T12:00:00Z',
            'updated_at'     => $day->format('Y-m-d') . 'T12:00:00Z',
            'currency'       => 'USD',
            'financial_status'   => 'paid',
            'fulfillment_status' => 'fulfilled',
            'line_items'     => [[
                'id'       => $lineItemId,
                'sku'      => $skuCode,
                'quantity' => $qty,
                'title'    => "Product {$skuCode}",
                'price'    => '10.00',
            ]],
            'discount_codes' => $isPromo ? [[
                'code'   => 'SPRING10',
                'amount' => '10.00',
                'type'   => 'percentage',
            ]] : [],
            'refunds' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function addRefund(array $order, int $refundedQty): array
    {
        $lineItem = $order['line_items'][0];
        $order['refunds'] = [[
            'id'         => $order['id'] * 10 + 9,
            'created_at' => $order['created_at'],
            'refund_line_items' => [[
                'id'           => $order['id'] * 10 + 8,
                'line_item_id' => $lineItem['id'],
                'quantity'     => $refundedQty,
                'restock_type' => 'return',
            ]],
        ]];
        return $order;
    }
}
