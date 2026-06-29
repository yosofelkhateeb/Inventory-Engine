<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadTimeObservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'supplier_id',
        'sku_id',
        'order_placed_at',
        'order_received_at',
        'days_actual',
        'source',
    ];

    protected $casts = [
        'order_placed_at'   => 'date',
        'order_received_at' => 'date',
        'days_actual'       => 'integer',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (self $obs) {
            if (empty($obs->tenant_id) && auth()->check()) {
                $obs->tenant_id = auth()->user()->tenant_id ?? 1;
            }
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
