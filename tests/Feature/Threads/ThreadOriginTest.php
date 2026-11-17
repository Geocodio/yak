<?php

use App\Enums\TaskMode;
use App\Enums\ThreadKind;
use App\Enums\ThreadSource;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\RepositoryRiskProfiles;
use App\Services\Stacks\ChildSpawner;
use App\Services\Threads\ThreadFactory;
use App\Services\Threads\ThreadOrigin;
use Illuminate\Support\Facades\Queue;

it('resolves a linear request', function () {
    $task = YakTask::factory()->create(['source' => 'linear', 'external_id' => 'LINEAR-ENG-1576', 'external_url' => 'https://linear.app/x/issue/ENG-1576', 'author_name' => 'Ada']);

    expect(app(ThreadOrigin::class)->forRequest($task))->toMatchArray([
        'source' => ThreadSource::Linear,
        'source_reference' => 'ENG-1576',
        'source_url' => 'https://linear.app/x/issue/ENG-1576',
        'starter_name' => 'Ada',
    ]);
});

it('maps preview-repair by value', function () {
    $task = YakTask::factory()->create(['source' => 'preview-repair']);

    expect(app(ThreadOrigin::class)->forRequest($task)['source'])->toBe(ThreadSource::PreviewRepair);
});

it('marks risk profile research', function () {
    Queue::fake();
    $task = app(RepositoryRiskProfiles::class)->generate(Repository::factory()->create(), 'cli');

    expect(app(ThreadOrigin::class)->forRequest($task)['source'])->toBe(ThreadSource::RepositoryResearch);
});

it('does not mark other cli research as repository research', function () {
    $task = YakTask::factory()->create(['source' => 'cli', 'mode' => TaskMode::Research, 'external_id' => 'CLI-1']);

    expect(app(ThreadOrigin::class)->forRequest($task)['source'])->toBe(ThreadSource::Cli);
});

it('falls back to dashboard without a request', function () {
    expect(app(ThreadOrigin::class)->forRequest(null))->toMatchArray([
        'source' => ThreadSource::Dashboard, 'source_reference' => null, 'source_url' => null, 'starter_name' => null,
    ]);
});

it('uses the given fallback for an unknown task source', function () {
    $task = YakTask::factory()->create(['source' => 'manual']);

    expect(app(ThreadOrigin::class)->forRequest($task, ThreadSource::Cli)['source'])->toBe(ThreadSource::Cli);
});

it('leaves the starter name empty when a user started the request', function () {
    $task = YakTask::factory()->create(['source' => 'slack', 'author_name' => 'Ada', 'started_by_user_id' => User::factory()->create()->id]);

    expect(app(ThreadOrigin::class)->forRequest($task)['starter_name'])->toBeNull();
});

it('stores the origin when ThreadFactory creates a thread', function () {
    $task = YakTask::factory()->create(['source' => 'slack', 'author_name' => 'Ada']);

    $thread = app(ThreadFactory::class)->forTask($task)->fresh();

    expect($thread->source)->toBe(ThreadSource::Slack)
        ->and($thread->starter_name)->toBe('Ada');
});

it('gives starter keys in order', function () {
    $origin = app(ThreadOrigin::class);

    expect($origin->starterKey(new Thread(['created_by_user_id' => 7, 'starter_name' => 'Ada'])))->toBe('user:7')
        ->and($origin->starterKey(new Thread(['starter_name' => 'Ada'])))->toBe('name:Ada')
        ->and($origin->starterKey(new Thread))->toBe('yak');
});

it('marks a spawned child thread as a stack', function () {
    $parent = Thread::factory()->create(['sandbox_container' => 'yak-thread-parent']);

    $child = app(ChildSpawner::class)->spawn($parent, 'helper', 'Find callers.', ThreadKind::Research, null);

    expect($child->fresh()->source)->toBe(ThreadSource::Stack);
});
