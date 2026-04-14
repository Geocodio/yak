<?php

use App\Drivers\GitHubNotificationDriver;
use App\Drivers\LinearNotificationDriver;
use App\Drivers\SlackNotificationDriver;
use App\Enums\NotificationType;
use App\Jobs\SendNotificationJob;
use App\Models\GitHubInstallationToken;
use App\Models\LinearOauthConnection;
use App\Models\YakTask;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Slack Notification Driver
|--------------------------------------------------------------------------
*/

it('Slack: posts acknowledgment with dashboard link', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    config()->set('yak.channels.slack.bot_token', 'xoxb-test');

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'slack_channel' => 'C_ACK',
        'slack_thread_ts' => '1111111111.111111',
    ]);

    (new SlackNotificationDriver)->send($task, NotificationType::Acknowledgment, 'Horns down, hooves moving — on it! 🐃');

    assertSlackThreadReply('C_ACK', '1111111111.111111', 'Horns down, hooves moving');
    assertSlackThreadReply(textContains: "/tasks/{$task->id}");
});

it('Slack: posts progress update', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    config()->set('yak.channels.slack.bot_token', 'xoxb-test');

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'slack_channel' => 'C_PROG',
        'slack_thread_ts' => '2222222222.222222',
    ]);

    (new SlackNotificationDriver)->send($task, NotificationType::Progress, 'I found the issue... Fixing now');

    assertSlackThreadReply('C_PROG', '2222222222.222222', 'I found the issue');
});

it('Slack: posts clarification options', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    config()->set('yak.channels.slack.bot_token', 'xoxb-test');

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'slack_channel' => 'C_CLAR',
        'slack_thread_ts' => '3333333333.333333',
    ]);

    (new SlackNotificationDriver)->send($task, NotificationType::Clarification, 'Which approach should I use? ❓');

    assertSlackThreadReply('C_CLAR', '3333333333.333333', 'Which approach should I use?');
});

it('Slack: posts retry notification', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    config()->set('yak.channels.slack.bot_token', 'xoxb-test');

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'slack_channel' => 'C_RETRY',
        'slack_thread_ts' => '4444444444.444444',
    ]);

    (new SlackNotificationDriver)->send($task, NotificationType::Retry, 'CI failed, retrying');

    assertSlackThreadReply('C_RETRY', '4444444444.444444', 'CI failed, retrying');
});

it('Slack: posts result with PR link', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    config()->set('yak.channels.slack.bot_token', 'xoxb-test');

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'slack_channel' => 'C_RES',
        'slack_thread_ts' => '5555555555.555555',
    ]);

    (new SlackNotificationDriver)->send($task, NotificationType::Result, 'PR: https://github.com/org/repo/pull/42');

    assertSlackThreadReply('C_RES', '5555555555.555555', 'https://github.com/org/repo/pull/42');
});

it('Slack: posts expiry message', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    config()->set('yak.channels.slack.bot_token', 'xoxb-test');

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'slack_channel' => 'C_EXP',
        'slack_thread_ts' => '6666666666.666666',
    ]);

    (new SlackNotificationDriver)->send($task, NotificationType::Expiry, 'Clarification expired');

    assertSlackThreadReply('C_EXP', '6666666666.666666', 'Clarification expired');
});

it('Slack: all notifications include dashboard link', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    config()->set('yak.channels.slack.bot_token', 'xoxb-test');

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'slack_channel' => 'C_LINK',
        'slack_thread_ts' => '7777777777.777777',
    ]);

    foreach (NotificationType::cases() as $type) {
        (new SlackNotificationDriver)->send($task, $type, 'test message');
    }

    Http::assertSentCount(count(NotificationType::cases()));

    Http::assertSent(function ($request) use ($task) {
        if (! str_contains($request->url(), 'slack.com/api/chat.postMessage')) {
            return false;
        }

        return str_contains($request['text'], "/tasks/{$task->id}");
    });
});

/*
|--------------------------------------------------------------------------
| Linear Notification Driver
|--------------------------------------------------------------------------
*/

it('Linear: posts acknowledgment comment with dashboard link', function () {
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);
    LinearOauthConnection::factory()->create();

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'external_id' => 'issue-ack-uuid',
    ]);

    (new LinearNotificationDriver)->send($task, NotificationType::Acknowledgment, 'Horns down — trotting over to this issue! 🐃');

    assertLinearComment('Horns down');
    assertLinearComment("/tasks/{$task->id}");
});

it('Linear: posts result comment with PR link', function () {
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);
    LinearOauthConnection::factory()->create();

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'external_id' => 'issue-result-uuid',
        'pr_url' => 'https://github.com/org/repo/pull/99',
    ]);

    (new LinearNotificationDriver)->send($task, NotificationType::Result, 'PR: https://github.com/org/repo/pull/99');

    assertLinearComment('https://github.com/org/repo/pull/99');
});

it('Linear: updates issue state to done on result', function () {
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);
    LinearOauthConnection::factory()->create();
    config()->set('yak.channels.linear.done_state_id', 'done-state-123');

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'external_id' => 'issue-done-uuid',
    ]);

    (new LinearNotificationDriver)->send($task, NotificationType::Result, 'Done!');

    assertLinearStateUpdate('done-state-123');
});

it('Linear: updates issue state to cancelled on expiry', function () {
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);
    LinearOauthConnection::factory()->create();
    config()->set('yak.channels.linear.cancelled_state_id', 'cancelled-state-456');

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'external_id' => 'issue-expired-uuid',
    ]);

    (new LinearNotificationDriver)->send($task, NotificationType::Expiry, 'Clarification expired');

    assertLinearStateUpdate('cancelled-state-456');
});

it('Linear: does not update state when state ID is not configured', function () {
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);
    LinearOauthConnection::factory()->create();
    config()->set('yak.channels.linear.done_state_id', null);

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'external_id' => 'issue-nostate-uuid',
    ]);

    (new LinearNotificationDriver)->send($task, NotificationType::Result, 'Done!');

    assertLinearComment('Done!');
    Http::assertSentCount(1);
});

it('Linear: posts failure summary', function () {
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);
    LinearOauthConnection::factory()->create();

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'external_id' => 'issue-fail-uuid',
    ]);

    (new LinearNotificationDriver)->send($task, NotificationType::Result, 'Task failed: could not reproduce the issue');

    assertLinearComment('could not reproduce');
});

/*
|--------------------------------------------------------------------------
| GitHub Notification Driver (PR Comments)
|--------------------------------------------------------------------------
*/

it('GitHub: posts PR comment as fallback', function () {
    Http::fake(['*' => Http::response(['id' => 1])]);

    config()->set('yak.channels.github.installation_id', 12345);

    GitHubInstallationToken::factory()->create([
        'installation_id' => 12345,
        'token' => 'ghs_test_token',
        'expires_at' => now()->addHour(),
    ]);

    $task = YakTask::factory()->create([
        'source' => 'sentry',
        'repo' => 'org/my-repo',
        'pr_url' => 'https://github.com/org/my-repo/pull/42',
    ]);

    $driver = app(GitHubNotificationDriver::class);
    $driver->send($task, NotificationType::Result, 'Fix applied and tests pass.');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'repos/org/my-repo/issues/42/comments')
            && str_contains($request['body'], 'Fix applied and tests pass.');
    });
});

it('GitHub: comment includes dashboard link', function () {
    Http::fake(['*' => Http::response(['id' => 1])]);

    config()->set('yak.channels.github.installation_id', 12345);

    GitHubInstallationToken::factory()->create([
        'installation_id' => 12345,
        'token' => 'ghs_test_token',
        'expires_at' => now()->addHour(),
    ]);

    $task = YakTask::factory()->create([
        'source' => 'sentry',
        'repo' => 'org/my-repo',
        'pr_url' => 'https://github.com/org/my-repo/pull/10',
    ]);

    $driver = app(GitHubNotificationDriver::class);
    $driver->send($task, NotificationType::Acknowledgment, 'Working on it.');

    Http::assertSent(function ($request) use ($task) {
        return str_contains($request['body'], "/tasks/{$task->id}");
    });
});

it('GitHub: skips when no PR URL', function () {
    Http::fake(['*' => Http::response(['id' => 1])]);

    config()->set('yak.channels.github.installation_id', 12345);

    $task = YakTask::factory()->create([
        'source' => 'sentry',
        'repo' => 'org/my-repo',
        'pr_url' => null,
    ]);

    $driver = app(GitHubNotificationDriver::class);
    $driver->send($task, NotificationType::Result, 'should not send');

    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| SendNotificationJob - Driver Resolution
|--------------------------------------------------------------------------
*/

it('SendNotificationJob routes to Slack driver for Slack tasks', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);

    config()->set('yak.channels.slack', [
        'driver' => 'slack',
        'bot_token' => 'xoxb-test',
        'signing_secret' => 'test-secret',
    ]);

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'slack_channel' => 'C_JOB_TEST',
        'slack_thread_ts' => '9999999999.999999',
    ]);

    $job = new SendNotificationJob($task, NotificationType::Progress, 'fix pushed, waiting on CI');
    $job->handle();

    assertSlackThreadReply('C_JOB_TEST', '9999999999.999999', 'Still working on this');  // Personality fallback
});

it('SendNotificationJob routes to Linear driver for Linear tasks', function () {
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);

    config()->set('yak.channels.linear', [
        'driver' => 'linear',
        'webhook_secret' => 'test-secret',
    ]);
    LinearOauthConnection::factory()->create();

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'external_id' => 'issue-job-uuid',
    ]);

    $job = new SendNotificationJob($task, NotificationType::Acknowledgment, 'On it.');
    $job->handle();

    assertLinearComment('On it!');  // Personality fallback
});

it('SendNotificationJob falls back to GitHub PR comment when source channel is disabled', function () {
    Http::fake(['*' => Http::response(['id' => 1])]);

    config()->set('yak.channels.slack', [
        'driver' => 'slack',
        'bot_token' => '',
        'signing_secret' => '',
    ]);
    config()->set('yak.channels.github.installation_id', 12345);

    GitHubInstallationToken::factory()->create([
        'installation_id' => 12345,
        'token' => 'ghs_test_token',
        'expires_at' => now()->addHour(),
    ]);

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'repo' => 'org/repo',
        'pr_url' => 'https://github.com/org/repo/pull/55',
        'slack_channel' => 'C_DISABLED',
        'slack_thread_ts' => '1111111111.111111',
    ]);

    $job = new SendNotificationJob($task, NotificationType::Result, 'PR merged');
    $job->handle();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'repos/org/repo/issues/55/comments')
            && str_contains($request['body'], 'PR merged');
    });
});

it('SendNotificationJob sends nothing when source disabled and no PR URL', function () {
    Http::fake(['*' => Http::response()]);

    config()->set('yak.channels.slack', [
        'driver' => 'slack',
        'bot_token' => '',
        'signing_secret' => '',
    ]);

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'pr_url' => null,
    ]);

    $job = new SendNotificationJob($task, NotificationType::Progress, 'no route');
    $job->handle();

    Http::assertNothingSent();
});

it('SendNotificationJob falls back for non-Slack/Linear sources with PR', function () {
    Http::fake(['*' => Http::response(['id' => 1])]);

    config()->set('yak.channels.github.installation_id', 12345);

    GitHubInstallationToken::factory()->create([
        'installation_id' => 12345,
        'token' => 'ghs_test_token',
        'expires_at' => now()->addHour(),
    ]);

    $task = YakTask::factory()->create([
        'source' => 'sentry',
        'repo' => 'org/sentry-repo',
        'pr_url' => 'https://github.com/org/sentry-repo/pull/7',
    ]);

    $job = new SendNotificationJob($task, NotificationType::Result, 'Fix applied');
    $job->handle();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'repos/org/sentry-repo/issues/7/comments');
    });
});
