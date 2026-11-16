<?php

use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Listeners\CaptureThreadDiff;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Services\Threads\ThreadDiffCapture;
use Illuminate\Contracts\Queue\ShouldQueue;
use Mockery\MockInterface;

function captureListenerThread(ThreadSandboxState $state = ThreadSandboxState::Running): Thread
{
    return Thread::factory()->create(['sandbox_state' => $state]);
}

function bindCapture(int $times, Thread $thread): MockInterface
{
    return test()->mock(ThreadDiffCapture::class, function (MockInterface $mock) use ($times, $thread) {
        $expectation = $mock->shouldReceive('capture');

        if ($times === 0) {
            $expectation->never();
        } else {
            $expectation->times($times)->withArgs(fn (Thread $given) => $given->is($thread))->andReturn(null);
        }
    });
}

it('captures when a turn completes in a running sandbox', function () {
    $thread = captureListenerThread();
    $run = TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => TurnState::Completed]);
    bindCapture(1, $thread);

    app(CaptureThreadDiff::class)->handleCompleted(new TurnCompleted($run));
});

it('captures when a turn fails', function () {
    $thread = captureListenerThread();
    $run = TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => TurnState::Failed]);
    bindCapture(1, $thread);

    app(CaptureThreadDiff::class)->handleFailed(new TurnFailed($run));
});

it('skips a paused, stopped or destroyed sandbox', function (ThreadSandboxState $state) {
    $thread = captureListenerThread($state);
    $previous = ThreadDiff::factory()->for($thread)->create();
    $run = TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => TurnState::Completed]);
    bindCapture(0, $thread);

    app(CaptureThreadDiff::class)->handleCompleted(new TurnCompleted($run));

    expect(ThreadDiff::query()->where('thread_id', $thread->id)->value('id'))->toBe($previous->id);
})->with([ThreadSandboxState::Paused, ThreadSandboxState::Stopped, ThreadSandboxState::Destroyed]);

it('skips a run without a thread', function () {
    $run = TaskRun::factory()->create(['thread_id' => null, 'turn_state' => TurnState::Completed]);
    bindCapture(0, Thread::factory()->make());

    app(CaptureThreadDiff::class)->handleCompleted(new TurnCompleted($run));
});

it('skips while another turn is running', function () {
    $thread = captureListenerThread();
    TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => TurnState::Running]);
    $run = TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => TurnState::Completed]);
    bindCapture(0, $thread);

    app(CaptureThreadDiff::class)->handleCompleted(new TurnCompleted($run));
});

it('is discovered for both turn end events and queued', function () {
    expect(app(CaptureThreadDiff::class))->toBeInstanceOf(ShouldQueue::class);

    $listeners = fn (string $event): string => collect(app('events')->getListeners($event))->count() > 0 ? 'listened' : 'none';

    expect($listeners(TurnCompleted::class))->toBe('listened')
        ->and($listeners(TurnFailed::class))->toBe('listened');
});
