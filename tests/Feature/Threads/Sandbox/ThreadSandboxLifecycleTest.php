<?php

use App\DataTransferObjects\WipResult;
use App\Enums\RunnerState;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadKind;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Jobs\Threads\StartThreadPreviewJob;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\WipPreserver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

require_once __DIR__ . '/../../../Support/ThreadPreviewHelpers.php';

function runningSandboxThread(array $attributes = []): Thread
{
    return Thread::factory()->create([
        'sandbox_container' => 'yak-thread-' . fake()->unique()->numberBetween(1, 99999),
        'sandbox_state' => ThreadSandboxState::Running,
        'runner_state' => RunnerState::Idle,
        ...$attributes,
    ]);
}

function operations(): array
{
    return array_column(app(SandboxManager::class)->calls, 'operation');
}

test('pause only when no runner in the sandbox has a turn', function () {
    $thread = runningSandboxThread();
    TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => TurnState::Running]);

    app(ThreadSandbox::class)->pauseIfIdle($thread);
    expect(operations())->toBe([])->and($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running);

    TaskRun::query()->update(['turn_state' => TurnState::Completed]);
    app(ThreadSandbox::class)->pauseIfIdle($thread);

    expect(operations())->toBe(['pause'])->and($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Paused);
});

test('a research childs running turn keeps the parents sandbox awake', function () {
    $parent = runningSandboxThread();
    $child = Thread::factory()->child($parent)->create(['sandbox_container' => $parent->sandbox_container, 'sandbox_state' => ThreadSandboxState::Running]);
    TaskRun::factory()->create(['thread_id' => $child->id, 'turn_state' => TurnState::Running]);

    app(ThreadSandbox::class)->pauseIfIdle($parent);

    expect(operations())->toBe([]);
});

test('idle pause records state and time', function () {
    $this->travelTo(now()->startOfSecond());
    $thread = runningSandboxThread();

    app(ThreadSandbox::class)->pauseIfIdle($thread);

    expect($thread->fresh()->sandbox_state_changed_at->equalTo(now()))->toBeTrue();
});

test('stop sends end waits for runner exit then syncs then stops', function () {
    $order = [];
    $thread = runningSandboxThread();
    $streams = app(BrokerStreams::class);
    $sync = app(SessionFileSync::class);

    Sleep::fake();
    Sleep::whenFakingSleep(function () use ($thread, &$order, $streams, $sync) {
        $order[] = 'waiting after ' . count($streams->commands("threads:{$thread->id}")) . ' end, ' . count($sync->synced) . ' sync';
        $thread->update(['runner_state' => RunnerState::None]);
    });

    app(ThreadSandbox::class)->stop($thread);

    expect($streams->commands("threads:{$thread->id}"))->toBe([['type' => 'end']])
        ->and($order)->toBe(['waiting after 1 end, 0 sync'])
        ->and($sync->synced)->toBe([$thread->id])
        ->and(operations())->toBe(['stop'])
        ->and($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Stopped)
        ->and($thread->fresh()->runner_state)->toBe(RunnerState::None);
});

test('stop forces after the timeout when the runner never exits', function () {
    Sleep::fake();
    config(['yak.threads.stop_timeout_seconds' => 5]);
    $thread = runningSandboxThread();

    app(ThreadSandbox::class)->stop($thread);

    Sleep::assertSleptTimes(5);
    expect(operations())->toBe(['stop'])->and(app(SandboxManager::class)->calls[0]['arguments']['timeoutSeconds'])->toBe(5);
});

test('stop continues when session sync throws', function () {
    Sleep::fake();
    $thread = runningSandboxThread(['runner_state' => RunnerState::None]);
    app()->instance(SessionFileSync::class, new class implements SessionFileSync
    {
        public function syncFromSandbox(Thread $thread): void
        {
            throw new RuntimeException('boom');
        }

        public function restoreIntoSandbox(Thread $thread): void {}
    });

    app(ThreadSandbox::class)->stop($thread);

    expect(operations())->toBe(['stop']);
});

test('stop leaves a busy thread alone', function () {
    $thread = runningSandboxThread();
    TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => TurnState::Running]);

    app(ThreadSandbox::class)->stop($thread);

    expect(operations())->toBe([])->and(app(BrokerStreams::class)->commands("threads:{$thread->id}"))->toBe([]);
});

test('stop resumes a paused sandbox first', function () {
    Sleep::fake();
    $thread = Thread::factory()->paused()->create(['runner_state' => RunnerState::None]);

    app(ThreadSandbox::class)->stop($thread);

    expect(operations())->toBe(['resume', 'stop']);
});

test('touch is throttled', function () {
    $this->travelTo(now()->startOfSecond());
    $thread = Thread::factory()->create(['last_activity_at' => now()->subHour()]);
    Cache::flush();

    app(ThreadSandbox::class)->touch($thread);
    $first = $thread->fresh()->last_activity_at;

    $this->travel(10)->seconds();
    app(ThreadSandbox::class)->touch($thread);
    expect($thread->fresh()->last_activity_at->equalTo($first))->toBeTrue();

    $this->travel(30)->seconds();
    app(ThreadSandbox::class)->touch($thread);
    expect($thread->fresh()->last_activity_at->greaterThan($first))->toBeTrue();
});

test('ensure awake resumes paused', function () {
    $thread = Thread::factory()->paused()->create();

    $wake = app(ThreadSandbox::class)->ensureAwake($thread);

    expect($wake->ready)->toBeTrue()->and(operations())->toBe(['resume'])->and($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running);
});

test('ensure awake starts stopped and hands the dev server to the preview manifest', function () {
    Queue::fake();
    $thread = Thread::factory()->stopped()->create(['repo' => 'acme/widgets', 'runner_state' => RunnerState::Idle]);
    queueSandboxOutput("port: 3000\nhealth_probe_path: /up\ncold_start: bin/serve\n");
    queueSandboxOutput('', 7);

    $wake = app(ThreadSandbox::class)->ensureAwake($thread);

    expect($wake->ready)->toBeTrue()
        ->and(operations())->toBe(['start', 'exec', 'exec'])
        ->and($thread->fresh()->runner_state)->toBe(RunnerState::None);
    Queue::assertPushed(StartThreadPreviewJob::class);
});

test('ensure awake on a running sandbox is ready without calls', function () {
    expect(app(ThreadSandbox::class)->ensureAwake(runningSandboxThread())->ready)->toBeTrue()->and(operations())->toBe([]);
});

test('ensure awake waiting for slot sets blocked reason and clears it later', function () {
    config(['yak.threads.awake_cap' => 1]);
    $holder = runningSandboxThread();
    $thread = Thread::factory()->stopped()->create();

    $wake = app(ThreadSandbox::class)->ensureAwake($thread);

    expect($wake->waitingForSlot)->toBeTrue()->and($wake->ready)->toBeFalse()
        ->and($thread->fresh()->blocked_reason)->toBe(ThreadBlockReason::WaitingForSandbox);

    $holder->update(['sandbox_state' => ThreadSandboxState::Stopped]);

    expect(app(ThreadSandbox::class)->ensureAwake($thread)->ready)->toBeTrue()
        ->and($thread->fresh()->blocked_reason)->toBeNull();
});

test('ensure awake evicts the oldest paused sandbox to make room', function () {
    Sleep::fake();
    config(['yak.threads.awake_cap' => 1]);
    $paused = Thread::factory()->paused()->create(['last_activity_at' => now()->subHour(), 'runner_state' => RunnerState::None]);
    $thread = Thread::factory()->stopped()->create();

    expect(app(ThreadSandbox::class)->ensureAwake($thread)->ready)->toBeTrue()
        ->and($paused->fresh()->sandbox_state)->toBe(ThreadSandboxState::Stopped);
});

test('a second caller during wake gets restoring not ready', function () {
    $thread = Thread::factory()->stopped()->create();
    $lock = Cache::lock("thread-sandbox:{$thread->id}", 60);
    $lock->get();

    $wake = app(ThreadSandbox::class)->ensureAwake($thread);

    expect($wake->restoring)->toBeTrue()->and($wake->ready)->toBeFalse();
    $lock->release();
});

function sharedResearchChild(): array
{
    $parent = runningSandboxThread();
    $child = Thread::factory()->child($parent)->create([
        'name' => 'scan',
        'kind' => ThreadKind::Research,
        'sandbox_container' => $parent->sandbox_container,
        'sandbox_state' => ThreadSandboxState::Running,
        'claude_config_dir' => '/home/yak/.claude-child',
    ]);

    return [$parent, $child];
}

test('releasing a research child syncs its transcript before its config dir is removed', function () {
    [, $child] = sharedResearchChild();
    $sandboxes = app(SandboxManager::class);
    $callsAtSync = null;
    app()->instance(SessionFileSync::class, new class($sandboxes, $callsAtSync) implements SessionFileSync
    {
        public function __construct(private $sandboxes, public &$callsAtSync) {}

        public function syncFromSandbox(Thread $thread): void
        {
            $this->callsAtSync = count($this->sandboxes->calls);
        }

        public function restoreIntoSandbox(Thread $thread): void {}
    });

    app(ThreadSandbox::class)->stop($child);

    $removal = collect($sandboxes->calls)->search(fn (array $call): bool => $call['operation'] === 'exec' && str_contains(implode(' ', $call['arguments']['command']), 'rm -rf'));
    expect($callsAtSync)->toBe($removal)
        ->and($child->fresh()->sandbox_container)->toBeNull();
});

test('a failing session sync does not block releasing a research child', function () {
    [, $child] = sharedResearchChild();
    app()->instance(SessionFileSync::class, new class implements SessionFileSync
    {
        public function syncFromSandbox(Thread $thread): void
        {
            throw new RuntimeException('boom');
        }

        public function restoreIntoSandbox(Thread $thread): void {}
    });

    app(ThreadSandbox::class)->stop($child);

    expect(collect(app(SandboxManager::class)->calls)->contains(fn (array $call): bool => $call['operation'] === 'exec' && str_contains(implode(' ', $call['arguments']['command']), 'rm -rf')))->toBeTrue()
        ->and($child->fresh()->sandbox_container)->toBeNull();
});

test('a failed WIP push during destroy puts a stopped sandbox back to stopped', function () {
    $thread = Thread::factory()->stopped()->create();
    app()->instance(WipPreserver::class, Mockery::mock(WipPreserver::class, fn ($mock) => $mock->shouldReceive('preserve')->andThrow(new RuntimeException('push rejected'))));

    app(ThreadSandbox::class)->destroy($thread);

    expect($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Stopped)
        ->and(operations())->toBe(['start', 'stop']);
});

test('a failed WIP push during destroy puts a paused sandbox back to paused', function () {
    $thread = Thread::factory()->paused()->create();
    app()->instance(WipPreserver::class, Mockery::mock(WipPreserver::class, fn ($mock) => $mock->shouldReceive('preserve')->andThrow(new RuntimeException('push rejected'))));

    app(ThreadSandbox::class)->destroy($thread);

    expect($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Paused)
        ->and(operations())->toBe(['resume', 'pause']);
});

test('a running sandbox stays running when its WIP push fails', function () {
    $thread = runningSandboxThread(['runner_state' => RunnerState::None]);
    app()->instance(WipPreserver::class, Mockery::mock(WipPreserver::class, fn ($mock) => $mock->shouldReceive('preserve')->andThrow(new RuntimeException('push rejected'))));

    app(ThreadSandbox::class)->destroy($thread);

    expect($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running)
        ->and(operations())->toBe([]);
});

test('the stopped sandbox is picked up by the next sweep once the push works', function () {
    $thread = Thread::factory()->stopped()->create();
    $preserver = $this->mock(WipPreserver::class);
    $preserver->shouldReceive('preserve')->once()->andThrow(new RuntimeException('push rejected'));
    $preserver->shouldReceive('preserve')->once()->andReturn(new WipResult(true, true, 'abc'));
    app()->forgetInstance(ThreadSandbox::class);

    app(ThreadSandbox::class)->destroy($thread);
    app(ThreadSandbox::class)->destroy($thread->fresh());

    expect($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Destroyed)
        ->and(operations())->toContain('destroy');
});
