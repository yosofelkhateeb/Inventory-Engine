<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;

class DataIngestionRun extends Model
{
    const SOURCE_CSV_UPLOAD  = 'csv_upload';
    const SOURCE_SHOPIFY     = 'shopify';
    const SOURCE_WOOCOMMERCE = 'woocommerce';
    const SOURCE_SALLA       = 'salla';
    const SOURCE_MANUAL      = 'manual';

    const STATUS_PENDING   = 'pending';
    const STATUS_RUNNING   = 'running';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED    = 'failed';
    const STATUS_PARTIAL   = 'partial';

    const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_PARTIAL,
    ];

    protected $fillable = [
        'tenant_id',
        'source',
        'importer',
        'status',
        'rows_processed',
        'rows_succeeded',
        'rows_failed',
        'error_log',
        'started_at',
        'completed_at',
        'metadata',
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
            'rows_processed' => 'integer',
            'rows_succeeded' => 'integer',
            'rows_failed'    => 'integer',
            'error_log'      => 'array',
            'metadata'       => 'array',
            'started_at'     => 'datetime',
            'completed_at'   => 'datetime',
        ];
    }
}
