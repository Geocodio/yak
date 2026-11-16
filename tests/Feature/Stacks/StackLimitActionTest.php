<?php

use App\Enums\ThreadKind;
use App\Enums\ThreadSandboxState;
use App\Jobs\StartChildThreadJob;
use App\Models\Thread;
use App\Services\Stacks\ChildAwakeLimit;
use App\Services\Stacks\ChildSandboxProvisioner;
use App\Services\Stacks\StackActions;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BusyRow;

beforeEach(function () {
    config(['yak.threads.person_awake_cap' => 4]);
    $this->root = Thread::factory()->create(['child_awake_limit' => 1]);
    $this->awake = Thread::factory()->child($this->root)->create(['sandbox_state' => ThreadSandboxState::Running]);
});

it('saves the limit and starts nothing in the request', function () {
    Queue::fake();
    $first = Thread::factory()->child($this->root)->create(['queued_for_slot_at' => now()->subMinutes(2)]);
    $second = Thread::factory()->child($this->root)->create(['queued_for_slot_at' => now()->subMinute()]);
    $this->mock(ChildSandboxProvisioner::class)->shouldNotReceive('provision');

    app(StackActions::class)->setLimit($this->root, 3);

    expect($this->root->fresh()->child_awake_limit)->toBe(3);
    Queue::assertPushed(StartChildThreadJob::class, 2);
    Queue::assertPushed(StartChildThreadJob::class, fn (StartChildThreadJob $job): bool => $job->afterCommit === true);
    $ids = Queue::pushed(StartChildThreadJob::class)->map(fn (StartChildThreadJob $job): int => $job->childThreadId)->all();
    expect($ids)->toBe([$first->id, $second->id]);
});

it('pushes nothing when no slot is free', function () {
    Queue::fake();
    Thread::factory()->child($this->root)->create(['queued_for_slot_at' => now()]);

    app(StackActions::class)->setLimit($this->root, 1);

    Queue::assertNothingPushed();
});

it('pushes nothing for a stopped stack', function () {
    Queue::fake();
    Thread::factory()->child($this->root)->create(['queued_for_slot_at' => now()]);
    $this->root->forceFill(['stack_stopped_at' => now()])->save();

    app(StackActions::class)->setLimit($this->root, 3);

    Queue::assertNothingPushed();
});

it('sets the root limit from a child', function () {
    Queue::fake();

    app(StackActions::class)->setLimit($this->awake, 2);

    expect($this->root->fresh()->child_awake_limit)->toBe(2)
        ->and($this->awake->fresh()->child_awake_limit)->not->toBe(2);
});

it('answers busy when the root lock wait runs out', function () {
    Queue::fake();
    BusyRow::on(Thread::class, $this->root);

    expect(fn () => app(StackActions::class)->setLimit($this->root, 3))
        ->toThrow(fn (HttpException $exception) => expect($exception->getStatusCode())->toBe(503));
    expect($this->root->fresh()->child_awake_limit)->toBe(1);
});

it('keeps a research child out of the awake count', function () {
    Thread::factory()->child($this->root)->create(['kind' => ThreadKind::Research, 'sandbox_state' => ThreadSandboxState::Running]);

    expect(app(ChildAwakeLimit::class)->awakeCount($this->root))->toBe(1);
});
