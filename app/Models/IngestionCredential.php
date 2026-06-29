<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class IngestionCredential extends Model
{
    protected $fillable = [
        'tenant_id',
        'source',
        'credentials',
        'connected_at',
        'last_sync_at',
        'last_sync_cursor',
        'is_active',
    ];

    protected $casts = [
        'credentials'  => 'encrypted:array',
        'connected_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'is_active'    => 'boolean',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }
}
