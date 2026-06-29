<?php

namespace App\Providers;

use App\Events\DecisionCalibrationDriftDetected;
use App\Events\ForecastDriftDetected;
use App\Listeners\EmailSystemOwnerOnDrift;
use App\Listeners\HandleForecastDrift;
use App\Listeners\RecordCalibrationDrift;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        ForecastDriftDetected::class => [
            HandleForecastDrift::class,
        ],

        // RecordCalibrationDrift runs first — persists the system_alerts
        // row that EmailSystemOwnerOnDrift then reads. Both queue, so
        // ordering at the queue layer would be loose, but the record-then-
        // email idempotency in the email listener handles the race
        // (logs and bails if the row isn't there yet).
        DecisionCalibrationDriftDetected::class => [
            RecordCalibrationDrift::class,
            EmailSystemOwnerOnDrift::class,
        ],
    ];
}
