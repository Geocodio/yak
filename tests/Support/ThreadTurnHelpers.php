<?php

use App\DataTransferObjects\TurnTrackerChange;
use App\Enums\RunnerState;
use App\Enums\ThreadLockHolder;
use App\Enums\TurnState;
use App\Models\ClaudeAccount;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\TurnTracker;
use Mockery\MockInterface;

require_once __DIR__ . '/NextGenStubs.php';

/**
 * A busy thread with one running turn, holding the sandbox lock and an account.
 *
 * @param  array<string, mixed>  $runAttributes
 * @return array{0: Thread, 1: TaskRun}
 */
function runningTurnFor(array $runAttributes = []): array
{
    $account = ClaudeAccount::factory()->create();
    $thread = Thread::factory()->create(['claude_account_id' => $account->id]);
    $thread->forceFill([
        'runner_generation' => 1,
        'runner_state' => RunnerState::Busy,
        'lock_holder' => ThreadLockHolder::Sandbox,
        'lock_turn_id' => 'turn-1',
    ])->save();
    $run = TaskRun::factory()->create([
        'thread_id' => $thread->id,
        'claude_account_id' => $account->id,
        'turn_state' => TurnState::Running,
        'started_at' => now(),
        'agent_started_at' => null,
        'finished_at' => null,
        ...$runAttributes,
    ]);

    return [$thread, $run];
}

/**
 * Plays a recorded runner stream into the driver, one entry per line.
 */
function replayRunnerFixture(Thread $thread, string $name): void
{
    $streams = app(BrokerStreams::class);
    $driver = app(ThreadDriver::class);
    $sequence = 0;

    foreach (file(base_path("tests/Fixtures/runner-streams/{$name}.jsonl"), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $raw) {
        $line = json_decode($raw, true);

        if (! is_array($line) || isset($line['fixture']) || isset($line['_driver_sent'])) {
            continue;
        }

        $driver->handle($streams->pushOut('threads:' . $thread->id, 1, ++$sequence, $line));
    }
}

/**
 * Binds a tracker that answers each applied line with the next change.
 *
 * @param  list<TurnTrackerChange>  $changes  the last one repeats once the list runs out
 * @param  (Closure(): TurnTrackerChange)|null  $onTick
 */
function bindTrackerChanges(array $changes, ?Closure $onTick = null): MockInterface
{
    $tracker = Mockery::mock(TurnTracker::class);
    $tracker->shouldReceive('apply')->andReturnUsing(function () use (&$changes): TurnTrackerChange {
        return count($changes) > 1 ? array_shift($changes) : $changes[0];
    });
    $tracker->shouldReceive('tick')->andReturnUsing($onTick ?? fn (): TurnTrackerChange => new TurnTrackerChange);
    $tracker->shouldReceive('state')->andReturn([]);
    app()->bind(TurnTracker::class, fn () => $tracker);

    return $tracker;
}

/**
 * @param  array<string, mixed>  $line
 */
function pushRunnerLine(Thread $thread, int $sequence, array $line): void
{
    app(ThreadDriver::class)->handle(app(BrokerStreams::class)->pushOut('threads:' . $thread->id, 1, $sequence, $line));
}
