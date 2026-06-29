<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Holds the active tenant id for the current execution context.
 *
 * Resolution order used by TenantScope and the model `creating` hooks:
 *   1. Auth::user()->tenant_id  (web requests)
 *   2. TenantContext::peek()    (jobs, seeders, console commands)
 *   3. throw RuntimeException
 *
 * The previous design fell back to tenant_id=1 silently, which masked
 * cross-tenant leaks the moment a second tenant was added. CSO finding #2
 * (2026-05-02) replaced that fallback with this explicit context.
 *
 * Jobs that hit globally-scoped models without an authenticated user
 * MUST wrap their work in `TenantContext::run($this->tenantId, fn () => ...)`.
 * Seeders likewise. Tests get tenant 1 via the Pest.php beforeEach.
 */
class TenantContext
{
    private static ?int $current = null;

    /**
     * Bind a tenant id for the current execution context.
     */
    public static function set(int $tenantId): void
    {
        self::$current = $tenantId;
    }

    /**
     * Clear the bound tenant. Mainly useful in tests; production code
     * should prefer `run()` so binding is always paired with cleanup.
     */
    public static function clear(): void
    {
        self::$current = null;
    }

    /**
     * Read the bound tenant without consulting Auth or throwing.
     * Used by callers that want to test "is a context bound?" without
     * triggering the auth lookup or the throw.
     */
    public static function peek(): ?int
    {
        return self::$current;
    }

    /**
     * Resolve the active tenant id (auth → context → throw).
     *
     * @throws RuntimeException when no tenant is in scope
     */
    public static function tenantId(): int
    {
        if (Auth::check()) {
            return (int) Auth::user()->tenant_id;
        }

        if (self::$current !== null) {
            return self::$current;
        }

        throw new RuntimeException(
            'TenantContext: no tenant in scope. '
            .'Wrap the operation in TenantContext::run($tenantId, fn () => ...) '
            .'or authenticate the request first. '
            .'For tests, the global Pest beforeEach binds tenant 1 by default.'
        );
    }

    /**
     * Run the given callback with `$tenantId` bound. Restores any previously
     * bound tenant on exit (so nested run() calls are safe), even when the
     * callback throws.
     *
     * @template T
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(int $tenantId, Closure $callback): mixed
    {
        $previous = self::$current;
        self::$current = $tenantId;

        try {
            return $callback();
        } finally {
            self::$current = $previous;
        }
    }
}
