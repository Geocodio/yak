<?php

use App\DataTransferObjects\Files\SandboxUnavailable;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\Files\SandboxFileAvailability;

beforeEach(function () {
    $this->sandboxes = new FakeSandboxManager;
    app()->instance(SandboxManager::class, $this->sandboxes);
    $this->owner = User::factory()->create();
    $this->viewer = User::factory()->create();
});

function availabilityThread(ThreadSandboxState $state, array $attributes = []): Thread
{
    $thread = Thread::factory()->private(test()->owner)->create(['sandbox_state' => $state, ...$attributes]);
    ThreadShare::query()->create(['thread_id' => $thread->id, 'user_id' => test()->viewer->id]);

    return $thread;
}

function checkAvailability(Thread $thread, ?User $user = null): ?SandboxUnavailable
{
    return app(SandboxFileAvailability::class)->check($thread->fresh(), $user ?? test()->owner);
}

it('serves a running sandbox', function () {
    expect(checkAvailability(availabilityThread(ThreadSandboxState::Running)))->toBeNull();
});

it('says a thread on a computer has its files there', function () {
    $unavailable = checkAvailability(availabilityThread(ThreadSandboxState::Running, ['owner_side' => ThreadOwnerSide::Device]));

    expect($unavailable->state)->toBe('on_device')
        ->and($unavailable->details())->toBe(['state' => 'on_device', 'canRestore' => false, 'waitingForSlot' => false, 'restoring' => false])
        ->and($unavailable->message())->toBe('This thread runs on a computer, so its files are there.');
});

it('says a removed sandbox was removed and offers a restore to a driver', function (ThreadSandboxState $state) {
    $thread = availabilityThread($state);

    $owner = checkAvailability($thread);
    $viewer = checkAvailability($thread, $this->viewer);

    expect($owner->state)->toBe('destroyed')
        ->and($owner->canRestore)->toBeTrue()
        ->and($owner->message())->toBe('The sandbox was removed.')
        ->and($viewer->state)->toBe('destroyed')
        ->and($viewer->canRestore)->toBeFalse();
})->with([ThreadSandboxState::Destroying, ThreadSandboxState::Destroyed]);

it('offers no restore for a sandbox that never existed', function () {
    $thread = availabilityThread(ThreadSandboxState::None);

    expect(checkAvailability($thread)->state)->toBe('destroyed')
        ->and(checkAvailability($thread)->canRestore)->toBeFalse();
});

it('offers a restore for a failed restore that left no sandbox', function () {
    $thread = availabilityThread(ThreadSandboxState::None, ['blocked_reason' => ThreadBlockReason::RestoreFailed]);

    expect(checkAvailability($thread)->canRestore)->toBeTrue();
});

it('says a closed thread lost its sandbox', function () {
    $thread = availabilityThread(ThreadSandboxState::Destroyed);
    $thread->forceFill(['status' => 'closed'])->save();

    $unavailable = checkAvailability($thread);

    expect($unavailable->state)->toBe('closed_destroyed')
        ->and($unavailable->message())->toBe('This thread is closed, so its sandbox is gone.');
});

it('says a starting sandbox is being restored', function () {
    $unavailable = checkAvailability(availabilityThread(ThreadSandboxState::Starting));

    expect($unavailable->state)->toBe('waking')
        ->and($unavailable->restoring)->toBeTrue()
        ->and($unavailable->waitingForSlot)->toBeFalse()
        ->and($unavailable->message())->toBe('The sandbox is being restored.');
});

it('wakes a sleeping sandbox for a driver', function (ThreadSandboxState $state) {
    $thread = availabilityThread($state);

    expect(checkAvailability($thread))->toBeNull()
        ->and($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running);
})->with([ThreadSandboxState::Paused, ThreadSandboxState::Stopped]);

it('never wakes a sleeping sandbox for a share recipient', function (ThreadSandboxState $state) {
    $thread = availabilityThread($state);

    $unavailable = checkAvailability($thread, $this->viewer);

    expect($unavailable->state)->toBe('asleep')
        ->and($unavailable->message())->toBe('The sandbox is asleep. It wakes when someone who can drive this thread opens it.')
        ->and($unavailable->webBody())->toBe(['state' => 'waking', 'waitingForSlot' => false, 'restoring' => false])
        ->and(collect($this->sandboxes->calls)->pluck('operation')->all())->not->toContain('resume', 'start')
        ->and($thread->fresh()->sandbox_state)->toBe($state);
})->with([ThreadSandboxState::Paused, ThreadSandboxState::Stopped]);

it('says a queued restore is running', function () {
    $thread = availabilityThread(ThreadSandboxState::Destroyed, ['restore_requested_at' => now()]);

    $unavailable = checkAvailability($thread);

    expect($unavailable->state)->toBe('waking')
        ->and($unavailable->restoring)->toBeTrue()
        ->and($unavailable->waitingForSlot)->toBeFalse()
        ->and($unavailable->message())->toBe('The sandbox is being restored.');
});

it('says a queued restore waits for a slot', function () {
    $thread = availabilityThread(ThreadSandboxState::Destroyed, [
        'restore_requested_at' => now(),
        'blocked_reason' => ThreadBlockReason::WaitingForSandbox,
    ]);

    $unavailable = checkAvailability($thread);

    expect($unavailable->restoring)->toBeTrue()
        ->and($unavailable->waitingForSlot)->toBeTrue()
        ->and($unavailable->message())->toBe('The sandbox is waiting for a free slot.');
});

it('offers a restore again once the guard is old', function () {
    $thread = availabilityThread(ThreadSandboxState::Destroyed, ['restore_requested_at' => now()->subMinutes(31)]);

    $unavailable = checkAvailability($thread);

    expect($unavailable->state)->toBe('destroyed')
        ->and($unavailable->canRestore)->toBeTrue();
});

it('keeps the web bodies', function () {
    expect((new SandboxUnavailable('on_device'))->webBody())->toBe(['state' => 'on_device'])
        ->and((new SandboxUnavailable('closed_destroyed'))->webBody())->toBe(['state' => 'closed_destroyed'])
        ->and((new SandboxUnavailable('destroyed', canRestore: true))->webBody())->toBe(['state' => 'destroyed', 'canRestore' => true])
        ->and((new SandboxUnavailable('waking', restoring: true))->webBody())->toBe(['state' => 'waking', 'waitingForSlot' => false, 'restoring' => true])
        ->and((new SandboxUnavailable('waking', waitingForSlot: true))->webBody())->toBe(['state' => 'waking', 'waitingForSlot' => true, 'restoring' => false]);
});

it('always carries all four details keys', function () {
    expect(array_keys((new SandboxUnavailable('asleep'))->details()))->toBe(['state', 'canRestore', 'waitingForSlot', 'restoring']);
});
