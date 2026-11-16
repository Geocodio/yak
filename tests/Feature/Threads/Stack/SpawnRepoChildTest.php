<?php

use App\DataTransferObjects\YakToolCall;
use App\Enums\RepositoryRole;
use App\Enums\ThreadKind;
use App\Enums\ThreadVisibility;
use App\Exceptions\StackRuleViolation;
use App\Jobs\StartChildThreadJob;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Stacks\ChildSpawner;
use App\Services\Stacks\StackToolRouter;
use Illuminate\Support\Facades\Queue;

function repoFanOutParent(array $attributes = []): Thread
{
    Repository::factory()->create(['slug' => 'acme/widgets']);
    Repository::factory()->create(['slug' => 'acme/website']);

    $parent = Thread::factory()->create([
        'repo' => 'acme/widgets',
        'branch' => 'yak/parent-work',
        'claude_account_id' => ClaudeAccount::factory()->create()->id,
        ...$attributes,
    ]);
    ThreadRepository::factory()->create(['thread_id' => $parent->id, 'repo' => 'acme/widgets', 'role' => RepositoryRole::Primary, 'sandbox_path' => '/workspace/widgets']);

    return $parent;
}

it('gives a repo child its own repo and primary row', function () {
    $parent = repoFanOutParent();

    $child = app(ChildSpawner::class)->spawn($parent, 'site-copy', 'Update the site.', ThreadKind::Implement, null, null, 'acme/website');

    $row = $child->primaryRepository;

    expect($child->repo)->toBe('acme/website')
        ->and($child->branch)->toBe('yak/yak/parent-work/site-copy')
        ->and($row->repo)->toBe('acme/website')
        ->and($row->sandbox_path)->toBe('/workspace')
        ->and($row->branch)->toBe($child->branch)
        ->and(YakTask::query()->where('thread_id', $child->id)->value('repo'))->toBe('acme/website')
        ->and($child->queued_for_slot_at)->toBeNull();
});

it('treats a repo equal to the parent repo as a normal child', function () {
    $parent = repoFanOutParent();

    $child = app(ChildSpawner::class)->spawn($parent, 'same', 'Do it.', ThreadKind::Implement, null, null, 'acme/widgets');

    expect($child->repo)->toBe('acme/widgets')
        ->and($child->primaryRepository->sandbox_path)->toBe('/workspace/widgets');
});

it('returns an error result and creates nothing for an unreachable repo', function () {
    Queue::fake();
    $parent = repoFanOutParent();

    $result = app(StackToolRouter::class)->handle($parent, new YakToolCall('c1', 'spawn_thread', ['name' => 'x', 'kind' => 'implement', 'brief' => 'Go.', 'repo' => 'acme/unknown']));

    expect($result->isError)->toBeTrue()
        ->and($result->content)->toBe('Yak cannot reach acme/unknown. Add the repository to the Yak GitHub app first.')
        ->and(Thread::query()->where('parent_thread_id', $parent->id)->exists())->toBeFalse();
    Queue::assertNotPushed(StartChildThreadJob::class);
});

it('rejects a research child with a repo', function () {
    $parent = repoFanOutParent();

    expect(fn () => app(ChildSpawner::class)->spawn($parent, 'look', 'Look.', ThreadKind::Research, null, null, 'acme/website'))
        ->toThrow(StackRuleViolation::class, 'Research sub-threads see every repo of the parent; leave repo out.');
});

it('passes the repo from the spawn_thread tool input', function () {
    Queue::fake();
    $parent = repoFanOutParent();

    $result = app(StackToolRouter::class)->handle($parent, new YakToolCall('c1', 'spawn_thread', ['name' => 'site', 'kind' => 'implement', 'brief' => 'Go.', 'repo' => 'acme/website']));

    expect($result->isError)->toBeFalse()
        ->and(Thread::query()->where('parent_thread_id', $parent->id)->value('repo'))->toBe('acme/website');
});

it('inherits visibility and owner from a private root', function () {
    $owner = User::factory()->create();
    $parent = repoFanOutParent(['visibility' => ThreadVisibility::Private, 'owner_user_id' => $owner->id]);

    $child = app(ChildSpawner::class)->spawn($parent, 'site', 'Go.', ThreadKind::Implement, null, null, 'acme/website');

    expect($child->visibility)->toBe(ThreadVisibility::Private)->and($child->owner_user_id)->toBe($owner->id);
});
