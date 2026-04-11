<?php

use App\Enums\TaskMode;
use App\Jobs\ResearchYakJob;
use App\Jobs\RunYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

test('creates a fix task and dispatches RunYakJob', function () {
    $repo = Repository::factory()->default()->create();

    $this->artisan('yak:run', [
        'id' => 'CLI-001',
        'description' => 'Fix the login bug',
    ])->assertSuccessful();

    $task = YakTask::where('external_id', 'CLI-001')->firstOrFail();
    expect($task->mode)->toBe(TaskMode::Fix);
    expect($task->description)->toBe('Fix the login bug');
    expect($task->repo)->toBe($repo->slug);
    expect($task->source)->toBe('cli');

    Queue::assertPushed(RunYakJob::class, fn (RunYakJob $job) => $job->task->id === $task->id);
});

test('creates a research task with --research flag', function () {
    $repo = Repository::factory()->default()->create();

    $this->artisan('yak:run', [
        'id' => 'CLI-002',
        'description' => 'Investigate API latency',
        '--research' => true,
    ])->assertSuccessful();

    $task = YakTask::where('external_id', 'CLI-002')->firstOrFail();
    expect($task->mode)->toBe(TaskMode::Research);

    Queue::assertPushed(ResearchYakJob::class, fn (ResearchYakJob $job) => $job->task->id === $task->id);
});

test('uses --repo option to select repository', function () {
    Repository::factory()->default()->create();
    Repository::factory()->create(['slug' => 'my-repo']);

    $this->artisan('yak:run', [
        'id' => 'CLI-003',
        'description' => 'Fix something',
        '--repo' => 'my-repo',
    ])->assertSuccessful();

    $task = YakTask::where('external_id', 'CLI-003')->firstOrFail();
    expect($task->repo)->toBe('my-repo');
});

test('passes context option to task', function () {
    Repository::factory()->default()->create();

    $this->artisan('yak:run', [
        'id' => 'CLI-004',
        'description' => 'Fix with context',
        '--context' => '{"file": "app.php"}',
    ])->assertSuccessful();

    $task = YakTask::where('external_id', 'CLI-004')->firstOrFail();
    expect($task->context)->toBe('{"file": "app.php"}');
});

test('fails when repository not found', function () {
    $this->artisan('yak:run', [
        'id' => 'CLI-005',
        'description' => 'Fix something',
        '--repo' => 'nonexistent',
    ])->assertFailed();

    Queue::assertNothingPushed();
});

test('fails when no default repository exists', function () {
    Repository::factory()->create(['is_default' => false]);

    $this->artisan('yak:run', [
        'id' => 'CLI-006',
        'description' => 'Fix something',
    ])->assertFailed();

    Queue::assertNothingPushed();
});

test('--sync flag runs job via dispatch_sync', function () {
    Repository::factory()->default()->create();

    $this->artisan('yak:run', [
        'id' => 'CLI-007',
        'description' => 'Fix sync',
        '--sync' => true,
    ])->assertSuccessful();

    $task = YakTask::where('external_id', 'CLI-007')->firstOrFail();
    expect($task)->not->toBeNull();
    expect($task->source)->toBe('cli');
});
