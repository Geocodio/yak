<?php

use App\Enums\TurnMessageState;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Services\E2e\E2eRunnerScript;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeBrokerStreams;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/../Support/ThreadTurnHelpers.php';

const RECORDED_COMMAND_UUID = 'f429399e-374d-5e1e-a7cc-65fce3bd2a28';
const RECORDED_STEER_UUID = 'da1213d2-e404-5255-85f1-5d09e68a921a';
const SCRIPT_TURN_UUID = '11111111-2222-4333-8444-555555555555';

beforeEach(function (): void {
    app()->singleton(BrokerStreams::class, fn () => new FakeBrokerStreams);
    app()->singleton(SandboxManager::class, fn () => new FakeSandboxManager);
});

/**
 * @return array<string, mixed>
 */
function scriptStartCommand(): array
{
    return ['type' => 'start_runner', 'container' => 'thread-1', 'start' => ['type' => 'start']];
}

/**
 * @return array<string, mixed>
 */
function scriptTurnCommand(string $text = 'Hello', string $uuid = SCRIPT_TURN_UUID): array
{
    return ['type' => 'turn', 'uuid' => $uuid, 'text' => $text];
}

/**
 * A script whose runner on the channel is live.
 */
function liveScript(float $pace = 1.0, string $channel = 'threads:1'): E2eRunnerScript
{
    $script = new E2eRunnerScript(pace: $pace);
    $script->respond($channel, scriptStartCommand(), 1);

    return $script;
}

/**
 * Writes scripted lines into the thread's channel and runs the driver over them.
 *
 * @param  list<array{afterMilliseconds: int, line: array<string, mixed>, fromBroker: bool}>  $lines
 */
function playScriptLines(Thread $thread, array $lines): void
{
    static $sequences = [];

    $streams = app(BrokerStreams::class);
    $channel = 'threads:' . $thread->id;
    $streams->register($channel);

    foreach ($lines as $scripted) {
        $streams->pushOut($channel, 1, $sequences[$channel] = ($sequences[$channel] ?? 0) + 1, $scripted['line'], $scripted['fromBroker']);
    }

    Artisan::call('yak:thread-drivers', ['--once' => true]);
}

/**
 * @return array{0: Thread, 1: TaskRun}
 */
function scriptTurn(string $uuid = SCRIPT_TURN_UUID): array
{
    [$thread, $run] = runningTurnFor(['prompt_uuid' => $uuid, 'prompt' => 'Hello']);
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'uuid' => $uuid]);
    ThreadTurns::trackUuid($run, $uuid);

    return [$thread, $run];
}

it('answers a start with the broker and runner start lines', function (): void {
    $lines = (new E2eRunnerScript)->respond('threads:1', scriptStartCommand(), 1);

    expect($lines)->toHaveCount(2)
        ->and($lines[0]['fromBroker'])->toBeTrue()
        ->and($lines[0]['line']['type'])->toBe('broker.runner_started')
        ->and($lines[0]['line']['generation'])->toBe(1)
        ->and($lines[1]['fromBroker'])->toBeFalse()
        ->and($lines[1]['line'])->toMatchArray(['type' => 'runner', 'event' => 'started']);
});

it('plays natural-turn-end under the command uuid', function (): void {
    $lines = liveScript(1.0)->respond('threads:1', scriptTurnCommand(), 1);
    $times = array_column($lines, 'afterMilliseconds');
    $encoded = json_encode(array_column($lines, 'line'));
    $assistant = collect($lines)->first(fn (array $scripted): bool => ($scripted['line']['type'] ?? null) === 'assistant');

    expect($encoded)->not->toContain(RECORDED_COMMAND_UUID)->not->toContain('"_ms"')
        ->and($times)->toBe(collect($times)->sort()->values()->all())
        ->and(end($times))->toBeLessThanOrEqual(30000)
        ->and($assistant['line']['message']['content'][0]['text'])->toBe('scrubbed text 1')
        ->and(collect($lines)->where('line.type', 'command_lifecycle')->every(fn (array $scripted): bool => $scripted['line']['command_uuid'] === SCRIPT_TURN_UUID))->toBeTrue()
        ->and(collect($lines)->where('line.type', 'runner')->isEmpty())->toBeTrue();
});

it('stretches recorded time by the pace', function (): void {
    $normal = array_column(liveScript(1.0)->respond('threads:1', scriptTurnCommand(), 1), 'afterMilliseconds');
    $slow = array_column(liveScript(4.0)->respond('threads:1', scriptTurnCommand(), 1), 'afterMilliseconds');
    $instant = array_column(liveScript(0.0)->respond('threads:1', scriptTurnCommand(), 1), 'afterMilliseconds');

    foreach ($normal as $index => $milliseconds) {
        expect(abs($slow[$index] - 4 * $milliseconds))->toBeLessThanOrEqual(1);
    }

    expect(end($slow))->toBeBetween(8000, 12000)
        ->and(array_unique($instant))->toBe([0]);
});

it('answers a turn without a runner with no_runner', function (): void {
    $script = new E2eRunnerScript;

    foreach (['turn', 'steer'] as $type) {
        $lines = $script->respond('threads:1', [...scriptTurnCommand(), 'type' => $type], 1);

        expect($lines)->toBe([['afterMilliseconds' => 0, 'line' => ['type' => 'broker.no_runner', 'uuid' => SCRIPT_TURN_UUID], 'fromBroker' => true]]);
    }

    $script->respond('threads:1', scriptStartCommand(), 1);

    expect($script->hasRunner('threads:1'))->toBeTrue()
        ->and($script->respond('threads:1', scriptTurnCommand(), 1)[0]['line']['type'])->not->toBe('broker.no_runner');

    $end = $script->respond('threads:1', ['type' => 'end'], 1);

    expect($script->hasRunner('threads:1'))->toBeFalse()
        ->and(array_column(array_column($end, 'line'), 'type'))->toContain('broker.runner_exited')
        ->and($script->respond('threads:1', scriptTurnCommand(), 1)[0]['line']['type'])->toBe('broker.no_runner');
});

it('ends a turn exactly once through the real driver', function (): void {
    Event::fake([TurnCompleted::class, TurnFailed::class]);
    [$thread, $run] = scriptTurn();

    playScriptLines($thread, liveScript()->respond('threads:' . $thread->id, scriptTurnCommand(), 1));
    $this->travel(60)->seconds();
    app(ThreadDriver::class)->tick();

    expect($run->refresh()->turn_state)->toBe(TurnState::Completed);
    Event::assertDispatchedTimes(TurnCompleted::class, 1);
    Event::assertNotDispatched(TurnFailed::class);
});

it('holds a marked turn until it is interrupted', function (): void {
    Event::fake([TurnCompleted::class, TurnFailed::class]);
    [$thread, $run] = scriptTurn();
    $channel = 'threads:' . $thread->id;
    $script = liveScript(1.0, $channel);

    playScriptLines($thread, $script->respond($channel, scriptTurnCommand('Work on it ' . E2eRunnerScript::HOLD_MARKER), 1));

    expect($run->refresh()->turn_state)->toBe(TurnState::Running)
        ->and($script->isHolding($channel))->toBeTrue();

    app(ThreadTurns::class)->stop($thread);
    playScriptLines($thread, $script->respond($channel, ['type' => 'interrupt'], 1));

    expect($run->refresh()->turn_state)->toBe(TurnState::Interrupted)
        ->and($script->isHolding($channel))->toBeFalse()
        ->and($script->hasRunner($channel))->toBeTrue();
    Event::assertNotDispatched(TurnCompleted::class);
});

it('marks a steer on a held turn as started under its own uuid', function (): void {
    [$thread, $run] = scriptTurn();
    $channel = 'threads:' . $thread->id;
    $script = liveScript(1.0, $channel);
    $steerUuid = '99999999-8888-4777-8666-555555555555';
    $steer = ThreadTurnMessage::factory()->steer()->create(['task_run_id' => $run->id, 'uuid' => $steerUuid]);
    ThreadTurns::trackUuid($run, $steerUuid);

    playScriptLines($thread, $script->respond($channel, scriptTurnCommand('Work ' . E2eRunnerScript::HOLD_MARKER), 1));
    $lines = $script->respond($channel, ['type' => 'steer', 'uuid' => $steerUuid, 'text' => 'Go left'], 1);

    expect(json_encode($lines))->toContain($steerUuid)->not->toContain(RECORDED_STEER_UUID);

    playScriptLines($thread, $lines);

    expect($steer->refresh()->state)->toBe(TurnMessageState::Started)
        ->and($run->refresh()->turn_state)->toBe(TurnState::Running);
});

it('releases a held turn after its bound', function (): void {
    // The bound is measured in whole seconds from the hold, so a clock that moves between the hold and the checks skews it.
    $this->freezeTime();
    Event::fake([TurnCompleted::class, TurnFailed::class]);
    [$thread, $run] = scriptTurn();
    $channel = 'threads:' . $thread->id;
    $script = liveScript(1.0, $channel);
    playScriptLines($thread, $script->respond($channel, scriptTurnCommand('Work ' . E2eRunnerScript::HOLD_MARKER), 1));

    expect($script->releaseExpired(now()->addSeconds(119)))->toBe([]);

    $released = $script->releaseExpired(now()->addSeconds(121));

    expect(array_keys($released))->toBe([$channel])
        ->and($script->isHolding($channel))->toBeFalse();

    playScriptLines($thread, $released[$channel]);
    $this->travel(60)->seconds();
    app(ThreadDriver::class)->tick();

    expect($run->refresh()->turn_state)->toBe(TurnState::Completed);
    Event::assertDispatchedTimes(TurnCompleted::class, 1);
});

it('ignores what it cannot answer', function (array $command): void {
    expect(liveScript()->respond('threads:1', $command, 1))->toBe([]);
})->with([
    'turn without uuid' => [['type' => 'turn', 'text' => 'x']],
    'turn with a numeric uuid' => [['type' => 'turn', 'uuid' => 42]],
    'unknown type' => [['type' => 'unknown']],
    'no type' => [['uuid' => SCRIPT_TURN_UUID]],
    'set_model' => [['type' => 'set_model', 'model' => 'x']],
    'tool_result' => [['type' => 'tool_result', 'call_id' => 'c', 'is_error' => false, 'content' => 'x']],
    'interrupt while not held' => [['type' => 'interrupt']],
]);

it('refuses an oversized or overlong fixture', function (string $contents): void {
    $directory = sys_get_temp_dir() . '/e2e-fixtures-' . bin2hex(random_bytes(4));
    mkdir($directory);

    try {
        file_put_contents($directory . '/natural-turn-end.jsonl', $contents);

        expect(fn () => (new E2eRunnerScript($directory))->respond('threads:1', scriptStartCommand(), 1))
            ->toThrow(RuntimeException::class, 'natural-turn-end.jsonl');
    } finally {
        array_map('unlink', glob($directory . '/*') ?: []);
        rmdir($directory);
    }
})->with([
    'over one MiB' => [fn () => str_repeat('a', E2eRunnerScript::MAX_FIXTURE_BYTES + 1)],
    'over 2000 lines' => [fn () => str_repeat("{}\n", E2eRunnerScript::MAX_FIXTURE_LINES + 1)],
]);

it('times an interrupt and a steer after the held turn lines that are still queued', function (string $commandType): void {
    $this->freezeTime();
    $script = liveScript(4.0);
    $opening = $script->respond('threads:1', scriptTurnCommand('Look closer ' . E2eRunnerScript::HOLD_MARKER), 1);
    $lastOpeningDue = max(array_column($opening, 'afterMilliseconds'));

    $following = $script->respond('threads:1', ['type' => $commandType, 'uuid' => '99999999-2222-4333-8444-555555555555'], 1);

    expect($lastOpeningDue)->toBeGreaterThan(8000)
        ->and($following)->not->toBeEmpty()
        ->and(min(array_column($following, 'afterMilliseconds')))->toBeGreaterThanOrEqual($lastOpeningDue);
})->with(['interrupt', 'steer']);

it('keeps a command uuid with a quote or a backslash inside the lines', function (): void {
    $uuid = 'a"b\\c';
    $lines = liveScript(0.0)->respond('threads:1', scriptTurnCommand('Hello', $uuid), 1);

    expect(collect($lines)->where('line.type', 'command_lifecycle')->every(fn (array $scripted): bool => $scripted['line']['command_uuid'] === $uuid))->toBeTrue();
});
