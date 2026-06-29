<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    /** @use HasFactory<\Database\Factories\SupplierFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'avg_lead_time_days',
        'lead_time_stddev',
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
            'avg_lead_time_days' => 'integer',
            'lead_time_stddev' => 'float',
        ];
    }

    public function skus(): HasMany
    {
        return $this->hasMany(\App\Models\Sku::class);
    }
}
