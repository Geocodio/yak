<?php

use App\Ai\Agents\TaskIntentClassifier;
use App\Channels\Slack\NotificationDriver as SlackNotificationDriver;
use App\Channels\Slack\SenderPolicy;
use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\ClarificationReplyJob;
use App\Jobs\RunYakJob;
use App\Jobs\SendNotificationJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Providers\ChannelServiceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Sign a Slack webhook payload using the v0:timestamp:body format.
 *
 * @return array{X-Slack-Request-Timestamp: string, X-Slack-Signature: string}
 */
function signSlackPayload(string $body, string $secret, ?string $timestamp = null): array
{
    $timestamp = $timestamp ?? (string) time();
    $basestring = "v0:{$timestamp}:{$body}";
    $signature = 'v0=' . hash_hmac('sha256', $basestring, $secret);

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
        'event_id' => $overrides['event_id'] ?? 'Ev' . Str::random(10),
        'event' => array_merge([
            'type' => 'app_mention',
            'text' => '<@U_BOT_ID> ' . $text,
            'channel' => 'C12345678',
            'ts' => '1234567890.123456',
            'user' => 'U_USER_ID',
        ], $overrides['event'] ?? []),
    ], array_diff_key($overrides, ['event' => true, 'event_id' => true]));

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
        'event_id' => $overrides['event_id'] ?? 'Ev' . Str::random(10),
        'event' => array_merge([
            'type' => 'message',
            'text' => $text,
            'channel' => $channel,
            'thread_ts' => $threadTs,
            'ts' => '1234567899.999999',
            'user' => 'U_REPLY_USER',
        ], $overrides['event'] ?? []),
    ], array_diff_key($overrides, ['event' => true, 'event_id' => true]));

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

it('classifies a question as Research mode via the intent classifier', function () {
    $secret = enableSlackChannel();
    Queue::fake();
    Http::fake(['*' => Http::response(['ok' => true])]);
    config(['yak.intent_classifier.enabled' => true]);
    TaskIntentClassifier::fake(['research']);

    Repository::factory()->default()->create();

    $body = slackMentionPayload('why does the export job retry twice?');
    $headers = signSlackPayload($body, $secret);

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ]);

    $task = YakTask::first();
    expect($task)->not->toBeNull();
    expect($task->mode)->toBe(TaskMode::Research);
});

it('skips the classifier when research: prefix is present', function () {
    $secret = enableSlackChannel();
    Queue::fake();
    Http::fake(['*' => Http::response(['ok' => true])]);
    config(['yak.intent_classifier.enabled' => true]);
    TaskIntentClassifier::fake()->preventStrayPrompts();

    Repository::factory()->default()->create();

    $body = slackMentionPayload('research: explain caching');
    $headers = signSlackPayload($body, $secret);

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ]);

    $task = YakTask::first();
    expect($task->mode)->toBe(TaskMode::Research);
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

    Queue::assertNotPushed(RunYakJob::class);
    Queue::assertPushed(SendNotificationJob::class, function (SendNotificationJob $job) {
        return $job->type === NotificationType::Clarification;
    });
});

it('omits the repo list from the clarification text (buttons carry it)', function () {
    $secret = enableSlackChannel();
    Queue::fake();
    Http::fake(['*' => Http::response(['ok' => true])]);

    Repository::factory()->create(['slug' => 'acme/one']);
    Repository::factory()->create(['slug' => 'acme/two']);

    $body = slackMentionPayload('fix something');
    $headers = signSlackPayload($body, $secret);

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ]);

    Queue::assertPushed(SendNotificationJob::class, function (SendNotificationJob $job) {
        return $job->type === NotificationType::Clarification
            && ! str_contains($job->message, 'acme/one')
            && ! str_contains($job->message, 'acme/two')
            && ! str_contains(strtolower($job->message), 'options');
    });
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

it('dispatches acknowledgment notification on task pickup', function () {
    $secret = enableSlackChannel();
    Queue::fake();

    Repository::factory()->default()->create();

    $body = slackMentionPayload('fix the bug');
    $headers = signSlackPayload($body, $secret);

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ]);

    Queue::assertPushed(SendNotificationJob::class, function (SendNotificationJob $job) {
        return $job->type === NotificationType::Acknowledgment;
    });
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
| Repo Clarification Reply
|--------------------------------------------------------------------------
*/

it('resolves repo from clarification reply using full slug', function () {
    $secret = enableSlackChannel();
    Queue::fake();

    Repository::factory()->create(['slug' => 'acme/marketing-site', 'is_active' => true]);

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'repo' => 'unknown',
        'status' => TaskStatus::AwaitingClarification,
        'session_id' => null,
        'slack_channel' => 'C_REPO_CLAR',
        'slack_thread_ts' => '5555555555.555555',
        'clarification_options' => ['acme/marketing-site', 'acme/deployer'],
        'clarification_expires_at' => now()->addDays(3),
    ]);

    $body = slackThreadReplyPayload('acme/marketing-site', 'C_REPO_CLAR', '5555555555.555555');
    $headers = signSlackPayload($body, $secret);

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    $task->refresh();
    expect($task->repo)->toBe('acme/marketing-site');
    expect($task->status)->toBe(TaskStatus::Pending);
    expect($task->clarification_options)->toBeNull();

    Queue::assertPushed(RunYakJob::class, fn (RunYakJob $job) => $job->task->id === $task->id);
    Queue::assertNotPushed(ClarificationReplyJob::class);
});

it('resolves repo from clarification reply using partial name', function () {
    $secret = enableSlackChannel();
    Queue::fake();

    Repository::factory()->create(['slug' => 'acme/marketing-site', 'is_active' => true]);

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'repo' => 'unknown',
        'status' => TaskStatus::AwaitingClarification,
        'session_id' => null,
        'slack_channel' => 'C_REPO_PART',
        'slack_thread_ts' => '6666666666.666666',
        'clarification_options' => ['acme/marketing-site', 'acme/deployer'],
        'clarification_expires_at' => now()->addDays(3),
    ]);

    $body = slackThreadReplyPayload('marketing-site', 'C_REPO_PART', '6666666666.666666');
    $headers = signSlackPayload($body, $secret);

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    $task->refresh();
    expect($task->repo)->toBe('acme/marketing-site');

    Queue::assertPushed(RunYakJob::class);
    Queue::assertNotPushed(ClarificationReplyJob::class);
});

it('resolves repo from clarification reply with spaces instead of hyphens', function () {
    $secret = enableSlackChannel();
    Queue::fake();

    Repository::factory()->create(['slug' => 'acme/marketing-site', 'is_active' => true]);

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'repo' => 'unknown',
        'status' => TaskStatus::AwaitingClarification,
        'session_id' => null,
        'slack_channel' => 'C_REPO_FUZZY',
        'slack_thread_ts' => '9999999999.999999',
        'clarification_options' => ['acme/marketing-site', 'acme/deployer'],
        'clarification_expires_at' => now()->addDays(3),
    ]);

    $body = slackThreadReplyPayload('marketing site', 'C_REPO_FUZZY', '9999999999.999999');
    $headers = signSlackPayload($body, $secret);

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    $task->refresh();
    expect($task->repo)->toBe('acme/marketing-site');

    Queue::assertPushed(RunYakJob::class);
});

it('prefers exact full-slug match over a shorter slug that appears as a substring', function () {
    // Regression: typing "acme/web-app" used to match "acme/web" first
    // because str_contains("acme/web-app", "acme/web") is true. The
    // resolver now exact-matches the full slug before falling back to
    // substring containment.
    $secret = enableSlackChannel();
    Queue::fake();

    Repository::factory()->create(['slug' => 'acme/web', 'is_active' => true]);
    Repository::factory()->create(['slug' => 'acme/web-app', 'is_active' => true]);

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'repo' => 'unknown',
        'status' => TaskStatus::AwaitingClarification,
        'session_id' => null,
        'slack_channel' => 'C_REPO_TRAP',
        'slack_thread_ts' => '1010101010.101010',
        'clarification_options' => ['acme/web', 'acme/web-app'],
        'clarification_expires_at' => now()->addDays(3),
    ]);

    $body = slackThreadReplyPayload('acme/web-app', 'C_REPO_TRAP', '1010101010.101010');
    $headers = signSlackPayload($body, $secret);

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    $task->refresh();
    expect($task->repo)->toBe('acme/web-app');

    Queue::assertPushed(RunYakJob::class);
});

it('re-prompts when repo clarification reply does not match any option', function () {
    $secret = enableSlackChannel();
    Queue::fake();

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'repo' => 'unknown',
        'status' => TaskStatus::AwaitingClarification,
        'session_id' => null,
        'slack_channel' => 'C_REPO_BAD',
        'slack_thread_ts' => '7777777777.777777',
        'clarification_options' => ['acme/marketing-site', 'acme/deployer'],
        'clarification_expires_at' => now()->addDays(3),
    ]);

    $body = slackThreadReplyPayload('something unrelated', 'C_REPO_BAD', '7777777777.777777');
    $headers = signSlackPayload($body, $secret);

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    $task->refresh();
    expect($task->repo)->toBe('unknown');
    expect($task->status)->toBe(TaskStatus::AwaitingClarification);

    Queue::assertNotPushed(RunYakJob::class);
    Queue::assertNotPushed(ClarificationReplyJob::class);
    Queue::assertPushed(SendNotificationJob::class, function (SendNotificationJob $job) {
        return $job->type === NotificationType::Clarification
            && ! str_contains($job->message, 'acme/marketing-site')
            && ! str_contains($job->message, 'acme/deployer')
            && ! str_contains(strtolower($job->message), 'options');
    });
});

it('dispatches ClarificationReplyJob for agent clarification (not repo)', function () {
    $secret = enableSlackChannel();
    Queue::fake();

    $task = YakTask::factory()->awaitingClarification()->create([
        'repo' => 'my-repo',
        'session_id' => 'sess_existing',
        'slack_channel' => 'C_AGENT_CLAR',
        'slack_thread_ts' => '8888888888.888888',
    ]);

    $body = slackThreadReplyPayload('Option A', 'C_AGENT_CLAR', '8888888888.888888');
    $headers = signSlackPayload($body, $secret);

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    Queue::assertPushed(ClarificationReplyJob::class);
    Queue::assertNotPushed(RunYakJob::class);
});

/*
|--------------------------------------------------------------------------
| Bot Message Filtering
|--------------------------------------------------------------------------
*/

/**
 * Build a top-level Slack direct message payload (`message.im`).
 *
 * @param  array<string, mixed>  $eventOverrides
 */
function slackDirectMessagePayload(string $text, array $eventOverrides = []): string
{
    return (string) json_encode([
        'type' => 'event_callback',
        'event_id' => 'Ev' . Str::random(10),
        'team_id' => 'T_WORKSPACE',
        'event' => array_merge([
            'type' => 'message',
            'channel_type' => 'im',
            'team' => 'T_WORKSPACE',
            'text' => $text,
            'channel' => 'D12345678',
            'ts' => '1234567890.654321',
            'user' => 'U_USER_ID',
        ], $eventOverrides),
    ]);
}

/**
 * Use the real SenderPolicy against faked Slack lookups. The workspace is
 * T_WORKSPACE; users not listed are full members of it.
 *
 * @param  array<string, array<string, mixed>>  $users
 */
function useRealSlackSenderPolicy(array $users = []): void
{
    app()->instance(SenderPolicy::class, new SenderPolicy);

    Http::fake([
        'slack.com/api/auth.test*' => Http::response(['ok' => true, 'team_id' => 'T_WORKSPACE']),
        'slack.com/api/users.info*' => function (Request $request) use ($users) {
            $userId = (string) $request['user'];

            return Http::response(['ok' => true, 'user' => array_merge([
                'id' => $userId,
                'team_id' => 'T_WORKSPACE',
                'is_restricted' => false,
                'is_ultra_restricted' => false,
                'deleted' => false,
            ], $users[$userId] ?? [])]);
        },
        '*' => Http::response(['ok' => true]),
    ]);
}

it('creates a task from a direct message to Yak', function () {
    $secret = enableSlackChannel();
    Queue::fake();
    useRealSlackSenderPolicy();

    Repository::factory()->default()->create(['slug' => 'my-app']);

    $body = slackDirectMessagePayload('fix the login bug');
    $headers = signSlackPayload($body, $secret);

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    $task = YakTask::sole();
    expect($task->description)->toBe('fix the login bug');
    expect($task->slack_channel)->toBe('D12345678');
    expect($task->slack_thread_ts)->toBe('1234567890.654321');

    Queue::assertPushed(RunYakJob::class);
});

it('ignores guests and Slack Connect users everywhere', function (string $userId, string $body) {
    $secret = enableSlackChannel();
    Queue::fake();
    useRealSlackSenderPolicy([
        'U_EXTERNAL' => ['team_id' => 'T_OTHER_ORG'],
        'U_GUEST' => ['is_restricted' => true],
        'U_SINGLE_CHANNEL_GUEST' => ['is_ultra_restricted' => true],
        'U_DEACTIVATED' => ['deleted' => true],
    ]);

    Repository::factory()->default()->create(['slug' => 'my-app']);
    YakTask::factory()->awaitingClarification()->create([
        'slack_channel' => 'C12345678',
        'slack_thread_ts' => '1111111111.111111',
    ]);

    $body = str_replace('U_SENDER', $userId, $body);
    $headers = signSlackPayload($body, $secret);

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful()->assertJson(['skipped' => 'not_workspace_member']);

    expect(YakTask::count())->toBe(1);
    Queue::assertNothingPushed();
})->with([
    'Slack Connect user' => 'U_EXTERNAL',
    'multi-channel guest' => 'U_GUEST',
    'single-channel guest' => 'U_SINGLE_CHANNEL_GUEST',
    'deactivated account' => 'U_DEACTIVATED',
])->with([
    'mention' => fn () => slackMentionPayload('fix the login bug', ['event' => ['user' => 'U_SENDER']]),
    'direct message' => fn () => slackDirectMessagePayload('fix the login bug', ['user' => 'U_SENDER']),
    'thread reply' => fn () => slackThreadReplyPayload('Option A', 'C12345678', '1111111111.111111', ['event' => ['user' => 'U_SENDER']]),
]);

it('ignores a sender whose payload names another organization', function () {
    $secret = enableSlackChannel();
    Queue::fake();
    useRealSlackSenderPolicy();

    $body = slackMentionPayload('fix the login bug', ['event' => ['user_team' => 'T_OTHER_ORG']]);
    $headers = signSlackPayload($body, $secret);

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful()->assertJson(['skipped' => 'not_workspace_member']);

    expect(YakTask::count())->toBe(0);
});

it('lets workspace members mention Yak with the real sender policy', function () {
    $secret = enableSlackChannel();
    Queue::fake();
    useRealSlackSenderPolicy();

    Repository::factory()->default()->create(['slug' => 'my-app']);

    $body = slackMentionPayload('fix the login bug');
    $headers = signSlackPayload($body, $secret);

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    expect(YakTask::count())->toBe(1);
});

it('treats a failed Slack lookup as an external user', function () {
    $secret = enableSlackChannel();
    Queue::fake();
    app()->instance(SenderPolicy::class, new SenderPolicy);
    Http::fake(['*' => Http::response(['ok' => false, 'error' => 'ratelimited'])]);

    $body = slackMentionPayload('fix the login bug');
    $headers = signSlackPayload($body, $secret);

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful()->assertJson(['skipped' => 'not_workspace_member']);

    expect(YakTask::count())->toBe(0);
});

it('does not create a task from an edited direct message', function () {
    $secret = enableSlackChannel();
    Queue::fake();
    Http::fake(['*' => Http::response(['ok' => true])]);

    Repository::factory()->default()->create(['slug' => 'my-app']);

    $body = slackDirectMessagePayload('fix the login bug', ['subtype' => 'message_changed']);
    $headers = signSlackPayload($body, $secret);

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    expect(YakTask::count())->toBe(0);
});

it('dispatches ClarificationReplyJob for a reply inside a direct message thread', function () {
    $secret = enableSlackChannel();
    Queue::fake();

    YakTask::factory()->awaitingClarification()->create([
        'slack_channel' => 'D12345678',
        'slack_thread_ts' => '1234567890.654321',
    ]);

    $body = slackDirectMessagePayload('the second option', ['thread_ts' => '1234567890.654321', 'ts' => '1234567899.000001']);
    $headers = signSlackPayload($body, $secret);

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    Queue::assertPushed(ClarificationReplyJob::class);
    expect(YakTask::count())->toBe(1);
});

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
| Event Deduplication
|--------------------------------------------------------------------------
*/

it('deduplicates Slack event retries using event_id', function () {
    $secret = enableSlackChannel();
    Queue::fake();
    Http::fake(['*' => Http::response(['ok' => true])]);

    Repository::factory()->default()->create(['slug' => 'my-app']);

    $body = slackMentionPayload('fix the login bug', ['event_id' => 'Ev_DUPLICATE_TEST']);
    $headers = signSlackPayload($body, $secret);

    $server = [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ];

    // First request — creates a task
    $this->call('POST', '/webhooks/slack', content: $body, server: $server)->assertSuccessful();
    expect(YakTask::count())->toBe(1);

    // Retry with same event_id — should be ignored
    $this->call('POST', '/webhooks/slack', content: $body, server: $server)->assertSuccessful();
    expect(YakTask::count())->toBe(1);

    // Third retry — still ignored
    $this->call('POST', '/webhooks/slack', content: $body, server: $server)->assertSuccessful();
    expect(YakTask::count())->toBe(1);
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

/*
|--------------------------------------------------------------------------
| @yak help command
|--------------------------------------------------------------------------
*/

it('posts a help card when the mention is just @yak with no body', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    Queue::fake();
    config()->set('yak.channels.slack.bot_token', 'xoxb-help');
    config()->set('yak.channels.slack.signing_secret', 'test-secret');
    (new ChannelServiceProvider(app()))->boot();

    $body = slackMentionPayload(text: '');
    $headers = signSlackPayload($body, 'test-secret');

    $this->postJson('/webhooks/slack', json_decode($body, true), $headers)->assertOk();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'chat.postMessage')
            && str_contains(json_encode($request['blocks'] ?? [], JSON_UNESCAPED_SLASHES), "I'm Yak");
    });

    // No task should have been created for the help query.
    expect(YakTask::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('posts a help card when the mention is @yak help', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    Queue::fake();
    config()->set('yak.channels.slack.bot_token', 'xoxb-help');
    config()->set('yak.channels.slack.signing_secret', 'test-secret');
    (new ChannelServiceProvider(app()))->boot();

    $body = slackMentionPayload(text: 'help');
    $headers = signSlackPayload($body, 'test-secret');

    $this->postJson('/webhooks/slack', json_decode($body, true), $headers)->assertOk();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'chat.postMessage')
            && str_contains(json_encode($request['blocks'] ?? [], JSON_UNESCAPED_SLASHES), 'How to ask for work');
    });

    expect(YakTask::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| app_home_opened — install DM
|--------------------------------------------------------------------------
*/

it('DMs the user the first time they open the App Home tab', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    config()->set('yak.channels.slack.bot_token', 'xoxb-home');
    config()->set('yak.channels.slack.signing_secret', 'test-secret');
    (new ChannelServiceProvider(app()))->boot();
    Cache::flush();

    $body = (string) json_encode([
        'type' => 'event_callback',
        'event_id' => 'Ev' . Str::random(10),
        'event' => [
            'type' => 'app_home_opened',
            'user' => 'U_NEWUSER',
            'tab' => 'home',
        ],
    ]);
    $headers = signSlackPayload($body, 'test-secret');

    $this->postJson('/webhooks/slack', json_decode($body, true), $headers)->assertOk();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'chat.postMessage')
            && $request['channel'] === 'U_NEWUSER'
            && str_contains(json_encode($request['blocks'] ?? [], JSON_UNESCAPED_SLASHES), "I'm Yak");
    });
});

it('does not DM the user on subsequent App Home opens', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    config()->set('yak.channels.slack.bot_token', 'xoxb-home');
    config()->set('yak.channels.slack.signing_secret', 'test-secret');
    (new ChannelServiceProvider(app()))->boot();
    Cache::flush();

    $buildPayload = fn () => (string) json_encode([
        'type' => 'event_callback',
        'event_id' => 'Ev' . Str::random(10),
        'event' => [
            'type' => 'app_home_opened',
            'user' => 'U_RETURNING',
            'tab' => 'home',
        ],
    ]);

    // First open — DM fires.
    $body1 = $buildPayload();
    $this->postJson('/webhooks/slack', json_decode($body1, true), signSlackPayload($body1, 'test-secret'))->assertOk();

    // Second open — no new DM.
    Http::fake(['*' => Http::response(['ok' => true])]);
    $body2 = $buildPayload();
    $this->postJson('/webhooks/slack', json_decode($body2, true), signSlackPayload($body2, 'test-secret'))->assertOk();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'chat.postMessage'));
});

it('does not treat normal task descriptions as help queries', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    Queue::fake();
    config()->set('yak.channels.slack.bot_token', 'xoxb-help');
    config()->set('yak.channels.slack.signing_secret', 'test-secret');
    (new ChannelServiceProvider(app()))->boot();

    Repository::factory()->create(['slug' => 'org/repo', 'is_default' => true, 'is_active' => true]);

    $body = slackMentionPayload(text: 'fix the login bug that helps users authenticate');
    $headers = signSlackPayload($body, 'test-secret');

    $this->postJson('/webhooks/slack', json_decode($body, true), $headers)->assertOk();

    // The task should be created — "help" appearing mid-sentence doesn't trigger the help card.
    expect(YakTask::count())->toBe(1);
});

it('records the Slack requester as starter and responsible', function () {
    $secret = enableSlackChannel();
    Queue::fake();
    Cache::flush();
    Http::fake([
        'slack.com/api/users.info*' => Http::response(['ok' => true, 'user' => ['profile' => ['display_name' => 'Slack Person']]]),
        '*' => Http::response(['ok' => true]),
    ]);
    Repository::factory()->default()->create(['slug' => 'my-app']);

    $body = slackMentionPayload('fix the login bug');
    $headers = signSlackPayload($body, $secret);

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    $task = YakTask::first();
    expect($task->author_name)->toBe('Slack Person')
        ->and($task->responsible_name)->toBe('Slack Person');
});
