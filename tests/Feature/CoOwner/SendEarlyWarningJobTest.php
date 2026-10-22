<?php

use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\SendEarlyWarningJob;
use App\Jobs\SendNotificationJob;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\CoOwner\RiskAreaGuesser;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function warningJobFixture(array $taskAttributes = [], string $areaName = 'API middleware and HTTP kernel', string $githubName = 'o/r'): YakTask
{
    config()->set('yak.channels.slack.bot_token', 'xoxb-test');
    configureFakeGitHubApp();
    $owner = User::factory()->create(['name' => 'Ada Lovelace']);
    $repository = Repository::factory()->create(['github_full_name' => $githubName, 'default_branch' => 'main', 'default_responsible_user_id' => $owner->id]);
    fakeYakFiles(['config.yml' => "version: 1\nco_owner_gate:\n  mode: enforce\n", 'risk-profile.yml' => riskProfileYaml()]);

    $guesser = Mockery::mock(RiskAreaGuesser::class);
    $guesser->shouldReceive('guess')->andReturn([$areaName])->byDefault();
    app()->instance(RiskAreaGuesser::class, $guesser);

    return YakTask::factory()->create([
        'repo' => $repository->slug, 'source' => 'slack', 'mode' => TaskMode::Fix, 'status' => TaskStatus::Pending,
        'started_by_user_id' => null, 'slack_user_id' => null, 'description' => 'change the middleware',
        'slack_channel' => 'C1', 'slack_thread_ts' => '55.66',
        ...$taskAttributes,
    ]);
}

it('posts the thread reply and stores the message and the shown state', function () {
    Http::fake(['slack.com/api/*' => Http::response(['ok' => true, 'channel' => 'C1', 'ts' => '77.88'])]);
    $task = warningJobFixture();

    SendEarlyWarningJob::dispatchSync($task);

    Http::assertSent(fn (Request $sent) => str_ends_with($sent->url(), 'chat.postMessage')
        && $sent['channel'] === 'C1' && $sent['thread_ts'] === '55.66'
        && str_contains($sent['blocks'][0]['text']['text'], 'API middleware and HTTP kernel (high risk)'));
    expect($task->fresh()->early_warning)->toMatchArray([
        'state' => 'shown', 'slackChannel' => 'C1', 'slackTs' => '77.88',
        'areas' => [['name' => 'API middleware and HTTP kernel', 'risk' => 'high']],
    ]);
});

it('records the warning on the dashboard without a Slack call or notification', function () {
    Http::fake();
    Bus::fake([SendNotificationJob::class]);
    $task = warningJobFixture(['source' => 'dashboard']);

    SendEarlyWarningJob::dispatchSync($task);

    Http::assertNothingSent();
    Bus::assertNotDispatched(SendNotificationJob::class);
    expect($task->fresh()->early_warning['state'])->toBe('shown');
});

it('sends Linear a Progress notification', function () {
    Bus::fake([SendNotificationJob::class]);
    $task = warningJobFixture(['source' => 'linear']);

    SendEarlyWarningJob::dispatchSync($task);

    Bus::assertDispatched(SendNotificationJob::class, fn (SendNotificationJob $job) => $job->type === NotificationType::Progress
        && str_contains($job->message, 'On it. Heads up: this will likely touch API middleware and HTTP kernel (high risk)')
        && str_ends_with($job->message, 'You can tag someone on the task page.'));
    expect($task->fresh()->early_warning['state'])->toBe('shown');
});

it('sends nothing when there is nothing to warn about', function () {
    Http::fake();
    $task = warningJobFixture(['mode' => TaskMode::Research]);

    SendEarlyWarningJob::dispatchSync($task);

    Http::assertNothingSent();
    expect($task->fresh()->early_warning)->toBeNull();
});

it('skips a task that already has a warning, is no longer running or has a co-owner request', function (string $case) {
    Http::fake();
    $task = warningJobFixture();
    match ($case) {
        'warned' => $task->update(['early_warning' => ['state' => 'shown', 'areas' => [], 'slackChannel' => null, 'slackTs' => null, 'taggedAt' => null]]),
        'finished' => $task->update(['status' => TaskStatus::Cancelled]),
        'requested' => CoOwnerRequest::factory()->create(['task_id' => $task->id, 'repository_id' => $task->repository->id]),
    };

    SendEarlyWarningJob::dispatchSync($task);

    Http::assertNothingSent();
})->with(['warned', 'finished', 'requested']);

it('logs a Slack failure and does not throw', function () {
    Http::fake(['slack.com/api/*' => Http::response(['ok' => false, 'error' => 'channel_not_found'])]);
    Log::shouldReceive('channel->warning')->once();
    $task = warningJobFixture();

    SendEarlyWarningJob::dispatchSync($task);

    expect($task->fresh()->early_warning)->toMatchArray(['state' => 'shown', 'slackTs' => null]);
});

it('reports an unexpected failure instead of throwing', function () {
    Http::fake(fn () => throw new RuntimeException('boom'));
    $task = warningJobFixture();
    $task->repository->update(['github_full_name' => 'o/r']);
    app()->instance(RiskAreaGuesser::class, Mockery::mock(RiskAreaGuesser::class, fn ($mock) => $mock->shouldReceive('guess')->andThrow(new RuntimeException('boom'))));

    SendEarlyWarningJob::dispatchSync($task);

    expect($task->fresh()->early_warning)->toBeNull();
});

it('escapes area, repository and fallback names for Slack', function () {
    Http::fake(['slack.com/api/*' => Http::response(['ok' => true, 'channel' => 'C1', 'ts' => '1.2'])]);
    $task = warningJobFixture(areaName: 'A<b>&c', githubName: 'o/<r>');
    fakeYakFiles(['config.yml' => "version: 1\nco_owner_gate:\n  mode: enforce\n", 'risk-profile.yml' => str_replace('API middleware and HTTP kernel', 'A<b>&c', riskProfileYaml())]);
    $task->repository->defaultResponsibleUser->update(['name' => 'Ada<x> Lovelace']);

    SendEarlyWarningJob::dispatchSync($task);

    Http::assertSent(function (Request $sent) {
        $text = $sent['blocks'][0]['text']['text'];
        $button = $sent['blocks'][1]['elements'][1]['text']['text'];

        return str_contains($text, 'A&lt;b&gt;&amp;c (high risk)') && str_contains($text, 'o/&lt;r&gt;') && ! str_contains($text, '<b>')
            && $button === 'No thanks, ask Ada<x> later';
    });
});
