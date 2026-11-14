<?php

use App\Enums\CoOwnerRequestStatus;
use App\Jobs\CreatePullRequestJob;
use App\Models\CoOwnerRequest;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\PullRequests\PullRequestOpening;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
    $this->thread = Thread::factory()->create();
    $this->task = YakTask::factory()->create(['thread_id' => $this->thread->id, 'branch_name' => 'yak/work']);
});

function latestThreadState(Thread $thread): array
{
    return ThreadEvent::query()->where('thread_id', $thread->id)->where('type', 'thread')->latest('sequence')->firstOrFail()->payload;
}

it('marks the pull request as opening when the person asks for it', function () {
    $this->actingAs(User::factory()->create())->post(route('threads.pull-request.store', $this->thread))->assertRedirect();

    Queue::assertPushed(CreatePullRequestJob::class);
    expect(latestThreadState($this->thread)['pullRequestOpening'])->toBe('opening');

    $this->get("/threads/{$this->thread->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('thread.pullRequestOpening.state', 'opening')
        ->where('thread.pullRequestOpening.error', null));
});

it('clears the opening once the job is done', function () {
    $opening = app(PullRequestOpening::class);
    $opening->start($this->thread);

    $opening->finish($this->task);

    expect($opening->stateFor($this->thread))->toBeNull()
        ->and(latestThreadState($this->thread)['pullRequestOpening'])->toBeNull();
});

it('keeps GitHub\'s reason when the job gives up', function () {
    app(PullRequestOpening::class)->start($this->thread);

    (new CreatePullRequestJob($this->task))->failed(new RuntimeException('GitHub PR creation failed: Validation Failed'));

    expect(app(PullRequestOpening::class)->stateFor($this->thread))->toMatchArray(['state' => 'failed', 'error' => 'GitHub said: Validation Failed'])
        ->and(latestThreadState($this->thread)['pullRequestOpening'])->toBe('failed');
});

it('hides other errors behind a plain reason', function () {
    app(PullRequestOpening::class)->start($this->thread);

    app(PullRequestOpening::class)->finish($this->task, new RuntimeException('cURL error 28: timed out'));

    expect(app(PullRequestOpening::class)->stateFor($this->thread)['error'])->toBe('Yak could not reach GitHub. Try again in a moment.');
});

it('leaves a pull request nobody asked for from the page alone', function () {
    app(PullRequestOpening::class)->finish($this->task, new RuntimeException('GitHub PR creation failed: nope'));

    expect(app(PullRequestOpening::class)->stateFor($this->thread))->toBeNull()
        ->and(ThreadEvent::query()->where('thread_id', $this->thread->id)->where('type', 'thread')->exists())->toBeFalse();
});

it('reports a pull request held for a co-owner', function () {
    CoOwnerRequest::factory()->create(['task_id' => $this->task->id, 'root_task_id' => $this->task->id, 'status' => CoOwnerRequestStatus::Pending]);

    expect(app(PullRequestOpening::class)->stateFor($this->thread))->toBe(['state' => 'held', 'error' => null, 'at' => null]);
});
