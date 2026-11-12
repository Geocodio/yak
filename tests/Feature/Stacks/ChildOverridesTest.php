<?php

use App\Channels\GitHub\AppService;
use App\Enums\StackThreadMessageKind;
use App\Enums\ThreadKind;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadStatus;
use App\Exceptions\StackRuleViolation;
use App\Models\StackMessage;
use App\Models\StackThreadMessage;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use App\Services\Stacks\StackMessenger;
use App\Services\Stacks\StackThreadCloser;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\ThreadCloser;
use Tests\Support\FakeSessionFileSync;

beforeEach(function () {
    $this->withoutVite();
    app()->instance(SessionFileSync::class, new FakeSessionFileSync);
    $this->user = User::factory()->create();
    $this->parent = Thread::factory()->ownedByDevice()->create();
    $this->child = Thread::factory()->child($this->parent)->create(['name' => 'scout']);
});

it('take over stops delivery to the parent', function () {
    StackThreadMessage::factory()->queued()->create([
        'root_thread_id' => $this->parent->id,
        'from_thread_id' => $this->child->id,
        'to_thread_id' => $this->parent->id,
        'kind' => StackThreadMessageKind::Result,
    ]);

    $this->actingAs($this->user)->post(route('threads.stack.take-over', $this->child))->assertRedirect();

    expect($this->child->fresh()->taken_over_at)->not->toBeNull()
        ->and(StackThreadMessage::query()->whereNull('delivered_at')->count())->toBe(0)
        ->and(StackMessage::query()->count())->toBe(0);
});

it('split notifies the parent', function () {
    $this->actingAs($this->user)->post(route('threads.stack.split', $this->child))->assertRedirect();

    expect(StackMessage::query()->where('thread_id', $this->parent->id)->sole()->text)->toContain('split the work of sub-thread "scout"');
});

it('drop closes the child', function () {
    $this->actingAs($this->user)->post(route('threads.stack.drop', $this->child))->assertRedirect();

    expect($this->child->fresh()->status)->toBe(ThreadStatus::Closed)
        ->and($this->child->fresh()->close_reason)->toBe('dropped')
        ->and(StackMessage::query()->where('thread_id', $this->parent->id)->sole()->text)->toContain('dropped sub-thread "scout"');
});

it('a viewer without drive rights gets a refusal', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $root = Thread::factory()->private($owner)->create();
    $child = Thread::factory()->child($root)->create();
    ThreadShare::query()->create(['thread_id' => $root->id, 'user_id' => $viewer->id]);

    $this->actingAs($viewer)->post(route('threads.stack.drop', $child))->assertForbidden();

    expect($child->fresh()->status)->toBe(ThreadStatus::Open);
});

it('a second user gets 404 for a private stack', function () {
    $root = Thread::factory()->private()->create();
    $child = Thread::factory()->child($root)->create();

    foreach (['take-over', 'split', 'drop'] as $action) {
        $this->actingAs($this->user)->post(route("threads.stack.{$action}", $child))->assertNotFound();
    }

    expect($child->fresh()->taken_over_at)->toBeNull()->and($child->fresh()->status)->toBe(ThreadStatus::Open);
});

it('refuses an override on a thread that is not a child', function () {
    $this->actingAs($this->user)->post(route('threads.stack.drop', $this->parent))->assertNotFound();
});

it('deletes child branches when the root closes', function () {
    config(['yak.channels.github.installation_id' => 77]);
    Thread::factory()->child($this->parent)->create(['branch' => 'yak/p/api']);
    Thread::factory()->child($this->parent)->create(['branch' => 'yak/p/ui']);
    Thread::factory()->child($this->parent)->create(['branch' => null]);
    $github = Mockery::mock(AppService::class);
    $github->shouldReceive('deleteBranch')->once()->with(77, Mockery::any(), 'yak/p/api')->andReturnTrue();
    $github->shouldReceive('deleteBranch')->once()->with(77, Mockery::any(), 'yak/p/ui')->andReturnTrue();
    app()->instance(AppService::class, $github);

    app(ThreadCloser::class)->close($this->parent, 'pr_merged');
});

it('keeps child branches when the root closes for idleness', function () {
    Thread::factory()->child($this->parent)->create(['branch' => 'yak/p/api']);
    $github = Mockery::mock(AppService::class);
    $github->shouldNotReceive('deleteBranch');
    app()->instance(AppService::class, $github);

    app(ThreadCloser::class)->close(Thread::factory()->create(), 'idle');
    app(ThreadCloser::class)->close($this->parent, 'idle');
});

function sharedStack(): array
{
    $parent = Thread::factory()->create([
        'sandbox_container' => 'yak-thread-parent',
        'sandbox_host' => 'local',
        'sandbox_state' => ThreadSandboxState::Running,
    ]);
    $research = Thread::factory()->child($parent)->create([
        'name' => 'scout',
        'kind' => ThreadKind::Research,
        'sandbox_container' => 'yak-thread-parent',
        'sandbox_state' => ThreadSandboxState::Running,
        'claude_config_dir' => '/home/yak/.claude-9',
    ]);
    $runner = Thread::factory()->child($parent)->create([
        'name' => 'builder',
        'sandbox_container' => 'yak-thread-builder',
        'sandbox_state' => ThreadSandboxState::Running,
    ]);

    return [$parent, $research, $runner];
}

it('closing a parent closes its research child and runner children', function () {
    [$parent, $research, $runner] = sharedStack();

    app(ThreadCloser::class)->close($parent, 'pr_merged');

    $calls = collect(app(SandboxManager::class)->calls);
    $worktreeRemoved = $calls->search(fn (array $call): bool => $call['operation'] === 'exec' && str_contains((string) ($call['arguments']['command'][2] ?? ''), 'git worktree remove'));
    $runnerDestroyed = $calls->search(fn (array $call): bool => $call['operation'] === 'destroy' && $call['threadId'] === $runner->id);
    $parentDestroyed = $calls->search(fn (array $call): bool => $call['operation'] === 'destroy' && $call['threadId'] === $parent->id);

    expect($research->fresh()->status)->toBe(ThreadStatus::Closed)
        ->and($research->fresh()->sandbox_container)->toBeNull()
        ->and($runner->fresh()->status)->toBe(ThreadStatus::Closed)
        ->and($runner->fresh()->close_reason)->toBe('parent_closed')
        ->and($parent->fresh()->status)->toBe(ThreadStatus::Closed)
        ->and($worktreeRemoved)->not->toBeFalse()
        ->and($worktreeRemoved)->toBeLessThan($runnerDestroyed)
        ->and($runnerDestroyed)->toBeLessThan($parentDestroyed);
});

it('does not touch the parent container when only a research child closes', function () {
    [$parent, $research] = sharedStack();

    app(ThreadCloser::class)->close($research, 'finished');

    $operations = collect(app(SandboxManager::class)->calls)->where('threadId', $parent->id)->pluck('operation')->all();

    expect($operations)->not->toContain('destroy', 'stop', 'pause')->and($parent->fresh()->status)->toBe(ThreadStatus::Open);
});

it('root close cascade is idempotent and skips already closed children', function () {
    [$parent, $research, $runner] = sharedStack();
    app(ThreadCloser::class)->close($runner, 'dropped');

    expect(app(StackThreadCloser::class)->closeChildren($parent))->toBe(1)
        ->and(app(StackThreadCloser::class)->closeChildren($parent))->toBe(0);

    app(ThreadCloser::class)->close($parent, 'pr_merged');
    app(ThreadCloser::class)->close($parent, 'pr_merged');

    expect($runner->fresh()->close_reason)->toBe('dropped');
});

it('a message to a child closed by the cascade is refused', function () {
    [$parent, $research, $runner] = sharedStack();
    $sibling = Thread::factory()->child($parent)->create(['name' => 'sibling']);

    app(ThreadCloser::class)->close($parent, 'pr_merged');

    expect(fn () => app(StackMessenger::class)->send($sibling, $runner->fresh(), 'Anyone there?'))->toThrow(StackRuleViolation::class, 'closed')
        ->and(StackThreadMessage::query()->count())->toBe(0);
});
