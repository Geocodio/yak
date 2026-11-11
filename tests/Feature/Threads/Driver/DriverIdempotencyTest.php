<?php

use App\Enums\TurnState;
use App\Events\ThreadActivity;
use App\Models\TaskLog;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\ThreadDriver;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/../../../Support/NextGenStubs.php';

function driverThread(int $generation = 1): Thread
{
    $thread = Thread::factory()->create();
    $thread->forceFill(['runner_generation' => $generation])->save();

    return $thread;
}

function runningRun(Thread $thread): TaskRun
{
    return TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => TurnState::Running]);
}

function assistantToolLine(string $text = 'hi'): array
{
    return ['type' => 'assistant', 'message' => ['content' => [
        ['type' => 'text', 'text' => $text],
        ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'Bash', 'input' => ['command' => 'ls']],
    ]]];
}

it('applies a redelivered entry once', function () {
    Event::fake([ThreadActivity::class]);
    $thread = driverThread();
    runningRun($thread);
    $entry = app(BrokerStreams::class)->pushOut('threads:' . $thread->id, 1, 1, assistantToolLine());
    $driver = app(ThreadDriver::class);

    $driver->handle($entry);
    $logCount = TaskLog::query()->count();
    $driver->handle($entry);

    expect($logCount)->toBeGreaterThan(0)
        ->and(TaskLog::query()->count())->toBe($logCount);
    Event::assertDispatchedTimes(ThreadActivity::class, 1);
    expect($thread->refresh()->getAttribute('applied_sequence'))->toBe(1);
});

it('skips an entry older than the cursor within the same generation', function () {
    Event::fake([ThreadActivity::class]);
    $thread = driverThread();
    $streams = app(BrokerStreams::class);
    $driver = app(ThreadDriver::class);

    $driver->handle($streams->pushOut('threads:' . $thread->id, 1, 5, ['type' => 'assistant']));
    $driver->handle($streams->pushOut('threads:' . $thread->id, 1, 4, ['type' => 'assistant']));

    Event::assertDispatchedTimes(ThreadActivity::class, 1);
    expect((int) $thread->refresh()->getAttribute('applied_sequence'))->toBe(5);
});

it('ignores unknown threads and run channels', function () {
    Event::fake([ThreadActivity::class]);
    $driver = app(ThreadDriver::class);
    $streams = app(BrokerStreams::class);

    $driver->handle($streams->pushOut('threads:999999', 1, 1, ['type' => 'assistant']));
    $driver->handle($streams->pushOut('runs:1', 1, 1, ['type' => 'assistant']));

    Event::assertNotDispatched(ThreadActivity::class);
});
