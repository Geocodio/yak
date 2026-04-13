<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('yak:refresh-repos')->everyThirtyMinutes();
Schedule::command('yak:cleanup')->daily();
Schedule::command('yak:timeout-ci')->everyFifteenMinutes();
Schedule::command('yak:healthcheck')->everyFifteenMinutes();
Schedule::command('yak:scan-ci')->everyTwoHours();
