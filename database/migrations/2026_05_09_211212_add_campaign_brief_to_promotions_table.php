<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the Campaign Brief — the structured cause-side inputs that replace
 * the old typed-from-gut `expected_uplift_pct` primary input. The engine
 * reads these fields and writes its prediction back to expected_uplift_pct
 * (kept as the engine's output for backward compat with SARIMAX and the
 * post-forecast multiplier in main.py:_apply_promo_uplift).
 *
 * See ~/.gstack/projects/yosofelkhateeb-Procurement_Project/hp-main-design-20260507-190736.md
 * for the full design — Recommended Approach: B' — Layered Hybrid Prediction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            // ─── Campaign Brief — cause-side fields ─────────────────────────
            // Headline discount (e.g. 20.00 = "20% off"). Decimal so we can
            // express fractional discounts like 12.50%.
            $table->decimal('discount_pct', 5, 2)
                ->nullable()
                ->after('promotion_type');

            // Discount mechanism. Nullable until backfilled — existing
            // promotion rows pre-Brief don't have this field.
            $table->enum('discount_type', [
                'percent_off',
                'BOGO',
                'free_shipping',
                'bundle_pricing',
                'fixed_amount_off',
            ])->nullable()->after('discount_pct');

            // JSON array of channel-mix tags. Multi-select on the frontend.
            // Per-tenant tags would need a lookup table; for v1 the enum-like
            // tag set is fixed and documented in the model's $brief_channels.
            $table->json('channel_mix')->nullable()->after('discount_type');

            // Spend band — bucket rather than absolute amount. Bands are
            // configurable per tenant in system_settings under uplift.spend_band.*
            // so the same enum value (`mid`) can mean different SAR amounts
            // for different operations.
            $table->enum('ad_spend_band', [
                'none',
                'low',
                'mid',
                'high',
                'very_high',
            ])->nullable()->after('channel_mix');

            // Audience targeting.
            $table->enum('audience', [
                'existing_customers',
                'new_acquisition',
                'both',
            ])->nullable()->after('ad_spend_band');

            // Days between announcement and start. 0 = surprise launch.
            $table->unsignedSmallInteger('lead_announcement_days')
                ->nullable()
                ->after('audience');

            // ─── Operator override ──────────────────────────────────────────
            // When set, manual_uplift_pct wins over the engine's prediction.
            // override_reason is required when manual_uplift_pct is non-null
            // (validated at the controller layer, not the schema).
            $table->decimal('manual_uplift_pct', 5, 2)
                ->nullable()
                ->after('expected_uplift_pct');

            $table->string('override_reason', 500)
                ->nullable()
                ->after('manual_uplift_pct');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn([
                'discount_pct',
                'discount_type',
                'channel_mix',
                'ad_spend_band',
                'audience',
                'lead_announcement_days',
                'manual_uplift_pct',
                'override_reason',
            ]);
        });
    }
};
