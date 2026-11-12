<?php

use App\Enums\ThreadSandboxState;
use App\Exceptions\InvalidSandboxTransition;
use App\Models\Thread;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use Illuminate\Support\Facades\Process;

test('the container resolves the fake sandbox manager in tests', function () {
    expect(app(SandboxManager::class))->toBeInstanceOf(FakeSandboxManager::class);
});

test('a sandbox walks the real lifecycle and records each call', function () {
    $sandboxes = new FakeSandboxManager;
    $thread = Thread::factory()->create();

    expect($sandboxes->state($thread))->toBe(ThreadSandboxState::None);

    $sandboxes->create($thread);
    expect($sandboxes->state($thread))->toBe(ThreadSandboxState::Stopped);
    $sandboxes->start($thread);
    expect($sandboxes->state($thread))->toBe(ThreadSandboxState::Running);
    $sandboxes->pause($thread);
    expect($sandboxes->state($thread))->toBe(ThreadSandboxState::Paused);
    $sandboxes->resume($thread);
    $sandboxes->stop($thread, 60);
    expect($sandboxes->state($thread))->toBe(ThreadSandboxState::Stopped);
    $sandboxes->destroy($thread);
    expect($sandboxes->state($thread))->toBe(ThreadSandboxState::Destroyed);

    expect(array_column($sandboxes->calls, 'operation'))->toBe(['create', 'start', 'pause', 'resume', 'stop', 'destroy'])
        ->and($sandboxes->calls[4]['arguments'])->toBe(['timeoutSeconds' => 60]);

    // A destroyed sandbox is restored by creating it again.
    $sandboxes->create($thread);
    expect($sandboxes->state($thread))->toBe(ThreadSandboxState::Stopped);
});

test('a paused sandbox can be stopped directly', function () {
    $sandboxes = new FakeSandboxManager;
    $thread = Thread::factory()->create();
    $sandboxes->create($thread);
    $sandboxes->start($thread);
    $sandboxes->pause($thread);
    $sandboxes->stop($thread, 30);

    expect($sandboxes->state($thread))->toBe(ThreadSandboxState::Stopped);
});

test('a transition the state machine does not allow throws', function (string $operation, ThreadSandboxState $from) {
    $sandboxes = new FakeSandboxManager;
    $thread = Thread::factory()->create(['sandbox_state' => $from]);

    expect(fn () => match ($operation) {
        'create' => $sandboxes->create($thread),
        'start' => $sandboxes->start($thread),
        'pause' => $sandboxes->pause($thread),
        'resume' => $sandboxes->resume($thread),
        'stop' => $sandboxes->stop($thread, 1),
        'destroy' => $sandboxes->destroy($thread),
    })->toThrow(InvalidSandboxTransition::class);

    expect($sandboxes->state($thread))->toBe($from)->and($sandboxes->calls)->toBe([]);
})->with([
    ['create', ThreadSandboxState::Running],
    ['create', ThreadSandboxState::Stopped],
    ['start', ThreadSandboxState::None],
    ['start', ThreadSandboxState::Running],
    ['start', ThreadSandboxState::Destroyed],
    ['pause', ThreadSandboxState::Stopped],
    ['pause', ThreadSandboxState::Paused],
    ['resume', ThreadSandboxState::Running],
    ['resume', ThreadSandboxState::Stopped],
    ['stop', ThreadSandboxState::None],
    ['stop', ThreadSandboxState::Stopped],
    ['destroy', ThreadSandboxState::None],
    ['destroy', ThreadSandboxState::Destroyed],
]);

test('a thread row seeds the fake with its recorded state', function () {
    $sandboxes = new FakeSandboxManager;
    $thread = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Paused]);

    $sandboxes->resume($thread);

    expect($sandboxes->state($thread))->toBe(ThreadSandboxState::Running);
});

test('exec needs an awake sandbox, records the call and returns queued results', function () {
    Process::fake();
    $sandboxes = new FakeSandboxManager;
    $thread = Thread::factory()->create();
    $sandboxes->create($thread);

    expect(fn () => $sandboxes->exec($thread, ['ls']))->toThrow(InvalidSandboxTransition::class);

    $sandboxes->start($thread);
    $sandboxes->queueExecResult(Process::result(output: 'queued', exitCode: 3));

    $first = $sandboxes->exec($thread, ['git', 'status'], 'input', ['A' => 'b']);
    $second = $sandboxes->exec($thread, ['true']);

    expect(trim($first->output()))->toBe('queued')
        ->and($first->exitCode())->toBe(3)
        ->and($second->successful())->toBeTrue()
        ->and(end($sandboxes->calls)['operation'])->toBe('exec')
        ->and($sandboxes->calls[2]['arguments'])->toBe(['command' => ['git', 'status'], 'stdin' => 'input', 'env' => ['A' => 'b']]);
});

test('copyFrom clones an existing sandbox into a stopped one and refuses an empty source', function () {
    $sandboxes = new FakeSandboxManager;
    $source = Thread::factory()->create();
    $target = Thread::factory()->create();

    expect(fn () => $sandboxes->copyFrom($source, $target))->toThrow(InvalidSandboxTransition::class);

    $sandboxes->create($source);
    $sandboxes->copyFrom($source, $target);

    expect($sandboxes->state($target))->toBe(ThreadSandboxState::Stopped)
        ->and($sandboxes->state($source))->toBe(ThreadSandboxState::Stopped);
});

test('memory and pool numbers are configurable', function () {
    $sandboxes = new FakeSandboxManager;
    $sandboxes->memoryBytes = 4 * 1024 ** 3;
    $sandboxes->poolPercent = 85.5;

    expect($sandboxes->availableMemoryBytes())->toBe(4 * 1024 ** 3)
        ->and($sandboxes->poolUsedPercent())->toBe(85.5);
});
