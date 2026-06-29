<?php

namespace App\Http\Controllers;

use App\Jobs\RunShopifyInitialLoadJob;
use App\Jobs\RunShopifyIncrementalSyncJob;
use App\Models\IngestionCredential;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShopifyController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('owner'), 403);

        $request->validate([
            'shop_domain'  => ['required', 'string', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/'],
            'access_token' => ['required', 'string', 'min:10'],
        ]);

        $tenantId = $request->user()->tenant_id;

        $credential = IngestionCredential::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenantId, 'source' => 'shopify'],
            [
                'credentials'     => [
                    'shop_domain'  => $request->input('shop_domain'),
                    'access_token' => $request->input('access_token'),
                ],
                'connected_at'    => now(),
                'is_active'       => true,
                'last_sync_at'    => null,
                'last_sync_cursor' => null,
            ],
        );

        RunShopifyInitialLoadJob::dispatch($credential->id, $tenantId);

        return back()->with('success', 'Shopify connected. Initial import has been queued — this may take several minutes.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('owner'), 403);

        IngestionCredential::withoutGlobalScopes()
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('source', 'shopify')
            ->update(['is_active' => false]);

        return back()->with('success', 'Shopify disconnected.');
    }

    public function sync(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('owner'), 403);

        $credential = IngestionCredential::withoutGlobalScopes()
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('source', 'shopify')
            ->where('is_active', true)
            ->firstOrFail();

        RunShopifyIncrementalSyncJob::dispatch($credential->id);

        return back()->with('success', 'Manual sync queued.');
    }
}
