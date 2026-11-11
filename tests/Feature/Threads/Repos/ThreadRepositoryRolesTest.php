<?php

use App\Enums\RepositoryRole;
use App\Events\ThreadRepositoryChanged;
use App\Exceptions\RepositoryRoleBlocked;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\YakTask;
use App\Services\Threads\Repos\ThreadRepositoryRoles;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([ThreadRepositoryChanged::class]);
    $this->thread = Thread::factory()->create(['branch' => 'yak/thread-x']);
    $this->roles = app(ThreadRepositoryRoles::class);
});

function extra(Thread $thread, string $state, array $attributes = []): ThreadRepository
{
    return ThreadRepository::factory()->{$state}()->create(['thread_id' => $thread->id, 'repo' => 'acme/lib', ...$attributes]);
}

it('promotes a context repo to changes on the thread branch', function () {
    $repository = extra($this->thread, 'context');

    $this->roles->promoteToChanges($repository);

    expect($repository->fresh()->role)->toBe(RepositoryRole::Changes)
        ->and($repository->fresh()->branch)->toBe('yak/thread-x');
    Event::assertDispatched(ThreadRepositoryChanged::class, fn ($event) => $event->repository->is($repository));
});

it('makes a changes repo context only when it has no pull request', function () {
    $repository = extra($this->thread, 'changes', ['branch' => 'yak/thread-x', 'merge_position' => 2]);

    $this->roles->makeContextOnly($repository);

    $fresh = $repository->fresh();
    expect($fresh->role)->toBe(RepositoryRole::Context)->and($fresh->branch)->toBeNull()->and($fresh->merge_position)->toBeNull();
    Event::assertDispatched(ThreadRepositoryChanged::class);
});

it('blocks context only while the pull request is open', function () {
    $url = 'https://github.com/acme/lib/pull/9';
    YakTask::factory()->create(['pr_url' => $url]);
    $repository = extra($this->thread, 'changes', ['pull_request_url' => $url]);

    expect(fn () => $this->roles->makeContextOnly($repository))
        ->toThrow(RepositoryRoleBlocked::class, 'Close the pull request first, then make this repo context only.');

    expect($repository->fresh()->role)->toBe(RepositoryRole::Changes);
    Event::assertNotDispatched(ThreadRepositoryChanged::class);
});

it('allows context only once the pull request is merged', function () {
    $url = 'https://github.com/acme/lib/pull/10';
    YakTask::factory()->create(['pr_url' => $url, 'pr_merged_at' => now()]);
    $repository = extra($this->thread, 'changes', ['pull_request_url' => $url]);

    $this->roles->makeContextOnly($repository);

    expect($repository->fresh()->role)->toBe(RepositoryRole::Context);
});

it('rejects role changes on the primary repository and no-op switches', function () {
    $primary = ThreadRepository::factory()->create(['thread_id' => $this->thread->id]);

    expect(fn () => $this->roles->promoteToChanges($primary))->toThrow(RepositoryRoleBlocked::class)
        ->and(fn () => $this->roles->makeContextOnly($primary))->toThrow(RepositoryRoleBlocked::class)
        ->and(fn () => $this->roles->promoteToChanges(extra($this->thread, 'changes', ['repo' => 'acme/a'])))->toThrow(RepositoryRoleBlocked::class)
        ->and(fn () => $this->roles->makeContextOnly(extra($this->thread, 'context', ['repo' => 'acme/b'])))->toThrow(RepositoryRoleBlocked::class);
});
