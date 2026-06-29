<?php

use App\Events\DecisionCalibrationDriftDetected;
use App\Listeners\EmailSystemOwnerOnDrift;
use App\Listeners\RecordCalibrationDrift;
use App\Mail\SystemAlertMail;
use App\Models\SystemAlert;
use App\Models\SystemSetting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

it('event is wired in EventServiceProvider with both listeners', function () {
    Event::fake();
    Event::assertListening(DecisionCalibrationDriftDetected::class, RecordCalibrationDrift::class);
    Event::assertListening(DecisionCalibrationDriftDetected::class, EmailSystemOwnerOnDrift::class);
});

it('RecordCalibrationDrift persists a system_alerts row with structured payload', function () {
    $event = new DecisionCalibrationDriftDetected(
        tenantId:      1,
        objective:     'f2',
        previousScore: 0.85,
        currentScore:  0.62,
        deltaPct:      -27.1,
        thresholdPct:  20.0,
    );

    (new RecordCalibrationDrift())->handle($event);

    $alert = SystemAlert::withoutGlobalScopes()->first();
    expect($alert)->not->toBeNull();
    expect($alert->type)->toBe('decision_calibration_drift');
    expect($alert->severity)->toBe(SystemAlert::SEVERITY_WARNING);
    expect($alert->title)->toContain('27.1%');
    expect($alert->payload)->toMatchArray([
        'objective'      => 'f2',
        'previous_score' => 0.85,
        'current_score'  => 0.62,
        'delta_pct'      => -27.1,
        'threshold_pct'  => 20.0,
    ]);
    expect($alert->payload)->toHaveKey('recommendation');
});

it('EmailSystemOwnerOnDrift no-ops when no recipient is configured', function () {
    Mail::fake();

    SystemAlert::withoutGlobalScopes()->create([
        'tenant_id' => 1,
        'type'      => 'decision_calibration_drift',
        'severity'  => SystemAlert::SEVERITY_WARNING,
        'title'     => 'test alert',
        'payload'   => [],
    ]);

    $event = new DecisionCalibrationDriftDetected(1, 'f2', 0.9, 0.7, -22.0, 20.0);
    (new EmailSystemOwnerOnDrift())->handle($event);

    Mail::assertNothingQueued();
});

it('EmailSystemOwnerOnDrift queues mail to the configured recipient', function () {
    Mail::fake();

    SystemSetting::firstOrCreate(
        ['tenant_id' => 1, 'key' => 'notifications.system_owner_email'],
        ['value' => 'ops@example.test', 'group' => 'forecasting'],
    );
    SystemAlert::withoutGlobalScopes()->create([
        'tenant_id' => 1,
        'type'      => 'decision_calibration_drift',
        'severity'  => SystemAlert::SEVERITY_WARNING,
        'title'     => 'Calibration drift: score regressed 22.0% (0.9 → 0.7)',
        'payload'   => ['recommendation' => 'Investigate'],
    ]);

    $event = new DecisionCalibrationDriftDetected(1, 'f2', 0.9, 0.7, -22.0, 20.0);
    (new EmailSystemOwnerOnDrift())->handle($event);

    Mail::assertQueued(SystemAlertMail::class, function ($mail) {
        return $mail->hasTo('ops@example.test');
    });
});

it('both listeners implement ShouldQueue (calibration is not request-scoped)', function () {
    expect(is_subclass_of(RecordCalibrationDrift::class, ShouldQueue::class))->toBeTrue();
    expect(is_subclass_of(EmailSystemOwnerOnDrift::class, ShouldQueue::class))->toBeTrue();
});
