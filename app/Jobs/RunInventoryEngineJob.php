<?php

namespace App\Jobs;

use App\Events\StockAlertEvent;
use App\Models\InventoryDecision;
use App\Services\InventoryEngine\InventoryEngineService;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunInventoryEngineJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $tenantId,
        public readonly ?int $triggeredBy = null,
    ) {}

    public function handle(InventoryEngineService $engine): void
    {
        TenantContext::run($this->tenantId, function () use ($engine) {
            $budget  = config('inventory.monthly_budget_halalas', PHP_INT_MAX);
            $results = $engine->run($budget, $this->triggeredBy);

            $orderNow = collect($results)->filter(fn ($d) => $d->decision === 'order');

            if ($orderNow->isNotEmpty()) {
                $latestRunAt = InventoryDecision::max('run_at');
                $alerts = InventoryDecision::where('run_at', $latestRunAt)
                    ->where('decision', 'order')
                    ->with('sku')
                    ->get()
                    ->map(fn ($d) => [
                        'sku_code'       => $d->sku->sku_code,
                        'sku_name'       => $d->sku->name,
                        'days_of_cover'  => $d->days_of_cover,
                        'lead_time_days' => $d->sku->lead_time_days,
                    ])
                    ->toArray();

                try {
                    StockAlertEvent::dispatch($alerts);
                } catch (\Throwable $e) {
                    // Broadcasting may not be configured in all environments — log and continue
                    logger()->warning('StockAlertEvent broadcast failed: '.$e->getMessage());
                }
            }
        });
    }
}
