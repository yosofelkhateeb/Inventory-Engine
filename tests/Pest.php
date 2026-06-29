<?php

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');
uses(RefreshDatabase::class)->in('Feature');

/**
 * Bind tenant 1 by default for every Feature test before any model touches
 * the global TenantScope. After CSO finding #2 (2026-05-02), TenantScope
 * throws when there's no tenant in context — Auth-bound tests still resolve
 * via Auth, but tests that create scoped models before calling actingAs()
 * (or that don't authenticate at all) get a sane default here.
 *
 * Tests that need a different tenant can override mid-test via
 * TenantContext::run($otherTenantId, fn () => ...).
 */
uses()
    ->beforeEach(fn () => TenantContext::set(1))
    ->afterEach(fn () => TenantContext::clear())
    ->in('Feature', 'Unit');
