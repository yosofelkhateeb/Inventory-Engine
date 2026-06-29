<?php

use App\Events\ForecastDriftDetected;
use App\Jobs\RunForecastJob;
use App\Listeners\HandleForecastDrift;
use Illuminate\Support\Facades\Queue;

it('HandleForecastDrift listener is wired to ForecastDriftDetected event', function () {
    $provider = new \App\Providers\EventServiceProvider(app());

    $listen = (new ReflectionClass($provider))->getProperty('listen');
    $listen->setAccessible(true);
    $map = $listen->getValue($provider);

    expect($map)->toHaveKey(ForecastDriftDetected::class)
        ->and($map[ForecastDriftDetected::class])->toContain(HandleForecastDrift::class);
});

it('HandleForecastDrift dispatches RunForecastJob for the correct SKU with bias_drift trigger', function () {
    Queue::fake();

    $event = new ForecastDriftDetected(skuId: 42, tenantId: 1, reason: 'bias_exceeded');

    (new HandleForecastDrift())->handle($event);

    Queue::assertPushed(RunForecastJob::class, function (RunForecastJob $job) {
        $ref = new ReflectionClass($job);

        $skuProp = $ref->getProperty('skuId');
        $skuProp->setAccessible(true);

        $triggerProp = $ref->getProperty('reevalTrigger');
        $triggerProp->setAccessible(true);

        return $skuProp->getValue($job) === 42
            && $triggerProp->getValue($job) === 'bias_drift';
    });
});

it('HandleForecastDrift is queued, not synchronous', function () {
    expect((new ReflectionClass(HandleForecastDrift::class))->implementsInterface(
        \Illuminate\Contracts\Queue\ShouldQueue::class
    ))->toBeTrue();
});
