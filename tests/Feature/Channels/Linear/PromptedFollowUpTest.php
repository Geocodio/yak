<?php

use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\ResearchFollowUpJob;
use App\Jobs\RunFollowUpJob;
use App\Jobs\RunYakJob;
use App\Jobs\SendNotificationJob;
use App\Models\LinearOauthConnection;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\ClarificationMessage;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

it('answers the single pending question when prompted on an AwaitingClarification task', function (): void {
    Bus::fake();

    $task = YakTask::factory()->withClarificationQuestions([sampleQuestion('scope', ['Small', 'Large'])->toArray()])->create([
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

    Bus::assertDispatched(RunYakJob::class);
    Bus::assertNotDispatched(RunFollowUpJob::class);
    expect($task->fresh()->clarificationAnswersAwaitingResume()['answers']['scope']['other'])->toBe('Here is my clarification');
});

it('links to the form instead of dispatching when several questions are pending', function (): void {
    Bus::fake();

    $task = YakTask::factory()->withClarificationQuestions()->create([
        'source' => 'linear',
        'linear_agent_session_id' => 'sess-multi',
    ]);

    postLinearPrompted([
        'type' => 'AgentSessionEvent',
        'action' => 'prompted',
        'organizationId' => TEST_WORKSPACE_ID,
        'agentSession' => ['id' => 'sess-multi'],
        'agentActivity' => ['content' => ['body' => 'Small']],
    ], $this->secret)->assertSuccessful()->assertJson(['handled' => 'clarification_form_link']);

    $link = trim((string) json_encode(ClarificationMessage::pointToForm($task->fresh())), '"');

    Http::assertSent(fn ($request): bool => str_contains($request->body(), $link));
    Bus::assertNotDispatched(RunYakJob::class);
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
});

it('records the Linear actor name as the follow-up author when present', function (): void {
    Bus::fake();

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'linear_agent_session_id' => 'sess-au',
        'pr_url' => 'https://github.com/org/repo/pull/11',
        'pr_merged_at' => null,
        'pr_closed_at' => null,
    ]);

    postLinearPrompted([
        'type' => 'AgentSessionEvent',
        'action' => 'prompted',
        'organizationId' => TEST_WORKSPACE_ID,
        'agentSession' => ['id' => 'sess-au'],
        'agentActivity' => ['content' => ['body' => 'tighten the copy']],
        'actor' => ['name' => 'Mathias'],
    ], $this->secret)->assertSuccessful();

    expect(YakTask::where('parent_task_id', $task->id)->first()->author_name)->toBe('Mathias');
});

// --- Reply to a repo question ---

it('resolves the repo from a numeric reply and dispatches RunYakJob', function (): void {
    Queue::fake();
    Repository::factory()->create(['slug' => 'acme/api']);
    Repository::factory()->create(['slug' => 'acme/billing']);

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'linear_agent_session_id' => 'sess-repo',
        'repo' => 'unknown',
        'session_id' => null,
        'status' => TaskStatus::AwaitingClarification,
        'clarification_options' => ['acme/api', 'acme/billing'],
        'clarification_expires_at' => now()->addDays(3),
    ]);

    postLinearPrompted([
        'type' => 'AgentSessionEvent',
        'action' => 'prompted',
        'organizationId' => TEST_WORKSPACE_ID,
        'agentSession' => ['id' => 'sess-repo'],
        'agentActivity' => ['content' => ['body' => '2']],
    ], $this->secret)->assertSuccessful();

    $task->refresh();
    expect($task->repo)->toBe('acme/billing')
        ->and($task->status)->toBe(TaskStatus::Pending);

    Queue::assertPushed(RunYakJob::class);
    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->type === NotificationType::Progress
        && $job->message === 'Working in acme/billing now.');
});

it('lists the options again when the reply does not match a repo', function (): void {
    Queue::fake();
    Repository::factory()->create(['slug' => 'acme/api']);
    Repository::factory()->create(['slug' => 'acme/billing']);

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'linear_agent_session_id' => 'sess-repo2',
        'repo' => 'unknown',
        'session_id' => null,
        'status' => TaskStatus::AwaitingClarification,
        'clarification_options' => ['acme/api', 'acme/billing'],
    ]);

    postLinearPrompted([
        'type' => 'AgentSessionEvent',
        'action' => 'prompted',
        'organizationId' => TEST_WORKSPACE_ID,
        'agentSession' => ['id' => 'sess-repo2'],
        'agentActivity' => ['content' => ['body' => '7']],
    ], $this->secret)->assertSuccessful();

    expect($task->refresh()->repo)->toBe('unknown');

    Queue::assertNotPushed(RunYakJob::class);
    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->type === NotificationType::Clarification
        && str_contains($job->message, "1. acme/api\n2. acme/billing"));
});

it('still routes a mid-run clarification reply to the job that asked', function (): void {
    Bus::fake();

    YakTask::factory()->withClarificationQuestions([sampleQuestion('scope', ['A', 'B'])->toArray()])->create([
        'source' => 'linear',
        'linear_agent_session_id' => 'sess-mid',
        'repo' => 'acme/api',
    ]);

    postLinearPrompted([
        'type' => 'AgentSessionEvent',
        'action' => 'prompted',
        'organizationId' => TEST_WORKSPACE_ID,
        'agentSession' => ['id' => 'sess-mid'],
        'agentActivity' => ['content' => ['body' => '2']],
    ], $this->secret)->assertSuccessful();

    Bus::assertDispatched(RunYakJob::class);
});

it('a Linear prompt on a finished research task creates a research follow-up', function () {
    Queue::fake();

    $task = YakTask::factory()->success()->create(['mode' => TaskMode::Research, 'pr_url' => null, 'repo' => 'research-repo', 'session_id' => 'sess_research', 'source' => 'linear', 'linear_agent_session_id' => 'sess-r1']);

    postLinearPrompted([
        'type' => 'AgentSessionEvent',
        'action' => 'prompted',
        'organizationId' => TEST_WORKSPACE_ID,
        'agentSession' => ['id' => 'sess-r1'],
        'agentActivity' => ['content' => ['body' => 'and the circuit breaker?']],
    ], $this->secret)->assertSuccessful()->assertJson(['handled' => 'follow_up']);

    Queue::assertPushed(ResearchFollowUpJob::class, fn (ResearchFollowUpJob $job) => $job->task->parent_task_id === $task->id);
});

it('tells the starter by DM when someone else stops the session', function (): void {
    Queue::fake([SendNotificationJob::class]);

    YakTask::factory()->running()->create([
        'source' => 'linear',
        'linear_agent_session_id' => 'sess-stop',
        'context' => json_encode(['linear_creator_id' => 'linear-creator']),
    ]);

    postLinearPrompted([
        'type' => 'AgentSessionEvent',
        'action' => 'prompted',
        'organizationId' => TEST_WORKSPACE_ID,
        'actor' => ['id' => 'linear-other', 'email' => 'other@example.com'],
        'agentSession' => ['id' => 'sess-stop'],
        'agentActivity' => ['signal' => 'stop'],
    ], $this->secret)->assertSuccessful();

    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->type === NotificationType::Cancelled
        && $job->directMessagesOnly === true);
});
