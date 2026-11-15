<?php

use App\Enums\ThreadKind;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadStatus;
use App\Enums\TurnState;
use App\Http\Resources\StackData;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Stacks\ChildAwakeLimit;
use App\Services\Stacks\StopStack;
use Illuminate\Support\Facades\DB;

function runningTurn(Thread $thread, TurnState $state = TurnState::Running): TaskRun
{
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);

    return TaskRun::factory()->create(['thread_id' => $thread->id, 'yak_task_id' => $task->id, 'turn_state' => $state]);
}

beforeEach(function () {
    config(['yak.threads.person_awake_cap' => 4]);
    $this->owner = User::factory()->create();
    $this->root = Thread::factory()->create(['child_awake_limit' => 2]);
});

it('counts awake as the limit does', function () {
    Thread::factory()->child($this->root)->create(['sandbox_state' => ThreadSandboxState::Running]);
    Thread::factory()->child($this->root)->stopped()->create();
    Thread::factory()->child($this->root)->create(['kind' => ThreadKind::Research, 'sandbox_state' => ThreadSandboxState::Running]);
    Thread::factory()->child($this->root)->create(['queued_for_slot_at' => now()]);
    runningTurn($this->root);

    expect(StackData::for($this->root, $this->owner)['limit'])->toBe(['current' => 2, 'max' => 4, 'awake' => 1, 'waiting' => 1])
        ->and(app(ChildAwakeLimit::class)->hasFreeSlot($this->root))->toBeTrue();
});

it('says whether a child can be added', function () {
    $child = Thread::factory()->child($this->root)->create();
    $guest = User::factory()->create();
    $private = Thread::factory()->private($this->owner)->create();
    ThreadShare::query()->create(['thread_id' => $private->id, 'user_id' => $guest->id]);

    expect(StackData::for($this->root, $this->owner)['addChild'])->toBe(['allowed' => true, 'error' => null, 'message' => null])
        ->and(StackData::for($child, $this->owner)['addChild'])->toBe([
            'allowed' => false,
            'error' => 'sub_thread_cannot_spawn',
            'message' => 'A sub-thread cannot start sub-threads of its own. Add it to the parent thread instead.',
        ])
        ->and(StackData::for($private, $guest)['addChild'])->toBe(['allowed' => false, 'error' => 'forbidden', 'message' => 'You do not have access to this.']);
});

it('shows when the stack was stopped and hides Stop afterwards', function () {
    $this->root->forceFill(['stack_stopped_at' => '2026-11-15 11:50:00'])->save();

    $data = StackData::for($this->root->fresh(), $this->owner);

    expect($data['stoppedAt'])->toBe('2026-11-15T11:50:00+00:00')
        ->and($data['canStop'])->toBeFalse()
        ->and($data['addChild']['error'])->toBe('stack_stopped');
});

it('keeps Stop while a stopped stack still runs a turn', function () {
    $this->root->forceFill(['stack_stopped_at' => '2026-11-15 11:50:00'])->save();
    $child = Thread::factory()->child($this->root)->create();
    $run = runningTurn($child);
    $guest = User::factory()->create();
    $private = Thread::factory()->private($this->owner)->create(['stack_stopped_at' => now()]);
    ThreadShare::query()->create(['thread_id' => $private->id, 'user_id' => $guest->id]);
    runningTurn(Thread::factory()->child($private)->create());

    expect(StackData::for($this->root->fresh(), $this->owner)['canStop'])->toBeTrue()
        ->and(StackData::for($private->fresh(), $guest)['canStop'])->toBeFalse()
        ->and(app(StopStack::class)->hasActiveTurn($this->root))->toBeTrue();

    $run->update(['turn_state' => TurnState::Interrupted]);

    expect(app(StopStack::class)->hasActiveTurn($this->root))->toBeFalse();

    DB::enableQueryLog();
    app(StopStack::class)->hasActiveTurn($this->root);
    expect(DB::getQueryLog())->toHaveCount(1);
});

it('offers Stop only when something can be stopped', function () {
    $guest = User::factory()->create();
    $this->root = Thread::factory()->private($this->owner)->create();
    ThreadShare::query()->create(['thread_id' => $this->root->id, 'user_id' => $guest->id]);

    expect(StackData::for($this->root, $this->owner)['canStop'])->toBeFalse();

    $queued = Thread::factory()->child($this->root)->create(['queued_for_slot_at' => now()]);
    expect(StackData::for($this->root, $this->owner)['canStop'])->toBeTrue()
        ->and(StackData::for($this->root, $guest)['canStop'])->toBeFalse();

    $queued->update(['status' => ThreadStatus::Closed]);
    expect(StackData::for($this->root, $this->owner)['canStop'])->toBeFalse();

    runningTurn($this->root);
    expect(StackData::for($this->root, $this->owner)['canStop'])->toBeTrue();
});

it('answers the shape for a root with no children', function () {
    $data = StackData::for($this->root, $this->owner);

    expect($data['children'])->toBe([])
        ->and($data['messages'])->toBe([])
        ->and($data['usage']['turnCount'])->toBe(0)
        ->and($data['limit']['awake'])->toBe(0)
        ->and($data['addChild']['allowed'])->toBeTrue();
});
