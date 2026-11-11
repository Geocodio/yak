<?php

use App\Enums\RunnerState;
use App\Enums\TurnState;
use App\Events\AccountSlotFreed;
use App\Models\ClaudeAccount;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Services\Threads\AuthProxyPlaceholders;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([AccountSlotFreed::class]);
    $this->account = ClaudeAccount::factory()->create();
});

function heldRun(array $attributes = []): TaskRun
{
    return TaskRun::factory()->create(array_merge([
        'claude_account_id' => test()->account->id,
        'account_slot_held_at' => now()->subMinutes(10),
        'finished_at' => null,
    ], $attributes));
}

function issueRunPlaceholder(TaskRun $run): string
{
    return app(AuthProxyPlaceholders::class)->issue('run:' . $run->id, test()->account, '10.0.0.5');
}

it('releases the slot and revokes the run placeholder of an expired one-shot run', function () {
    $run = heldRun(['oneshot_heartbeat_at' => now(), 'oneshot_deadline_at' => now()->subMinute()]);
    $placeholder = issueRunPlaceholder($run);

    $this->artisan('yak:reap-account-slots')->assertSuccessful();

    expect($run->fresh()->account_slot_held_at)->toBeNull()
        ->and(app(AuthProxyPlaceholders::class)->resolve($placeholder, '10.0.0.5'))->toBeNull();
    Event::assertDispatchedTimes(AccountSlotFreed::class, 1);
});

it('releases a one-shot run with no heartbeat for several minutes', function () {
    $run = heldRun(['oneshot_heartbeat_at' => now()->subMinutes(5), 'oneshot_deadline_at' => now()->addMinutes(30)]);

    $this->artisan('yak:reap-account-slots')->assertSuccessful();

    expect($run->fresh()->account_slot_held_at)->toBeNull();
});

it('keeps a live one-shot run', function () {
    $run = heldRun(['oneshot_heartbeat_at' => now()->subSeconds(20), 'oneshot_deadline_at' => now()->addMinutes(30)]);
    $placeholder = issueRunPlaceholder($run);

    $this->artisan('yak:reap-account-slots')->assertSuccessful();

    expect($run->fresh()->account_slot_held_at)->not->toBeNull()
        ->and(app(AuthProxyPlaceholders::class)->resolve($placeholder, '10.0.0.5'))->not->toBeNull();
    Event::assertNotDispatched(AccountSlotFreed::class);
});

it('releases a slot held by a terminal thread turn', function () {
    $run = heldRun(['thread_id' => Thread::factory()->create(['runner_state' => RunnerState::Idle])->id, 'turn_state' => TurnState::Completed]);

    $this->artisan('yak:reap-account-slots')->assertSuccessful();

    expect($run->fresh()->account_slot_held_at)->toBeNull();
});

it('releases a slot and revokes the thread placeholder when the thread runner is gone', function () {
    $thread = Thread::factory()->create(['runner_state' => RunnerState::None, 'sandbox_container' => 'c1']);
    $run = heldRun(['thread_id' => $thread->id, 'turn_state' => TurnState::Running]);
    $placeholder = app(AuthProxyPlaceholders::class)->issue($thread, $this->account, '10.0.0.5', 'c1');

    $this->artisan('yak:reap-account-slots')->assertSuccessful();

    expect($run->fresh()->account_slot_held_at)->toBeNull()
        ->and(app(AuthProxyPlaceholders::class)->resolve($placeholder, '10.0.0.5'))->toBeNull();
});

it('keeps a running thread turn with a live runner', function () {
    $run = heldRun(['thread_id' => Thread::factory()->create(['runner_state' => RunnerState::Busy])->id, 'turn_state' => TurnState::Running]);

    $this->artisan('yak:reap-account-slots')->assertSuccessful();

    expect($run->fresh()->account_slot_held_at)->not->toBeNull();
    Event::assertNotDispatched(AccountSlotFreed::class);
});

it('keeps a slot held by a turn waiting for a sandbox', function () {
    $run = heldRun(['thread_id' => Thread::factory()->create(['runner_state' => RunnerState::None])->id, 'turn_state' => TurnState::WaitingForSandbox]);

    $this->artisan('yak:reap-account-slots')->assertSuccessful();

    expect($run->fresh()->account_slot_held_at)->not->toBeNull();
    Event::assertNotDispatched(AccountSlotFreed::class);
});

it('keeps a slot acquired less than two minutes ago', function () {
    $run = heldRun([
        'account_slot_held_at' => now()->subSeconds(30),
        'thread_id' => Thread::factory()->create(['runner_state' => RunnerState::None])->id,
        'turn_state' => TurnState::Running,
    ]);

    $this->artisan('yak:reap-account-slots')->assertSuccessful();

    expect($run->fresh()->account_slot_held_at)->not->toBeNull();
});

it('is idempotent', function () {
    heldRun(['oneshot_deadline_at' => now()->subMinute()]);

    $this->artisan('yak:reap-account-slots')->assertSuccessful();
    $this->artisan('yak:reap-account-slots')->assertSuccessful();

    Event::assertDispatchedTimes(AccountSlotFreed::class, 1);
});

it('is scheduled every minute', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'yak:reap-account-slots'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *');
});
