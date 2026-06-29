<?php

namespace Tests;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Queue;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure the default tenant exists for all DB tests.
        // The TenantScope falls back to tenant_id=1 when unauthenticated,
        // so every factory-created record references this tenant.
        if (app()->bound('db') && \Illuminate\Support\Facades\Schema::hasTable('tenants')) {
            Tenant::firstOrCreate(
                ['id' => 1],
                ['name' => 'Demo Client', 'locale' => 'ar', 'currency' => 'SAR']
            );
        }

        // Prevent queue jobs (including the SkuObserver's RunForecastJob) from
        // executing synchronously during tests. Tests that need to assert dispatches
        // call Queue::fake() themselves to reset the captured-jobs list.
        Queue::fake();
    }
}
