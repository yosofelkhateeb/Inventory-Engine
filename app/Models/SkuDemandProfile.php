<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkuDemandProfile extends Model
{
    protected $table = 'sku_demand_profiles';

    protected $fillable = [
        'tenant_id',
        'sku_id',
        'volume_tier',
        'volatility',
        'intermittency',
        'seasonality_detected',
        'trend_direction',
        'history_days_used',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
        static::creating(function (self $model) {
            if (empty($model->tenant_id)) {
                $model->tenant_id = TenantContext::tenantId();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'seasonality_detected' => 'boolean',
            'history_days_used'    => 'integer',
        ];
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
