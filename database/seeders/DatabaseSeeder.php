<?php

namespace Database\Seeders;

use App\Support\TenantContext;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // All seeders create scoped models; bind tenant 1 so the strict
        // TenantScope (post-CSO finding #2) doesn't throw when seeders
        // run from `php artisan db:seed` without an authenticated user.
        TenantContext::run(1, function () {
            $this->call(SyntheticDataSeeder::class);
            $this->call(RegionalHolidaySeeder::class);
            $this->call(ForecastSettingsSeeder::class);
        });
    }
}
