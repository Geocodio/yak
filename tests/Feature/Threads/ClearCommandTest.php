<?php

use App\Enums\TaskStatus;
use App\Enums\ThreadSessionKind;
use App\Jobs\RunFollowUpJob;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\FollowUpTaskFactory;
use App\Services\Threads\ClearCommand;
use App\Services\Threads\ThreadFactory;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Repository::factory()->create(['slug' => 'clear-repo']);
    $this->head = YakTask::factory()->create([
        'repo' => 'clear-repo', 'status' => TaskStatus::AwaitingCi, 'branch_name' => 'yak/ISSUE-1', 'session_id' => null,
        'pr_url' => 'https://github.com/acme/web/pull/1', 'pr_number' => 1, 'thread_id' => null,
    ]);
    $this->thread = app(ThreadFactory::class)->forTask($this->head);
    $this->thread->sessions()->create(['session_id' => 'session-1', 'kind' => ThreadSessionKind::Main]);
    $this->thread->update(['branch' => 'yak/ISSUE-1']);
});

it('matches only a message that is exactly /clear', function (string $text, bool $expected) {
    expect(ClearCommand::matches($text))->toBe($expected);
})->with([['/clear', true], ["  /clear\n", true], ['/clear please', false], ['please /clear', false], ['/Clear', false], ['', false]]);

it('/clear starts a new open thread that links back, and leaves the old thread alone', function () {
    $new = ClearCommand::apply($this->thread, $this->head);

    expect($new->id)->not->toBe($this->thread->id)
        ->and($new->continued_from_thread_id)->toBe($this->thread->id)
        ->and($new->isOpen())->toBeTrue()
        ->and($new->branch)->toBe('yak/ISSUE-1')
        ->and($new->sessions()->count())->toBe(0)
        ->and($this->head->refresh()->thread_id)->toBe($new->id)
        ->and($this->thread->refresh()->isOpen())->toBeTrue()
        ->and($this->thread->sessions()->count())->toBe(1);
});

it('a /clear follow-up message switches threads without queueing a turn', function () {
    $result = app(FollowUpTaskFactory::class)->create($this->head, '/clear', 'slack');

    expect($result?->is($this->head))->toBeTrue()
        ->and(Thread::query()->count())->toBe(2)
        ->and($this->head->refresh()->thread_id)->not->toBe($this->thread->id);
    Queue::assertNothingPushed();
});

it('the next message after /clear runs in the new thread, which resumes nothing', function () {
    app(FollowUpTaskFactory::class)->create($this->head, '/clear', 'slack');
    $newThreadId = $this->head->refresh()->thread_id;

    $child = app(FollowUpTaskFactory::class)->create($this->head, 'Now rename the helper', 'slack');

    expect($child->thread_id)->toBe($newThreadId)
        ->and(app(ThreadFactory::class)->forTask($child)->sessions()->count())->toBe(0);
    Queue::assertPushed(RunFollowUpJob::class);
});

it('/clear please is an ordinary message', function () {
    $child = app(FollowUpTaskFactory::class)->create($this->head, '/clear please', 'slack');

    expect($child->is($this->head))->toBeFalse()
        ->and($child->thread_id)->toBe($this->thread->id)
        ->and(Thread::query()->count())->toBe(1);
    Queue::assertPushed(RunFollowUpJob::class);
});
