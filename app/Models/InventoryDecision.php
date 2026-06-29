<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Models\Supplier;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryDecision extends Model
{
    const STATUS_PENDING      = 'pending';
    const STATUS_ACKNOWLEDGED = 'acknowledged';
    const STATUS_ORDERED      = 'ordered';
    const STATUS_IN_TRANSIT   = 'in_transit';
    const STATUS_RECEIVED     = 'received';
    const STATUS_IGNORED      = 'ignored';
    const STATUS_SUPERSEDED   = 'superseded';

    const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACKNOWLEDGED,
        self::STATUS_ORDERED,
        self::STATUS_IN_TRANSIT,
        self::STATUS_RECEIVED,
        self::STATUS_IGNORED,
        self::STATUS_SUPERSEDED,
    ];

    const TERMINAL_STATUSES = [
        self::STATUS_RECEIVED,
        self::STATUS_IGNORED,
        self::STATUS_SUPERSEDED,
    ];

    protected $fillable = [
        'tenant_id',
        'sku_id',
        'engine_run_id',
        'run_at',
        'decision',
        'recommended_qty',
        'constrained_qty',
        'reasoning',
        'forecast_demand',
        'days_of_cover',
        'reorder_point',
        'safety_stock',
        // status is intentionally NOT in $fillable — transitions go through DecisionStatusService
        'status_changed_at',
        // status_changed_by is a user FK stamped by DecisionStatusService, never from HTTP input
        'status_changed_by',
        'status_notes',
        // action-tracking columns (set via DecisionStatusService or controller)
        'ordered_qty',
        'ordered_at',
        'expected_arrival',
        'supplier_id',
        'received_qty',
        'received_at',
        'ignored_reason',
        'superseded_by_decision_id',
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
            'run_at'            => 'datetime',
            'reasoning'         => 'array',
            'forecast_demand'   => 'float',
            'days_of_cover'     => 'float',
            'reorder_point'     => 'float',
            'status_changed_at' => 'datetime',
            'status_history'    => 'array',
            'ordered_at'        => 'datetime',
            'received_at'       => 'datetime',
            'expected_arrival'  => 'date',
        ];
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_decision_id');
    }
}
