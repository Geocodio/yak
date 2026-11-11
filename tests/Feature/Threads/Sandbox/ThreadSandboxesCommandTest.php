<?php

use App\Enums\RunnerState;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadKind;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Events\ThreadSandboxSlotFreed;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\WipPreserver;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

function sweep(): void
{
    test()->artisan('yak:thread-sandboxes')->assertSuccessful();
}

function sweepOperations(): array
{
    return array_column(app(SandboxManager::class)->calls, 'operation');
}

beforeEach(function () {
    Sleep::fake();
    config(['yak.threads.pause_to_stop_minutes' => 30, 'yak.threads.stop_to_destroy_hours' => 72, 'yak.threads.awake_cap' => 3, 'yak.threads.person_awake_cap' => 3, 'yak.threads.eviction_grace_minutes' => 10, 'yak.threads.pool_watermark_percent' => 80]);
});

test('paused sandboxes stop after the idle window', function () {
    $idle = Thread::factory()->paused()->create(['last_activity_at' => now()->subMinutes(31)]);
    $recent = Thread::factory()->paused()->create(['last_activity_at' => now()->subMinutes(5)]);

    sweep();

    expect($idle->fresh()->sandbox_state)->toBe(ThreadSandboxState::Stopped)
        ->and($recent->fresh()->sandbox_state)->toBe(ThreadSandboxState::Paused);
});

test('stopped sandboxes of closed threads are destroyed', function () {
    $closed = Thread::factory()->closed()->stopped()->create(['last_activity_at' => now()]);
    $open = Thread::factory()->stopped()->create(['last_activity_at' => now()]);

    sweep();

    expect($closed->fresh()->sandbox_state)->toBe(ThreadSandboxState::Destroyed)
        ->and($open->fresh()->sandbox_state)->toBe(ThreadSandboxState::Stopped);
});

test('stopped sandboxes destroy after 72 hours', function () {
    $old = Thread::factory()->stopped()->create(['last_activity_at' => now()->subHours(73)]);
    $young = Thread::factory()->stopped()->create(['last_activity_at' => now()->subHours(71)]);

    sweep();

    expect($old->fresh()->sandbox_state)->toBe(ThreadSandboxState::Destroyed)
        ->and($young->fresh()->sandbox_state)->toBe(ThreadSandboxState::Stopped);
});

test('watermark destroys oldest stopped first and stops at the boundary', function () {
    $manager = new class extends FakeSandboxManager
    {
        public function destroy(Thread $thread): void
        {
            parent::destroy($thread);
            $this->poolPercent -= 10;
        }
    };
    $manager->poolPercent = 95;
    app()->instance(SandboxManager::class, $manager);

    $newest = Thread::factory()->stopped()->create(['last_activity_at' => now()->subHours(3)]);
    $oldest = Thread::factory()->stopped()->create(['last_activity_at' => now()->subHours(30)]);
    $middle = Thread::factory()->stopped()->create(['last_activity_at' => now()->subHours(10)]);
    $third = Thread::factory()->stopped()->create(['last_activity_at' => now()->subHours(5)]);

    sweep();

    // 95 -> 85 -> 75: two destroys, oldest first.
    expect($oldest->fresh()->sandbox_state)->toBe(ThreadSandboxState::Destroyed)
        ->and($middle->fresh()->sandbox_state)->toBe(ThreadSandboxState::Destroyed)
        ->and($third->fresh()->sandbox_state)->toBe(ThreadSandboxState::Stopped)
        ->and($newest->fresh()->sandbox_state)->toBe(ThreadSandboxState::Stopped);
});

test('watermark never destroys when the wip push fails', function () {
    app(SandboxManager::class)->poolPercent = 95;
    $thread = Thread::factory()->stopped()->create(['last_activity_at' => now()->subHours(3)]);
    $this->mock(WipPreserver::class)->shouldReceive('preserve')->andThrow(new RuntimeException('push rejected'));

    sweep();

    expect(sweepOperations())->not->toContain('destroy')
        ->and($thread->fresh()->sandbox_state)->not->toBe(ThreadSandboxState::Destroyed);
});

test('ram floor stops the oldest paused first', function () {
    config(['yak.threads.pause_to_stop_minutes' => 600]);
    app(SandboxManager::class)->memoryBytes = 1024 ** 3;
    $newer = Thread::factory()->paused()->create(['last_activity_at' => now()->subHour()]);
    $older = Thread::factory()->paused()->create(['last_activity_at' => now()->subHours(2)]);

    sweep();

    $stops = collect(app(SandboxManager::class)->calls)->where('operation', 'stop')->pluck('threadId')->all();
    expect($stops[0])->toBe($older->id)
        ->and($older->fresh()->sandbox_state)->toBe(ThreadSandboxState::Stopped);
});

test('waiting threads are released one per free slot oldest first', function () {
    Event::fake([ThreadSandboxSlotFreed::class]);
    Thread::factory()->create(['sandbox_container' => 'c-1', 'sandbox_state' => ThreadSandboxState::Running, 'runner_state' => RunnerState::Idle, 'last_activity_at' => now()]);
    Thread::factory()->create(['sandbox_container' => 'c-2', 'sandbox_state' => ThreadSandboxState::Running, 'runner_state' => RunnerState::Idle, 'last_activity_at' => now()]);

    $second = Thread::factory()->stopped()->create(['blocked_reason' => ThreadBlockReason::WaitingForSandbox, 'blocked_at' => now()->subMinutes(5), 'last_activity_at' => now()]);
    $first = Thread::factory()->stopped()->create(['blocked_reason' => ThreadBlockReason::WaitingForSandbox, 'blocked_at' => now()->subMinutes(10), 'last_activity_at' => now()]);

    sweep();

    Event::assertDispatchedTimes(ThreadSandboxSlotFreed::class, 1);
    Event::assertDispatched(ThreadSandboxSlotFreed::class, fn (ThreadSandboxSlotFreed $event): bool => $event->thread->id === $first->id);
    expect($first->fresh()->blocked_reason)->toBeNull()
        ->and($second->fresh()->blocked_reason)->toBe(ThreadBlockReason::WaitingForSandbox);
});

test('busy sandboxes are never touched', function () {
    $paused = Thread::factory()->paused()->create(['last_activity_at' => now()->subHours(2)]);
    TaskRun::factory()->create(['thread_id' => $paused->id, 'turn_state' => TurnState::Running]);
    $stopped = Thread::factory()->closed()->stopped()->create(['last_activity_at' => now()]);
    TaskRun::factory()->create(['thread_id' => $stopped->id, 'turn_state' => TurnState::Running]);

    sweep();

    expect(sweepOperations())->toBe([])
        ->and($paused->fresh()->sandbox_state)->toBe(ThreadSandboxState::Paused)
        ->and($stopped->fresh()->sandbox_state)->toBe(ThreadSandboxState::Stopped);
});

test('woken without a turn is paused after the grace', function () {
    config(['yak.threads.wake_pause_grace_minutes' => 5]);
    $stale = Thread::factory()->create(['sandbox_container' => 'c-stale', 'sandbox_state' => ThreadSandboxState::Running, 'sandbox_state_changed_at' => now()->subMinutes(6), 'last_activity_at' => now()->subMinutes(6), 'runner_state' => RunnerState::Idle]);
    $fresh = Thread::factory()->create(['sandbox_container' => 'c-fresh', 'sandbox_state' => ThreadSandboxState::Running, 'sandbox_state_changed_at' => now()->subMinute(), 'last_activity_at' => now()->subMinute(), 'runner_state' => RunnerState::Idle]);

    sweep();

    expect($stale->fresh()->sandbox_state)->toBe(ThreadSandboxState::Paused)
        ->and($fresh->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running);
});

test('scheduled every minute without overlap', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => Str::contains($event->command, 'yak:thread-sandboxes'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

test('a research child is left alone when its parent sandbox is stopped by the sweep', function () {
    $parent = Thread::factory()->paused()->create(['last_activity_at' => now()->subMinutes(31)]);
    $child = Thread::factory()->child($parent)->create([
        'kind' => ThreadKind::Research,
        'name' => 'scan',
        'sandbox_container' => $parent->sandbox_container,
        'sandbox_state' => ThreadSandboxState::Paused,
        'last_activity_at' => now()->subMinutes(31),
    ]);

    sweep();

    expect($parent->fresh()->sandbox_state)->toBe(ThreadSandboxState::Stopped)
        ->and($child->fresh()->sandbox_container)->toBe($parent->sandbox_container)
        ->and(collect(app(SandboxManager::class)->calls)->contains(fn (array $call): bool => $call['operation'] === 'exec' && str_contains(implode(' ', $call['arguments']['command']), 'rm -rf')))->toBeFalse();
});

it('keeps an idle stopped thread whose repo asks for a longer destroy delay', function () {
    $longer = Thread::factory()->stopped()->create(['last_activity_at' => now(), 'destroy_after_hours' => 168]);
    $default = Thread::factory()->stopped()->create(['last_activity_at' => now()]);

    $this->travel(100)->hours();
    sweep();

    expect($longer->fresh()->sandbox_state)->toBe(ThreadSandboxState::Stopped)
        ->and($default->fresh()->sandbox_state)->toBe(ThreadSandboxState::Destroyed);
});

it('destroys a thread past its shorter per-repo delay', function () {
    $shorter = Thread::factory()->stopped()->create(['last_activity_at' => now(), 'destroy_after_hours' => 24]);
    $default = Thread::factory()->stopped()->create(['last_activity_at' => now()]);

    $this->travel(30)->hours();
    sweep();

    expect($shorter->fresh()->sandbox_state)->toBe(ThreadSandboxState::Destroyed)
        ->and($default->fresh()->sandbox_state)->toBe(ThreadSandboxState::Stopped);
});

it('still destroys a closed thread immediately regardless of the delay', function () {
    $closed = Thread::factory()->closed()->stopped()->create(['last_activity_at' => now(), 'destroy_after_hours' => 168]);

    sweep();

    expect($closed->fresh()->sandbox_state)->toBe(ThreadSandboxState::Destroyed);
});
