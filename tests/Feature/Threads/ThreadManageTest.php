<?php

use App\Enums\ThreadListFilter;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadStatus;
use App\Models\SessionFile;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\ThreadShare;
use App\Models\ThreadStar;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadList;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => $this->withoutVite());

test('a star is personal to the person who toggled it', function () {
    $ana = User::factory()->create();
    $ben = User::factory()->create();
    $thread = Thread::factory()->create();

    $this->actingAs($ana)->post(route('threads.star', $thread))->assertRedirect();

    expect(ThreadList::for($ana, ThreadListFilter::Everyone)['starred'])->toHaveCount(1)
        ->and(ThreadList::for($ben, ThreadListFilter::Everyone)['starred'])->toBe([])
        ->and(ThreadList::for($ben, ThreadListFilter::Everyone)['threads'])->toHaveCount(1);

    $this->actingAs($ana)->post(route('threads.star', $thread))->assertRedirect();

    expect(ThreadStar::query()->count())->toBe(0);
});

test('the starred group pins a thread above needs you and the dated groups', function () {
    $user = User::factory()->create();
    $starred = Thread::factory()->create(['created_by_user_id' => $user->id, 'last_activity_at' => now()->subDays(20)]);
    Thread::factory()->create(['created_by_user_id' => $user->id, 'last_activity_at' => now()]);
    ThreadStar::factory()->create(['user_id' => $user->id, 'thread_id' => $starred->id]);

    $list = ThreadList::for($user);

    expect(collect($list['starred'])->pluck('id')->all())->toBe([$starred->public_id])
        ->and($list['starred'][0]['isStarred'])->toBeTrue()
        ->and(collect($list['threads'])->pluck('id')->all())->not->toContain($starred->public_id);
});

test('the thread page tells the viewer whether they starred the thread', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create();
    ThreadStar::factory()->create(['user_id' => $user->id, 'thread_id' => $thread->id]);

    $this->actingAs($user)->get(route('threads.show', $thread))
        ->assertInertia(fn ($page) => $page->where('thread.isStarred', true));
    $this->actingAs(User::factory()->create())->get(route('threads.show', $thread))
        ->assertInertia(fn ($page) => $page->where('thread.isStarred', false));
});

test('closed threads leave the default list and show under the closed filter', function () {
    $user = User::factory()->create();
    $open = Thread::factory()->create();
    $closed = Thread::factory()->closed()->create();

    $default = ThreadList::for($user, ThreadListFilter::Everyone);
    $filtered = ThreadList::for($user, ThreadListFilter::Closed);

    expect(collect($default['threads'])->pluck('id')->all())->toBe([$open->public_id])
        ->and(collect($filtered['threads'])->pluck('id')->all())->toBe([$closed->public_id])
        ->and($filtered['threads'][0]['status'])->toBe('closed')
        ->and($filtered['needsYou'])->toBe([]);
});

test('the closed filter is reachable even when every thread is closed', function () {
    $user = User::factory()->create();
    Thread::factory()->closed()->create();

    expect(ThreadList::for($user)['hasAnyThreads'])->toBeTrue();
});

test('closing a thread destroys its sandbox and marks it closed', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create();
    $sandbox = Mockery::mock(ThreadSandbox::class);
    $sandbox->shouldReceive('destroy')->once();
    app()->instance(ThreadSandbox::class, $sandbox);

    $this->actingAs($user)->post(route('threads.close', $thread))->assertRedirect();

    expect($thread->refresh()->status)->toBe(ThreadStatus::Closed)
        ->and($thread->close_reason)->toBe('manual');
});

test('a viewer of a shared private thread cannot close or delete it', function () {
    $owner = User::factory()->create();
    $guest = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    ThreadShare::factory()->create(['thread_id' => $thread->id, 'user_id' => $guest->id]);

    $this->actingAs($guest)->post(route('threads.close', $thread))->assertForbidden();
    $this->actingAs($guest)->delete(route('threads.destroy', $thread))->assertForbidden();
    $this->actingAs(User::factory()->create())->delete(route('threads.destroy', $thread))->assertNotFound();

    expect($thread->refresh()->status)->toBe(ThreadStatus::Open)
        ->and(Thread::query()->withoutGlobalScopes()->count())->toBe(1);
});

test('deleting a thread removes it, its sub-threads and their rows, and leaves GitHub alone', function () {
    Http::fake();
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['branch' => 'yak/work']);
    $child = Thread::factory()->child($thread)->create(['branch' => 'yak/work-child']);
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id]);
    ThreadStar::factory()->create(['user_id' => $user->id, 'thread_id' => $thread->id]);
    ThreadEvent::query()->create(['thread_id' => $thread->id, 'sequence' => 1, 'type' => 'note', 'payload' => []]);
    $sandbox = Mockery::mock(ThreadSandbox::class);
    $sandbox->shouldReceive('destroy')->twice()->with(Mockery::any(), false);
    app()->instance(ThreadSandbox::class, $sandbox);

    $this->actingAs($user)->from(route('threads.show', $thread))->delete(route('threads.destroy', $thread))->assertRedirect(route('home'));

    expect(Thread::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(YakTask::query()->count())->toBe(0)
        ->and(TaskRun::query()->count())->toBe(0)
        ->and(ThreadStar::query()->count())->toBe(0)
        ->and(ThreadEvent::query()->count())->toBe(0)
        ->and(SessionFile::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('deleting from the sidebar list leaves the person where they are', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->closed()->create();
    $other = Thread::factory()->create();
    $sandbox = Mockery::mock(ThreadSandbox::class);
    $sandbox->shouldReceive('destroy')->once()->with(Mockery::any(), false);
    app()->instance(ThreadSandbox::class, $sandbox);

    $this->actingAs($user)->from(route('threads.show', $other))->delete(route('threads.destroy', $thread))
        ->assertRedirect(route('threads.show', $other));

    expect(Thread::query()->withoutGlobalScopes()->whereKey($thread->id)->exists())->toBeFalse();
});

test('a thread whose sandbox cannot be destroyed is kept', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Running]);
    $sandbox = Mockery::mock(ThreadSandbox::class);
    $sandbox->shouldReceive('destroy')->once();
    app()->instance(ThreadSandbox::class, $sandbox);

    $this->actingAs($user)->delete(route('threads.destroy', $thread))->assertRedirect();

    expect(Thread::query()->withoutGlobalScopes()->count())->toBe(1);
});
