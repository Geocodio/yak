<?php

use App\DataTransferObjects\SandboxWake;
use App\Enums\TeleportState;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Jobs\PrepareTeleportDownJob;
use App\Models\Repository;
use App\Models\SessionFile;
use App\Models\TaskRun;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\ThreadSession;
use App\Models\YakTask;
use App\Services\SessionStore\SessionStore;
use App\Services\Teleport\BranchHeadVerifier;
use App\Services\Teleport\ThreadNotOwnedByYak;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\ThreadTurns;
use Illuminate\Contracts\Process\ProcessResult;
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

    public function exec(Thread $thread, array $command, ?string $stdin = null, array $env = []): ProcessResult
    {
        parent::exec($thread, $command, $stdin, $env);
        $line = implode(' ', $command);

        return match (true) {
            str_contains($line, 'status --porcelain') => Process::result(output: " M app.php\n"),
            str_contains($line, 'rev-list') => Process::result(output: "0\n"),
            str_contains($line, 'rev-parse HEAD') => Process::result(output: DOWN_HEAD . "\n"),
            $this->pushFails && str_contains($line, ' push ') => Process::result(errorOutput: 'rejected', exitCode: 1),
            str_contains($line, 'check-ignore') => Process::result(output: ".env\n"),
            default => Process::result(),
        };
    }
}

beforeEach(function () {
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
        ->and(implode(' ', $commit))->toContain('WIP: teleport to local machine')->toContain('Co-authored-by: Yak <yak@geocod.io>');
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
