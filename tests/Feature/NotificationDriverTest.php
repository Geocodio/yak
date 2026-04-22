<?php

use App\Channels\Linear\NotificationDriver as LinearNotificationDriver;
use App\Channels\Slack\NotificationDriver as SlackNotificationDriver;
use App\Drivers\GitHubNotificationDriver;
use App\Enums\NotificationType;
use App\Jobs\SendNotificationJob;
use App\Models\GitHubInstallationToken;
use App\Models\LinearOauthConnection;
use App\Models\YakTask;
use Illuminate\Support\Facades\Cache;
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

it('Slack: @-mentions requester on Result notifications', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    config()->set('yak.channels.slack.bot_token', 'xoxb-test');

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'slack_channel' => 'C_MENTION',
        'slack_thread_ts' => '8888.8888',
        'slack_user_id' => 'U1234567',
    ]);

    (new SlackNotificationDriver)->send($task, NotificationType::Result, 'PR opened');

    assertSlackThreadReply(textContains: '<@U1234567>');
});

it('Slack: @-mentions requester on Clarification/Error/Expiry but not on Progress or Acknowledgment', function () {
    config()->set('yak.channels.slack.bot_token', 'xoxb-test');

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'slack_channel' => 'C_MIX',
        'slack_thread_ts' => '9999.9999',
        'slack_user_id' => 'U7654321',
    ]);

    $shouldMentionByType = [
        'acknowledgment' => false,
        'progress' => false,
        'retry' => false,
        'clarification' => true,
        'result' => true,
        'error' => true,
        'expiry' => true,
    ];

    foreach (NotificationType::cases() as $type) {
        $shouldMention = $shouldMentionByType[$type->value];
        Http::fake(['*' => Http::response(['ok' => true])]);

        (new SlackNotificationDriver)->send($task, $type, 'msg');

        Http::assertSent(function ($request) use ($shouldMention) {
            if (! str_contains($request->url(), 'slack.com/api/chat.postMessage')) {
                return false;
            }
            $hasMention = str_contains((string) ($request['text'] ?? ''), '<@U7654321>');

            return $hasMention === $shouldMention;
        });
    }
});

it('Slack: skips mention when slack_user_id is missing', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    config()->set('yak.channels.slack.bot_token', 'xoxb-test');

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'slack_channel' => 'C_NOMENT',
        'slack_thread_ts' => '1.2',
        'slack_user_id' => null,
    ]);

    (new SlackNotificationDriver)->send($task, NotificationType::Result, 'PR opened');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'slack.com/api/chat.postMessage')
            && ! str_contains((string) ($request['text'] ?? ''), '<@');
    });
});

it('Slack: applies an eyes reaction on Acknowledgment', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    config()->set('yak.channels.slack.bot_token', 'xoxb-test');

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'slack_channel' => 'C_REACT',
        'slack_thread_ts' => '111.111',
        'slack_message_ts' => '111.111',
    ]);

    (new SlackNotificationDriver)->send($task, NotificationType::Acknowledgment, 'On it!');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'slack.com/api/reactions.add')
            && $request['channel'] === 'C_REACT'
            && $request['timestamp'] === '111.111'
            && $request['name'] === 'eyes';
    });
});

it('Slack: reaction maps cover all visible status transitions', function () {
    config()->set('yak.channels.slack.bot_token', 'xoxb-test');

    $expectations = [
        'acknowledgment' => 'eyes',
        'progress' => 'construction',
        'result' => 'white_check_mark',
        'error' => 'x',
        'expiry' => 'x',
    ];

    foreach (NotificationType::cases() as $type) {
        Http::fake(['*' => Http::response(['ok' => true])]);

        $task = YakTask::factory()->create([
            'source' => 'slack',
            'slack_channel' => 'C_R' . $type->value,
            'slack_thread_ts' => '1.' . $type->value,
            'slack_message_ts' => '1.' . $type->value,
        ]);

        (new SlackNotificationDriver)->send($task, $type, 'msg');

        $expected = $expectations[$type->value] ?? null;

        if ($expected === null) {
            Http::assertNotSent(fn ($request) => str_contains($request->url(), 'reactions.add'));
        } else {
            Http::assertSent(fn ($request) => str_contains($request->url(), 'reactions.add')
                && $request['name'] === $expected
            );
        }
    }
});

it('Slack: skips reactions when slack_message_ts is missing', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    config()->set('yak.channels.slack.bot_token', 'xoxb-test');

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'slack_channel' => 'C_NORTS',
        'slack_thread_ts' => '1.1',
        'slack_message_ts' => null,
    ]);

    (new SlackNotificationDriver)->send($task, NotificationType::Acknowledgment, 'On it!');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'reactions.add'));
});

it('Slack: shows the first-time intro block on a user\'s initial acknowledgment', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    config()->set('yak.channels.slack.bot_token', 'xoxb-test');
    Cache::flush();

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'slack_channel' => 'C_INTRO',
        'slack_thread_ts' => '1.1',
        'slack_user_id' => 'UFIRSTTIMER',
    ]);

    (new SlackNotificationDriver)->send($task, NotificationType::Acknowledgment, 'On it!');

    assertSlackThreadReply(textContains: 'First time seeing me');
});

it('Slack: skips the intro block on a returning user\'s acknowledgment', function () {
    config()->set('yak.channels.slack.bot_token', 'xoxb-test');
    Cache::flush();

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'slack_channel' => 'C_SECOND',
        'slack_thread_ts' => '1.1',
        'slack_user_id' => 'URETURNING',
    ]);

    // First ack — seen flag gets set.
    Http::fake(['*' => Http::response(['ok' => true])]);
    (new SlackNotificationDriver)->send($task, NotificationType::Acknowledgment, 'On it!');

    // Second ack on a different task from the same user.
    Http::fake(['*' => Http::response(['ok' => true])]);
    $task2 = YakTask::factory()->create([
        'source' => 'slack',
        'slack_channel' => 'C_SECOND',
        'slack_thread_ts' => '2.2',
        'slack_user_id' => 'URETURNING',
    ]);

    (new SlackNotificationDriver)->send($task2, NotificationType::Acknowledgment, 'On it!');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'chat.postMessage')
            && ! str_contains(json_encode($request['blocks'] ?? [], JSON_UNESCAPED_SLASHES), 'First time seeing me');
    });
});

it('Slack: intro block is only attached to Acknowledgment, not to Progress or Result', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    config()->set('yak.channels.slack.bot_token', 'xoxb-test');
    Cache::flush();

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'slack_channel' => 'C_NOTACK',
        'slack_thread_ts' => '1.1',
        'slack_user_id' => 'UXYZ',
    ]);

    (new SlackNotificationDriver)->send($task, NotificationType::Progress, 'Still working…');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'chat.postMessage')
            && ! str_contains(json_encode($request['blocks'] ?? [], JSON_UNESCAPED_SLASHES), 'First time seeing me');
    });
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

        // Dashboard URL now lives inside a Block Kit button rather than
        // the text fallback — search the serialized blocks payload.
        return str_contains(json_encode($request['blocks'] ?? [], JSON_UNESCAPED_SLASHES), "/tasks/{$task->id}");
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
        'linear_agent_session_id' => 'session-ack',
    ]);

    (new LinearNotificationDriver)->send($task, NotificationType::Acknowledgment, 'Horns down — trotting over to this issue! 🐃');

    assertLinearActivity('Horns down');
    assertLinearActivity("/tasks/{$task->id}");
});

it('Linear: posts result comment with PR link', function () {
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);
    LinearOauthConnection::factory()->create();

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'external_id' => 'issue-result-uuid',
        'linear_agent_session_id' => 'session-result',
        'pr_url' => 'https://github.com/org/repo/pull/99',
    ]);

    (new LinearNotificationDriver)->send($task, NotificationType::Result, 'PR: https://github.com/org/repo/pull/99');

    assertLinearActivity('https://github.com/org/repo/pull/99');
});

it('Linear: updates issue state to done on result', function () {
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);
    LinearOauthConnection::factory()->create();
    config()->set('yak.channels.linear.done_state_id', 'done-state-123');

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'external_id' => 'issue-done-uuid',
        'linear_agent_session_id' => 'session-done',
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
        'linear_agent_session_id' => 'session-expired',
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
        'linear_agent_session_id' => 'session-nostate',
    ]);

    (new LinearNotificationDriver)->send($task, NotificationType::Result, 'Done!');

    assertLinearActivity('Done!');
    Http::assertSentCount(1);
});

it('Linear: posts failure summary', function () {
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);
    LinearOauthConnection::factory()->create();

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'external_id' => 'issue-fail-uuid',
        'linear_agent_session_id' => 'session-fail',
    ]);

    (new LinearNotificationDriver)->send($task, NotificationType::Result, 'Task failed: could not reproduce the issue');

    assertLinearActivity('could not reproduce');
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

    assertSlackThreadReply('C_JOB_TEST', '9999999999.999999', 'fix pushed, waiting on CI');  // Personality fallback interpolates context
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
        'linear_agent_session_id' => 'session-job',
    ]);

    $job = new SendNotificationJob($task, NotificationType::Acknowledgment, 'On it.');
    $job->handle();

    assertLinearActivity('On it — On it.');  // Personality fallback interpolates context
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
