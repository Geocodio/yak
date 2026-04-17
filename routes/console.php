<?php

use App\Jobs\PollPullRequestReactionsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('yak:cleanup')->daily();
Schedule::command('yak:cleanup-sandboxes')->hourly();
Schedule::command('yak:reap-orphaned-tasks')->everyFiveMinutes()->withoutOverlapping();
// Hourly because the Max access token lives ~8h and
// everyFourHours() fires at fixed clock hours — a deploy landing a
// few minutes after a fire time can go 4h without a refresh, which
// stacks badly against an already-aging on-disk token.
Schedule::command('yak:refresh-claude-auth')->hourly()->withoutOverlapping();
Schedule::command('yak:timeout-ci')->everyFifteenMinutes();
Schedule::command('yak:healthcheck')->everyFifteenMinutes();
Schedule::command('yak:scan-ci')->everyTwoHours();
Schedule::command('yak:poll-drone-ci')->everyMinute()->withoutOverlapping();
Schedule::job(PollPullRequestReactionsJob::class)->hourly()->name('poll-pr-reactions');
