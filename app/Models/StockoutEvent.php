<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockoutEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'sku_id',
        'occurred_at',
        'recovered_at',
        'duration_days',
        'demand_lost_units',
        'source',
    ];

    protected $casts = [
        'occurred_at'       => 'date',
        'recovered_at'      => 'date',
        'duration_days'     => 'integer',
        'demand_lost_units' => 'integer',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (self $event) {
            if (empty($event->tenant_id) && auth()->check()) {
                $event->tenant_id = auth()->user()->tenant_id ?? 1;
            }
        });
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
