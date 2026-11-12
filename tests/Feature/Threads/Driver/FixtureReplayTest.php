<?php

use App\DataTransferObjects\TurnTrackerChange;
use App\Enums\RunnerState;
use App\Enums\ThreadBlockReason;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Models\TaskLog;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Services\ClaudeAccounts\AccountFailure;
use App\Services\ClaudeAccounts\AccountFailureKind;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\ThreadTurns;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;

require_once __DIR__ . '/../../../Support/ThreadTurnHelpers.php';
require_once __DIR__ . '/../../../Support/ReplayFixture.php';

const FIXTURE_PROMPT_UUID = 'f429399e-374d-5e1e-a7cc-65fce3bd2a28';

/**
 * A running turn whose prompt uuid is the one the fixtures use.
 *
 * @return array{0: Thread, 1: TaskRun}
 */
function fixtureTurn(): array
{
    [$thread, $run] = runningTurnFor(['prompt_uuid' => FIXTURE_PROMPT_UUID, 'prompt' => 'scrubbed text 1']);
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'uuid' => FIXTURE_PROMPT_UUID]);
    ThreadTurns::trackUuid($run, FIXTURE_PROMPT_UUID);

    return [$thread, $run];
}

it('ends a natural turn with one TurnCompleted', function () {
    Event::fake([TurnCompleted::class, TurnFailed::class]);
    [$thread, $run] = fixtureTurn();

    replayFixtureThroughConsumer($thread, 'natural-turn-end');
    $this->travel(60)->seconds();
    app(ThreadDriver::class)->tick();

    expect($run->refresh()->turn_state)->toBe(TurnState::Completed);
    Event::assertDispatchedTimes(TurnCompleted::class, 1);
    Event::assertNotDispatched(TurnFailed::class);
});

it('fails a usage-limit turn and blocks the thread', function () {
    Event::fake([TurnCompleted::class, TurnFailed::class]);
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(1794136119 - 3600));
    [$thread, $run] = fixtureTurn();

    replayFixtureThroughConsumer($thread, 'usage-limit');

    expect($run->refresh()->turn_state)->toBe(TurnState::Failed)
        ->and($thread->refresh()->blocked_reason)->toBe(ThreadBlockReason::UsageLimit)
        ->and($thread->claudeAccount->refresh()->usage_limit_resets_at->getTimestamp())->toBe(1794136119);
    Event::assertDispatchedTimes(TurnFailed::class, 1);
    Event::assertNotDispatched(TurnCompleted::class);
});

it('falls back to a delay when the limit has no reset time', function () {
    bindTrackerChanges([new TurnTrackerChange(ended: true, failure: new AccountFailure(AccountFailureKind::UsageLimit, null))]);
    [$thread] = runningTurnFor();

    pushRunnerLine($thread, 1, ['type' => 'assistant']);

    expect($thread->refresh()->blocked_reason)->toBe(ThreadBlockReason::UsageLimit)
        ->and($thread->claudeAccount->refresh()->usage_limit_resets_at->isFuture())->toBeTrue();
});

it('keeps an interrupted turn interrupted when the aborted result arrives', function () {
    Event::fake([TurnCompleted::class, TurnFailed::class]);
    [$thread, $run] = fixtureTurn();
    app(ThreadTurns::class)->stop($thread);

    replayFixtureThroughConsumer($thread, 'interrupt-result-without-result-key');

    expect($run->refresh()->turn_state)->toBe(TurnState::Interrupted);
    Event::assertNotDispatched(TurnCompleted::class);
});

it('recovers the runner once after it exits mid-turn', function () {
    [$thread, $run] = fixtureTurn();
    $thread->forceFill(['sandbox_container' => 'thread-1', 'sandbox_state' => 'running'])->save();
    app(SandboxManager::class)->queueExecResult(Process::result("2: eth0    inet 10.100.0.7/24 scope global eth0\n"));

    replayFixtureThroughConsumer($thread, 'runner-exit-mid-turn');

    $starts = array_values(array_filter(app(BrokerStreams::class)->commands('threads:' . $thread->id), fn (array $command): bool => $command['type'] === 'start_runner'));

    expect($starts)->toHaveCount(1)
        ->and($starts[0]['start']['resume'])->toBe('a46f8256-9427-5ce0-88ae-4d0cc885f796')
        ->and($run->refresh()->restart_count)->toBe(1)
        ->and($thread->refresh()->runner_state)->toBe(RunnerState::Starting);
});

it('does not complete before background work reports done', function (string $fixture) {
    Event::fake([TurnCompleted::class]);
    [$thread, $run] = fixtureTurn();
    $lines = array_values(array_filter(
        file(base_path("tests/Fixtures/runner-streams/{$fixture}.jsonl"), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        fn (string $raw): bool => ! str_contains($raw, '"_driver') && ! str_contains($raw, '"fixture"'),
    ));
    $lastResult = array_key_last(array_filter($lines, fn (string $raw): bool => str_contains($raw, '"type":"result"')));

    replayFixtureThroughConsumer($thread, $fixture, limit: $lastResult);
    app(ThreadDriver::class)->tick();

    expect($run->refresh()->turn_state)->toBe(TurnState::Running);
    Event::assertNotDispatched(TurnCompleted::class);

    // The entries already applied are dropped by the cursor; the rest finish the turn.
    replayFixtureThroughConsumer($thread, $fixture);
    $this->travel(60)->seconds();
    app(ThreadDriver::class)->tick();

    expect($run->refresh()->turn_state)->toBe(TurnState::Completed);
    Event::assertDispatchedTimes(TurnCompleted::class, 1);
})->with(['background-bash-task', 'background-subagent', 'background-bash-wake-result']);

it('steers inside the grace window without a second completion', function () {
    Event::fake([TurnCompleted::class]);
    [$thread, $run] = fixtureTurn();

    replayFixtureThroughConsumer($thread, 'steer-in-grace-window');
    $this->travel(60)->seconds();
    app(ThreadDriver::class)->tick();

    Event::assertDispatchedTimes(TurnCompleted::class, 1);
    expect($run->refresh()->turn_state)->toBe(TurnState::Completed);
});

it('applies every fixture twice without a second log line or event', function (string $fixture) {
    Event::fake([TurnCompleted::class, TurnFailed::class]);
    [$thread] = fixtureTurn();

    replayFixtureThroughConsumer($thread, $fixture);
    $logs = TaskLog::query()->count();
    $ends = collect(Event::dispatched(TurnCompleted::class))->count() + collect(Event::dispatched(TurnFailed::class))->count();

    // Same generation and sequences again: the applied cursor drops every entry.
    $streams = app(BrokerStreams::class);
    foreach ($streams->outbound('threads:' . $thread->id) as $entry) {
        app(ThreadDriver::class)->handle($entry);
    }

    expect(TaskLog::query()->count())->toBe($logs)
        ->and(collect(Event::dispatched(TurnCompleted::class))->count() + collect(Event::dispatched(TurnFailed::class))->count())->toBe($ends);
})->with(['natural-turn-end', 'usage-limit', 'steer-in-grace-window', 'compaction']);

it('completes a turn after the runner exits mid-turn and recovery continues it', function () {
    Event::fake([TurnCompleted::class, TurnFailed::class]);
    [$thread, $run] = fixtureTurn();
    $thread->forceFill(['sandbox_container' => 'thread-1', 'sandbox_state' => 'running'])->save();
    app(SandboxManager::class)->queueExecResult(Process::result("2: eth0    inet 10.100.0.7/24 scope global eth0\n"));

    replayFixtureThroughConsumer($thread, 'runner-exit-mid-turn');

    $continuation = (string) ThreadTurnMessage::query()->where('task_run_id', $run->id)->where('uuid', '!=', FIXTURE_PROMPT_UUID)->value('uuid');
    expect($run->refresh()->restart_count)->toBe(1)->and($continuation)->not->toBe('');

    $streams = app(BrokerStreams::class);
    $channel = 'threads:' . $thread->id;
    $sequence = 100;

    foreach ([
        ['type' => 'runner', 'event' => 'started', 'generation' => 2],
        ['type' => 'command_lifecycle', 'command_uuid' => $continuation, 'state' => 'started', 'generation' => 2],
        ['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'session_id' => 'a46f8256-9427-5ce0-88ae-4d0cc885f796', 'generation' => 2],
        ['type' => 'command_lifecycle', 'command_uuid' => $continuation, 'state' => 'completed', 'generation' => 2],
    ] as $line) {
        $streams->pushOut($channel, 2, ++$sequence, $line);
    }

    Artisan::call('yak:thread-drivers', ['--once' => true]);
    $this->travel(60)->seconds();
    app(ThreadDriver::class)->tick();

    expect($run->refresh()->turn_state)->toBe(TurnState::Completed);
    Event::assertDispatchedTimes(TurnCompleted::class, 1);
});
