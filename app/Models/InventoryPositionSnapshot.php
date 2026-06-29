<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row per (sku, date) capturing what the engine knew and decided that
 * day. This is the primary training input for Chunk 3's calibration job.
 *
 * Outcome columns (`reorder_within_threshold`, `stockout_within_threshold`)
 * are filled in retroactively by the calibration loop using
 * lead_time_observations + stockout_events as ground truth.
 */
class InventoryPositionSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'sku_id',
        'snapshot_date',
        'on_hand',
        'in_transit',
        'reserved',
        'effective_position',
        'reorder_point',
        'daily_demand',
        'demand_stddev',
        'lead_time_days',
        'lead_time_stddev',
        'smape',
        'trend_direction',
        'decision',
        'reorder_within_threshold',
        'stockout_within_threshold',
    ];

    protected $casts = [
        'snapshot_date'             => 'date',
        'on_hand'                   => 'integer',
        'in_transit'                => 'integer',
        'reserved'                  => 'integer',
        'effective_position'        => 'integer',
        'reorder_point'             => 'integer',
        'daily_demand'              => 'float',
        'demand_stddev'             => 'float',
        'lead_time_days'            => 'integer',
        'lead_time_stddev'          => 'float',
        'smape'                     => 'float',
        'reorder_within_threshold'  => 'boolean',
        'stockout_within_threshold' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (self $snap) {
            if (empty($snap->tenant_id) && auth()->check()) {
                $snap->tenant_id = auth()->user()->tenant_id ?? 1;
            }
        });
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
