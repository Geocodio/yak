<?php

use App\Drivers\SlackNotificationDriver;
use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\ClarificationReplyJob;
use App\Jobs\RunYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Providers\ChannelServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Sign a Slack webhook payload using the v0:timestamp:body format.
 *
 * @return array{X-Slack-Request-Timestamp: string, X-Slack-Signature: string}
 */
function signSlackPayload(string $body, string $secret, ?string $timestamp = null): array
{
    $timestamp = $timestamp ?? (string) time();
    $basestring = "v0:{$timestamp}:{$body}";
    $signature = 'v0='.hash_hmac('sha256', $basestring, $secret);

    return [
        'X-Slack-Request-Timestamp' => $timestamp,
        'X-Slack-Signature' => $signature,
    ];
}

/**
 * Build a Slack app_mention event payload.
 *
 * @param  array<string, mixed>  $overrides
 */
function slackMentionPayload(string $text = 'fix the login bug', array $overrides = []): string
{
    $payload = array_merge([
        'type' => 'event_callback',
        'event' => array_merge([
            'type' => 'app_mention',
            'text' => '<@U_BOT_ID> '.$text,
            'channel' => 'C12345678',
            'ts' => '1234567890.123456',
            'user' => 'U_USER_ID',
        ], $overrides['event'] ?? []),
    ], array_diff_key($overrides, ['event' => true]));

    return (string) json_encode($payload);
}

/**
 * Build a Slack thread reply event payload.
 *
 * @param  array<string, mixed>  $overrides
 */
function slackThreadReplyPayload(string $text, string $channel, string $threadTs, array $overrides = []): string
{
    $payload = array_merge([
        'type' => 'event_callback',
        'event' => array_merge([
            'type' => 'message',
            'text' => $text,
            'channel' => $channel,
            'thread_ts' => $threadTs,
            'ts' => '1234567899.999999',
            'user' => 'U_REPLY_USER',
        ], $overrides['event'] ?? []),
    ], array_diff_key($overrides, ['event' => true]));

    return (string) json_encode($payload);
}

function enableSlackChannel(): string
{
    $secret = 'test-slack-signing-secret';

    config()->set('yak.channels.slack', [
        'driver' => 'slack',
        'bot_token' => 'xoxb-test-token',
        'signing_secret' => $secret,
    ]);

    // Re-register routes so the Slack route is available
    (new ChannelServiceProvider(app()))->boot();

    return $secret;
}

/*
|--------------------------------------------------------------------------
| Signature Verification
|--------------------------------------------------------------------------
*/

it('rejects requests with invalid Slack signature', function () {
    $secret = enableSlackChannel();
    $body = slackMentionPayload();

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => (string) time(),
        'HTTP_X-Slack-Signature' => 'v0=invalid_signature',
        'CONTENT_TYPE' => 'application/json',
    ])->assertForbidden();
});

it('rejects requests with missing Slack signature', function () {
    enableSlackChannel();
    $body = slackMentionPayload();

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'CONTENT_TYPE' => 'application/json',
    ])->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| URL Verification
|--------------------------------------------------------------------------
*/

it('responds to Slack URL verification challenge', function () {
    $secret = enableSlackChannel();
    $body = (string) json_encode([
        'type' => 'url_verification',
        'challenge' => 'test_challenge_token',
    ]);
    $headers = signSlackPayload($body, $secret);

    $response = $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ]);

    $response->assertSuccessful();
    $response->assertJson(['challenge' => 'test_challenge_token']);
});

/*
|--------------------------------------------------------------------------
| @yak Mention Creates Task
|--------------------------------------------------------------------------
*/

it('creates a task from a valid @yak mention', function () {
    $secret = enableSlackChannel();
    Queue::fake();
    Http::fake(['*' => Http::response(['ok' => true])]);

    Repository::factory()->default()->create(['slug' => 'my-app']);

    $body = slackMentionPayload('fix the login bug');
    $headers = signSlackPayload($body, $secret);

    $response = $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ]);

    $response->assertSuccessful();

    $task = YakTask::first();
    expect($task)->not->toBeNull();
    expect($task->source)->toBe('slack');
    expect($task->repo)->toBe('my-app');
    expect($task->description)->toBe('fix the login bug');
    expect($task->mode)->toBe(TaskMode::Fix);
    expect($task->status)->toBe(TaskStatus::Pending);
    expect($task->slack_channel)->toBe('C12345678');
    expect($task->slack_thread_ts)->toBe('1234567890.123456');
    expect($task->external_id)->toStartWith('SLACK-');

    Queue::assertPushed(RunYakJob::class, function (RunYakJob $job) use ($task) {
        return $job->task->id === $task->id;
    });
});

it('generates external_id as SLACK-{date}-{sequence}', function () {
    $secret = enableSlackChannel();
    Queue::fake();
    Http::fake(['*' => Http::response(['ok' => true])]);

    Repository::factory()->default()->create();

    $date = now()->format('Ymd');

    // Create first task
    $body1 = slackMentionPayload('first task');
    $headers1 = signSlackPayload($body1, $secret);
    $this->call('POST', '/webhooks/slack', content: $body1, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers1['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers1['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ]);

    // Create second task (different channel/ts to avoid unique constraint)
    $body2 = slackMentionPayload('second task', ['event' => ['channel' => 'C99999999', 'ts' => '9999999999.999999']]);
    $headers2 = signSlackPayload($body2, $secret);
    $this->call('POST', '/webhooks/slack', content: $body2, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers2['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers2['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ]);

    $tasks = YakTask::orderBy('id')->get();
    expect($tasks[0]->external_id)->toBe("SLACK-{$date}-1");
    expect($tasks[1]->external_id)->toBe("SLACK-{$date}-2");
});

/*
|--------------------------------------------------------------------------
| Research Prefix
|--------------------------------------------------------------------------
*/

it('creates a research task from research: prefix', function () {
    $secret = enableSlackChannel();
    Queue::fake();
    Http::fake(['*' => Http::response(['ok' => true])]);

    Repository::factory()->default()->create();

    $body = slackMentionPayload('research: investigate memory leak patterns');
    $headers = signSlackPayload($body, $secret);

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ]);

    $task = YakTask::first();
    expect($task)->not->toBeNull();
    expect($task->mode)->toBe(TaskMode::Research);
    expect($task->description)->toBe('investigate memory leak patterns');
});

/*
|--------------------------------------------------------------------------
| Explicit Repo Detection
|--------------------------------------------------------------------------
*/

it('detects explicit repo from in {repo}: syntax', function () {
    $secret = enableSlackChannel();
    Queue::fake();
    Http::fake(['*' => Http::response(['ok' => true])]);

    Repository::factory()->create(['slug' => 'acme/api']);

    $body = slackMentionPayload('in acme/api: fix the auth middleware');
    $headers = signSlackPayload($body, $secret);

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ]);

    $task = YakTask::first();
    expect($task)->not->toBeNull();
    expect($task->repo)->toBe('acme/api');
    expect($task->description)->toBe('fix the auth middleware');
});

it('enters awaiting_clarification when no repo specified and multiple active repos (slack)', function () {
    $secret = enableSlackChannel();
    Queue::fake();
    Http::fake(['*' => Http::response(['ok' => true])]);

    Repository::factory()->create(['slug' => 'other-repo']);
    Repository::factory()->default()->create(['slug' => 'default-repo']);

    $body = slackMentionPayload('fix something');
    $headers = signSlackPayload($body, $secret);

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ]);

    $task = YakTask::first();
    expect($task)->not->toBeNull();
    expect($task->repo)->toBe('unknown');
    expect($task->status->value)->toBe('awaiting_clarification');
    expect($task->clarification_options)->toBeArray()->toHaveCount(2);

    Queue::assertNothingPushed();
});

it('uses single active repo without clarification for slack', function () {
    $secret = enableSlackChannel();
    Queue::fake();
    Http::fake(['*' => Http::response(['ok' => true])]);

    Repository::factory()->create(['slug' => 'only-repo']);

    $body = slackMentionPayload('fix something');
    $headers = signSlackPayload($body, $secret);

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ]);

    $task = YakTask::first();
    expect($task)->not->toBeNull();
    expect($task->repo)->toBe('only-repo');

    Queue::assertPushed(RunYakJob::class);
});

/*
|--------------------------------------------------------------------------
| Acknowledgment
|--------------------------------------------------------------------------
*/

it('posts acknowledgment to Slack thread with dashboard link', function () {
    $secret = enableSlackChannel();
    Queue::fake();
    Http::fake(['*' => Http::response(['ok' => true])]);

    Repository::factory()->default()->create();

    $body = slackMentionPayload('fix the bug');
    $headers = signSlackPayload($body, $secret);

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ]);

    $task = YakTask::first();

    assertSlackThreadReply('C12345678', '1234567890.123456', "/tasks/{$task->id}");
});

/*
|--------------------------------------------------------------------------
| Thread Reply for Clarification
|--------------------------------------------------------------------------
*/

it('dispatches ClarificationReplyJob for thread reply on awaiting clarification task', function () {
    $secret = enableSlackChannel();
    Queue::fake();

    $task = YakTask::factory()->awaitingClarification()->create([
        'slack_channel' => 'C_TEST_CHANNEL',
        'slack_thread_ts' => '1111111111.111111',
    ]);

    $body = slackThreadReplyPayload('Option A', 'C_TEST_CHANNEL', '1111111111.111111');
    $headers = signSlackPayload($body, $secret);

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ]);

    Queue::assertPushed(ClarificationReplyJob::class, function (ClarificationReplyJob $job) use ($task) {
        return $job->task->id === $task->id && $job->replyText === 'Option A';
    });
});

it('ignores thread reply when no task is awaiting clarification', function () {
    $secret = enableSlackChannel();
    Queue::fake();

    // Task exists but is running (not awaiting clarification)
    YakTask::factory()->running()->create([
        'slack_channel' => 'C_TEST_CHANNEL',
        'slack_thread_ts' => '1111111111.111111',
    ]);

    $body = slackThreadReplyPayload('some reply', 'C_TEST_CHANNEL', '1111111111.111111');
    $headers = signSlackPayload($body, $secret);

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    Queue::assertNotPushed(ClarificationReplyJob::class);
});

/*
|--------------------------------------------------------------------------
| Bot Message Filtering
|--------------------------------------------------------------------------
*/

it('ignores bot messages to prevent loops', function () {
    $secret = enableSlackChannel();
    Queue::fake();

    $body = (string) json_encode([
        'type' => 'event_callback',
        'event' => [
            'type' => 'app_mention',
            'text' => '<@U_BOT_ID> do something',
            'channel' => 'C12345678',
            'ts' => '1234567890.123456',
            'user' => 'U_USER_ID',
            'bot_id' => 'B_BOT_ID',
        ],
    ]);
    $headers = signSlackPayload($body, $secret);

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    Queue::assertNotPushed(RunYakJob::class);
    expect(YakTask::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Notification Driver
|--------------------------------------------------------------------------
*/

it('SlackNotificationDriver posts threaded replies', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);

    config()->set('yak.channels.slack.bot_token', 'xoxb-test-token');

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'slack_channel' => 'C_NOTIFY_CHAN',
        'slack_thread_ts' => '2222222222.222222',
    ]);

    $driver = new SlackNotificationDriver;
    $driver->send($task, NotificationType::Progress, 'I found the issue... Fixing now and adding a test');

    assertSlackThreadReply('C_NOTIFY_CHAN', '2222222222.222222', 'I found the issue');
});

it('SlackNotificationDriver posts result to thread', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);

    config()->set('yak.channels.slack.bot_token', 'xoxb-test-token');

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'slack_channel' => 'C_RESULT_CHAN',
        'slack_thread_ts' => '3333333333.333333',
    ]);

    $driver = new SlackNotificationDriver;
    $driver->send($task, NotificationType::Result, 'PR created: https://github.com/org/repo/pull/42');

    assertSlackThreadReply('C_RESULT_CHAN', '3333333333.333333', 'PR created');
});

it('SlackNotificationDriver skips when bot token is missing', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);

    config()->set('yak.channels.slack.bot_token', '');

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'slack_channel' => 'C_CHAN',
        'slack_thread_ts' => '4444444444.444444',
    ]);

    $driver = new SlackNotificationDriver;
    $driver->send($task, NotificationType::Progress, 'should not be sent');

    Http::assertNothingSent();
});
