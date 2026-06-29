<?php

use App\Models\SystemSetting;
use App\Services\Promotions\Layers\RuleBasedLayer;
use App\Support\TenantContext;

function seedRule(string $key, float|int|string $value): void
{
    SystemSetting::create([
        'tenant_id' => 1,
        'key'       => $key,
        'value'     => (string) $value,
        'group'     => 'forecasting',
    ]);
}

beforeEach(function () {
    TenantContext::set(1);
});

afterEach(function () {
    TenantContext::clear();
});

it('falls back to the generic default when the brief is empty', function () {
    seedRule('uplift_default.generic', 12);

    $result = (new RuleBasedLayer)->predict([]);

    expect($result['value'])->toBe(12.0)
        ->and($result['layer'])->toBe('rules')
        ->and($result['sample_size'])->toBe(0);
});

it('uses the per-type base when promotion_type is provided', function () {
    seedRule('uplift_default.flash', 50);

    $result = (new RuleBasedLayer)->predict(['promotion_type' => 'flash']);

    expect($result['value'])->toBe(50.0)
        ->and($result['basis'])->toContain('flash');
});

it('adds the discount adjustment using the configured coefficient', function () {
    seedRule('uplift_default.generic', 10);
    seedRule('uplift.rule.discount_pct_coef', 0.5);

    // 10 base + 0.5 * 30 = 10 + 15 = 25
    $result = (new RuleBasedLayer)->predict(['discount_pct' => 30]);

    expect($result['value'])->toBe(25.0);
});

it('adds a channel adjustment proportional to channel_mix count', function () {
    seedRule('uplift_default.generic', 10);
    seedRule('uplift.rule.channel_count_coef', 3);

    // 10 base + 3 * 3 channels = 10 + 9 = 19
    $result = (new RuleBasedLayer)->predict([
        'channel_mix' => ['paid_social', 'email', 'sms'],
    ]);

    expect($result['value'])->toBe(19.0);
});

it('looks up spend-band and audience bonuses from settings', function () {
    seedRule('uplift_default.generic', 10);
    seedRule('uplift.rule.spend_band_bonus.high', 30);
    seedRule('uplift.rule.audience_bonus.both', 8);

    // 10 + 30 + 8 = 48
    $result = (new RuleBasedLayer)->predict([
        'ad_spend_band' => 'high',
        'audience'      => 'both',
    ]);

    expect($result['value'])->toBe(48.0);
});

it('combines all rule terms additively', function () {
    seedRule('uplift_default.flash', 50);
    seedRule('uplift.rule.discount_pct_coef', 0.5);
    seedRule('uplift.rule.channel_count_coef', 3);
    seedRule('uplift.rule.spend_band_bonus.high', 30);
    seedRule('uplift.rule.audience_bonus.both', 8);

    // 50 + (0.5 * 40) + (3 * 2) + 30 + 8 = 50 + 20 + 6 + 30 + 8 = 114
    $result = (new RuleBasedLayer)->predict([
        'promotion_type' => 'flash',
        'discount_pct'   => 40,
        'channel_mix'    => ['paid_social', 'email'],
        'ad_spend_band'  => 'high',
        'audience'       => 'both',
    ]);

    expect($result['value'])->toBe(114.0);
});

it('clamps a negative computed value to zero', function () {
    // Pathological config: large negative coefficient. Should not return
    // a negative prediction — the layer floors at 0.
    seedRule('uplift_default.generic', 5);
    seedRule('uplift.rule.discount_pct_coef', -1);

    $result = (new RuleBasedLayer)->predict(['discount_pct' => 50]);

    expect($result['value'])->toBe(0.0);
});

it('returns a confidence band whose width scales with confidence_band_pct', function () {
    seedRule('uplift_default.generic', 20);
    seedRule('uplift.rule.confidence_band_pct', 50);

    $result = (new RuleBasedLayer)->predict([]);

    // value=20, band ±50% → lower=10, upper=30
    expect($result['value'])->toBe(20.0)
        ->and($result['lower'])->toBe(10.0)
        ->and($result['upper'])->toBe(30.0);
});

it('lower bound is clamped to zero when band would push it negative', function () {
    seedRule('uplift_default.generic', 5);
    seedRule('uplift.rule.confidence_band_pct', 200);

    $result = (new RuleBasedLayer)->predict([]);

    // value=5, band ±200% → would be -5..15; lower clamped to 0
    expect($result['lower'])->toBe(0.0)
        ->and($result['upper'])->toBe(15.0);
});

it('basis string surfaces the rule terms that fired for operator transparency', function () {
    seedRule('uplift_default.flash', 50);

    $result = (new RuleBasedLayer)->predict([
        'promotion_type' => 'flash',
        'discount_pct'   => 30,
        'channel_mix'    => ['paid_social', 'email'],
        'ad_spend_band'  => 'high',
        'audience'       => 'new_acquisition',
    ]);

    expect($result['basis'])->toContain('flash')
        ->and($result['basis'])->toContain('30% discount')
        ->and($result['basis'])->toContain('2 channel')
        ->and($result['basis'])->toContain('high spend')
        ->and($result['basis'])->toContain('new acquisition');
});
