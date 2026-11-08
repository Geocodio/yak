<?php

use App\Enums\RunnerState;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Exceptions\SandboxCapacityReached;
use App\Exceptions\ThreadBusy;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->repository = Repository::factory()->create();
    $this->configSource = sys_get_temp_dir() . '/yak-thread-config-' . uniqid();
    mkdir($this->configSource . '/plugins', 0777, true);
    mkdir($this->configSource . '/skills', 0777, true);
    foreach (['.credentials.json', 'settings.json', 'CLAUDE.md'] as $file) {
        file_put_contents("{$this->configSource}/{$file}", '{}');
    }
    config()->set('yak.sandbox.claude_config_source', $this->configSource);
    config()->set('yak.threads.awake_cap', 8);
});

afterEach(function () {
    exec('rm -rf ' . escapeshellarg($this->configSource));
});

function fakeIncus(string $listState = 'RUNNING'): void
{
    Process::fake([
        'incus info *' => Process::result(exitCode: 1),
        '*systemctl is-system-running*' => Process::result(output: 'running'),
        'incus list *' => Process::result(output: $listState),
        '*' => Process::result(),
    ]);
}

it('creates a thread sandbox without the shared claude directory mount', function () {
    fakeIncus();
    $thread = Thread::factory()->create();

    $container = app(ThreadSandbox::class)->ensureAwake($thread, $this->repository);

    expect($container)->toBe("yak-thread-{$thread->id}")
        ->and($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running)
        ->and($thread->fresh()->sandbox_container)->toBe($container);
    Process::assertRan(fn ($p) => str_contains((string) $p->command, 'incus copy'));
    Process::assertNotRan(fn ($p) => str_contains((string) $p->command, ' claude disk '));
});

it('mounts the credentials file live and user configuration read only', function () {
    fakeIncus();
    $thread = Thread::factory()->create();

    app(ThreadSandbox::class)->ensureAwake($thread, $this->repository);

    $credentials = "source='{$this->configSource}/.credentials.json' path='/home/yak/.claude/.credentials.json'";
    Process::assertRan(fn ($p) => str_contains((string) $p->command, $credentials) && ! str_contains((string) $p->command, 'readonly'));
    foreach (['settings.json', 'CLAUDE.md', 'plugins', 'skills'] as $relative) {
        Process::assertRan(fn ($p) => str_contains((string) $p->command, "path='/home/yak/.claude/{$relative}' readonly=true"));
    }
    Process::assertRan(fn ($p) => str_contains((string) $p->command, 'raw.idmap'));
});

it('resumes a paused sandbox', function () {
    fakeIncus();
    $thread = Thread::factory()->paused()->create();

    $container = app(ThreadSandbox::class)->ensureAwake($thread, $this->repository);

    expect($container)->toBe($thread->sandbox_container)
        ->and($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running);
    Process::assertRan("incus resume '{$container}'");
    Process::assertNotRan(fn ($p) => str_contains((string) $p->command, 'incus copy'));
});

it('starts a stopped sandbox and runs cold start', function () {
    fakeIncus();
    $this->repository->update(['preview_manifest' => ['cold_start' => 'docker compose up -d']]);
    $thread = Thread::factory()->stopped()->create();

    app(ThreadSandbox::class)->ensureAwake($thread, $this->repository);

    expect($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running);
    Process::assertRan("incus start '{$thread->sandbox_container}'");
    Process::assertRan(fn ($p) => str_contains((string) $p->command, 'docker compose up -d'));
});

it('resets the runner after a stop', function () {
    fakeIncus();
    $thread = Thread::factory()->stopped()->create(['runner_state' => RunnerState::Idle]);

    app(ThreadSandbox::class)->ensureAwake($thread, $this->repository);

    expect($thread->fresh()->runner_state)->toBe(RunnerState::None);
});

it('pauses a running sandbox between turns', function () {
    fakeIncus();
    $thread = Thread::factory()->paused()->create(['sandbox_state' => ThreadSandboxState::Running]);

    app(ThreadSandbox::class)->pause($thread);

    expect($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Paused);
    Process::assertRan("incus pause '{$thread->sandbox_container}'");
});

it('never pauses while a turn runs', function () {
    fakeIncus();
    $thread = Thread::factory()->paused()->create(['sandbox_state' => ThreadSandboxState::Running]);
    $task = YakTask::factory()->running()->create(['thread_id' => $thread->id]);
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => TurnState::Running]);

    app(ThreadSandbox::class)->pause($thread);

    expect($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running);
    Process::assertNotRan(fn ($p) => str_contains((string) $p->command, 'incus pause'));
});

it('refuses to wake past the awake cap', function () {
    fakeIncus();
    config()->set('yak.threads.awake_cap', 1);
    Thread::factory()->paused()->create();
    $thread = Thread::factory()->stopped()->create();

    expect(fn () => app(ThreadSandbox::class)->ensureAwake($thread, $this->repository))->toThrow(SandboxCapacityReached::class);
    expect($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Stopped);
    Process::assertNotRan(fn ($p) => str_contains((string) $p->command, 'incus start'));
});

it('refuses to start a turn on a thread that already runs one', function () {
    $thread = Thread::factory()->paused()->create();
    $task = YakTask::factory()->running()->create(['thread_id' => $thread->id]);
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => TurnState::Running]);
    $started = false;

    expect(fn () => app(ThreadSandbox::class)->startingTurn($thread, function () use (&$started) {
        $started = true;
    }))->toThrow(ThreadBusy::class);
    expect($started)->toBeFalse();
});

it('never stops or pauses a sandbox while a turn is being started on it', function () {
    fakeIncus();
    $thread = Thread::factory()->paused()->create();
    $sandbox = app(ThreadSandbox::class);

    $sandbox->startingTurn($thread, function () use ($sandbox, $thread) {
        $sandbox->stop($thread);
        $sandbox->pause($thread);
    });

    expect($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Paused);
    Process::assertNotRan(fn ($p) => str_contains((string) $p->command, 'incus stop') || str_contains((string) $p->command, 'incus pause'));
});

it('releases the start lock after the turn is written', function () {
    $thread = Thread::factory()->paused()->create();
    $sandbox = app(ThreadSandbox::class);

    $sandbox->startingTurn($thread, fn () => null);

    expect($sandbox->startingTurn($thread, fn () => 'again'))->toBe('again');
});
