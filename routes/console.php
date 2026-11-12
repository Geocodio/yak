<?php

use App\Channels\GitHub\PollPullRequestReactionsJob;
use App\Jobs\Deployments\BackfillOpenPrDeploymentsJob;
use App\Jobs\Deployments\GarbageCollectTemplateSnapshotsJob;
use App\Jobs\Deployments\HibernateIdleDeploymentsJob;
use App\Jobs\Deployments\SweepExpiredDeploymentsJob;
use App\Jobs\Deployments\WatchdogStuckDeploymentsJob;
use App\Models\DeviceAuthorization;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('yak:cleanup')->hourly();
Schedule::command('yak:video:prune')->daily();
Schedule::command('yak:refresh-claude-models')->daily();
Schedule::command('yak:cleanup-sandboxes')->hourly();
Schedule::command('yak:reap-orphaned-tasks')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('yak:reap-lost-pending')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('yak:timeout-ci')->everyFifteenMinutes();
Schedule::command('yak:healthcheck')->everyFifteenMinutes();
Schedule::command('yak:scan-ci')->everyTwoHours()->withoutOverlapping();
Schedule::command('yak:observations:prune')->daily();
Schedule::command('yak:telemetry:prune')->daily();
Schedule::command('telemetry:flush')->everyMinute()->withoutOverlapping(5);
Schedule::command('yak:reconcile-pr-state')->hourly()->withoutOverlapping();
Schedule::command('yak:poll-drone-ci')->everyMinute()->withoutOverlapping();
Schedule::job(PollPullRequestReactionsJob::class)->hourly()->name('poll-pr-reactions');
Schedule::job(HibernateIdleDeploymentsJob::class)->everyMinute()->name('deployments:hibernate-idle')->withoutOverlapping();
Schedule::job(SweepExpiredDeploymentsJob::class)->hourly()->name('deployments:sweep-expired')->withoutOverlapping();
Schedule::job(WatchdogStuckDeploymentsJob::class)->everyTenMinutes()->name('deployments:watchdog-stuck')->withoutOverlapping();
Schedule::job(GarbageCollectTemplateSnapshotsJob::class)->hourly()->name('deployments:gc-template-snapshots')->withoutOverlapping();
Schedule::job(BackfillOpenPrDeploymentsJob::class)->hourly()->name('deployments:backfill-open-prs')->withoutOverlapping();
Schedule::command('yak:co-owner:sweep')->hourly()->withoutOverlapping();
Schedule::command('yak:preview-repair:sweep')->hourly()->withoutOverlapping();
Schedule::command('yak:prune-session-store')->daily();
Schedule::command('yak:prune-thread-events')->daily();
Schedule::command('yak:claude-accounts-expiry')->daily();
Schedule::command('yak:deliver-stack-results')->everyMinute()->withoutOverlapping();
Schedule::command('yak:check-stack-usage')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('yak:thread-sandboxes')->everyMinute()->withoutOverlapping();
Schedule::command('yak:reap-account-slots')->everyMinute()->withoutOverlapping();
Schedule::command('yak:close-idle-threads')->hourly()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [DeviceAuthorization::class]])->daily();
Schedule::command('yak:expire-teleports')->everyMinute()->withoutOverlapping();
Schedule::command('yak:dataset-sweep')->hourly()->withoutOverlapping();
Schedule::command('yak:ssh-sweep')->hourly()->withoutOverlapping();
