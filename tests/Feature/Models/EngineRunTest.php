<?php

use App\Models\EngineRun;
use App\Models\User;

it('can create an engine run record', function () {
    $run = EngineRun::factory()->create([
        'status'          => 'completed',
        'decisions_count' => 11,
        'duration_ms'     => 430,
    ]);

    expect($run->status)->toBe('completed')
        ->and($run->decisions_count)->toBe(11)
        ->and($run->triggeredBy)->not->toBeNull();
});

it('allows null triggered_by for scheduled runs', function () {
    $run = EngineRun::factory()->scheduled()->create();

    expect($run->triggered_by)->toBeNull();
});
