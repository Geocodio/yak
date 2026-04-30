<?php

use App\Channels\Slack\InteractivityTracker;
use App\Enums\TaskStatus;
use App\Jobs\ClarificationReplyJob;
use App\Jobs\RunYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Providers\ChannelServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    InteractivityTracker::reset();
});

/**
 * Sign a Slack webhook payload using the v0:timestamp:body format.
 *
 * @return array{HTTP_X-Slack-Request-Timestamp: string, HTTP_X-Slack-Signature: string}
 */
function signSlackInteractivePayload(string $body, string $secret): array
{
    $timestamp = (string) time();
    $basestring = "v0:{$timestamp}:{$body}";
    $signature = 'v0=' . hash_hmac('sha256', $basestring, $secret);

    return [
        'HTTP_X-Slack-Request-Timestamp' => $timestamp,
        'HTTP_X-Slack-Signature' => $signature,
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ];
}

function enableSlackForInteractive(): string
{
    $secret = 'test-interactive-secret';

    config()->set('yak.channels.slack', [
        'driver' => 'slack',
        'bot_token' => 'xoxb-test',
        'signing_secret' => $secret,
    ]);

    (new ChannelServiceProvider(app()))->boot();

    return $secret;
}

/**
 * Build a block_actions interaction payload body as Slack sends it:
 * `payload={json...}` URL-encoded form data.
 */
function buildClarifyButtonBody(int $taskId, string $option, int $optionIndex = 0, ?string $responseUrl = null): string
{
    $payload = [
        'type' => 'block_actions',
        'user' => ['id' => 'U12345'],
        'actions' => [[
            'action_id' => 'yak_clarify_' . $optionIndex,
            'value' => $taskId . '|' . $option,
        ]],
    ];

    if ($responseUrl !== null) {
        $payload['response_url'] = $responseUrl;
    }

    return 'payload=' . urlencode((string) json_encode($payload));
}

it('rejects requests with an invalid Slack signature', function () {
    enableSlackForInteractive();
    $body = buildClarifyButtonBody(1, 'acme/web');

    $this->call('POST', '/webhooks/slack/interactive', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => (string) time(),
        'HTTP_X-Slack-Signature' => 'v0=invalid',
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ])->assertForbidden();
});

it('dispatches ClarificationReplyJob when a clarification button is clicked', function () {
    $secret = enableSlackForInteractive();
    Queue::fake();

    $task = YakTask::factory()->create([
        'status' => TaskStatus::AwaitingClarification,
        'source' => 'slack',
        'clarification_options' => ['acme/web', 'acme/api'],
    ]);

    $body = buildClarifyButtonBody($task->id, 'acme/web');

    $this->call('POST', '/webhooks/slack/interactive', content: $body,
        server: signSlackInteractivePayload($body, $secret)
    )->assertOk();

    Queue::assertPushed(ClarificationReplyJob::class, function (ClarificationReplyJob $job) use ($task) {
        return $job->task->id === $task->id && $job->replyText === 'acme/web';
    });
});

it('ignores unrecognised action_ids', function () {
    $secret = enableSlackForInteractive();
    Queue::fake();

    $task = YakTask::factory()->create([
        'status' => TaskStatus::AwaitingClarification,
    ]);

    $payload = json_encode([
        'type' => 'block_actions',
        'user' => ['id' => 'U1'],
        'actions' => [[
            'action_id' => 'some_other_button',
            'value' => $task->id . '|anything',
        ]],
    ]);
    $body = 'payload=' . urlencode($payload);

    $this->call('POST', '/webhooks/slack/interactive', content: $body,
        server: signSlackInteractivePayload($body, $secret)
    )->assertOk();

    Queue::assertNothingPushed();
});

it('ignores interactions for tasks not in AwaitingClarification', function () {
    $secret = enableSlackForInteractive();
    Queue::fake();

    $task = YakTask::factory()->create([
        'status' => TaskStatus::Running,
    ]);

    $body = buildClarifyButtonBody($task->id, 'acme/web');

    $this->call('POST', '/webhooks/slack/interactive', content: $body,
        server: signSlackInteractivePayload($body, $secret)
    )->assertOk();

    Queue::assertNothingPushed();
});

it('ignores interactions for unknown tasks', function () {
    $secret = enableSlackForInteractive();
    Queue::fake();

    $body = buildClarifyButtonBody(99999, 'whatever');

    $this->call('POST', '/webhooks/slack/interactive', content: $body,
        server: signSlackInteractivePayload($body, $secret)
    )->assertOk();

    Queue::assertNothingPushed();
});

it('resolves repo and dispatches RunYakJob when a repo-clarification button is clicked', function () {
    $secret = enableSlackForInteractive();
    Queue::fake();

    Repository::factory()->create(['slug' => 'acme/marketing-site', 'is_active' => true]);

    $task = YakTask::factory()->create([
        'status' => TaskStatus::AwaitingClarification,
        'source' => 'slack',
        'repo' => 'unknown',
        'session_id' => null,
        'clarification_options' => ['acme/marketing-site', 'acme/deployer'],
    ]);

    $body = buildClarifyButtonBody($task->id, 'acme/marketing-site');

    $this->call('POST', '/webhooks/slack/interactive', content: $body,
        server: signSlackInteractivePayload($body, $secret)
    )->assertOk();

    $task->refresh();
    expect($task->repo)->toBe('acme/marketing-site');
    expect($task->status)->toBe(TaskStatus::Pending);

    Queue::assertPushed(RunYakJob::class, fn (RunYakJob $job) => $job->task->id === $task->id);
    Queue::assertNotPushed(ClarificationReplyJob::class);
});

it('replaces the original message via response_url when a click resolves', function () {
    // Without this hook a successful click is visually indistinguishable
    // from one that never reached the server, which made the buttons
    // feel broken when they actually worked.
    $secret = enableSlackForInteractive();
    Queue::fake();
    Http::fake();

    Repository::factory()->create(['slug' => 'acme/marketing-site', 'is_active' => true]);

    $task = YakTask::factory()->create([
        'status' => TaskStatus::AwaitingClarification,
        'source' => 'slack',
        'repo' => 'unknown',
        'session_id' => null,
        'clarification_options' => ['acme/marketing-site'],
    ]);

    $responseUrl = 'https://hooks.slack.com/actions/T0/123/abc';
    $body = buildClarifyButtonBody($task->id, 'acme/marketing-site', responseUrl: $responseUrl);

    $this->call('POST', '/webhooks/slack/interactive', content: $body,
        server: signSlackInteractivePayload($body, $secret)
    )->assertOk();

    Http::assertSent(function ($request) use ($responseUrl) {
        if ($request->url() !== $responseUrl) {
            return false;
        }

        $data = $request->data();

        return ($data['replace_original'] ?? null) === true
            && str_contains((string) ($data['text'] ?? ''), 'acme/marketing-site');
    });
});

it('records every signed interactive payload for the health check', function () {
    // The Interactivity health check uses received-count to detect the
    // "Slack app has no Request URL configured" failure mode. Any
    // payload that reaches the controller proves the URL is wired up.
    $secret = enableSlackForInteractive();
    Queue::fake();
    Http::fake();

    $task = YakTask::factory()->create([
        'status' => TaskStatus::AwaitingClarification,
        'source' => 'slack',
        'clarification_options' => ['acme/web'],
    ]);

    $body = buildClarifyButtonBody($task->id, 'acme/web');

    $this->call('POST', '/webhooks/slack/interactive', content: $body,
        server: signSlackInteractivePayload($body, $secret)
    )->assertOk();

    expect(InteractivityTracker::receivedCount())->toBe(1);
});

it('does not record receipt when the signature is invalid', function () {
    enableSlackForInteractive();
    $body = buildClarifyButtonBody(1, 'acme/web');

    $this->call('POST', '/webhooks/slack/interactive', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => (string) time(),
        'HTTP_X-Slack-Signature' => 'v0=invalid',
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ])->assertForbidden();

    expect(InteractivityTracker::receivedCount())->toBe(0);
});

it('does not ack when repo resolution fails to match an option', function () {
    $secret = enableSlackForInteractive();
    Queue::fake();
    Http::fake();

    Repository::factory()->create(['slug' => 'acme/marketing-site', 'is_active' => true]);

    $task = YakTask::factory()->create([
        'status' => TaskStatus::AwaitingClarification,
        'source' => 'slack',
        'repo' => 'unknown',
        'session_id' => null,
        // 'acme/totally-different' isn't in the option list so resolve()
        // re-prompts and the task stays in AwaitingClarification.
        'clarification_options' => ['acme/marketing-site'],
    ]);

    $responseUrl = 'https://hooks.slack.com/actions/T0/999/xyz';
    $body = buildClarifyButtonBody($task->id, 'acme/totally-different', responseUrl: $responseUrl);

    $this->call('POST', '/webhooks/slack/interactive', content: $body,
        server: signSlackInteractivePayload($body, $secret)
    )->assertOk();

    // No POST to response_url: the buttons remain so the user can retry.
    Http::assertNotSent(fn ($request) => $request->url() === $responseUrl);
});
