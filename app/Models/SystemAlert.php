<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemAlert extends Model
{
    use HasFactory;

    public const SEVERITY_INFO     = 'info';
    public const SEVERITY_WARNING  = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    protected $fillable = [
        'tenant_id',
        'type',
        'severity',
        'title',
        'payload',
        'resolved_at',
        'resolved_by',
    ];

    protected $casts = [
        'payload'     => 'array',
        'resolved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (self $alert): void {
            if (empty($alert->tenant_id) && auth()->check()) {
                $alert->tenant_id = auth()->user()->tenant_id ?? 1;
            }
        });
    }

    public function scopeUnresolved(Builder $q): Builder
    {
        return $q->whereNull('resolved_at');
    }

    public function scopeOfSeverity(Builder $q, string $severity): Builder
    {
        return $q->where('severity', $severity);
    }
}
