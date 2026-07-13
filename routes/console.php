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
