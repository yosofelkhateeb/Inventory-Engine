<?php

namespace App\Http\Controllers;

use App\Jobs\RunCsvImportJob;
use App\Models\DataIngestionRun;
use App\Models\IngestionCredential;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Str;

class IngestionController extends Controller
{
    private const VALID_ENTITIES = ['suppliers', 'skus', 'sales_history'];

    public function index(Request $request): InertiaResponse
    {
        $tenantId = $request->user()->tenant_id;

        $runs = DataIngestionRun::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->latest('started_at')
            ->take(20)
            ->get();

        $shopifyCred = IngestionCredential::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('source', 'shopify')
            ->first();

        return Inertia::render('Ingestion/Index', [
            'runs'      => $runs,
            'canUpload' => $request->user()->hasRole('owner'),
            'shopify'   => $shopifyCred ? [
                'connected'    => (bool) $shopifyCred->is_active,
                'shop_domain'  => $shopifyCred->credentials['shop_domain'] ?? null,
                'last_sync_at' => $shopifyCred->last_sync_at?->toISOString(),
                'connected_at' => $shopifyCred->connected_at?->toISOString(),
            ] : null,
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('owner'), 403);

        $request->validate([
            'entity' => ['required', 'string', 'in:suppliers,skus,sales_history'],
            'file'   => ['required', 'file', 'mimes:csv,txt', 'max:51200'],
        ]);

        $entity   = $request->input('entity');
        $tenantId = $request->user()->tenant_id;
        $uuid     = (string) Str::uuid();

        $dir = storage_path("app/ingestion/uploads/{$tenantId}");

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $request->file('file')->move($dir, "{$uuid}.csv");
        $filePath = "{$dir}/{$uuid}.csv";

        RunCsvImportJob::dispatch($entity, $filePath, $tenantId)->onQueue('default');

        return back()->with('success', "Import queued for {$entity}. Refresh this page to see the result.");
    }

    public function template(string $entity): Response
    {
        abort_unless(in_array($entity, self::VALID_ENTITIES, true), 404);

        $templates = [
            'suppliers' => [
                'headers' => ['name', 'stated_lead_time_days', 'notes'],
                'example' => ['Acme Distributor', '7', 'Primary supplier for grip sock lines'],
            ],
            'skus' => [
                'headers' => ['sku_code', 'name', 'category', 'supplier_name', 'moq', 'unit_cost', 'current_stock', 'lead_time_days'],
                'example' => ['FB-001', 'Adidas Grip Socks 8pk', 'accessory', 'Acme Distributor', '24', '45.00', '120', '7'],
            ],
            'sales_history' => [
                'headers' => ['sku_code', 'sale_date', 'quantity_sold', 'is_promotion'],
                'example' => ['FB-001', '2024-04-01', '3', 'false'],
            ],
        ];

        $tpl = $templates[$entity];

        ob_start();
        $handle = fopen('php://output', 'w');
        fputcsv($handle, $tpl['headers']);
        fputcsv($handle, $tpl['example']);
        fclose($handle);
        $csv = ob_get_clean();

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$entity}_template.csv\"",
        ]);
    }
}
