<?php

use App\Enums\TurnState;
use App\Events\ThreadActivity;
use App\Models\TaskLog;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\ThreadDriver;
use Illuminate\Support\Facades\Event;

it('never logs or broadcasts a secret', function () {
    Event::fake([ThreadActivity::class]);
    $thread = Thread::factory()->create();
    $thread->forceFill(['runner_generation' => 1])->save();
    TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => TurnState::Running]);
    $line = ['type' => 'assistant', 'message' => ['content' => [
        ['type' => 'text', 'text' => 'token sk-ant-oat01-SECRET and yakph-ABCDEFGHIJKLMNOPQRSTUVWX'],
        ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'Bash', 'input' => ['command' => 'echo sk-ant-oat01-SECRET yakph-ABCDEFGHIJKLMNOPQRSTUVWX']],
    ]]];

    app(ThreadDriver::class)->handle(app(BrokerStreams::class)->pushOut('threads:' . $thread->id, 1, 1, $line));

    expect(TaskLog::query()->count())->toBeGreaterThan(0)
        ->and(TaskLog::all()->toJson())->not->toContain('sk-ant-oat01-SECRET')->not->toContain('yakph-ABCDEFGHIJKLMNOPQRSTUVWX');
    Event::assertDispatched(ThreadActivity::class, fn (ThreadActivity $event): bool => ! str_contains(json_encode($event->line), 'SECRET') && ! str_contains(json_encode($event->line), 'yakph-ABCDEFGHIJKLMNOPQRSTUVWX'));
});

it('writes tool calls from the stream to task logs', function () {
    $thread = Thread::factory()->create();
    $thread->forceFill(['runner_generation' => 1])->save();
    TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => TurnState::Running]);
    $streams = app(BrokerStreams::class);
    $driver = app(ThreadDriver::class);
    $sequence = 0;

    foreach (file(base_path('tests/Fixtures/runner-streams/natural-turn-end.jsonl'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $raw) {
        $line = json_decode($raw, true);

        if (! is_array($line) || isset($line['fixture']) || isset($line['_driver'])) {
            continue;
        }

        $driver->handle($streams->pushOut('threads:' . $thread->id, 1, ++$sequence, $line));
    }

    expect(TaskLog::query()->count())->toBeGreaterThan(0);
});

it('still announces lines when no turn is active', function () {
    Event::fake([ThreadActivity::class]);
    $thread = Thread::factory()->create();
    $thread->forceFill(['runner_generation' => 1])->save();

    app(ThreadDriver::class)->handle(app(BrokerStreams::class)->pushOut('threads:' . $thread->id, 1, 1, ['type' => 'assistant']));

    Event::assertDispatchedTimes(ThreadActivity::class, 1);
});
