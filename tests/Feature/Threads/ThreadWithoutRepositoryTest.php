<?php

use App\Enums\ThreadListFilter;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Exceptions\ThreadActionRefused;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\QueuedMessageRunner;
use App\Services\Threads\ThreadFactory;
use App\Services\Threads\ThreadList;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\ThreadTurns;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__ . '/../../Support/AppApiHelpers.php';

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create();
    $this->task = YakTask::factory()->create(['repo' => 'unknown', 'started_by_user_id' => $this->user->id, 'description' => 'Fix the login bug']);
    $this->thread = app(ThreadFactory::class)->forRequestWithoutRepository($this->task);
    $this->path = "/threads/{$this->thread->public_id}";
});

function expectNoSandbox(Thread $thread): void
{
    expect($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::None)
        ->and($thread->fresh()->sandbox_container)->toBeNull()
        ->and(collect(app(SandboxManager::class)->calls)->pluck('operation')->all())->toBe([]);
}

test('the factory makes a thread with the unknown marker and no repository row', function () {
    expect($this->thread->repo)->toBe('unknown')
        ->and($this->thread->hasRepository())->toBeFalse()
        ->and($this->thread->sandbox_state)->toBe(ThreadSandboxState::None)
        ->and(ThreadRepository::query()->where('thread_id', $this->thread->id)->count())->toBe(0)
        ->and($this->task->fresh()->thread_id)->toBe($this->thread->id);
});

test('attaching a repository sets the repo and creates the primary row', function () {
    app(ThreadFactory::class)->attachRepository($this->thread, 'acme/widgets');

    expect($this->thread->repo)->toBe('acme/widgets')
        ->and($this->thread->fresh()->hasRepository())->toBeTrue()
        ->and($this->thread->repositories()->where('role', 'primary')->value('repo'))->toBe('acme/widgets');

    app(ThreadFactory::class)->attachRepository($this->thread, 'acme/other');

    expect($this->thread->repositories()->count())->toBe(1)->and($this->thread->fresh()->repo)->toBe('acme/widgets');
});

test('the thread list includes it', function () {
    $list = ThreadList::for($this->user, ThreadListFilter::Everyone);

    expect(collect($list['threads'])->pluck('id'))->toContain($this->thread->public_id);
});

test('the thread page renders with no repositories', function () {
    $this->actingAs($this->user)->get($this->path)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Threads/Show')
        ->where('thread.repositories', []));
});

test('the app api shows it with repo as a string', function () {
    [, $headers] = phoneWithHeaders(['user_id' => $this->user->id]);

    $this->getJson(appThreadPath($this->thread), $headers)->assertOk()->assertJsonPath('thread.repo', 'unknown')->assertJsonPath('thread.preview.canRestore', false);
    $this->getJson('/api/app/v1/threads', $headers)->assertOk();
});

test('the environment page answers', function () {
    $this->actingAs($this->user)->getJson("{$this->path}/environment")->assertOk();
    expectNoSandbox($this->thread);
});

test('the diff page answers', function () {
    $this->actingAs($this->user)->getJson("{$this->path}/diff")->assertOk();
    expectNoSandbox($this->thread);
});

test('opening a terminal is refused without a sandbox', function () {
    $this->actingAs($this->user)->postJson("{$this->path}/terminal-sessions", [], ['Origin' => rtrim((string) config('app.url'), '/')])
        ->assertStatus(409)->assertJsonPath('error', 'no_sandbox');
    expectNoSandbox($this->thread);
});

test('the preview status answers', function () {
    $this->actingAs($this->user)->getJson("{$this->path}/preview/status")->assertSuccessful();
    expectNoSandbox($this->thread);
});

test('the preview restore is refused with the pick a repository answer', function () {
    $this->actingAs($this->user)->postJson("{$this->path}/preview/restore")
        ->assertStatus(422)->assertJsonPath('message', 'Pick a repository first.');
    expectNoSandbox($this->thread);
});

test('the preview warm and heartbeat answer without a sandbox', function (string $route) {
    $response = $this->actingAs($this->user)->postJson("{$this->path}/preview/{$route}");

    expect($response->status())->toBeLessThan(500);
    expectNoSandbox($this->thread);
})->with(['warm', 'heartbeat']);

test('the restore retry has nothing to restore', function () {
    $this->actingAs($this->user)->post("{$this->path}/restore/retry")->assertSessionHas('error');
    expectNoSandbox($this->thread);
});

test('starting a turn is refused and never wakes a sandbox', function () {
    expect(fn () => app(ThreadTurns::class)->start($this->task->fresh(), 'go', $this->user, null))
        ->toThrow(ThreadActionRefused::class, 'Pick a repository first.');

    expect($this->thread->runs()->count())->toBe(0);
    expectNoSandbox($this->thread);
});

test('waking or restoring the sandbox does nothing', function () {
    $sandbox = app(ThreadSandbox::class);

    expect($sandbox->ensureAwake($this->thread)->ready)->toBeFalse()
        ->and($sandbox->restore($this->thread))->toBeFalse()
        ->and($sandbox->restoreIfNeeded($this->thread)?->ready)->toBeFalse();
    expectNoSandbox($this->thread);
});

test('the file tree answers without a sandbox', function () {
    $response = $this->actingAs($this->user)->getJson("{$this->path}/files");

    expect($response->status())->toBeLessThan(500)->and($response->json('canRestore'))->not->toBeTrue();
    expectNoSandbox($this->thread);
});

test('the pull request panel and the repository progress answer', function (string $route) {
    $response = $this->actingAs($this->user)->getJson("{$this->path}/{$route}");

    expect($response->status())->toBeLessThan(500);
})->with(['pull-requests', 'repositories', 'events', 'local-turns', 'share', 'stack']);

test('opening a pull request is refused', function () {
    $response = $this->actingAs($this->user)->postJson("{$this->path}/pull-request");

    expect($response->status())->toBeLessThan(500);
    expectNoSandbox($this->thread);
});

test('a composer message is refused with a designed answer', function () {
    $this->actingAs($this->user)->postJson("{$this->path}/messages", ['message' => 'hello'])
        ->assertStatus(422)->assertJsonPath('message', 'Pick a repository first.');

    expect($this->thread->runs()->count())->toBe(0);
    expectNoSandbox($this->thread);
});

test('the sandbox lifecycle command leaves it alone', function () {
    $this->artisan('yak:thread-sandboxes')->assertSuccessful();
    $this->artisan('yak:cleanup-sandboxes')->assertSuccessful();

    expectNoSandbox($this->thread);
});

test('the other scheduled thread commands leave it alone', function (string $command) {
    $this->artisan($command)->assertSuccessful();

    expectNoSandbox($this->thread);
    expect($this->thread->fresh()->hasRepository())->toBeFalse();
})->with(['yak:close-idle-threads', 'yak:reconcile-pr-state', 'yak:prune-thread-events', 'yak:reap-orphaned-tasks', 'yak:reap-lost-pending']);

test('closing and the idle close sweep work without a sandbox', function () {
    $this->thread->forceFill(['last_activity_at' => now()->subDays(365)])->save();
    $this->task->forceFill(['status' => 'failed'])->save();
    Thread::query()->whereKey($this->thread->id)->update(['created_at' => now()->subDays(365)]);

    $this->artisan('yak:close-idle-threads')->assertSuccessful();

    expect($this->thread->fresh()->isOpen())->toBeFalse();
    expectNoSandbox($this->thread);
});

test('the close and delete routes answer', function () {
    $this->actingAs($this->user)->post("{$this->path}/close")->assertSessionHasNoErrors();
    expect($this->thread->fresh()->isOpen())->toBeFalse();

    $response = $this->actingAs($this->user)->delete($this->path);
    expect($response->status())->toBeLessThan(500)
        ->and(Thread::query()->withoutGlobalScopes()->whereKey($this->thread->id)->exists())->toBeFalse()
        ->and(collect(app(SandboxManager::class)->calls)->pluck('operation')->all())->toBe([]);
});

test('a queued message never starts a turn on it', function () {
    expect(app(QueuedMessageRunner::class)->afterTurn(TaskRun::factory()->create(['thread_id' => $this->thread->id, 'turn_state' => TurnState::Completed])))->toBeNull();
});
