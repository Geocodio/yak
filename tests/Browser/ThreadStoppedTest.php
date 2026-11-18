<?php

use App\Enums\CoOwnerRequestStatus;
use App\Enums\StopKind;
use App\Enums\TaskStatus;
use App\Jobs\RunYakJob;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Queue;

function stoppedThread(array $attributes = []): Thread
{
    $repository = Repository::factory()->create(['slug' => 'o-r', 'is_active' => true]);
    Repository::factory()->create(['slug' => 'other-repo', 'is_active' => true]);
    $task = YakTask::factory()->failed()->create([
        'repo' => $repository->slug,
        'pr_url' => null,
        'source' => 'dashboard',
        'stop_kind' => StopKind::YakStopped,
        'stop_summary' => 'CI failed twice on the same test.',
        ...$attributes,
    ]);

    return Thread::query()->findOrFail($task->thread_id);
}

it('retries a stopped thread from the card', function () {
    Queue::fake();
    $thread = stoppedThread();
    $this->actingAs(User::factory()->create());

    visit("/threads/{$thread->public_id}")
        ->assertVisible('[data-testid="card-stop"]')
        ->assertSeeIn('[data-testid="card-stop"]', 'Yak could not finish this change')
        ->assertSeeIn('[data-testid="stop-summary"]', 'CI failed twice on the same test.')
        ->assertSeeIn('[data-testid="stop-next-step"]', 'Press Retry and Yak will try again.')
        ->assertAttribute('[data-testid="thread-composer"] textarea', 'placeholder', 'Write a note for Yak…')
        ->click('[data-testid="stop-retry"]')
        ->assertMissing('[data-testid="card-stop"]')
        ->assertNoJavaScriptErrors();

    expect(YakTask::query()->where('thread_id', $thread->id)->latest('id')->first()->status)->toBe(TaskStatus::Pending);
    Queue::assertPushed(RunYakJob::class);
});

it('moves a stopped thread to another repository from the header menu', function () {
    Queue::fake();
    $thread = stoppedThread();
    $this->actingAs(User::factory()->create());

    visit("/threads/{$thread->public_id}")
        ->click('[data-testid="thread-menu"]')
        ->click('[data-testid="thread-menu-move"]')
        ->assertSee('Move to another repository')
        ->click('[data-testid="move-target"]')
        ->assertSee('Move this task to other-repo?')
        ->click('[data-testid="move-confirm"]')
        ->wait(2)
        ->assertNoJavaScriptErrors();

    $moved = Thread::query()->findOrFail($thread->id);
    $next = Thread::query()->where('continued_from_thread_id', $thread->id)->first();

    expect($moved->close_reason)->toBe('moved')
        ->and($next)->not->toBeNull()
        ->and($next->repo)->toBe('other-repo');
});

it('moves from the stop card too', function () {
    Queue::fake();
    $thread = stoppedThread();
    $this->actingAs(User::factory()->create());

    visit("/threads/{$thread->public_id}")
        ->click('[data-testid="stop-move"]')
        ->click('[data-testid="move-target"]')
        ->click('[data-testid="move-confirm"]')
        ->wait(2)
        ->assertNoJavaScriptErrors();

    expect(Thread::query()->where('continued_from_thread_id', $thread->id)->exists())->toBeTrue();
});

it('shows a read-only viewer the stop card without its buttons', function () {
    $owner = User::factory()->create();
    $thread = stoppedThread();
    $thread->forceFill(['visibility' => 'private', 'owner_user_id' => $owner->id])->save();
    $viewer = User::factory()->create();
    $thread->shares()->create(['user_id' => $viewer->id]);
    $this->actingAs($viewer);

    visit("/threads/{$thread->public_id}")
        ->assertVisible('[data-testid="card-stop"]')
        ->assertMissing('[data-testid="stop-retry"]')
        ->assertMissing('[data-testid="stop-move"]')
        ->assertNoJavaScriptErrors();
});

it('shows an expired co-owner request as a stop card with no Retry button', function () {
    Queue::fake();
    $thread = stoppedThread(['status' => TaskStatus::Expired, 'stop_kind' => null, 'stop_summary' => null]);
    $task = $thread->tasks()->firstOrFail();
    $repository = Repository::where('slug', 'o-r')->firstOrFail();
    CoOwnerRequest::factory()->create(['task_id' => $task->id, 'repository_id' => $repository->id, 'status' => CoOwnerRequestStatus::Expired, 'decided_at' => now()]);
    $this->actingAs(User::factory()->create());

    visit("/threads/{$thread->public_id}")
        ->assertVisible('[data-testid="card-stop"]')
        ->assertSeeIn('[data-testid="card-stop"]', 'The co-owner request expired')
        ->assertSeeIn('[data-testid="stop-summary"]', 'Nobody decided within 7 days, so Yak did not open the pull request.')
        ->assertSeeIn('[data-testid="stop-next-step"]', 'Send a message to continue this work.')
        ->assertMissing('[data-testid="stop-retry"]')
        ->assertNoJavaScriptErrors();
});

it('scrolls the progress row so the failed step is in view on a phone', function () {
    $this->actingAs(User::factory()->create());
    $thread = stoppedThread();

    $page = visit("/threads/{$thread->public_id}")->on()->mobile()->assertVisible('[data-testid="thread-progress-row"]');

    expect($page->script("(() => { const row = document.querySelector('[data-testid=\"thread-progress-row\"]'); const step = row.querySelector('[data-focus=\"true\"]'); return step.getBoundingClientRect().right <= row.getBoundingClientRect().right && row.scrollLeft > 0; })()"))->toBeTrue()
        ->and($page->script('document.documentElement.scrollLeft'))->toBe(0);
});
