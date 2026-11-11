<?php

use App\Enums\ThreadStatus;
use App\Enums\TurnState;
use App\Events\RunnerExited;
use App\Events\ThreadActivity;
use App\Events\ThreadEventBroadcast;
use App\Events\TurnCompleted;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\YakTask;
use App\Services\Threads\ThreadEventWindow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/RealtimeRedis.php';

beforeEach(function () {
    config(['yak.threads.realtime_key_prefix' => parallelToken() . ':']);
    useThrowawayRedis();
    Event::fake([ThreadEventBroadcast::class]);
});

afterEach(fn () => stopThrowawayRedis());

/**
 * @return list<array<string, mixed>>
 */
function fixtureLines(string $name): array
{
    $lines = file(base_path("tests/Fixtures/runner-streams/{$name}.jsonl"), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    array_shift($lines);

    return array_map(function (string $line): array {
        $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        unset($decoded['_ms']);

        return $decoded;
    }, $lines);
}

/**
 * @param  array<string, mixed>  $line
 */
function activity(Thread $thread, array $line, int $sequence = 1): void
{
    event(new ThreadActivity($thread, 1, $sequence, $line));
}

function streamDelta(string $text, int $index = 0): array
{
    return ['type' => 'stream_event', 'message_id' => 'm1', 'event' => ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'text_delta', 'text' => $text]]];
}

it('replays a fixture into ordered entries and a completed turn event', function () {
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    $run = TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => TurnState::Completed]);

    foreach (fixtureLines('natural-turn-end') as $index => $line) {
        activity($thread, $line, $index + 1);
    }

    event(new TurnCompleted($run));

    $stored = DB::table('thread_events')->where('thread_id', $thread->id)->orderBy('sequence')->get();
    $window = app(ThreadEventWindow::class)->since($thread->id, 0, 100);

    expect($stored->pluck('type')->all())->toBe(['entry', 'entry', 'entry', 'turn'])
        ->and(json_decode($stored[0]->payload, true)['role'])->toBe('system')
        ->and(json_decode($stored[1]->payload, true)['role'])->toBe('assistant')
        ->and(json_decode($stored[2]->payload, true)['role'])->toBe('result')
        ->and(json_decode($stored[3]->payload, true))->toBe(['turnId' => $run->id, 'state' => 'completed'])
        ->and(array_column($window, 'sequence'))->toBe([1, 2, 3, 4]);
});

it('flushes buffered deltas before a structural event', function () {
    $thread = Thread::factory()->create();

    activity($thread, streamDelta('Hel'));
    activity($thread, streamDelta('lo'));
    activity($thread, ['type' => 'assistant', 'message' => ['id' => 'm1', 'content' => [['type' => 'text', 'text' => 'Hello']]]]);

    $window = app(ThreadEventWindow::class)->since($thread->id, 0, 100);

    expect(array_column($window, 'type'))->toBe(['delta', 'entry'])
        ->and($window[0]['data'])->toBe(['blockKey' => 'm1:0', 'kind' => 'text', 'text' => 'Hello']);
});

it('flushes a due batch of another thread on the next line', function () {
    $first = Thread::factory()->create();
    $second = Thread::factory()->create();

    activity($first, streamDelta('a'));
    expect(app(ThreadEventWindow::class)->since($first->id, 0, 10))->toBeNull();

    $this->travel(60)->milliseconds();
    activity($second, streamDelta('b'));

    expect(array_column(app(ThreadEventWindow::class)->since($first->id, 0, 10), 'type'))->toBe(['delta']);
});

it('resets streaming state on a cut-off synthetic message', function () {
    $thread = Thread::factory()->create();
    $synthetic = collect(fixtureLines('cutoff-synthetic-user-message'))->first(fn (array $line): bool => ($line['isSynthetic'] ?? false) === true);

    activity($thread, streamDelta('partial'));
    activity($thread, $synthetic);
    activity($thread, $synthetic);

    $window = app(ThreadEventWindow::class)->since($thread->id, 0, 100);

    expect(array_column($window, 'type'))->toBe(['delta', 'stream.reset'])
        ->and(DB::table('thread_events')->count())->toBe(0);
});

it('marks a prompt entry as a steer when its uuid was sent as one', function () {
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    $run = TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id]);
    ThreadTurnMessage::factory()->steer()->create(['task_run_id' => $run->id, 'uuid' => 'steer-1']);

    activity($thread, ['type' => 'user', 'uuid' => 'steer-1', 'message' => ['content' => 'Also do X']]);

    expect(json_decode(DB::table('thread_events')->value('payload'), true)['kind'])->toBe('steer');
});

it('publishes a thread event when the runner exits', function () {
    $thread = Thread::factory()->create();

    event(new RunnerExited($thread, 1, 137));

    expect(DB::table('thread_events')->where('thread_id', $thread->id)->pluck('type')->all())->toBe(['thread']);
});

it('drops the window when the runner of a closed thread exits', function () {
    $thread = Thread::factory()->closed()->create();
    expect($thread->status)->toBe(ThreadStatus::Closed);

    event(new RunnerExited($thread, 1, 0));

    expect(app(ThreadEventWindow::class)->since($thread->id, 0, 10))->toBeNull()
        ->and(DB::table('thread_events')->count())->toBe(1);
});
