<?php

namespace App\Http\Controllers;

use App\Jobs\RunInventoryEngineJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EngineController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        RunInventoryEngineJob::dispatch($user->tenant_id, $user->id)
            ->onQueue('inventory');

        return back()->with('success', 'Engine run dispatched.');
    }
}
