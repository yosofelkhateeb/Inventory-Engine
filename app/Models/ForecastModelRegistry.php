<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForecastModelRegistry extends Model
{
    protected $table = 'forecast_model_registry';

    protected $fillable = [
        'tenant_id',
        'sku_id',
        'model_name',
        'demand_rate',
        'forecast_horizon_days',
        'mae',
        'rmse',
        'bias',
        'smape',
        'reeval_trigger',
        'warnings',
        'trained_at',
        'next_review_at',
        'interval_lower',
        'interval_upper',
        'interval_confidence',
        'interval_empirical_coverage',
        'selection_rationale',
        'transformation_applied',
        'hyperparameters',
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
            'demand_rate'                  => 'float',
            'forecast_horizon_days'        => 'integer',
            'mae'                          => 'float',
            'rmse'                         => 'float',
            'bias'                         => 'float',
            'smape'                        => 'float',
            'warnings'                     => 'array',
            'trained_at'                   => 'datetime',
            'next_review_at'               => 'datetime',
            'interval_lower'               => 'float',
            'interval_upper'               => 'float',
            'interval_confidence'          => 'float',
            'interval_empirical_coverage'  => 'float',
            'hyperparameters'              => 'array',
        ];
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
