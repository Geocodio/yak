<?php

use App\DataTransferObjects\YakToolCall;
use App\DataTransferObjects\YakToolResult;
use App\Models\Thread;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\YakToolRouter;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/../../../Support/ThreadTurnHelpers.php';

const CALL_ID = '5b7f6f0e-1d0a-4d6e-9a53-0c1f6c8d2a11';

/**
 * @return array<string, mixed>
 */
function toolCallLine(string $callId = CALL_ID): array
{
    return ['type' => 'yak.tool_call', 'call_id' => $callId, 'tool' => 'list_threads', 'input' => ['limit' => 3]];
}

/**
 * @return list<array<string, mixed>>
 */
function toolResults(Thread $thread): array
{
    return array_values(array_filter(
        app(BrokerStreams::class)->commands('threads:' . $thread->id),
        fn (array $command): bool => $command['type'] === 'tool_result',
    ));
}

it('answers a tool call once even when redelivered', function () {
    $calls = new ArrayObject(['count' => 0]);
    app()->bind(YakToolRouter::class, fn () => new class($calls) implements YakToolRouter
    {
        public function __construct(private ArrayObject $calls) {}

        public function handle(Thread $thread, YakToolCall $call): YakToolResult
        {
            $this->calls['count']++;

            return new YakToolResult(false, 'three threads');
        }
    });
    [$thread] = runningTurnFor();

    pushRunnerLine($thread, 1, toolCallLine());
    pushRunnerLine($thread, 1, toolCallLine());
    pushRunnerLine($thread, 2, toolCallLine());

    expect(toolResults($thread))->toBe([['type' => 'tool_result', 'call_id' => CALL_ID, 'is_error' => false, 'content' => 'three threads']])
        ->and($calls['count'])->toBe(1)
        ->and(DB::table('thread_tool_calls')->where('thread_id', $thread->id)->whereNotNull('answered_at')->count())->toBe(1);
});

it('answers a stale generation with nothing', function () {
    [$thread] = runningTurnFor();
    $thread->forceFill(['runner_generation' => 2])->save();

    app(ThreadDriver::class)->handle(app(BrokerStreams::class)->pushOut('threads:' . $thread->id, 1, 1, toolCallLine()));

    expect(toolResults($thread))->toBe([])
        ->and(DB::table('thread_tool_calls')->count())->toBe(0);
});

it('turns a router exception into an error result', function () {
    app()->bind(YakToolRouter::class, fn () => new class implements YakToolRouter
    {
        public function handle(Thread $thread, YakToolCall $call): YakToolResult
        {
            throw new RuntimeException('boom with sk-ant-secret-123');
        }
    });
    [$thread] = runningTurnFor();

    pushRunnerLine($thread, 1, toolCallLine());

    $results = toolResults($thread);

    expect($results)->toHaveCount(1)
        ->and($results[0]['is_error'])->toBeTrue()
        ->and($results[0]['content'])->toContain('boom')->not->toContain('sk-ant-secret-123');
});

it('uses the registered router', function () {
    app()->bind(YakToolRouter::class, fn () => new class implements YakToolRouter
    {
        public function handle(Thread $thread, YakToolCall $call): YakToolResult
        {
            return new YakToolResult(false, "ran {$call->tool} with " . json_encode($call->input));
        }
    });
    [$thread] = runningTurnFor();

    pushRunnerLine($thread, 1, toolCallLine());

    expect(toolResults($thread)[0]['content'])->toBe('ran list_threads with {"limit":3}');
});

it('does not hand the call to the log handler', function () {
    [$thread, $run] = runningTurnFor();

    pushRunnerLine($thread, 1, toolCallLine());

    expect($run->task?->logs()->count() ?? 0)->toBe(0);
});
