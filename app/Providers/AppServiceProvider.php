<?php

namespace App\Providers;

use App\Models\Sku;
use App\Observers\SkuObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\App\Services\InventoryEngine\InventoryEngineService::class, function ($app) {
            return new \App\Services\InventoryEngine\InventoryEngineService(
                new \App\Services\InventoryEngine\DemandForecaster(),
                new \App\Services\InventoryEngine\InventoryPositionTracker(),
                new \App\Services\InventoryEngine\LeadTimeHandler(),
                new \App\Services\InventoryEngine\ConstraintEngine(),
                new \App\Services\InventoryEngine\DecisionScorer(),
                new \App\Services\InventoryEngine\AbcXyzClassifier(),
                new \App\Services\InventoryEngine\DecisionStatusService(),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sku::observe(SkuObserver::class);

        RateLimiter::for('engine.run', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('ingestion.upload', function (Request $request) {
            return Limit::perHour(10)->by($request->user()?->tenant_id ?: $request->ip());
        });

        RateLimiter::for('decision.transition', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
