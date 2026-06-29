<?php

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filters every read on a scoped model by the active tenant.
 *
 * Active tenant resolution lives in {@see TenantContext}: auth, then
 * an explicitly bound context, then throw. The previous fallback to
 * tenant_id=1 silently masked cross-tenant leaks the moment a second
 * tenant was added — closed in CSO finding #2 (2026-05-02).
 *
 * Code that intentionally crosses tenants (cross-tenant reporting,
 * queue dispatchers iterating tenants) must use `withoutGlobalScopes()`
 * and apply explicit `where('tenant_id', $X)` filters — the existing
 * pattern across all engine services.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where(
            $model->getTable().'.tenant_id',
            TenantContext::tenantId(),
        );
    }
}
