<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Optional Brief preset. Two sources:
 *   `seeded`          — curated by the team / imported from a starter library.
 *   `auto_discovered` — surfaced by the clustering job (Phase 2) when a
 *                        stable Brief pattern emerges in the tenant's history.
 *
 * Operators apply a template to pre-fill the Brief fields on a new promotion;
 * every field stays individually editable before save. Templates are
 * convenience, not constraint.
 */
class PromotionTemplate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'brief',
        'source',
        'cluster_signature',
        'representative_sample_size',
    ];

    protected function casts(): array
    {
        return [
            'brief'                       => 'array',
            'representative_sample_size'  => 'integer',
        ];
    }

    public const SOURCE_SEEDED = 'seeded';
    public const SOURCE_AUTO_DISCOVERED = 'auto_discovered';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
        static::creating(function (self $model) {
            if (empty($model->tenant_id)) {
                $model->tenant_id = TenantContext::tenantId();
            }
        });
    }
}
