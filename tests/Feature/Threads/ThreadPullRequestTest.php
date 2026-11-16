<?php

use App\Jobs\CreatePullRequestJob;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadPublishGuard;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
});

function threadWithBranch(Thread $thread): YakTask
{
    return YakTask::factory()->create(['thread_id' => $thread->id, 'branch_name' => 'yak/work']);
}

it('a private thread without the acknowledgement is refused with the guard message', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    threadWithBranch($thread);

    $response = $this->actingAs($owner)->post(route('threads.pull-request.store', $thread));

    $response->assertStatus(302)->assertSessionHasErrors('acknowledged_private_publish');
    expect(session('errors')->first('acknowledged_private_publish'))->toBe(ThreadPublishGuard::MESSAGE);
    Queue::assertNothingPushed();
});

it('a private thread with the acknowledgement opens the pull request', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    $task = threadWithBranch($thread);

    $this->actingAs($owner)
        ->post(route('threads.pull-request.store', $thread), ['acknowledged_private_publish' => true])
        ->assertRedirect();

    Queue::assertPushed(CreatePullRequestJob::class, 1);
    Queue::assertPushed(CreatePullRequestJob::class, fn ($job) => $job->task->is($task));
});

it('a team thread needs no acknowledgement', function () {
    $thread = Thread::factory()->create();
    threadWithBranch($thread);

    $this->actingAs(User::factory()->create())->post(route('threads.pull-request.store', $thread))->assertRedirect();

    Queue::assertPushed(CreatePullRequestJob::class, 1);
});

it('a private child of a private thread needs it', function () {
    $owner = User::factory()->create();
    $root = Thread::factory()->private($owner)->create();
    $child = Thread::factory()->child($root)->create();
    threadWithBranch($child);

    $this->actingAs($owner)
        ->post(route('threads.pull-request.store', $child))
        ->assertSessionHasErrors('acknowledged_private_publish');

    Queue::assertNothingPushed();
});

it('a shared-with-people thread still needs it', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    ThreadShare::factory()->create(['thread_id' => $thread->id]);
    threadWithBranch($thread);

    $this->actingAs($owner)
        ->post(route('threads.pull-request.store', $thread))
        ->assertSessionHasErrors('acknowledged_private_publish');

    Queue::assertNothingPushed();
});

it('a hidden thread answers 404 before any validation', function () {
    $thread = Thread::factory()->private(User::factory()->create())->create();
    threadWithBranch($thread);

    $this->actingAs(User::factory()->create())->post(route('threads.pull-request.store', $thread))->assertNotFound();

    Queue::assertNothingPushed();
});

it('a thread whose branch lives on the thread opens the pull request from it', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->create(['branch' => 'yak/teleported']);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'branch_name' => null]);

    $this->actingAs($owner)->post(route('threads.pull-request.store', $thread))->assertRedirect();

    expect($task->fresh()->branch_name)->toBe('yak/teleported');
    Queue::assertPushed(CreatePullRequestJob::class, fn (CreatePullRequestJob $job): bool => $job->task->is($task));
});

it('a thread without a task or branch is refused with 409', function () {
    $thread = Thread::factory()->create();
    YakTask::factory()->create(['thread_id' => $thread->id, 'branch_name' => null]);

    $this->actingAs(User::factory()->create())->post(route('threads.pull-request.store', $thread))->assertStatus(409);

    Queue::assertNothingPushed();
});
