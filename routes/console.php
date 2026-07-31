<?php

use App\Jobs\AnalyzeRecommendationFeedbackJob;
use App\Jobs\CheckBiasDriftJob;
use App\Jobs\RunDecisionCalibrationJob;
use App\Jobs\RunInventoryEngineJob;
use App\Jobs\RunShopifyIncrementalSyncJob;
use App\Models\IngestionCredential;
use App\Models\Tenant;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily engine run per tenant. Iterates explicitly because RunInventoryEngineJob
// is now tenant-scoped (post-CSO finding #2: TenantScope no longer falls back
// to tenant_id=1 silently). One dispatch per tenant; jobs run in parallel via
// the inventory queue.
Schedule::call(function () {
    Tenant::query()->each(
        fn ($tenant) => RunInventoryEngineJob::dispatch($tenant->id)->onQueue('inventory'),
    );
})
    ->dailyAt('06:00')
    ->name('daily-inventory-engine-run')
    ->withoutOverlapping();

Schedule::job(new AnalyzeRecommendationFeedbackJob(), 'inventory')
    ->weekly()
    ->name('weekly-recommendation-feedback-analysis')
    ->withoutOverlapping();

Schedule::job(new CheckBiasDriftJob(), 'forecasting')
    ->dailyAt('07:00')
    ->name('daily-bias-drift-check')
    ->withoutOverlapping();

// Bi-weekly safety-net sweep: re-trains every active SKU in case a model
// has gone stale without tripping the bias-drift or feedback-drift triggers.
// Runs every other Saturday at 03:00 — weekend + off-peak so the worker
// draining ~30 forecast jobs never competes with daily engine runs.
// Week parity is anchored to a fixed Saturday (2026-01-03) rather than the
// ISO week number, which resets each year and would drift the cadence.
Schedule::command('forecast:sweep')
    ->saturdays()
    ->at('03:00')
    ->when(fn () => (int) Carbon::parse('2026-01-03')->diffInWeeks(now()) % 2 === 0)
    ->name('biweekly-forecast-sweep')
    ->withoutOverlapping();

Schedule::command('ingestion:cleanup-uploads')
    ->daily()
    ->name('daily-ingestion-cleanup');

// Demo-only: keeps the hosted synthetic feed current so the dataset does not
// age one day per day. Gated on config rather than registered conditionally so
// `schedule:list` shows it, and inert unless DEMO_FEED_TOPUP_ENABLED is set.
// Runs before the 03:00 sweep so a top-up night re-forecasts on fresh data.
Schedule::command('demo:topup-sales')
    ->dailyAt((string) config('synthetic_dataset.feed_topup.at', '02:00'))
    ->when(fn () => (bool) config('synthetic_dataset.feed_topup.enabled'))
    ->name('daily-demo-feed-topup')
    ->withoutOverlapping();

Schedule::call(function () {
    IngestionCredential::withoutGlobalScopes()
        ->where('source', 'shopify')
        ->where('is_active', true)
        ->each(fn ($cred) => RunShopifyIncrementalSyncJob::dispatch($cred->id));
})
    ->hourly()
    ->name('hourly-shopify-incremental-sync');

// Decision-engine calibration: refits (k_lead, k_ltv, k_smape, k_trend)
// against the trailing snapshots + ground-truth tables. Runs on the 1st
// and 15th of each month at 04:00 UTC — bi-weekly cadence per the
// rollout plan, off-peak so the 10-minute grid search doesn't compete
// with the daily inventory engine. One dispatch per active tenant; the
// job's uniqueId() prevents concurrent runs for the same tenant.
Schedule::call(function () {
    Tenant::query()->each(
        fn ($tenant) => RunDecisionCalibrationJob::dispatch($tenant->id),
    );
})
    ->cron('0 4 1,15 * *')
    ->name('biweekly-decision-calibration');
