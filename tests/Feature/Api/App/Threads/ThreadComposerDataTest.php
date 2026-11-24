<?php

use App\Enums\RunnerState;
use App\Enums\ThreadAgentStatus;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadShareMode;
use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadAgent;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\SlashCommands;
use App\Services\Threads\ThreadSharing;
use Carbon\CarbonImmutable;
use Tests\Support\ContractFixture;

const COMPOSER_SHOW_VOLATILE = ['thread.id', 'thread.rootThreadId', 'thread.preview.targets.*.threadId', 'messages.*.id', 'messages.*.uuid', 'question.taskId', 'teleport.command', 'teleport.link'];

/** @return array{0: Thread, 1: User, 2: TaskRun} a private thread with a running turn that has a step limit */
function composerDataThread(): array
{
    config(['yak.max_turns' => 300]);
    $user = User::factory()->create(['name' => 'Test person']);
    $thread = privateThreadOf($user, ['title' => 'Retry failed uploads', 'name' => 'retry-failed-uploads', 'repo' => 'example/api', 'runner_state' => RunnerState::Idle, 'sandbox_state' => ThreadSandboxState::None]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'source' => 'sentry', 'started_by_user_id' => $user->id]);
    $run = TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => TurnState::Running, 'driver_user_id' => null, 'source' => 'sentry', 'assistant_turns' => 262, 'cost_usd' => 1.25, 'num_turns' => 10, 'total_ms' => 60_000]);

    return [$thread, $user, $run];
}

function composerStoppedAgentIds(Thread $thread): array
{
    return array_values(array_map(fn (array $command): string => $command['taskId'], array_filter(app(BrokerStreams::class)->commands('threads:' . $thread->id), fn (array $command): bool => $command['type'] === 'stop_agent')));
}

it('serves the slash commands the web composer offers', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    [$thread, $user] = composerDataThread();
    $thread->forceFill([
        'slash_commands' => [
            ['name' => 'review', 'description' => 'Review the diff', 'argumentHint' => '[focus]', 'kind' => 'skill'],
            ['name' => 'clear', 'description' => 'Claude clear', 'argumentHint' => null, 'kind' => 'command'],
        ],
        'slash_commands_captured_at' => now(),
    ])->save();

    $response = $this->getJson(appThreadPath($thread), phoneHeadersFor($user))->assertOk()
        ->assertJsonPath('slashCommands.capturedAt', '2026-11-15T12:00:00+00:00')
        ->assertJsonPath('slashCommands.commands.0.name', 'clear')
        ->assertJsonPath('slashCommands.commands.0.kind', 'yak')
        ->assertJsonPath('slashCommands.commands.1', ['name' => 'review', 'description' => 'Review the diff', 'argumentHint' => '[focus]', 'kind' => 'skill'])
        ->assertJsonCount(2, 'slashCommands.commands');

    expect($response->json('slashCommands'))->toBe(app(SlashCommands::class)->forThread($thread->fresh()));
});

it('serves only the built-in commands before a session has listed any', function () {
    [$thread, $user] = composerDataThread();

    $this->getJson(appThreadPath($thread), phoneHeadersFor($user))->assertOk()
        ->assertJsonPath('slashCommands.capturedAt', null)
        ->assertJsonCount(1, 'slashCommands.commands');
});

it('lists the thread of a share recipient with the same commands and agents', function () {
    [$thread, $owner] = composerDataThread();
    ThreadAgent::factory()->create(['thread_id' => $thread->id, 'task_id' => 'aone', 'description' => 'Read the spec']);
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $owner, ThreadShareMode::People, [$recipient->id]);

    $this->getJson(appThreadPath($thread), phoneHeadersFor($recipient))->assertOk()
        ->assertJsonCount(1, 'slashCommands.commands')
        ->assertJsonPath('agents.runningCount', 1);
    $this->getJson(appThreadPath($thread), phoneHeadersFor(User::factory()->create()))->assertNotFound();
});

it('shows the composer data on thread show and writes the contract fixture', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    [$thread, $user] = composerDataThread();
    $thread->forceFill(['event_sequence' => 40, 'slash_commands' => [['name' => 'review', 'description' => 'Review the diff', 'argumentHint' => '[focus]', 'kind' => 'skill']], 'slash_commands_captured_at' => now()])->save();
    ThreadAgent::factory()->create(['thread_id' => $thread->id, 'task_id' => 'aone', 'description' => 'Read the spec', 'started_at' => now()->subMinutes(5)]);
    ThreadAgent::factory()->create(['thread_id' => $thread->id, 'task_id' => 'atwo', 'description' => 'Build mockups', 'started_at' => now()->subMinutes(2), 'stop_requested_at' => now()]);
    ThreadAgent::factory()->finished()->create(['thread_id' => $thread->id, 'task_id' => 'adone']);

    $response = $this->getJson(appThreadPath($thread), phoneHeadersFor($user))->assertOk()
        ->assertJsonPath('composer.turn', ['steps' => 262, 'limit' => 300, 'canAddSteps' => true, 'stepsToAdd' => 300])
        ->assertJsonPath('agents.runningCount', 2)
        ->assertJsonPath('agents.running.0', ['id' => 'aone', 'name' => 'Read the spec', 'state' => 'running', 'startedAt' => '2026-11-15T11:55:00+00:00'])
        ->assertJsonPath('agents.running.1.state', 'stopping')
        ->assertJsonCount(2, 'agents.running');

    ContractFixture::assertMatches('app', 'thread-composer', $response->json(), COMPOSER_SHOW_VOLATILE);
});

it('caps the short agent list and counts them all', function () {
    [$thread, $user] = composerDataThread();
    ThreadAgent::factory()->count(23)->create(['thread_id' => $thread->id]);

    $this->getJson(appThreadPath($thread), phoneHeadersFor($user))->assertOk()
        ->assertJsonPath('agents.runningCount', 23)
        ->assertJsonCount(20, 'agents.running');
});

it('raises the step limit of the running turn like the web', function () {
    [$thread, $user, $run] = composerDataThread();

    $response = $this->postJson(appThreadPath($thread, '/step-limit'), [], phoneHeadersFor($user))->assertOk()
        ->assertExactJson(['status' => 'raised', 'added' => 300, 'turn' => ['steps' => 262, 'limit' => 600, 'canAddSteps' => true, 'stepsToAdd' => 300]]);

    expect($run->refresh()->extra_turns)->toBe(300);
    ContractFixture::assertMatches('app', 'thread-step-limit', $response->json());
});

it('adds the steps again on every call, as the web does', function () {
    [$thread, $user, $run] = composerDataThread();
    $headers = phoneHeadersFor($user);

    $this->postJson(appThreadPath($thread, '/step-limit'), [], $headers)->assertOk();
    $this->postJson(appThreadPath($thread, '/step-limit'), [], $headers)->assertOk()->assertJsonPath('turn.limit', 900);

    expect($run->refresh()->extra_turns)->toBe(600);
});

it('refuses a step limit raise with no limited turn', function (string $case) {
    [$thread, $user, $run] = composerDataThread();
    $case === 'started by a person' ? $run->update(['driver_user_id' => $user->id]) : $run->update(['turn_state' => TurnState::Completed]);

    $response = $this->postJson(appThreadPath($thread, '/step-limit'), [], phoneHeadersFor($user))->assertStatus(409)
        ->assertExactJson(['error' => 'no_step_limit', 'message' => 'This thread has no step limit to raise right now.']);

    expect($run->refresh()->extra_turns)->toBe(0);
    ContractFixture::assertMatches('app', 'error-no-step-limit', $response->json());
})->with(['started by a person', 'no turn running']);

it('stops every running agent and answers the agents that remain', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    [$thread, $user] = composerDataThread();
    ThreadAgent::factory()->create(['thread_id' => $thread->id, 'task_id' => 'aone', 'description' => 'Read the spec']);
    ThreadAgent::factory()->create(['thread_id' => $thread->id, 'task_id' => 'atwo', 'description' => 'Build mockups']);
    ThreadAgent::factory()->finished()->create(['thread_id' => $thread->id, 'task_id' => 'adone']);

    $response = $this->postJson(appThreadPath($thread, '/agents/stop'), [], phoneHeadersFor($user))->assertOk()
        ->assertJsonPath('status', 'stopping')
        ->assertJsonPath('stopped', 2)
        ->assertJsonPath('agents.runningCount', 2)
        ->assertJsonPath('agents.running.0.state', 'stopping');

    expect(composerStoppedAgentIds($thread))->toBe(['aone', 'atwo'])
        ->and(ThreadAgent::query()->where('task_id', 'adone')->value('stop_requested_at'))->toBeNull();
    ContractFixture::assertMatches('app', 'thread-agents-stop', $response->json());
});

it('answers idle when no agent runs, and a repeat asks the runner again for the ones still running', function () {
    [$thread, $user] = composerDataThread();
    $headers = phoneHeadersFor($user);

    $idle = $this->postJson(appThreadPath($thread, '/agents/stop'), [], $headers)->assertOk()
        ->assertExactJson(['status' => 'idle', 'stopped' => 0, 'agents' => ['runningCount' => 0, 'running' => []]]);
    ContractFixture::assertMatches('app', 'thread-agents-stop-idle', $idle->json());

    ThreadAgent::factory()->create(['thread_id' => $thread->id, 'task_id' => 'aone']);
    $this->postJson(appThreadPath($thread, '/agents/stop'), [], $headers)->assertOk()->assertJsonPath('stopped', 1);
    $this->postJson(appThreadPath($thread, '/agents/stop'), [], $headers)->assertOk()->assertJsonPath('agents.running.0.state', 'stopping');

    ThreadAgent::query()->update(['status' => ThreadAgentStatus::Stopped, 'finished_at' => now()]);
    $this->postJson(appThreadPath($thread, '/agents/stop'), [], $headers)->assertOk()->assertJsonPath('status', 'idle');
});

it('fails the agents when the runner is gone, as the web does', function () {
    [$thread, $user] = composerDataThread();
    $thread->forceFill(['runner_state' => RunnerState::None])->save();
    ThreadAgent::factory()->create(['thread_id' => $thread->id, 'task_id' => 'aone']);

    $this->postJson(appThreadPath($thread, '/agents/stop'), [], phoneHeadersFor($user))->assertOk()
        ->assertJsonPath('stopped', 1)
        ->assertJsonPath('agents.runningCount', 0);
    expect(composerStoppedAgentIds($thread))->toBe([]);
});

it('answers 404 when the subagents flag is off, like the web', function () {
    [$thread, $user] = composerDataThread();
    config(['yak.threads.subagents' => false]);

    $this->postJson(appThreadPath($thread, '/agents/stop'), [], phoneHeadersFor($user))->assertNotFound();
});

it('keeps both routes closed to others', function (string $suffix) {
    [$thread, $owner, $run] = composerDataThread();
    ThreadAgent::factory()->create(['thread_id' => $thread->id, 'task_id' => 'aone']);
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $owner, ThreadShareMode::People, [$recipient->id]);
    $path = appThreadPath($thread, $suffix);

    $this->postJson($path, [], phoneHeadersFor($recipient))->assertForbidden()->assertJsonPath('error', 'forbidden');
    $missing = $this->postJson('/api/app/v1/threads/zzzzzzzz' . $suffix, [], phoneHeadersFor(User::factory()->create()))->assertNotFound();
    $this->postJson($path, [], phoneHeadersFor(User::factory()->create()))->assertNotFound()->assertExactJson($missing->json());
    $this->postJson($path, [], ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders(['user_id' => $owner->id]);
    $device->update(['revoked_at' => now()]);
    $this->postJson($path, [], $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    expect($run->refresh()->extra_turns)->toBe(0)
        ->and(composerStoppedAgentIds($thread))->toBe([])
        ->and(ThreadAgent::query()->whereNotNull('stop_requested_at')->count())->toBe(0);
})->with(['/step-limit', '/agents/stop']);

it('throttles both routes', function (string $suffix) {
    [$thread, $user] = composerDataThread();
    $headers = phoneHeadersFor($user);

    foreach (range(1, 30) as $ignored) {
        $this->postJson(appThreadPath($thread, $suffix), [], $headers);
    }

    $this->postJson(appThreadPath($thread, $suffix), [], $headers)->assertStatus(429);
})->with(['/step-limit', '/agents/stop']);
