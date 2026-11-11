<?php

use App\Enums\ThreadKind;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Exceptions\StackRuleViolation;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use App\Services\Stacks\StackRules;
use App\Services\Stacks\StopStack;
use App\Services\Threads\Contracts\BrokerStreams;

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create();
    $this->parent = Thread::factory()->create(['sandbox_container' => 'yak-thread-parent', 'sandbox_state' => ThreadSandboxState::Running]);
    $this->implement = Thread::factory()->child($this->parent)->create(['name' => 'builder', 'sandbox_container' => 'yak-thread-builder', 'sandbox_state' => ThreadSandboxState::Running]);
    $this->research = Thread::factory()->child($this->parent)->create([
        'name' => 'scout',
        'kind' => ThreadKind::Research,
        'sandbox_container' => 'yak-thread-parent',
        'sandbox_state' => ThreadSandboxState::Running,
    ]);
});

function runningTurnFor(Thread $thread): TaskRun
{
    return TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => TurnState::Running, 'finished_at' => null]);
}

it('stops the parent and every child turn', function () {
    $runs = collect([$this->parent, $this->implement, $this->research])->map(fn (Thread $thread) => runningTurnFor($thread));

    $stopped = app(StopStack::class)->stop($this->parent);

    expect($stopped)->toBe(3)
        ->and($this->parent->fresh()->stack_stopped_at)->not->toBeNull()
        ->and($runs->map(fn (TaskRun $run) => $run->fresh()->turn_state)->unique()->all())->toBe([TurnState::Interrupted]);
});

it('kills a research child runner without touching the parent runner', function () {
    app(StopStack::class)->stop($this->parent);

    $streams = app(BrokerStreams::class);

    expect(array_column($streams->commands("threads:{$this->research->id}"), 'type'))->toBe(['kill'])
        ->and($streams->commands("threads:{$this->parent->id}"))->toBe([])
        ->and($streams->commands("threads:{$this->implement->id}"))->toBe([])
        ->and($this->research->fresh()->isOpen())->toBeTrue()
        ->and($this->parent->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running);
});

it('drops queued children', function () {
    $queued = Thread::factory()->child($this->parent)->create(['queued_for_slot_at' => now()]);

    app(StopStack::class)->stop($this->parent);

    expect($queued->fresh()->queued_for_slot_at)->toBeNull();
});

it('blocks new spawns afterwards', function () {
    app(StopStack::class)->stop($this->parent);

    expect(fn () => app(StackRules::class)->assertMaySpawn($this->parent->fresh()))->toThrow(StackRuleViolation::class);
});

it('stops the whole stack from a child page', function () {
    $this->actingAs($this->user)->post(route('threads.stack.stop', $this->implement))->assertRedirect();

    expect($this->parent->fresh()->stack_stopped_at)->not->toBeNull();
});

it('requires drive access and answers 404 for a hidden stack', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $root = Thread::factory()->private($owner)->create();
    ThreadShare::query()->create(['thread_id' => $root->id, 'user_id' => $viewer->id]);

    $this->actingAs($viewer)->post(route('threads.stack.stop', $root))->assertForbidden();
    $this->actingAs($this->user)->post(route('threads.stack.stop', $root))->assertNotFound();

    expect($root->fresh()->stack_stopped_at)->toBeNull();
});
