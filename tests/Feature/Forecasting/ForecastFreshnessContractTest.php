<?php

use App\Jobs\RunForecastJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;

it('RunForecastJob implements ShouldQueue and targets the forecasting queue', function () {
    expect(is_subclass_of(RunForecastJob::class, ShouldQueue::class))->toBeTrue();

    $job = new RunForecastJob(skuId: 1, tenantId: 1);

    expect($job->queue)->toBe('forecasting');
});

it('has the bi-weekly forecast sweep registered on the scheduler', function () {
    $schedule = app(Schedule::class);

    $match = collect($schedule->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'forecast:sweep'));

    expect($match)->not->toBeNull('forecast:sweep is not in the schedule');
    // Cron fires every Saturday at 03:00; the every-other-Saturday cadence
    // is enforced at runtime by a week-parity when() filter on the event.
    expect($match->expression)->toBe('0 3 * * 6');
});

it('has the daily bias drift check registered on the scheduler', function () {
    $schedule = app(Schedule::class);

    $match = collect($schedule->events())
        ->first(fn ($event) => ($event->description ?? '') === 'daily-bias-drift-check');

    expect($match)->not->toBeNull('daily-bias-drift-check is not in the schedule');
});

it('has the weekly recommendation feedback analysis registered on the scheduler', function () {
    $schedule = app(Schedule::class);

    $match = collect($schedule->events())
        ->first(fn ($event) => ($event->description ?? '') === 'weekly-recommendation-feedback-analysis');

    expect($match)->not->toBeNull('weekly-recommendation-feedback-analysis is not in the schedule');
});

it('has the bi-weekly decision calibration registered on the scheduler', function () {
    $schedule = app(Schedule::class);

    $match = collect($schedule->events())
        ->first(fn ($event) => ($event->description ?? '') === 'biweekly-decision-calibration');

    expect($match)->not->toBeNull('biweekly-decision-calibration is not in the schedule');
    // Cron expression: minute 0, hour 4, days 1 and 15, every month, every weekday
    expect($match->expression)->toBe('0 4 1,15 * *');
});
