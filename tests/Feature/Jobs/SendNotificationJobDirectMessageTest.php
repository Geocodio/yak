<?php

use App\Channels\ChannelRegistry;
use App\Channels\Slack\NotificationDriver as SlackNotificationDriver;
use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\SendNotificationJob;
use App\Models\GitHubInstallationToken;
use App\Models\LinearOauthConnection;
use App\Models\TelemetryEvent;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('yak.channels.slack', ['driver' => 'slack', 'bot_token' => 'xoxb-test', 'signing_secret' => 'secret']);
    config()->set('yak.channels.github.installation_id', 4242);
    GitHubInstallationToken::factory()->create(['installation_id' => 4242, 'token' => 'ghs_test', 'expires_at' => now()->addHour()]);
    Http::fake([
        'slack.com/api/users.lookupByEmail*' => Http::response(['ok' => true, 'user' => ['id' => 'U_DM']]),
        'slack.com/*' => Http::response(['ok' => true]),
        'api.github.com/*' => Http::response(['id' => 1]),
    ]);
});

/**
 * @return list<Request>
 */
function directMessagePosts(): array
{
    return Http::recorded()
        ->map(fn (array $pair): Request => $pair[0])
        ->filter(fn (Request $request): bool => str_contains($request->url(), 'chat.postMessage') && ! isset($request['thread_ts']))
        ->values()
        ->all();
}

function sendNotification(YakTask $task, NotificationType $type, string $message, ?User $actingUser = null, bool $directMessagesOnly = false): void
{
    (new SendNotificationJob($task, $type, $message, actingUser: $actingUser, directMessagesOnly: $directMessagesOnly))
        ->handle(app(ChannelRegistry::class));
}

test('a dashboard PR result sends the starter a DM with a fixed headline and no PR comment', function () {
    $starter = User::factory()->create();
    $task = YakTask::factory()->success()->create([
        'source' => 'dashboard',
        'description' => "Fix null postal code in batch geocoder\n\nMore detail.",
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'started_by_user_id' => $starter->id,
        'responsible_user_id' => $starter->id,
    ]);

    sendNotification($task, NotificationType::Result, 'PR created: https://github.com/acme/web/pull/9');

    expect(directMessagePosts())->toHaveCount(1)
        ->and(directMessagePosts()[0]['channel'])->toBe('U_DM')
        ->and(directMessagePosts()[0]['text'])->toBe('PR ready: Fix null postal code in batch geocoder');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.github.com'));
});

test('a Slack clarification mentions in the thread and sends no DM', function () {
    $task = YakTask::factory()->create([
        'source' => 'slack',
        'slack_channel' => 'C1',
        'slack_thread_ts' => '1.1',
        'slack_user_id' => 'U_REQUESTER',
    ]);

    sendNotification($task, NotificationType::Clarification, 'Which repo?');

    assertSlackThreadReply('C1', '1.1', '<@U_REQUESTER>');
    expect(directMessagePosts())->toBe([]);
});

test('a Slack follow-up result mentions both people in the thread', function () {
    $task = YakTask::factory()->success()->create([
        'source' => 'slack',
        'slack_channel' => 'C2',
        'slack_thread_ts' => '2.2',
        'slack_user_id' => 'U_REQUESTER',
        'slack_follow_up_user_id' => 'U_REPLIER',
    ]);

    sendNotification($task, NotificationType::Result, 'Pushed changes.');

    assertSlackThreadReply('C2', '2.2', '<@U_REQUESTER> <@U_REPLIER>');
});

test('an expired question posts in the thread without a mention or a DM', function () {
    $starter = User::factory()->create();
    $task = YakTask::factory()->create([
        'source' => 'slack',
        'status' => TaskStatus::Expired,
        'slack_channel' => 'C3',
        'slack_thread_ts' => '3.3',
        'slack_user_id' => 'U_REQUESTER',
        'started_by_user_id' => $starter->id,
    ]);

    sendNotification($task, NotificationType::Expiry, 'Closing this one.');

    assertSlackThreadReply('C3', '3.3');
    Http::assertNotSent(fn (Request $request): bool => str_contains((string) ($request['text'] ?? ''), '<@U_REQUESTER>'));
    expect(directMessagePosts())->toBe([]);
});

test('a cancel by someone else names them in the headline', function () {
    $starter = User::factory()->create();
    $other = User::factory()->create(['name' => 'Other Person']);
    $task = YakTask::factory()->create([
        'source' => 'dashboard',
        'status' => TaskStatus::Cancelled,
        'description' => 'Add CSV export',
        'started_by_user_id' => $starter->id,
    ]);

    sendNotification($task, NotificationType::Cancelled, 'Cancelled from the dashboard.', actingUser: $other);

    expect(directMessagePosts()[0]['text'])->toBe('Cancelled by Other Person: Add CSV export');
});

test('direct-messages-only skips the source channel', function () {
    $starter = User::factory()->create();
    config()->set('yak.channels.linear.webhook_secret', 'linear-secret');
    LinearOauthConnection::factory()->create();
    $task = YakTask::factory()->awaitingClarification()->create([
        'source' => 'linear',
        'linear_agent_session_id' => 'session-dm',
        'started_by_user_id' => $starter->id,
    ]);

    sendNotification($task, NotificationType::Clarification, 'Which repo?', directMessagesOnly: true);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.linear.app'));
    expect(directMessagePosts())->toHaveCount(1);
});

test('setup and render headlines name the right thing', function (array $taskAttributes, NotificationType $type, string $expectedHeadline) {
    $starter = User::factory()->create();

    if (($taskAttributes['parent_task_id'] ?? null) === 'a parent task') {
        $taskAttributes['parent_task_id'] = YakTask::factory()->create()->id;
    }

    $task = YakTask::factory()->create([
        'source' => 'dashboard',
        'repo' => 'acme/web',
        'description' => 'Add CSV export',
        'started_by_user_id' => $starter->id,
        ...$taskAttributes,
    ]);

    sendNotification($task, $type, 'Details.');

    expect(directMessagePosts()[0]['text'])->toBe($expectedHeadline);
})->with([
    'setup finished' => [['mode' => TaskMode::Setup, 'status' => TaskStatus::Success], NotificationType::Result, 'Setup finished: acme/web'],
    'setup failed' => [['mode' => TaskMode::Setup, 'status' => TaskStatus::Failed], NotificationType::Error, 'Setup failed: acme/web'],
    'video failed' => [['status' => TaskStatus::Success, 'pr_url' => 'https://github.com/acme/web/pull/1'], NotificationType::Error, 'Video failed: Add CSV export'],
    'task failed' => [['status' => TaskStatus::Failed], NotificationType::Error, 'Failed: Add CSV export'],
    'answer ready' => [['status' => TaskStatus::Success, 'pr_url' => null, 'mode' => TaskMode::Research], NotificationType::Result, 'Answer ready: Add CSV export'],
    'needs an answer' => [['status' => TaskStatus::AwaitingClarification], NotificationType::Clarification, 'Yak needs an answer: Add CSV export'],
    'reminder' => [['status' => TaskStatus::AwaitingClarification], NotificationType::Reminder, 'Still waiting for your answer: Add CSV export'],
    'pr updated' => [['status' => TaskStatus::Success, 'pr_url' => 'https://github.com/acme/web/pull/1', 'parent_task_id' => 'a parent task'], NotificationType::Result, 'PR updated: Add CSV export'],
]);

test('a failing DM is logged and does not fail the job or repeat the thread message', function () {
    $this->mock(SlackNotificationDriver::class)
        ->shouldReceive('sendDirect')
        ->andThrow(new RuntimeException('Slack down'));
    $starter = User::factory()->create();
    $task = YakTask::factory()->create(['source' => 'dashboard', 'started_by_user_id' => $starter->id, 'status' => TaskStatus::Failed]);

    sendNotification($task, NotificationType::Error, 'Broke.');

    expect(directMessagePosts())->toBe([]);
});

test('records a slack_dm telemetry event per direct message', function () {
    $starter = User::factory()->create();
    $task = YakTask::factory()->create(['source' => 'dashboard', 'started_by_user_id' => $starter->id, 'status' => TaskStatus::Failed]);

    sendNotification($task, NotificationType::Error, 'Broke.');

    expect(TelemetryEvent::where('name', 'notification.sent')->sole()->properties)
        ->toMatchArray(['type' => 'error', 'channel' => 'slack_dm']);
});
