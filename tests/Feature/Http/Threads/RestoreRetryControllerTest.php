<?php

use App\Enums\ThreadBlockReason;
use App\Enums\ThreadLockHolder;
use App\Jobs\RetryThreadRestoreJob;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Support\Facades\Queue;

function failedRestoreThread(array $state = []): Thread
{
    return Thread::factory()->create(['blocked_reason' => ThreadBlockReason::RestoreFailed, ...$state]);
}

it('retries a failed restore for the owner', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $thread = failedRestoreThread(['visibility' => 'private', 'owner_user_id' => $owner->id]);

    $this->actingAs($owner)->post(route('threads.restore.retry', $thread))->assertSessionHas('success');

    Queue::assertPushed(RetryThreadRestoreJob::class, 1);
    Queue::assertPushed(RetryThreadRestoreJob::class, fn ($job) => $job->thread->is($thread));
});

it('lets a teammate retry on a team-visible thread', function () {
    Queue::fake();

    $this->actingAs(User::factory()->create())->post(route('threads.restore.retry', failedRestoreThread()))->assertSessionHas('success');

    Queue::assertPushed(RetryThreadRestoreJob::class, 1);
});

it('404s for a second user and an admin on a private thread', function (bool $isAdmin) {
    Queue::fake();
    $thread = Thread::factory()->private()->create(['blocked_reason' => ThreadBlockReason::RestoreFailed]);
    $user = User::factory()->create();
    if ($isAdmin) {
        config(['claude-accounts.admin_user_ids' => [$user->id]]);
    }

    $this->actingAs($user)->post(route('threads.restore.retry', $thread))->assertNotFound();

    Queue::assertNothingPushed();
})->with(['second user' => [false], 'admin' => [true]]);

it('refuses when the thread is not in restore_failed', function () {
    Queue::fake();

    $this->actingAs(User::factory()->create())->post(route('threads.restore.retry', Thread::factory()->create()))->assertSessionHas('error');

    Queue::assertNothingPushed();
});

it('refuses a double click while a restore runs', function () {
    Queue::fake();
    $thread = failedRestoreThread(['lock_holder' => ThreadLockHolder::Sandbox, 'lock_turn_id' => 'restore']);

    $this->actingAs(User::factory()->create())->post(route('threads.restore.retry', $thread))->assertSessionHas('error');

    Queue::assertNothingPushed();
});

it('refuses on a closed thread', function () {
    Queue::fake();

    $this->actingAs(User::factory()->create())->post(route('threads.restore.retry', failedRestoreThread(['status' => 'closed', 'closed_at' => now()])))->assertSessionHas('error');

    Queue::assertNothingPushed();
});

it('runs the restore from the job', function () {
    $thread = failedRestoreThread();
    $this->mock(ThreadSandbox::class)->shouldReceive('restore')->once()->withArgs(fn ($t) => $t->is($thread))->andReturn(true);

    (new RetryThreadRestoreJob($thread))->handle(app(ThreadSandbox::class));
});
