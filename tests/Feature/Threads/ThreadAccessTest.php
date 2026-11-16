<?php

use App\Models\Scopes\VisibleThreadScope;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Policies\ThreadPolicy;
use App\Services\Threads\ThreadAccess;
use App\Services\Threads\ThreadNotificationGuard;
use Illuminate\Support\Facades\Broadcast;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->withoutVite());

test('a team thread is visible to any signed-in user and to nobody else', function () {
    $thread = Thread::factory()->create();

    expect(app(ThreadAccess::class)->canSee(User::factory()->create(), $thread))->toBeTrue()
        ->and(app(ThreadAccess::class)->canSee(null, $thread))->toBeFalse();
});

test('a private thread is visible to its owner only, admins included', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    $access = app(ThreadAccess::class);

    expect($access->canSee($owner, $thread))->toBeTrue()
        ->and($access->canDrive($owner, $thread))->toBeTrue()
        ->and($access->canSee(User::factory()->create(['email' => 'admin@example.com']), $thread))->toBeFalse()
        ->and($access->canDrive(User::factory()->create(), $thread))->toBeFalse();
});

test('a child inherits its root visibility and owner', function () {
    $owner = User::factory()->create();
    $root = Thread::factory()->private($owner)->create();
    $child = Thread::factory()->create(['parent_thread_id' => $root->id, 'root_thread_id' => $root->id]);
    $access = app(ThreadAccess::class);

    expect($access->canSee($owner, $child))->toBeTrue()
        ->and($access->canSee(User::factory()->create(), $child))->toBeFalse();
});

test('the policy and the notification guard follow ThreadAccess', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $private = Thread::factory()->private($owner)->create(['title' => 'Secret plan']);
    $team = Thread::factory()->create(['title' => 'Fix the cache']);
    $guard = app(ThreadNotificationGuard::class);

    expect(app(ThreadPolicy::class)->view($other, $private))->toBeFalse()
        ->and(app(ThreadPolicy::class)->drive($owner, $private))->toBeTrue()
        ->and($guard->mayNotify($other, $private))->toBeFalse()
        ->and($guard->mayNotify($owner, $private))->toBeTrue()
        ->and($guard->pushTitle($private))->not->toContain('Secret')
        ->and($guard->pushTitle($team))->toBe('Fix the cache');
});

test('visibleTo keeps only the threads the user may see, children included', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $team = Thread::factory()->create();
    $private = Thread::factory()->private($owner)->create();
    $child = Thread::factory()->create(['parent_thread_id' => $private->id, 'root_thread_id' => $private->id]);

    expect(Thread::visibleTo($other)->pluck('id')->all())->toBe([$team->id])
        ->and(Thread::visibleTo($owner)->pluck('id')->all())->toBe([$team->id, $private->id, $child->id])
        ->and(Thread::visibleTo(null)->count())->toBe(0);
});

test('a signed-in user does not load other people private threads or their tasks', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $private = Thread::factory()->private($owner)->create();
    $child = Thread::factory()->create(['parent_thread_id' => $private->id, 'root_thread_id' => $private->id]);
    $privateTask = YakTask::factory()->create(['thread_id' => $child->id]);
    $plainTask = YakTask::factory()->create();

    $this->actingAs($other);
    expect(Thread::count())->toBe(0)
        ->and(YakTask::pluck('id')->all())->toBe([$plainTask->id])
        ->and(Thread::withoutGlobalScopes()->count())->toBe(2);

    $this->actingAs($owner);
    expect(Thread::count())->toBe(2)
        ->and(YakTask::count())->toBe(2);
});

test('withoutVisibilityScope drops only the visibility scope', function () {
    $private = Thread::factory()->private(User::factory()->create())->create();
    $task = YakTask::factory()->create(['thread_id' => $private->id]);
    $this->actingAs(User::factory()->create());

    expect(Thread::withoutVisibilityScope()->count())->toBe(1)
        ->and(YakTask::withoutVisibilityScope()->pluck('id')->all())->toBe([$task->id])
        ->and(Thread::withoutVisibilityScope()->removedScopes())->toBe([VisibleThreadScope::class]);
});

test('jobs and commands see everything', function () {
    $private = Thread::factory()->private()->create();
    YakTask::factory()->create(['thread_id' => $private->id]);

    expect(auth()->check())->toBeFalse()
        ->and(Thread::count())->toBe(1)
        ->and(YakTask::count())->toBe(1);
});

test('a private task is missing from the tasks, costs, analytics and system pages for other users', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'repo' => 'secret/repo', 'cost_usd' => 4.25, 'created_at' => now()]);
    YakTask::factory()->create(['repo' => 'acme/api', 'cost_usd' => 1, 'created_at' => now()]);

    foreach ([User::factory()->create(), User::factory()->create(['email' => 'admin@example.com'])] as $viewer) {
        $this->actingAs($viewer);

        $this->get('/tasks')->assertInertia(fn (Assert $page) => $page
            ->where('counts.tasks', 1)
            ->where('tasks.data', fn ($rows) => collect($rows)->pluck('id')->doesntContain($task->id)));
        $this->get('/costs')->assertInertia(fn (Assert $page) => $page
            ->where('summary.taskCount', 1)
            ->where('summary.claudeCode.amount', 1));
        $this->get('/analytics')->assertInertia(fn (Assert $page) => $page
            ->where('filters.repos', ['acme/api']));
        $this->get('/system')->assertOk()->assertDontSee('secret/repo');
    }

    $this->actingAs($owner);
    $this->get('/tasks')->assertInertia(fn (Assert $page) => $page->where('counts.tasks', 2));
    $this->get('/costs')->assertInertia(fn (Assert $page) => $page
        ->where('summary.taskCount', 2)
        ->where('summary.claudeCode.amount', 5.25));
    $this->get('/analytics')->assertInertia(fn (Assert $page) => $page
        ->where('filters.repos', ['acme/api', 'secret/repo']));
});

test('the thread page answers 404 for a hidden thread and a missing one alike', function () {
    $owner = User::factory()->create();
    $private = Thread::factory()->private($owner)->create();
    $team = Thread::factory()->create(['title' => 'Fix the cache']);

    $this->actingAs(User::factory()->create());
    $this->get("/threads/{$private->public_id}")->assertNotFound();
    $this->get('/threads/999999')->assertNotFound();
    $this->get("/threads/{$team->public_id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Threads/Show')
        ->where('thread.title', 'Fix the cache')
        ->where('canDrive', true));

    $this->actingAs($owner);
    $this->get("/threads/{$private->public_id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('thread.visibility', 'private'));
});

test('guests are sent to log in from the thread page', function () {
    $thread = Thread::factory()->create();

    $this->get("/threads/{$thread->public_id}")->assertRedirect('/login');
});

test('thread channels authorize through ThreadAccess', function (string $channelName) {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    // Channels register on the driver resolved at boot; register them on the Reverb driver too.
    Broadcast::purge();
    require base_path('routes/channels.php');
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    $team = Thread::factory()->create();
    $post = fn (User $user, Thread $target) => $this->actingAs($user)->post('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => str_replace('{id}', $target->public_id, $channelName),
    ]);

    $post($owner, $thread)->assertOk();
    $post(User::factory()->create(), $thread)->assertForbidden();
    $post(User::factory()->create(), $team)->assertOk();
    $post($owner, Thread::factory()->make(['id' => 999999, 'public_id' => 'zzzzzzzz']))->assertForbidden();
})->with(['private-thread.{id}', 'presence-thread-presence.{id}']);
