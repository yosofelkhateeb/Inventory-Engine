<?php

namespace App\Observers;

use App\Jobs\RunForecastJob;
use App\Models\Sku;

class SkuObserver
{
    /**
     * Trigger forecast job when a new SKU is created.
     * If history is below threshold, the job assigns ets_fallback immediately.
     */
    public function created(Sku $sku): void
    {
        RunForecastJob::dispatch(
            $sku->id,
            $sku->tenant_id ?? 1,
            'new_sku',
        );
    }
}
