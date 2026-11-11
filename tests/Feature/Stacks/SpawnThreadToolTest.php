<?php

use App\DataTransferObjects\YakToolCall;
use App\DataTransferObjects\YakToolResult;
use App\Enums\RepositoryRole;
use App\Jobs\StartChildThreadJob;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Stacks\StackToolRouter;
use App\Services\Threads\YakToolRouter;
use Illuminate\Support\Facades\Queue;

function spawnCall(array $input): YakToolCall
{
    return new YakToolCall('c1', 'spawn_thread', $input);
}

function spawningParent(array $attributes = []): Thread
{
    $parent = Thread::factory()->create(['branch' => 'yak/parent', ...$attributes]);
    ThreadRepository::factory()->create(['thread_id' => $parent->id, 'repo' => $parent->repo, 'role' => RepositoryRole::Primary]);

    return $parent;
}

it('spawns a child from a thread tool call', function () {
    Queue::fake();
    $parent = spawningParent();

    $result = app(YakToolRouter::class)->handle($parent, spawnCall(['name' => 'docs', 'brief' => 'Find the docs.', 'kind' => 'research']));

    $child = Thread::query()->where('parent_thread_id', $parent->id)->sole();
    expect($result)->toEqual(new YakToolResult(false, 'Started sub-thread docs (research).'))
        ->and($child->brief)->toBe('Find the docs.');
    Queue::assertPushed(StartChildThreadJob::class, fn (StartChildThreadJob $job): bool => $job->childThreadId === $child->id);
});

it('answers a child spawn attempt with an error result', function () {
    Queue::fake();
    $child = Thread::factory()->child(spawningParent())->create();

    $result = app(YakToolRouter::class)->handle($child, spawnCall(['name' => 'x', 'brief' => 'y', 'kind' => 'general']));

    expect($result->isError)->toBeTrue()
        ->and($result->content)->toContain('cannot spawn')
        ->and(Thread::query()->where('parent_thread_id', $child->id)->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

it('refuses a device-owned parent with the teleport message', function () {
    $parent = Thread::factory()->ownedByDevice()->create();

    $result = app(YakToolRouter::class)->handle($parent, spawnCall(['name' => 'x', 'brief' => 'y', 'kind' => 'general']));

    expect($result)->toEqual(new YakToolResult(true, 'Teleport this session to Yak first.'));
});

it('refuses an unknown kind', function () {
    $result = app(YakToolRouter::class)->handle(spawningParent(), spawnCall(['name' => 'x', 'brief' => 'y', 'kind' => 'root']));

    expect($result->isError)->toBeTrue();
});

it('passes other tools to the inner router', function () {
    $result = app(YakToolRouter::class)->handle(Thread::factory()->create(), new YakToolCall('c1', 'other_tool', []));

    expect(app(YakToolRouter::class))->toBeInstanceOf(StackToolRouter::class)
        ->and($result->content)->toBe('The other_tool tool is not available yet.');
});

it('creates one child per call', function () {
    Queue::fake();
    $parent = spawningParent();

    app(YakToolRouter::class)->handle($parent, spawnCall(['name' => 'docs', 'brief' => 'One.', 'kind' => 'general']));

    expect(Thread::query()->where('parent_thread_id', $parent->id)->count())->toBe(1);
});
