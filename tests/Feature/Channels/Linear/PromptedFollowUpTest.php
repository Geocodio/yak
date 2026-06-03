<?php

use App\Jobs\ClarificationReplyJob;
use App\Jobs\RunFollowUpJob;
use App\Models\LinearOauthConnection;
use App\Models\YakTask;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

// Reuse helpers defined in WebhookTest.php (same test suite, loaded by Pest autoloader)

function postLinearPrompted(array $payload, string $secret): TestResponse
{
    $body = (string) json_encode($payload);

    return test()->call('POST', '/webhooks/linear', content: $body, server: [
        'HTTP_Linear-Event' => 'AgentSessionEvent',
        'HTTP_Linear-Signature' => signLinearPayload($body, $secret),
        'CONTENT_TYPE' => 'application/json',
    ]);
}

beforeEach(function (): void {
    $this->secret = enableLinearChannel();

    // Create the OAuth connection so resolveConnection() succeeds.
    LinearOauthConnection::factory()->create([
        'workspace_id' => TEST_WORKSPACE_ID,
        'installer_user_id' => TEST_YAK_ACTOR_ID,
    ]);

    Http::fake(['*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]])]);
});

// --- Follow-up on an open-PR task ---

it('creates a follow-up task and dispatches RunFollowUpJob when prompted on an open-PR task', function (): void {
    Bus::fake();

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'linear_agent_session_id' => 'sess-1',
        'pr_url' => 'https://github.com/org/repo/pull/10',
        'pr_merged_at' => null,
        'pr_closed_at' => null,
    ]);

    postLinearPrompted([
        'type' => 'AgentSessionEvent',
        'action' => 'prompted',
        'organizationId' => TEST_WORKSPACE_ID,
        'agentSession' => ['id' => 'sess-1'],
        'agentActivity' => ['content' => ['body' => 'also handle empty state']],
    ], $this->secret)->assertSuccessful();

    expect(YakTask::where('parent_task_id', $task->id)->exists())->toBeTrue();
    Bus::assertDispatched(RunFollowUpJob::class);
});

// --- Stop signal cancels the task ---

it('cancels the task when prompted with a stop signal', function (): void {
    Bus::fake();

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'linear_agent_session_id' => 'sess-1',
        'pr_url' => 'https://github.com/org/repo/pull/10',
        'pr_merged_at' => null,
        'pr_closed_at' => null,
    ]);

    postLinearPrompted([
        'type' => 'AgentSessionEvent',
        'action' => 'prompted',
        'organizationId' => TEST_WORKSPACE_ID,
        'agentSession' => ['id' => 'sess-1'],
        'agentActivity' => ['signal' => 'stop'],
    ], $this->secret)->assertSuccessful();

    $task->refresh();
    expect($task->status->value)->toBe('cancelled');
    expect($task->completed_at)->not->toBeNull();

    Bus::assertNotDispatched(RunFollowUpJob::class);
    expect(YakTask::where('parent_task_id', $task->id)->exists())->toBeFalse();
});

// --- Clarification reply ---

it('dispatches ClarificationReplyJob when prompted on an AwaitingClarification task', function (): void {
    Bus::fake();

    YakTask::factory()->awaitingClarification()->create([
        'source' => 'linear',
        'linear_agent_session_id' => 'sess-2',
    ]);

    postLinearPrompted([
        'type' => 'AgentSessionEvent',
        'action' => 'prompted',
        'organizationId' => TEST_WORKSPACE_ID,
        'agentSession' => ['id' => 'sess-2'],
        'agentActivity' => ['content' => ['body' => 'Here is my clarification']],
    ], $this->secret)->assertSuccessful();

    Bus::assertDispatched(ClarificationReplyJob::class);
    Bus::assertNotDispatched(RunFollowUpJob::class);
});

// --- Merged/closed PR decline ---

it('does not create a follow-up and dispatches nothing when the PR is already merged', function (): void {
    Bus::fake();

    YakTask::factory()->merged()->create([
        'source' => 'linear',
        'linear_agent_session_id' => 'sess-3',
    ]);

    postLinearPrompted([
        'type' => 'AgentSessionEvent',
        'action' => 'prompted',
        'organizationId' => TEST_WORKSPACE_ID,
        'agentSession' => ['id' => 'sess-3'],
        'agentActivity' => ['content' => ['body' => 'can you also fix the footer']],
    ], $this->secret)->assertSuccessful();

    Bus::assertNotDispatched(RunFollowUpJob::class);
    Bus::assertNotDispatched(ClarificationReplyJob::class);
    expect(YakTask::whereNotNull('parent_task_id')->exists())->toBeFalse();
});

// --- Unknown session ---

it('returns 200 and dispatches nothing when the session id matches no task', function (): void {
    Bus::fake();

    postLinearPrompted([
        'type' => 'AgentSessionEvent',
        'action' => 'prompted',
        'organizationId' => TEST_WORKSPACE_ID,
        'agentSession' => ['id' => 'unknown-session-id'],
        'agentActivity' => ['content' => ['body' => 'hello?']],
    ], $this->secret)->assertSuccessful();

    Bus::assertNotDispatched(RunFollowUpJob::class);
    Bus::assertNotDispatched(ClarificationReplyJob::class);
});
