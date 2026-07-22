<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('integrations:execute-scheduled-activations --limit=25 --lease-seconds=120')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(5);

if ((bool) config('hummingbird-patient.staff_messaging.enabled', false)
    && config('hummingbird-patient.staff_messaging.governance_status') === 'approved'
) {
    // A bounded, content-free handoff run keeps the readiness heartbeat fresh
    // and drains patient messages into accountable pool-owned work items. The
    // command itself remains safe for scheduler retries and multi-node overlap.
    Schedule::command('hummingbird:consume-patient-message-handoff --once --worker=laravel-scheduler')
        ->everyMinute()
        ->onOneServer()
        ->withoutOverlapping(2);
    Schedule::command('hummingbird:escalate-patient-communications --once --limit=100')
        ->everyMinute()
        ->onOneServer()
        ->withoutOverlapping(2);
    Schedule::command('hummingbird:reconcile-patient-communications --once --limit=100')
        ->everyMinute()
        ->onOneServer()
        ->withoutOverlapping(2);
}

if ((bool) config('hummingbird-patient.enabled', false)
    && (bool) config('hummingbird-patient.features.pathway', false)
    && (bool) config('hummingbird-patient.features.pathway_history_drafts', false)
    && (bool) config('care-pathways.patient_enabled', false)
) {
    // This is intentionally a draft-only producer. It cannot publish, notify,
    // or bypass the independent patient-projection review/release boundary.
    Schedule::command('hummingbird:draft-patient-pathway-history --once --commit --limit=100')
        ->everyFiveMinutes()
        ->onOneServer()
        ->withoutOverlapping(10);
}
