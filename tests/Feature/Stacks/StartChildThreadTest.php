<?php

use App\Enums\ThreadKind;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Jobs\StartChildThreadJob;
use App\Models\ClaudeAccount;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Services\Stacks\ChildSpawner;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeSessionFileSync;

beforeEach(function () {
    app()->instance(SessionFileSync::class, new FakeSessionFileSync);
    $this->sandbox = app(SandboxManager::class);
    $account = ClaudeAccount::factory()->create();
    $this->parent = Thread::factory()->create([
        'title' => 'Ship the widget',
        'branch' => 'yak/parent',
        'sandbox_container' => 'yak-thread-parent',
        'sandbox_state' => ThreadSandboxState::Running,
        'claude_account_id' => $account->id,
    ]);
    ThreadRepository::factory()->create(['thread_id' => $this->parent->id, 'repo' => $this->parent->repo]);
});

function spawnedChild(Thread $parent, ThreadKind $kind): Thread
{
    return app(ChildSpawner::class)->spawn($parent, 'helper', 'Find every caller of the widget.', $kind, null);
}

it('starts a turn with the wrapped brief', function () {
    $child = spawnedChild($this->parent, ThreadKind::Implement);

    (new StartChildThreadJob($child->id))->handle(app(ChildSpawner::class));

    $run = TaskRun::query()->where('thread_id', $child->id)->sole();
    $commands = app(BrokerStreams::class)->commands("threads:{$child->id}");

    expect($run->prompt)->toContain('sub-thread named "helper"')
        ->toContain('Ship the widget')
        ->toContain('cannot approve anything on a person')
        ->toContain('Find every caller of the widget.')
        ->and($run->turn_state)->toBe(TurnState::Running)
        ->and(array_column($commands, 'type'))->toBe(['start_runner', 'turn'])
        ->and($commands[0]['cwd'])->toBe('/workspace')
        ->and($child->fresh()->append_system_prompt)->not->toBeEmpty();
});

it('start of a research child uses the research runner', function () {
    $this->sandbox->queueExecResult(Process::result());
    $this->sandbox->queueExecResult(Process::result());
    $child = spawnedChild($this->parent, ThreadKind::Research);

    (new StartChildThreadJob($child->id))->handle(app(ChildSpawner::class));

    $commands = app(BrokerStreams::class)->commands("threads:{$child->id}");
    $child->refresh();

    expect($child->sharesParentSandbox())->toBeTrue()
        ->and($commands[0]['cwd'])->toBe('/workspace-research/helper')
        ->and($commands[0]['container'])->toBe('yak-thread-parent')
        ->and(array_column($commands, 'type'))->toBe(['start_runner', 'turn']);
});

it('starts a child once however many times the job runs', function () {
    $child = spawnedChild($this->parent, ThreadKind::General);
    $job = new StartChildThreadJob($child->id);

    $job->handle(app(ChildSpawner::class));
    $job->handle(app(ChildSpawner::class));

    expect(TaskRun::query()->where('thread_id', $child->id)->count())->toBe(1);
});

it('does not start a child in a stopped stack', function () {
    $child = spawnedChild($this->parent, ThreadKind::General);
    $child->update(['stack_stopped_at' => now()]);

    (new StartChildThreadJob($child->id))->handle(app(ChildSpawner::class));

    expect(TaskRun::query()->where('thread_id', $child->id)->count())->toBe(0);
});

it('drives the first turn of an HTTP spawn as the acting user', function () {
    $actor = User::factory()->create();
    $child = app(ChildSpawner::class)->spawn($this->parent, 'helper', 'Find callers.', ThreadKind::Implement, null, null, $actor);

    (new StartChildThreadJob($child->id))->handle(app(ChildSpawner::class));

    expect(TaskRun::query()->where('thread_id', $child->id)->sole()->driver_user_id)->toBe($actor->id);
});
