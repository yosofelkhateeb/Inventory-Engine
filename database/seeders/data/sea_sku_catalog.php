<?php

/*
|--------------------------------------------------------------------------
| SEA Demo Dataset — SKU & Supplier Catalog
|--------------------------------------------------------------------------
| The 30-SKU South-East-Asian football (soccer) gear catalog driving the
| synthetic demo dataset. Read by SeaDatasetSeeder (Step 4) to create
| suppliers, SKUs, and opening-stock StockAdjustment rows.
|
| Distribution
| ------------
|   Equipment   (6): 4 clean high-volume boots + 2 stopped_selling
|                  mature/EOL boots. Sourced internationally or regionally
|                  (longer lead times for hardware).
|   Accessories (18): 9 clean + 6 sparse (intermittent) + 3 promo_spike.
|                  Mix of grip socks, goalkeeper gloves, shin guards, team
|                  socks, ball pumps. Mostly fast-local supply (faster turnover).
|   Bundles    (6): all promo_spike — bundles primarily move on promotion days.
|                  Assembled locally so fast-local supply.
|
| Pathology values match Tests\Fixtures\ShopifyOrderFactory::PATHOLOGIES.
| Each SKU's base_level is the integer the factory uses to scale its
| per-pathology daily series. The SEA seasonal calendar (Step 1) multiplies
| these values; the campaign generator (Step 2) further boosts promo windows.
|
| Monetary fields stored as integer halalas (SAR × 100) per the project's
| locked decision.
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Suppliers
    |--------------------------------------------------------------------------
    | Five suppliers spanning fast-local (5-7 day lead times) to international
    | slow (28-day lead time with high variance). Keys are referenced by
    | SKU entries via `supplier_key`.
    */
    'suppliers' => [
        'fast_local_a' => [
            'name'               => 'Lion City Fast Suppliers',
            'avg_lead_time_days' => 5,
            'lead_time_stddev'   => 1.5,
        ],
        'fast_local_b' => [
            'name'               => 'KL Express Distribution',
            'avg_lead_time_days' => 7,
            'lead_time_stddev'   => 2.0,
        ],
        'regional_a' => [
            'name'               => 'Bangkok Regional Wholesale',
            'avg_lead_time_days' => 14,
            'lead_time_stddev'   => 3.0,
        ],
        'regional_b' => [
            'name'               => 'Jakarta Bay Trading Co.',
            'avg_lead_time_days' => 18,
            'lead_time_stddev'   => 4.5,
        ],
        'international' => [
            'name'               => 'Shenzhen Global Manufacturing',
            'avg_lead_time_days' => 28,
            'lead_time_stddev'   => 7.0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SKUs
    |--------------------------------------------------------------------------
    | 30 total. Each entry's `pathology` value selects the demand shape from
    | ShopifyOrderFactory::PATHOLOGIES; `base_level` scales the series. The
    | seeder derives opening stock as 1.2 × base_level × supplier.avg_lead_time_days
    | rounded to the nearest 10.
    */
    'skus' => [
        // ─── Equipment (6) ────────────────────────────────────────────────
        // 4 clean high-volume boots
        [
            'sku_code'     => 'SEA-BOO-001',
            'name'         => 'Nike Phantom GX Elite Boots',
            'category'     => 'equipment',
            'pathology'    => 'clean',
            'base_level'   => 22,
            'supplier_key' => 'international',
            'unit_cost'    => 28500, // 285 SAR
            'moq'          => 6,
            'reorder_qty'  => 24,
        ],
        [
            'sku_code'     => 'SEA-BOO-002',
            'name'         => 'Adidas Predator Elite Boots',
            'category'     => 'equipment',
            'pathology'    => 'clean',
            'base_level'   => 20,
            'supplier_key' => 'regional_a',
            'unit_cost'    => 19500,
            'moq'          => 8,
            'reorder_qty'  => 30,
        ],
        [
            'sku_code'     => 'SEA-BOO-003',
            'name'         => 'Puma Future Ultimate Boots',
            'category'     => 'equipment',
            'pathology'    => 'clean',
            'base_level'   => 18,
            'supplier_key' => 'regional_b',
            'unit_cost'    => 22500,
            'moq'          => 6,
            'reorder_qty'  => 24,
        ],
        [
            'sku_code'     => 'SEA-BOO-004',
            'name'         => 'Nike Mercurial Vapor Boots',
            'category'     => 'equipment',
            'pathology'    => 'clean',
            'base_level'   => 24,
            'supplier_key' => 'international',
            'unit_cost'    => 24500,
            'moq'          => 12,
            'reorder_qty'  => 36,
        ],
        // 2 stopped_selling mature/EOL boots
        [
            'sku_code'     => 'SEA-BOO-005',
            'name'         => 'Adidas Copa Mundial Boots',
            'category'     => 'equipment',
            'pathology'    => 'stopped_selling',
            'base_level'   => 14,
            'supplier_key' => 'regional_a',
            'unit_cost'    => 32500,
            'moq'          => 4,
            'reorder_qty'  => 12,
        ],
        [
            'sku_code'     => 'SEA-BOO-006',
            'name'         => 'Umbro Speciali Boots',
            'category'     => 'equipment',
            'pathology'    => 'stopped_selling',
            'base_level'   => 10,
            'supplier_key' => 'international',
            'unit_cost'    => 14500,
            'moq'          => 6,
            'reorder_qty'  => 18,
        ],

        // ─── Accessories (18) ────────────────────────────────────────────
        // Grip socks (5): 3 clean + 1 promo_spike + 1 sparse
        [
            'sku_code'     => 'SEA-GRP-001',
            'name'         => '5-Pair Premium Grip Socks',
            'category'     => 'accessory',
            'pathology'    => 'clean',
            'base_level'   => 12,
            'supplier_key' => 'fast_local_a',
            'unit_cost'    => 4500,
            'moq'          => 24,
            'reorder_qty'  => 96,
        ],
        [
            'sku_code'     => 'SEA-GRP-002',
            'name'         => '5-Pair Anti-Slip Grip Socks',
            'category'     => 'accessory',
            'pathology'    => 'clean',
            'base_level'   => 10,
            'supplier_key' => 'fast_local_a',
            'unit_cost'    => 4800,
            'moq'          => 24,
            'reorder_qty'  => 72,
        ],
        [
            'sku_code'     => 'SEA-GRP-003',
            'name'         => '5-Pair Standard Grip Socks',
            'category'     => 'accessory',
            'pathology'    => 'clean',
            'base_level'   => 14,
            'supplier_key' => 'fast_local_b',
            'unit_cost'    => 3500,
            'moq'          => 48,
            'reorder_qty'  => 120,
        ],
        [
            'sku_code'     => 'SEA-GRP-004',
            'name'         => '10-Pair Bulk Grip Socks',
            'category'     => 'accessory',
            'pathology'    => 'promo_spike',
            'base_level'   => 8,
            'supplier_key' => 'fast_local_b',
            'unit_cost'    => 6500,
            'moq'          => 24,
            'reorder_qty'  => 60,
        ],
        [
            'sku_code'     => 'SEA-GRP-005',
            'name'         => 'Travel-Pack Grip Socks',
            'category'     => 'accessory',
            'pathology'    => 'sparse',
            'base_level'   => 4,
            'supplier_key' => 'fast_local_a',
            'unit_cost'    => 2800,
            'moq'          => 24,
            'reorder_qty'  => 48,
        ],
        // Goalkeeper gloves (3): 1 clean + 2 sparse
        [
            'sku_code'     => 'SEA-GLV-001',
            'name'         => 'Club Goalkeeper Gloves',
            'category'     => 'accessory',
            'pathology'    => 'clean',
            'base_level'   => 9,
            'supplier_key' => 'regional_a',
            'unit_cost'    => 12500,
            'moq'          => 12,
            'reorder_qty'  => 36,
        ],
        [
            'sku_code'     => 'SEA-GLV-002',
            'name'         => 'Junior Goalkeeper Gloves',
            'category'     => 'accessory',
            'pathology'    => 'sparse',
            'base_level'   => 3,
            'supplier_key' => 'fast_local_b',
            'unit_cost'    => 7500,
            'moq'          => 12,
            'reorder_qty'  => 24,
        ],
        [
            'sku_code'     => 'SEA-GLV-003',
            'name'         => 'Pro Latex Goalkeeper Gloves',
            'category'     => 'accessory',
            'pathology'    => 'sparse',
            'base_level'   => 2,
            'supplier_key' => 'international',
            'unit_cost'    => 32500,
            'moq'          => 6,
            'reorder_qty'  => 18,
        ],
        // Shin guards (3): 1 clean + 1 sparse + 1 promo_spike
        [
            'sku_code'     => 'SEA-SHN-001',
            'name'         => 'Lightweight Slip-In Shin Guards',
            'category'     => 'accessory',
            'pathology'    => 'clean',
            'base_level'   => 8,
            'supplier_key' => 'fast_local_a',
            'unit_cost'    => 3800,
            'moq'          => 24,
            'reorder_qty'  => 60,
        ],
        [
            'sku_code'     => 'SEA-SHN-002',
            'name'         => 'Ankle-Protect Shin Guards',
            'category'     => 'accessory',
            'pathology'    => 'sparse',
            'base_level'   => 4,
            'supplier_key' => 'regional_b',
            'unit_cost'    => 4500,
            'moq'          => 24,
            'reorder_qty'  => 48,
        ],
        [
            'sku_code'     => 'SEA-SHN-003',
            'name'         => 'Carbon-Shell Shin Guards',
            'category'     => 'accessory',
            'pathology'    => 'promo_spike',
            'base_level'   => 6,
            'supplier_key' => 'fast_local_a',
            'unit_cost'    => 3500,
            'moq'          => 24,
            'reorder_qty'  => 48,
        ],
        // Team socks (4): 2 clean + 1 sparse + 1 promo_spike
        [
            'sku_code'     => 'SEA-SOK-001',
            'name'         => 'Cushioned Team Socks',
            'category'     => 'accessory',
            'pathology'    => 'clean',
            'base_level'   => 13,
            'supplier_key' => 'fast_local_a',
            'unit_cost'    => 4200,
            'moq'          => 24,
            'reorder_qty'  => 72,
        ],
        [
            'sku_code'     => 'SEA-SOK-002',
            'name'         => 'Pro Match Team Socks',
            'category'     => 'accessory',
            'pathology'    => 'clean',
            'base_level'   => 11,
            'supplier_key' => 'fast_local_b',
            'unit_cost'    => 4800,
            'moq'          => 24,
            'reorder_qty'  => 60,
        ],
        [
            'sku_code'     => 'SEA-SOK-003',
            'name'         => 'Striped Home Team Socks',
            'category'     => 'accessory',
            'pathology'    => 'sparse',
            'base_level'   => 5,
            'supplier_key' => 'regional_a',
            'unit_cost'    => 5200,
            'moq'          => 12,
            'reorder_qty'  => 36,
        ],
        [
            'sku_code'     => 'SEA-SOK-004',
            'name'         => 'Compression Team Socks',
            'category'     => 'accessory',
            'pathology'    => 'promo_spike',
            'base_level'   => 7,
            'supplier_key' => 'fast_local_a',
            'unit_cost'    => 5500,
            'moq'          => 24,
            'reorder_qty'  => 60,
        ],
        // Ball pumps & inflation (3): 2 clean + 1 sparse
        [
            'sku_code'     => 'SEA-PMP-001',
            'name'         => 'Dual-Action Ball Pump',
            'category'     => 'accessory',
            'pathology'    => 'clean',
            'base_level'   => 10,
            'supplier_key' => 'fast_local_b',
            'unit_cost'    => 3800,
            'moq'          => 24,
            'reorder_qty'  => 60,
        ],
        [
            'sku_code'     => 'SEA-PMP-002',
            'name'         => 'Mini Hand Ball Pump',
            'category'     => 'accessory',
            'pathology'    => 'clean',
            'base_level'   => 9,
            'supplier_key' => 'fast_local_a',
            'unit_cost'    => 3500,
            'moq'          => 24,
            'reorder_qty'  => 60,
        ],
        [
            'sku_code'     => 'SEA-PMP-003',
            'name'         => 'Pump Needle & Valve Set',
            'category'     => 'accessory',
            'pathology'    => 'sparse',
            'base_level'   => 3,
            'supplier_key' => 'regional_b',
            'unit_cost'    => 4200,
            'moq'          => 12,
            'reorder_qty'  => 36,
        ],

        // ─── Bundles (6) ─────────────────────────────────────────────────
        [
            'sku_code'     => 'SEA-BND-001',
            'name'         => 'Complete Match-Day Starter Bundle',
            'category'     => 'bundle',
            'pathology'    => 'promo_spike',
            'base_level'   => 6,
            'supplier_key' => 'fast_local_a',
            'unit_cost'    => 32500,
            'moq'          => 6,
            'reorder_qty'  => 18,
        ],
        [
            'sku_code'     => 'SEA-BND-002',
            'name'         => 'Junior Player Gift Set',
            'category'     => 'bundle',
            'pathology'    => 'promo_spike',
            'base_level'   => 5,
            'supplier_key' => 'fast_local_a',
            'unit_cost'    => 21500,
            'moq'          => 6,
            'reorder_qty'  => 18,
        ],
        [
            'sku_code'     => 'SEA-BND-003',
            'name'         => 'Travel Training Bundle',
            'category'     => 'bundle',
            'pathology'    => 'promo_spike',
            'base_level'   => 4,
            'supplier_key' => 'fast_local_b',
            'unit_cost'    => 18500,
            'moq'          => 6,
            'reorder_qty'  => 12,
        ],
        [
            'sku_code'     => 'SEA-BND-004',
            'name'         => 'Goalkeeper Starter Bundle',
            'category'     => 'bundle',
            'pathology'    => 'promo_spike',
            'base_level'   => 5,
            'supplier_key' => 'fast_local_a',
            'unit_cost'    => 25500,
            'moq'          => 6,
            'reorder_qty'  => 18,
        ],
        [
            'sku_code'     => 'SEA-BND-005',
            'name'         => 'Premium Club Gift Set',
            'category'     => 'bundle',
            'pathology'    => 'promo_spike',
            'base_level'   => 4,
            'supplier_key' => 'fast_local_b',
            'unit_cost'    => 42500,
            'moq'          => 4,
            'reorder_qty'  => 12,
        ],
        [
            'sku_code'     => 'SEA-BND-006',
            'name'         => 'Hari Raya Gift Bundle',
            'category'     => 'bundle',
            'pathology'    => 'promo_spike',
            'base_level'   => 7,
            'supplier_key' => 'fast_local_a',
            'unit_cost'    => 28500,
            'moq'          => 6,
            'reorder_qty'  => 18,
        ],
    ],
];
