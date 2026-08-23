<?php

use App\Enums\TaskMode;
use App\Jobs\ResearchYakJob;
use App\Jobs\RunYakJob;
use App\Livewire\Tasks\CreateTask;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('save creates a dashboard fix task and dispatches RunYakJob', function () {
    Queue::fake();
    Repository::factory()->create(['slug' => 'web', 'is_active' => true]);

    Livewire::test(CreateTask::class)
        ->set('repo', 'web')
        ->set('taskMode', 'fix')
        ->set('description', 'Add a CSV export to the reports page')
        ->call('save');

    $task = YakTask::where('source', 'dashboard')->first();
    expect($task)->not->toBeNull()
        ->and($task->repo)->toBe('web')
        ->and($task->mode)->toBe(TaskMode::Fix)
        ->and($task->description)->toBe('Add a CSV export to the reports page');

    Queue::assertPushed(RunYakJob::class, fn (RunYakJob $j) => $j->task->is($task));
    Queue::assertNotPushed(ResearchYakJob::class);
});

test('save with research mode dispatches ResearchYakJob', function () {
    Queue::fake();
    Repository::factory()->create(['slug' => 'api', 'is_active' => true]);

    Livewire::test(CreateTask::class)
        ->set('repo', 'api')
        ->set('taskMode', 'research')
        ->set('description', 'Investigate the slow dashboard query')
        ->call('save');

    $task = YakTask::where('source', 'dashboard')->first();
    expect($task->mode)->toBe(TaskMode::Research);
    Queue::assertPushed(ResearchYakJob::class);
    Queue::assertNotPushed(RunYakJob::class);
});

test('save validates required fields and an active repo', function () {
    Queue::fake();
    Repository::factory()->create(['slug' => 'web', 'is_active' => true]);

    // blank description
    Livewire::test(CreateTask::class)
        ->set('repo', 'web')->set('taskMode', 'fix')->set('description', '')
        ->call('save')
        ->assertHasErrors('description');

    // missing/inactive repo
    Livewire::test(CreateTask::class)
        ->set('repo', 'does-not-exist')->set('taskMode', 'fix')->set('description', 'something valid here')
        ->call('save')
        ->assertHasErrors('repo');

    expect(YakTask::where('source', 'dashboard')->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('repos computed returns only active repos', function () {
    Repository::factory()->create(['slug' => 'active-one', 'is_active' => true]);
    Repository::factory()->create(['slug' => 'inactive-one', 'is_active' => false]);

    $repos = Livewire::test(CreateTask::class)->instance()->repos();

    expect($repos->pluck('slug')->all())->toContain('active-one')
        ->and($repos->pluck('slug')->all())->not->toContain('inactive-one');
});
