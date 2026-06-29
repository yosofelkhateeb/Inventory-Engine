<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedbackMetric extends Model
{
    protected $fillable = [
        'tenant_id',
        'sku_id',
        'period_start',
        'period_end',
        'ignored_rate',
        'ordered_delta',
        'received_delta',
        'superseded_rate',
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
            'period_start'    => 'date',
            'period_end'      => 'date',
            'ignored_rate'    => 'float',
            'ordered_delta'   => 'float',
            'received_delta'  => 'float',
            'superseded_rate' => 'float',
        ];
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
