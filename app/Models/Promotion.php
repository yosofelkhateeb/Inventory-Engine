<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Promotion extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'promotion_type',
        'start_date',
        'end_date',
        // Engine output — written by the Layered Hybrid Prediction Engine.
        // SARIMAX and the post-forecast multiplier in main.py read this field
        // unchanged; the redesign only changes what computes its value.
        'expected_uplift_pct',
        // Operator override path. When manual_uplift_pct is non-null it wins
        // over the engine's prediction; override_reason is required at the
        // controller layer when manual_uplift_pct is set.
        'manual_uplift_pct',
        'override_reason',
        // Campaign Brief — cause-side fields the prediction engine consumes.
        'discount_pct',
        'discount_type',
        'channel_mix',
        'ad_spend_band',
        'audience',
        'lead_announcement_days',
        // Targeting (existing).
        'affects_all_skus',
        'applies_to_categories',
    ];

    protected function casts(): array
    {
        return [
            'start_date'             => 'date',
            'end_date'               => 'date',
            'expected_uplift_pct'    => 'float',
            'manual_uplift_pct'      => 'float',
            'discount_pct'           => 'float',
            'channel_mix'            => 'array',
            'lead_announcement_days' => 'integer',
            'affects_all_skus'       => 'boolean',
            'applies_to_categories'  => 'array',
        ];
    }

    // Reference enum sets — used by validators and the Vue form.
    public const DISCOUNT_TYPES = [
        'percent_off',
        'BOGO',
        'free_shipping',
        'bundle_pricing',
        'fixed_amount_off',
    ];

    public const CHANNEL_TAGS = [
        'paid_social',
        'email',
        'organic_social',
        'in_store',
        'influencer',
        'display_ads',
        'sms',
    ];

    public const AD_SPEND_BANDS = ['none', 'low', 'mid', 'high', 'very_high'];

    public const AUDIENCES = ['existing_customers', 'new_acquisition', 'both'];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
        static::creating(function (self $model) {
            if (empty($model->tenant_id)) {
                $model->tenant_id = TenantContext::tenantId();
            }
        });
    }

    public function skus(): BelongsToMany
    {
        return $this->belongsToMany(Sku::class, 'promotion_skus');
    }
}
