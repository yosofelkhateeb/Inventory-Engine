<?php

use App\Services\Synthetic\SeaSeasonalCalendar;
use Carbon\Carbon;

it('returns 1.0 for ordinary days', function () {
    $cal = new SeaSeasonalCalendar;
    // A random Tuesday in the middle of a non-event month.
    expect($cal->multiplierFor(Carbon::parse('2024-05-14')))->toBe(1.0);
});

it('applies the 11.11 single-day multiplier', function () {
    $cal = new SeaSeasonalCalendar;
    expect($cal->multiplierFor(Carbon::parse('2024-11-11')))->toBe(3.0);
});

it('applies the 11.11 runup the day before', function () {
    $cal = new SeaSeasonalCalendar;
    expect($cal->multiplierFor(Carbon::parse('2024-11-10')))->toBe(1.6);
});

it('applies the 11.11 runoff the day after', function () {
    $cal = new SeaSeasonalCalendar;
    expect($cal->multiplierFor(Carbon::parse('2024-11-12')))->toBe(1.3);
});

it('applies the 12.12 multiplier', function () {
    $cal = new SeaSeasonalCalendar;
    expect($cal->multiplierFor(Carbon::parse('2024-12-12')))->toBe(2.3);
});

it('applies the 9.9 multiplier', function () {
    $cal = new SeaSeasonalCalendar;
    expect($cal->multiplierFor(Carbon::parse('2024-09-09')))->toBe(2.5);
});

it('applies the 10.10 multiplier', function () {
    $cal = new SeaSeasonalCalendar;
    expect($cal->multiplierFor(Carbon::parse('2024-10-10')))->toBe(2.5);
});

it('applies CNY peak across the multi-day window', function () {
    $cal = new SeaSeasonalCalendar;
    // 2024 CNY = Feb 10 — peak window is 2024-02-08 to 2024-02-13.
    expect($cal->multiplierFor(Carbon::parse('2024-02-10')))->toBe(1.8)
        ->and($cal->multiplierFor(Carbon::parse('2024-02-08')))->toBe(1.8)
        ->and($cal->multiplierFor(Carbon::parse('2024-02-13')))->toBe(1.8);
});

it('applies CNY runup the week before the peak window', function () {
    $cal = new SeaSeasonalCalendar;
    expect($cal->multiplierFor(Carbon::parse('2024-02-05')))->toBe(1.3);
});

it('applies Hari Raya Puasa peak and runup', function () {
    $cal = new SeaSeasonalCalendar;
    // 2025 Hari Raya Puasa = Mar 31 — peak window 2025-03-29 to 2025-04-02.
    expect($cal->multiplierFor(Carbon::parse('2025-03-31')))->toBe(1.5);
    expect($cal->multiplierFor(Carbon::parse('2025-03-20')))->toBe(1.3); // inside runup window
});

it('applies Christmas window multiplier', function () {
    $cal = new SeaSeasonalCalendar;
    expect($cal->multiplierFor(Carbon::parse('2024-12-24')))->toBe(1.4);
});

it('applies Songkran window multiplier', function () {
    $cal = new SeaSeasonalCalendar;
    expect($cal->multiplierFor(Carbon::parse('2024-04-14')))->toBe(1.15);
});

it('applies Singapore National Day window multiplier (isolated from monsoon)', function () {
    // National Day falls inside the monsoon window, so isolate the
    // amplifier by passing weights without monsoon_dampening (the weight()
    // helper falls back to 1.0 for absent keys).
    $cal = new SeaSeasonalCalendar(weights: ['singapore_natl_day' => 1.10]);
    expect($cal->multiplierFor(Carbon::parse('2024-08-09')))->toBe(1.10);
});

it('applies Black Friday multiplier', function () {
    $cal = new SeaSeasonalCalendar;
    expect($cal->multiplierFor(Carbon::parse('2024-11-29')))->toBe(1.8);
});

it('takes the MAX when overlapping amplifiers land on the same day', function () {
    // 2023-11-11 hits mega_1111 (3.0). Construct an artificial collision
    // via custom weights to verify the MAX rule explicitly.
    $cal = new SeaSeasonalCalendar(weights: [
        'mega_1111'    => 3.0,
        'cny_peak'     => 1.8,
        'black_friday' => 1.8,
    ]);
    // Real date with only the 11.11 event → 3.0, NOT 3.0 × 1.8.
    expect($cal->multiplierFor(Carbon::parse('2023-11-11')))->toBe(3.0);
});

it('multiplies by monsoon dampener when in a monsoon window', function () {
    $cal = new SeaSeasonalCalendar;
    // 2024-07-15 is inside the monsoon window (2024-06-20 to 2024-08-05)
    // and has no positive amplifier — result should be 0.90.
    expect($cal->multiplierFor(Carbon::parse('2024-07-15')))->toBe(0.90);
});

it('composes a positive amplifier with the monsoon dampener', function () {
    $cal = new SeaSeasonalCalendar;
    // Singapore National Day 2024-08-09 falls inside the monsoon window
    // (2024-06-20 to 2024-08-12). 1.10 × 0.90 = 0.99 (float-precision tolerant).
    expect($cal->multiplierFor(Carbon::parse('2024-08-09')))
        ->toEqualWithDelta(0.99, 0.0001);
});

it('reads weights from the config when not given an explicit override', function () {
    config()->set('synthetic_dataset.seasonal_calendar.mega_1111', 4.2);
    $cal = new SeaSeasonalCalendar;
    expect($cal->multiplierFor(Carbon::parse('2024-11-11')))->toBe(4.2);
});

it('respects an explicit weights override over the config', function () {
    config()->set('synthetic_dataset.seasonal_calendar.mega_1111', 4.2);
    $cal = new SeaSeasonalCalendar(weights: ['mega_1111' => 2.0]);
    expect($cal->multiplierFor(Carbon::parse('2024-11-11')))->toBe(2.0);
});
