<?php

namespace App\Models;

use App\Models\InventoryDecision;
use App\Models\SalesHistory;
use App\Models\Scopes\TenantScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sku extends Model
{
    /** @use HasFactory<\Database\Factories\SkuFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'supplier_id',
        'name',
        'sku_code',
        'category',
        'moq',
        'unit_cost',
        'reorder_qty',
        'current_stock',
        'in_transit_qty',
        'reserved_qty',
        'lead_time_days',
        'abc_class',
        'xyz_class',
        'safety_stock_multiplier_override',
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
            'moq' => 'integer',
            'unit_cost' => 'integer',
            'reorder_qty' => 'integer',
            'current_stock' => 'integer',
            'in_transit_qty' => 'integer',
            'reserved_qty' => 'integer',
            'lead_time_days' => 'integer',
            'safety_stock_multiplier_override' => 'decimal:2',
        ];
    }

    /**
     * Effective inventory position: on-hand + in-transit - reserved.
     */
    public function getEffectivePositionAttribute(): int
    {
        return $this->current_stock + $this->in_transit_qty - $this->reserved_qty;
    }

    /**
     * unit_cost stored as integer halalas, displayed as SAR.
     */
    public function getUnitCostSarAttribute(): float
    {
        return $this->unit_cost / 100;
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function salesHistory(): HasMany
    {
        return $this->hasMany(SalesHistory::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(InventoryDecision::class);
    }
}
