<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('yak:cleanup')->daily();
Schedule::command('yak:cleanup-sandboxes')->hourly();
Schedule::command('yak:timeout-ci')->everyFifteenMinutes();
Schedule::command('yak:healthcheck')->everyFifteenMinutes();
Schedule::command('yak:scan-ci')->everyTwoHours();
Schedule::command('yak:poll-drone-ci')->everyMinute()->withoutOverlapping();
