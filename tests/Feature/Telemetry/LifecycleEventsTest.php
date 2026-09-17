<?php

use App\Actions\RecordPullRequestOutcome;
use App\Channels\ChannelRegistry;
use App\Channels\GitHub\AppService;
use App\Enums\DeploymentStatus;
use App\Enums\NotificationType;
use App\Jobs\CreatePullRequestJob;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\SendNotificationJob;
use App\Models\BranchDeployment;
use App\Models\GitHubInstallationToken;
use App\Models\PendingSteeringMessage;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\TelemetryEvent;
use App\Models\YakTask;
use App\Providers\ChannelServiceProvider;
use App\Services\FollowUpTaskFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function telemetryEvent(string $name): TelemetryEvent
{
    return TelemetryEvent::where('name', $name)->sole();
}

function bootGitHubTelemetryRoutes(string $secret): void
{
    config()->set('yak.channels.github', array_merge(
        (array) config('yak.channels.github'),
        ['app_id' => '123', 'private_key' => 'key', 'webhook_secret' => $secret, 'installation_id' => 99],
    ));
    (new ChannelServiceProvider(app()))->boot();
}

test('an unhandled GitHub event is recorded as a skipped webhook with its reason', function () {
    bootGitHubTelemetryRoutes('secret');
    $body = json_encode(['action' => 'labeled', 'repository' => ['full_name' => 'acme/widgets']]);

    $this->call('POST', '/webhooks/github', content: $body, server: [
        'HTTP_X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $body, 'secret'),
        'HTTP_X-GitHub-Event' => 'star',
        'CONTENT_TYPE' => 'application/json',
    ])->assertOk();

    $event = telemetryEvent('webhook.received');
    expect($event->source)->toBe('github')
        ->and($event->properties)->toMatchArray(['channel' => 'github', 'event' => 'star.labeled', 'outcome' => 'skipped', 'repo' => 'acme/widgets'])
        ->and($event->properties['reason'])->toContain('unhandled event')
        ->and($event->duration_ms)->toBeInt();
});

test('a filtered Sentry alert is recorded as a rejected webhook with the filter reason', function () {
    config()->set('yak.channels.sentry', [
        'driver' => 'sentry', 'auth_token' => 't', 'webhook_secret' => 'sentry-secret', 'org_slug' => 'org',
        'region_url' => 'https://us.sentry.io', 'min_events' => 5, 'min_actionability' => 'medium',
    ]);
    (new ChannelServiceProvider(app()))->boot();

    $body = json_encode(['action' => 'triggered', 'data' => ['issue' => [
        'id' => '1', 'title' => 'Boom', 'culprit' => 'app.js', 'count' => 1, 'seerActionability' => 'high', 'project' => ['slug' => 'proj'],
    ], 'event' => ['tags' => []]]]);

    $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'HTTP_Sentry-Hook-Signature' => hash_hmac('sha256', $body, 'sentry-secret'),
        'CONTENT_TYPE' => 'application/json',
    ])->assertOk();

    $event = telemetryEvent('webhook.received');
    expect($event->properties)->toMatchArray(['channel' => 'sentry', 'event' => 'issue_alert.triggered', 'outcome' => 'rejected', 'project' => 'proj'])
        ->and($event->properties['reason'])->toBeString()->not->toBe('');
});

test('a Slack help query is recorded as an accepted webhook and a help_card feature', function () {
    config()->set('yak.channels.slack', ['driver' => 'slack', 'bot_token' => 'xoxb', 'signing_secret' => 'slack-secret']);
    (new ChannelServiceProvider(app()))->boot();
    Http::fake(['*' => Http::response(['ok' => true])]);

    $body = json_encode(['type' => 'event_callback', 'event_id' => 'Ev1', 'event' => [
        'type' => 'app_mention', 'text' => '<@U_BOT> help', 'channel' => 'C1', 'ts' => '1.1', 'user' => 'U1',
    ]]);
    $timestamp = (string) time();

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $timestamp,
        'HTTP_X-Slack-Signature' => 'v0=' . hash_hmac('sha256', "v0:{$timestamp}:{$body}", 'slack-secret'),
        'CONTENT_TYPE' => 'application/json',
    ])->assertOk();

    expect(telemetryEvent('webhook.received')->properties)->toMatchArray(['channel' => 'slack', 'event' => 'app_mention', 'outcome' => 'accepted', 'reason' => 'help_card'])
        ->and(telemetryEvent('feature.used')->properties)->toBe(['feature' => 'help_card']);
});

test('a stale Linear delivery is recorded as skipped', function () {
    config()->set('yak.channels.linear', ['driver' => 'linear', 'webhook_secret' => 'linear-secret']);
    (new ChannelServiceProvider(app()))->boot();

    $body = json_encode(['type' => 'AgentSessionEvent', 'action' => 'created', 'webhookTimestamp' => 1]);

    $this->call('POST', '/webhooks/linear', content: $body, server: [
        'HTTP_Linear-Signature' => hash_hmac('sha256', $body, 'linear-secret'),
        'HTTP_Linear-Event' => 'AgentSessionEvent',
        'CONTENT_TYPE' => 'application/json',
    ])->assertOk();

    expect(telemetryEvent('webhook.received')->properties)->toMatchArray(['channel' => 'linear', 'event' => 'AgentSessionEvent.created', 'outcome' => 'skipped', 'reason' => 'stale webhook']);
});

test('sending a notification records notification.sent with the type, channel and timing', function () {
    config()->set('yak.channels.slack', ['driver' => 'slack', 'bot_token' => 'xoxb', 'signing_secret' => 's']);
    config()->set('yak.telemetry.enabled', true);
    Http::fake(['*' => Http::response(['ok' => true])]);
    (new ChannelServiceProvider(app()))->boot();

    $task = YakTask::factory()->create(['source' => 'slack', 'slack_channel' => 'C1', 'slack_thread_ts' => '1.1']);

    (new SendNotificationJob($task, NotificationType::Progress, 'Working on it'))->handle(app(ChannelRegistry::class));

    $event = telemetryEvent('notification.sent');
    expect($event->yak_task_id)->toBe($task->id)
        ->and($event->properties)->toMatchArray(['type' => 'progress', 'channel' => 'slack'])
        ->and($event->properties['length'])->toBeGreaterThan(0)
        ->and($event->duration_ms)->toBeInt();
});

test('a deployment status change records the transition and how long the previous state lasted', function () {
    Http::fake();
    $deployment = BranchDeployment::factory()->starting()->create(['updated_at' => now()->subSeconds(45)]);

    $deployment->update(['status' => DeploymentStatus::Running]);

    $event = telemetryEvent('deployment.status_changed');
    expect($event->subject_type)->toBe('BranchDeployment')
        ->and($event->subject_id)->toBe($deployment->id)
        ->and($event->repo)->toBe($deployment->repository->slug)
        ->and($event->properties)->toMatchArray(['from' => 'starting', 'to' => 'running'])
        ->and($event->duration_ms)->toBeGreaterThanOrEqual(44_000);
});

test('opening a PR stamps pr_opened_at and records pr.opened with request-to-PR latency', function () {
    config()->set('yak.channels.github.installation_id', 99);
    GitHubInstallationToken::create(['installation_id' => 99, 'token' => 'ghs', 'expires_at' => now()->addHour()]);
    Http::fake([
        'api.github.com/repos/*/pulls?*' => Http::response([]),
        'api.github.com/repos/*/pulls' => Http::response(['number' => 7, 'html_url' => 'https://github.com/acme/widgets/pull/7']),
        'api.github.com/repos/*/issues/*/labels' => Http::response(['ok' => true]),
        'api.github.com/repos/*/compare/*' => Http::response(['files' => []]),
    ]);

    Repository::factory()->create(['slug' => 'acme/widgets', 'github_full_name' => 'acme/widgets']);
    $task = YakTask::factory()->awaitingCi()->create(['repo' => 'acme/widgets', 'created_at' => now()->subMinutes(12), 'attempts' => 1]);

    (new CreatePullRequestJob($task, isLargeChange: true))->handle(app(AppService::class));

    $task->refresh();
    $event = telemetryEvent('pr.opened');

    expect($task->pr_opened_at)->not->toBeNull()
        ->and($event->properties)->toMatchArray(['pr_number' => 7, 'large_change' => true, 'attempts' => 1])
        ->and($event->duration_ms)->toBeGreaterThanOrEqual(12 * 60_000 - 1000);
});

test('recording a merge stamps the chain and reviews and records pr.merged with time open', function () {
    $root = YakTask::factory()->success()->create([
        'pr_url' => 'https://github.com/acme/widgets/pull/9',
        'pr_number' => 9,
        'created_at' => now()->subDays(3),
        'pr_opened_at' => now()->subDays(2),
    ]);
    $child = YakTask::factory()->success()->create(['parent_task_id' => $root->id, 'pr_url' => $root->pr_url, 'pr_number' => 9]);
    $review = PrReview::factory()->create(['pr_url' => $root->pr_url]);

    $recorded = app(RecordPullRequestOutcome::class)->record($root->pr_url, merged: true, mergedAt: now(), via: 'reconcile', humanCommits: 2);

    expect($recorded)->toBeTrue()
        ->and($root->refresh()->pr_merged_at)->not->toBeNull()
        ->and($root->human_commits)->toBe(2)
        ->and($child->refresh()->pr_merged_at)->not->toBeNull()
        ->and($review->refresh()->pr_merged_at)->not->toBeNull()
        ->and($review->pr_closed_at)->not->toBeNull();

    $event = telemetryEvent('pr.merged');
    expect($event->yak_task_id)->toBe($root->id)
        ->and($event->properties)->toMatchArray(['via' => 'reconcile', 'pr_number' => 9, 'follow_ups' => 1, 'human_commits' => 2, 'reviews' => 1])
        ->and($event->duration_ms)->toBeGreaterThanOrEqual(2 * 86_400_000 - 1000)
        ->and($event->properties['request_to_outcome_ms'])->toBeGreaterThanOrEqual(3 * 86_400_000 - 1000);

    // Recording the same outcome again neither re-stamps nor re-emits.
    app(RecordPullRequestOutcome::class)->record($root->pr_url, merged: true);
    expect(TelemetryEvent::where('name', 'pr.merged')->count())->toBe(1);
});

test('recording an outcome for an unknown PR returns false', function () {
    expect(app(RecordPullRequestOutcome::class)->record('https://github.com/x/y/pull/1', merged: false))->toBeFalse()
        ->and(TelemetryEvent::count())->toBe(0);
});

test('the reconciler backfills a merged PR the webhook missed and counts human commits on open ones', function () {
    config()->set('yak.channels.github.installation_id', 99);
    config()->set('yak.git_user_email', 'yak@noreply.github.com');
    GitHubInstallationToken::create(['installation_id' => 99, 'token' => 'ghs', 'expires_at' => now()->addHour()]);

    Repository::factory()->create(['slug' => 'acme/widgets', 'github_full_name' => 'acme/widgets']);
    $merged = YakTask::factory()->success()->create(['repo' => 'acme/widgets', 'pr_url' => 'https://github.com/acme/widgets/pull/1', 'pr_number' => 1]);
    $open = YakTask::factory()->success()->create(['repo' => 'acme/widgets', 'pr_url' => 'https://github.com/acme/widgets/pull/2', 'pr_number' => 2]);
    $recentlyChecked = YakTask::factory()->success()->create(['repo' => 'acme/widgets', 'pr_url' => 'https://github.com/acme/widgets/pull/3', 'pr_number' => 3, 'pr_state_checked_at' => now()]);

    Http::fake([
        'api.github.com/repos/acme/widgets/pulls/1/commits*' => Http::response([
            ['author' => ['login' => 'yak-bot[bot]'], 'commit' => ['author' => ['email' => 'yak@noreply.github.com']]],
            ['author' => ['login' => 'mathias'], 'commit' => ['author' => ['email' => 'm@example.com']]],
        ]),
        'api.github.com/repos/acme/widgets/pulls/1' => Http::response(['state' => 'closed', 'merged' => true, 'merged_at' => '2026-09-15T10:00:00Z', 'closed_at' => '2026-09-15T10:00:00Z']),
        'api.github.com/repos/acme/widgets/pulls/2/commits*' => Http::response([
            ['author' => null, 'commit' => ['author' => ['email' => 'someone@example.com']]],
            ['author' => null, 'commit' => ['author' => ['email' => 'yak@noreply.github.com']]],
        ]),
        'api.github.com/repos/acme/widgets/pulls/2' => Http::response(['state' => 'open', 'merged' => false]),
        'api.github.com/*' => Http::response([]),
    ]);

    $this->artisan('yak:reconcile-pr-state')
        ->expectsOutputToContain('Checked 2 open PR(s); recorded 1 newly merged/closed.')
        ->assertSuccessful();

    expect($merged->refresh()->pr_merged_at)->not->toBeNull()
        ->and($merged->human_commits)->toBe(1)
        ->and($open->refresh()->pr_merged_at)->toBeNull()
        ->and($open->human_commits)->toBe(1)
        ->and($open->pr_state_checked_at)->not->toBeNull()
        ->and($recentlyChecked->refresh()->human_commits)->toBeNull()
        ->and(telemetryEvent('pr.merged')->properties['via'])->toBe('reconcile');
});

test('the reconciler is a no-op when disabled or when GitHub is not configured', function () {
    config()->set('yak.telemetry.reconcile_pr_state', false);
    $this->artisan('yak:reconcile-pr-state')->assertSuccessful();

    config()->set('yak.telemetry.reconcile_pr_state', true);
    config()->set('yak.channels.github.installation_id', 0);
    $this->artisan('yak:reconcile-pr-state')->expectsOutputToContain('nothing to reconcile')->assertSuccessful();
});

test('creating a follow-up records the conversation round as a feature event', function () {
    Queue::fake();
    $root = YakTask::factory()->success()->create(['pr_url' => 'https://github.com/acme/widgets/pull/5', 'source' => 'slack']);

    $child = app(FollowUpTaskFactory::class)->create($root, 'please also fix the tests', 'github', 'mathias');

    $event = telemetryEvent('feature.used');
    expect($event->yak_task_id)->toBe($child?->id)
        ->and($event->source)->toBe('github')
        ->and($event->properties)->toMatchArray(['feature' => 'follow_up', 'round' => 1, 'root_task_id' => $root->id]);
});

test('queueing a steering message records a feature event against the active task', function () {
    $task = YakTask::factory()->running()->create(['source' => 'linear']);

    PendingSteeringMessage::queueFor($task, 'also bump the version', 'dashboard');

    $event = telemetryEvent('feature.used');
    expect($event->yak_task_id)->toBe($task->id)
        ->and($event->source)->toBe('dashboard')
        ->and($event->properties)->toMatchArray(['feature' => 'steering', 'status' => 'running']);
});

test('a CI result records ci.result timed from the last agent run', function () {
    Queue::fake();
    Http::fake();
    Repository::factory()->create(['slug' => 'acme/widgets', 'ci_system' => 'github_actions']);
    $task = YakTask::factory()->awaitingCi()->create(['repo' => 'acme/widgets', 'attempts' => 1]);
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'agent_finished_at' => now()->subMinutes(4)]);

    config(['yak.max_attempts' => 2]);
    (new ProcessCIResultJob($task, passed: false, output: 'tests failed'))->handle();

    $event = telemetryEvent('ci.result');
    expect($event->properties)->toMatchArray(['passed' => false, 'attempts' => 1, 'synthetic' => false])
        ->and($event->duration_ms)->toBeGreaterThanOrEqual(4 * 60_000 - 1000)
        ->and($task->refresh()->status->value)->toBe('retrying');
});
