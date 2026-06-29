<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use App\Jobs\RunCsvImportJob;
use Spatie\Permission\Models\Role;

it('renders the ingestion page for authenticated users', function () {
    $user = User::factory()->create(['tenant_id' => 1]);

    $this->actingAs($user)
        ->get('/ingestion')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Ingestion/Index')->has('runs')->has('canUpload')->has('shopify'));
});

it('returns 403 when a non-owner tries to upload', function () {
    Role::firstOrCreate(['name' => 'warehouse', 'guard_name' => 'web']);
    $user = User::factory()->create(['tenant_id' => 1]);
    $user->assignRole('warehouse');

    $csv = UploadedFile::fake()->createWithContent('suppliers.csv', "name,stated_lead_time_days,notes\nAcme,7,test\n");

    $this->actingAs($user)
        ->postJson('/ingestion/upload', ['entity' => 'suppliers', 'file' => $csv])
        ->assertForbidden();
});

it('owner upload dispatches RunCsvImportJob', function () {
    Queue::fake();

    Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $user = User::factory()->create(['tenant_id' => 1]);
    $user->assignRole('owner');

    $csv = UploadedFile::fake()->createWithContent('suppliers.csv', "name,stated_lead_time_days,notes\nAcme,7,test\n");

    $this->actingAs($user)
        ->post('/ingestion/upload', ['entity' => 'suppliers', 'file' => $csv])
        ->assertRedirect();

    Queue::assertPushed(RunCsvImportJob::class);
});

it('template endpoint returns a CSV download', function () {
    $user = User::factory()->create(['tenant_id' => 1]);

    $this->actingAs($user)
        ->get('/ingestion/template/suppliers')
        ->assertOk()
        ->assertHeaderContains('content-type', 'text/csv');
});

it('template endpoint returns 404 for unknown entity', function () {
    $user = User::factory()->create(['tenant_id' => 1]);

    $this->actingAs($user)
        ->get('/ingestion/template/nonexistent')
        ->assertNotFound();
});
