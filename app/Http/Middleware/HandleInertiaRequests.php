<?php

namespace App\Http\Middleware;

use App\Models\Sku;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $request->user(),
            ],
            // Cmd+K command palette index — lightweight SKU list (id, code, name)
            // shared globally on every authenticated page so the palette can do
            // instant client-side fuzzy matching without a network round-trip.
            // Closure form ensures the query only runs when Inertia evaluates
            // the prop, not on every request that doesn't surface it.
            'commandPaletteSkus' => fn () => $request->user()
                ? Sku::orderBy('sku_code')->get(['id', 'sku_code', 'name'])
                : [],
        ]);
    }
}
