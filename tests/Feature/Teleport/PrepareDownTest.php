<?php

use App\DataTransferObjects\SandboxWake;
use App\Enums\RunnerState;
use App\Enums\TaskRunKind;
use App\Enums\TeleportState;
use App\Enums\ThreadAgentStatus;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Events\TurnStarted;
use App\Jobs\PrepareTeleportDownJob;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\SessionFile;
use App\Models\TaskRun;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\ThreadAgent;
use App\Models\ThreadRepository;
use App\Models\ThreadSession;
use App\Models\YakTask;
use App\Services\SessionStore\SessionStore;
use App\Services\Teleport\BranchHeadVerifier;
use App\Services\Teleport\ThreadNotOwnedByYak;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\ThreadTurns;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

const DOWN_SESSION = 'e4e4e4e4-0000-4000-8000-000000000005';
const DOWN_HEAD = 'def456def456';

/**
 * A fake sandbox that answers git status and rev-parse like a dirty checkout.
 */
class DownScriptedSandbox extends FakeSandboxManager
{
    public bool $pushFails = false;

    public ?ProcessResult $sha256Result = null;

    public function exec(Thread $thread, array $command, ?string $stdin = null, array $env = [], ?int $timeout = null): ProcessResult
    {
        parent::exec($thread, $command, $stdin, $env);
        $line = implode(' ', $command);

        return match (true) {
            str_contains($line, 'status --porcelain') => Process::result(output: " M app.php\n"),
            str_contains($line, 'rev-list') => Process::result(output: "0\n"),
            str_contains($line, 'rev-parse HEAD') => Process::result(output: DOWN_HEAD . "\n"),
            $this->pushFails && str_contains($line, ' push ') => Process::result(errorOutput: 'rejected', exitCode: 1),
            str_contains($line, 'sha256sum') => $this->sha256Result ?? Process::result(output: hash('sha256', app(SessionStore::class)->read($thread, DOWN_SESSION, DOWN_SESSION . '.jsonl')) . "  file\n"),
            str_contains($line, 'check-ignore') => Process::result(output: ".env\n"),
            default => Process::result(),
        };
    }
}

beforeEach(function () {
    config()->set('yak.git_user_email', 'yak@trailer.test');
    $this->storePath = sys_get_temp_dir() . '/' . Str::uuid();
    config()->set('yak.threads.session_store_path', $this->storePath);
    config()->set('yak.threads.session_store_key', base64_encode(sodium_crypto_secretbox_keygen()));
    Repository::factory()->create(['slug' => 'acme/widgets', 'github_full_name' => 'acme/widgets']);
    [$this->device, $this->token] = makeDevice();

    $this->thread = Thread::factory()->paused()->create([
        'repo' => 'acme/widgets', 'branch' => 'yak/fix-it', 'git_author_name' => 'Ada', 'git_author_email' => 'ada@example.com',
    ]);
    ThreadRepository::factory()->create(['thread_id' => $this->thread->id, 'repo' => 'acme/widgets', 'branch' => 'yak/fix-it']);
    ThreadSession::factory()->create(['thread_id' => $this->thread->id, 'session_id' => DOWN_SESSION]);

    $this->heads = Mockery::mock(BranchHeadVerifier::class);
    $this->heads->shouldReceive('headOf')->andReturn(DOWN_HEAD)->byDefault();
    app()->instance(BranchHeadVerifier::class, $this->heads);

    $this->sandboxes = new DownScriptedSandbox;
    app()->instance(SandboxManager::class, $this->sandboxes);

    $this->sessions = Mockery::mock(SessionFileSync::class);
    $this->sessions->shouldReceive('syncFromSandbox')->byDefault();
    app()->instance(SessionFileSync::class, $this->sessions);

    $this->sandbox = Mockery::mock(ThreadSandbox::class);
    $this->sandbox->shouldReceive('ensureAwake')->andReturn(SandboxWake::ready())->byDefault();
    app()->instance(ThreadSandbox::class, $this->sandbox);

    $this->store = app(SessionStore::class);
    $chunk = "{\"type\":\"user\"}\n";
    $this->store->append($this->thread, DOWN_SESSION, DOWN_SESSION . '.jsonl', 0, $chunk, hash('sha256', $chunk), hash('sha256', ''));
});

afterEach(function () {
    File::deleteDirectory($this->storePath);
});

function downTeleport(object $test, array $attributes = []): Teleport
{
    return Teleport::factory()->down()->inState(TeleportState::Preparing)->create([
        'thread_id' => $test->thread->id, 'device_id' => $test->device->id, 'payload' => ['os' => 'darwin', 'arch' => 'arm64', 'local_path' => '/Users/ada/widgets'],
        ...$attributes,
    ]);
}

function runDown(Teleport $teleport): Teleport
{
    PrepareTeleportDownJob::dispatchSync($teleport->id);

    return $teleport->refresh();
}

function startRunningTurn(Thread $thread): TaskRun
{
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);

    return TaskRun::factory()->create(['thread_id' => $thread->id, 'yak_task_id' => $task->id, 'turn_state' => TurnState::Running]);
}

it('reaches ready with a manifest on an idle thread', function () {
    $teleport = runDown(downTeleport($this));

    expect($teleport->state)->toBe(TeleportState::Ready)
        ->and($teleport->branch_heads)->toBe(['acme/widgets' => DOWN_HEAD])
        ->and(collect($teleport->progress)->pluck('status')->unique()->all())->toBe(['done']);
    expect($teleport->manifest['repos'][0])->toMatchArray([
        'repo' => 'acme/widgets', 'role' => 'primary', 'branch' => 'yak/fix-it', 'head_sha' => DOWN_HEAD,
        'sandbox_path' => '/workspace', 'wip_commit_made' => true, 'pushed' => true, 'branch_created_by_yak' => true,
    ]);
    expect($teleport->manifest['session']['session_id'])->toBe(DOWN_SESSION);

    $this->getJson("/api/cli/v1/teleports/{$teleport->id}", cliHeaders($this->token))->assertOk()
        ->assertJsonPath('teleport.state', 'ready')->assertJsonPath('repos.0.head_sha', DOWN_HEAD)->assertJsonPath('thread.slug', $this->thread->name);
});

it('commits with the person identity and the Yak trailer', function () {
    runDown(downTeleport($this));

    $commit = collect($this->sandboxes->calls)->where('operation', 'exec')->map(fn (array $call): array => $call['arguments']['command'])
        ->first(fn (array $command): bool => in_array('commit', $command, true));
    expect($commit)->toContain('user.name=Ada')->toContain('user.email=ada@example.com')
        ->and(implode(' ', $commit))->toContain('WIP: teleport to local machine')->toContain('Co-authored-by: Yak <yak@trailer.test>');
});

it('releases while a turn runs and proceeds after it ends', function () {
    $run = startRunningTurn($this->thread);
    $teleport = downTeleport($this, ['state' => TeleportState::WaitingForTurn, 'running_turn' => 'wait']);

    $teleport = runDown($teleport);
    expect($teleport->state)->toBe(TeleportState::WaitingForTurn)
        ->and($this->sandboxes->calls)->toBe([]);

    $run->update(['turn_state' => TurnState::Completed]);

    expect(runDown($teleport)->state)->toBe(TeleportState::Ready);
});

it('stops the turn and still commits and pushes first', function () {
    $run = startRunningTurn($this->thread);
    $turns = Mockery::mock(ThreadTurns::class);
    $turns->shouldReceive('stop')->once()->andReturnUsing(fn () => $run->update(['turn_state' => TurnState::Interrupted]));
    app()->instance(ThreadTurns::class, $turns);

    $teleport = runDown(downTeleport($this, ['running_turn' => 'stop']));

    expect($teleport->state)->toBe(TeleportState::Ready);
    $first = collect($this->sandboxes->calls)->where('operation', 'exec')->first();
    expect(implode(' ', $first['arguments']['command']))->toContain('status --porcelain');
});

it('fails on a push error and leaves the owner untouched', function () {
    $this->sandboxes->pushFails = true;
    $before = $this->thread->only(['owner_side', 'owner_device_id']);

    $teleport = runDown(downTeleport($this));

    expect($teleport->state)->toBe(TeleportState::Failed)->and($teleport->failure_code)->toBe('teleport_failed');
    expect($this->thread->refresh()->only(['owner_side', 'owner_device_id']))->toBe($before);
});

it('fails when the pushed head is not visible on GitHub', function () {
    $this->heads->shouldReceive('headOf')->andReturn('other');

    expect(runDown(downTeleport($this))->state)->toBe(TeleportState::Failed);
});

it('lists only changed memory files and checkpoints every append file', function () {
    $this->store->putWhole($this->thread, SessionStore::MEMORY_SESSION_ID, 'memory/MEMORY.md', 'one');
    $this->store->putWhole($this->thread, SessionStore::MEMORY_SESSION_ID, 'memory/stable.md', 'same');
    Teleport::factory()->up()->inState(TeleportState::Completed)->create([
        'thread_id' => $this->thread->id, 'device_id' => $this->device->id, 'completed_at' => now()->subHour(),
        'memory_snapshot' => ['memory/stable.md' => hash('sha256', 'same')],
        'payload' => ['uploaded_files' => [['rel_path' => DOWN_SESSION . '.jsonl', 'length' => 5, 'sha256' => 'abc']]],
    ]);

    $teleport = runDown(downTeleport($this));

    expect(collect($teleport->manifest['memory'])->pluck('rel_path')->all())->toBe(['memory/MEMORY.md'])
        ->and($teleport->manifest['last_up']['files'][0]['sha256'])->toBe('abc')
        ->and($teleport->manifest['repos'][0]['branch_created_by_yak'])->toBeFalse();
    expect(SessionFile::query()->where('mode', 'append')->whereNull('sha256')->count())->toBe(0)
        ->and(collect($teleport->manifest['session']['files'])->every(fn (array $file): bool => $file['sha256'] !== null))->toBeTrue();
});

it('fails with session_incomplete when the store is behind the sandbox transcript', function () {
    $this->sandboxes->sha256Result = Process::result(output: hash('sha256', "more bytes the store never received\n") . "  file\n");
    $before = $this->thread->only(['owner_side', 'owner_device_id']);

    $teleport = runDown(downTeleport($this));

    expect($teleport->state)->toBe(TeleportState::Failed)->and($teleport->failure_code)->toBe('session_incomplete')
        ->and($this->thread->refresh()->only(['owner_side', 'owner_device_id']))->toBe($before)
        ->and(SessionFile::query()->where('mode', 'append')->whereNotNull('sha256')->count())->toBe(0);
});

it('fails with session_incomplete when sha256sum cannot be read', function () {
    $this->sandboxes->sha256Result = Process::result(errorOutput: 'no such file', exitCode: 1);

    expect(runDown(downTeleport($this))->failure_code)->toBe('session_incomplete');
});

it('has a null last_up for a thread never teleported up', function () {
    expect(runDown(downTeleport($this))->manifest['last_up'])->toBeNull();
});

it('lists forks in other_session_ids', function () {
    ThreadSession::factory()->create(['thread_id' => $this->thread->id, 'session_id' => 'f5f5f5f5-0000-4000-8000-000000000006', 'kind' => 'fork']);
    ThreadSession::query()->where('session_id', DOWN_SESSION)->update(['kind' => 'main']);

    expect(runDown(downTeleport($this))->manifest['session']['other_session_ids'])->toBe(['f5f5f5f5-0000-4000-8000-000000000006']);
});

it('starts no new turn while preparing', function () {
    $teleport = downTeleport($this);
    $thread = $this->thread->refresh();

    expect(fn () => app(ThreadTurns::class)->start(YakTask::factory()->create(['thread_id' => $thread->id]), 'go', null, null))
        ->toThrow(ThreadNotOwnedByYak::class);
    expect($teleport->refresh()->state)->toBe(TeleportState::Preparing);
});

it('stays preparing while no sandbox slot is free', function () {
    $this->sandbox->shouldReceive('ensureAwake')->andReturn(SandboxWake::waitingForSlot());
    $this->thread->update(['sandbox_state' => ThreadSandboxState::Stopped]);

    expect(runDown(downTeleport($this))->state)->toBe(TeleportState::Preparing);
});

it('shows the sandbox wake as running while no slot is free', function () {
    $this->sandbox->shouldReceive('ensureAwake')->andReturn(SandboxWake::waitingForSlot());

    expect(runDown(downTeleport($this))->progress)->toContain(['step' => 'wake_sandbox', 'status' => 'running', 'detail' => 'waiting for a free sandbox']);
});

it('says when another wake of the sandbox holds it up', function () {
    $this->sandbox->shouldReceive('ensureAwake')->andReturn(SandboxWake::wakingElsewhere());

    expect(runDown(downTeleport($this))->progress)->toContain(['step' => 'wake_sandbox', 'status' => 'running', 'detail' => 'waiting for another wake of this sandbox to finish']);
});

it('fails the teleport when the job times out, so the CLI stops waiting', function () {
    $teleport = downTeleport($this);
    $job = new PrepareTeleportDownJob($teleport->id);

    $job->failed(new RuntimeException('timed out'));

    expect($job->timeout)->toBe(600)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($teleport->refresh()->state)->toBe(TeleportState::Failed)
        ->and($teleport->failure_message)->toContain('took too long');
});

it('stops running agents, waits for them to end, and proceeds on the next attempt', function () {
    config(['yak.threads.subagents' => true]);
    $this->thread->forceFill(['runner_generation' => 1, 'runner_state' => RunnerState::Idle])->save();
    $agents = ThreadAgent::factory()->count(2)->create(['thread_id' => $this->thread->id]);
    $broker = app(BrokerStreams::class);
    $stream = 'threads:' . $this->thread->id;

    $teleport = runDown(downTeleport($this));

    expect($teleport->state)->toBe(TeleportState::WaitingForTurn)
        ->and($this->sandboxes->calls)->toBe([])
        ->and(collect($broker->commands($stream))->pluck('taskId')->sort()->values()->all())->toBe($agents->pluck('task_id')->sort()->values()->all());

    // The same attempt again while they still run asks again and still waits.
    expect(runDown($teleport)->state)->toBe(TeleportState::WaitingForTurn);

    foreach ($agents as $index => $agent) {
        app(ThreadDriver::class)->handle($broker->pushOut($stream, 1, $index + 1, [
            'type' => 'system', 'subtype' => 'task_notification', 'task_id' => $agent->task_id, 'status' => 'stopped', 'summary' => 'Stopped',
        ]));
    }

    expect($agents->map->refresh()->pluck('status')->unique()->all())->toBe([ThreadAgentStatus::Stopped])
        ->and(runDown($teleport)->state)->toBe(TeleportState::Ready);
});

it('waits for the reply Claude starts to the stopped agents, then reaches ready once with the reply in the session', function () {
    config(['yak.threads.subagents' => true]);
    $account = ClaudeAccount::factory()->create();
    $this->thread->forceFill(['runner_generation' => 1, 'runner_state' => RunnerState::Idle, 'claude_account_id' => $account->id])->save();
    $origin = startRunningTurn($this->thread);
    $origin->update(['turn_state' => TurnState::Completed, 'finished_at' => now()]);
    $agents = ThreadAgent::factory()->count(2)->create(['thread_id' => $this->thread->id, 'task_run_id' => $origin->id]);
    $stream = 'threads:' . $this->thread->id;
    $driver = app(ThreadDriver::class);
    $broker = app(BrokerStreams::class);
    $sequence = 0;
    $push = function (array $line) use ($driver, $broker, $stream, &$sequence): void {
        $driver->handle($broker->pushOut($stream, 1, ++$sequence, $line));
    };

    $teleport = runDown(downTeleport($this));
    expect($teleport->state)->toBe(TeleportState::WaitingForTurn);

    foreach ($agents as $agent) {
        $push(['type' => 'system', 'subtype' => 'task_notification', 'task_id' => $agent->task_id, 'status' => 'stopped', 'summary' => 'Stopped']);
    }

    // Claude answers the notifications on its own while the teleport waits.
    Event::fake([TurnStarted::class, TurnCompleted::class, TurnFailed::class]);
    $push(['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'Both agents were stopped.']]], 'parent_tool_use_id' => null]);

    $followUp = TaskRun::query()->where('thread_id', $this->thread->id)->where('kind', TaskRunKind::AgentFollowUp)->sole();
    expect($followUp->turn_state)->toBe(TurnState::Running);

    $teleport = runDown($teleport);
    expect($teleport->state)->toBe(TeleportState::WaitingForTurn)
        ->and($this->sandboxes->calls)->toBe([]);

    $push(['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'stop_reason' => 'end_turn', 'origin' => ['kind' => 'task-notification'], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]);
    $this->travel(60)->seconds();
    $driver->tick();

    expect($followUp->refresh()->turn_state)->toBe(TurnState::Completed)
        ->and($followUp->account_slot_held_at)->toBeNull();

    $teleport = runDown($teleport);
    expect($teleport->state)->toBe(TeleportState::Ready)
        ->and(TaskRun::query()->where('thread_id', $this->thread->id)->where('turn_state', TurnState::Running)->count())->toBe(0)
        ->and(TaskRun::query()->where('thread_id', $this->thread->id)->where('kind', TaskRunKind::AgentFollowUp)->count())->toBe(1);

    // The teleport is ready and the session copied: a later reply is refused and silent.
    $push(['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'late']]], 'parent_tool_use_id' => null]);
    expect(TaskRun::query()->where('thread_id', $this->thread->id)->where('turn_state', TurnState::Running)->count())->toBe(0);
});

it('stops a reply Claude already runs to the agents when the teleport asks for a stop, and reaches ready', function () {
    config(['yak.threads.subagents' => true]);
    $account = ClaudeAccount::factory()->create();
    $this->thread->forceFill(['claude_account_id' => $account->id])->save();
    $followUp = startRunningTurn($this->thread);
    $followUp->update(['kind' => TaskRunKind::AgentFollowUp, 'claude_account_id' => $account->id]);
    $turns = Mockery::mock(ThreadTurns::class);
    $turns->shouldReceive('stop')->once()->andReturnUsing(fn () => $followUp->update(['turn_state' => TurnState::Interrupted, 'finished_at' => now()]));
    app()->instance(ThreadTurns::class, $turns);

    $teleport = runDown(downTeleport($this, ['running_turn' => 'stop']));

    expect($teleport->state)->toBe(TeleportState::Ready)
        ->and(TaskRun::query()->where('thread_id', $this->thread->id)->where('turn_state', TurnState::Running)->count())->toBe(0);
});

it('waits for a reply to the agents that is already running when the teleport asks to wait', function () {
    $followUp = startRunningTurn($this->thread);
    $followUp->update(['kind' => TaskRunKind::AgentFollowUp]);

    $teleport = runDown(downTeleport($this, ['state' => TeleportState::WaitingForTurn, 'running_turn' => 'wait']));
    expect($teleport->state)->toBe(TeleportState::WaitingForTurn)->and($this->sandboxes->calls)->toBe([]);

    $followUp->update(['turn_state' => TurnState::Completed, 'finished_at' => now()]);

    expect(runDown($teleport)->state)->toBe(TeleportState::Ready);
});
