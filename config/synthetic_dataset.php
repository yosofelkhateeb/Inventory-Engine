<?php

/*
|--------------------------------------------------------------------------
| Synthetic Dataset Configuration
|--------------------------------------------------------------------------
| All numerical levers for the RealisticDatasetSimulator. Per the project's
| locked decision that numerical parameters are tunable, every multiplier,
| threshold, and distribution parameter lives here — never in PHP literals
| inside the profile or simulator classes.
|
| Calibration: seasonal multipliers anchored to public SEA mega-sale-day
| trend reports (Lazada/Shopee public summaries 2023–2024). The 11.11
| anchor of 3.0× is the strongest single-day uplift in regional e-commerce.
| Verify and re-tune once real Shopify ingestion is live.
*/

return [
    // Deterministic seed. Bumping this regenerates the entire dataset.
    'rng_seed' => 42,

    // 30 months back from 2026-05-12 → 2023-11-12 = 913 days.
    'window_days' => 913,

    // Demo tenant. The synthetic dataset never seeds beyond tenant_id = 1.
    'tenant_id' => 1,

    /*
    |--------------------------------------------------------------------------
    | Pathology Mix (per ShopifyFixtureGenerator)
    |--------------------------------------------------------------------------
    | The SEA demo dataset rides on Tests\Fixtures\ShopifyFixtureGenerator's
    | pathology system. Weights here override the fixture's DEFAULT_MIX to
    | tune the demo-specific shape — heavier on promo_spike (Layer 3 LightGBM
    | training corpus) and lighter on returns_heavy (out of scope for the
    | demo). Sum is renormalized at draw time.
    */
    'pathology_mix' => [
        'clean'           => 14, // continuous, low-noise
        'promo_spike'     => 8,  // demand with seasonal/promo spikes
        'sparse'          => 4,  // intermittent / Croston territory
        'stockout_gaps'   => 2,
        'stopped_selling' => 2,  // declining/mature SKUs
        'new_sku'         => 0,  // skip — confuses the model selector on a small catalogue
        'returns_heavy'   => 0,  // skip — returns are out of scope for the engine
    ],

    /*
    |--------------------------------------------------------------------------
    | SEA Seasonal Multipliers
    |--------------------------------------------------------------------------
    | Hardcoded multipliers per event. Calendar dates live in the
    | SeaSeasonalCalendar service (Step 2) — this file holds the magnitudes.
    | Overlapping events MAX-compose, not multiply, to prevent unrealistic
    | stacking (e.g., CNY + 11.11 would otherwise hit 5.4×).
    */
    'seasonal_calendar' => [
        'cny_peak'              => 1.8,
        'cny_runup'             => 1.3,
        'hari_raya_puasa_peak'  => 1.5,
        'hari_raya_puasa_runup' => 1.3,
        'hari_raya_haji_peak'   => 1.2,
        'songkran'              => 1.15,
        'singapore_natl_day'    => 1.10,
        'mega_99'               => 2.5,
        'mega_99_runup'         => 1.4,
        'mega_99_runoff'        => 1.2,
        'mega_1010'             => 2.5,
        'mega_1010_runup'       => 1.4,
        'mega_1111'             => 3.0, // anchor — strongest single-day uplift
        'mega_1111_runup'       => 1.6,
        'mega_1111_runoff'      => 1.3,
        'mega_1212'             => 2.3,
        'mega_1212_runup'       => 1.3,
        'black_friday'          => 1.8,
        'cyber_monday'          => 1.5,
        'christmas_window'      => 1.4,
        'monsoon_dampening'     => 0.90,
    ],

    // Mon=index 0 ... Sun=index 6. Weekend lift typical for SEA Shopify stores.
    'day_of_week_multipliers' => [1.0, 1.0, 1.05, 1.05, 1.1, 1.25, 1.15],

    // Per-day multiplicative jitter — N(1.0, this stddev) applied after all multipliers.
    'multiplicative_noise_stddev' => 0.08,

    /*
    |--------------------------------------------------------------------------
    | Promotion Campaign Generator
    |--------------------------------------------------------------------------
    | Used by SeaPromotionCampaignGenerator (Step 3). Target count is the
    | mean of a uniform stochastic spread; the floor (50) is the
    | uplift.min_ml_samples threshold that activates Layer 3 LightGBM.
    */
    'promotions' => [
        'target_count'             => 65,
        'count_tolerance'          => 5,
        'min_inter_promo_gap_days' => 31,
        'targeting_split'          => [
            'all_skus_pct'   => 50,
            'categories_pct' => 30,
            'specific_pct'   => 20,
        ],
        'lift_ranges' => [
            'flash'     => [40, 80],
            'clearance' => [25, 60],
            'seasonal'  => [20, 45],
            'bundle'    => [10, 30],
            'other'     => [8, 25],
        ],
    ],
];
