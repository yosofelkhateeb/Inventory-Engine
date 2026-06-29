<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesHistory extends Model
{
    protected $table = 'sales_history';

    protected $fillable = [
        'tenant_id',
        'sku_id',
        'sale_date',
        'quantity_sold',
        'is_promotion',
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
            'sale_date'    => 'date',
            'quantity_sold' => 'integer',
            'is_promotion' => 'boolean',
        ];
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
