<?php

use App\Enums\TaskMode;
use App\Jobs\ResearchYakJob;
use App\Jobs\RunYakJob;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('create validates required fields', function () {
    $this->post(route('tasks.store'), [])
        ->assertSessionHasErrors(['repo', 'mode', 'description']);
});

test('save creates a dashboard fix task and dispatches RunYakJob', function () {
    Queue::fake();
    Repository::factory()->create(['slug' => 'web', 'is_active' => true]);

    $this->post(route('tasks.store'), [
        'repo' => 'web',
        'mode' => 'fix',
        'description' => 'Add a CSV export to the reports page',
    ])->assertRedirect();

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

    $this->post(route('tasks.store'), [
        'repo' => 'api',
        'mode' => 'research',
        'description' => 'Investigate the slow dashboard query',
    ])->assertRedirect();

    $task = YakTask::where('source', 'dashboard')->first();
    expect($task->mode)->toBe(TaskMode::Research);
    Queue::assertPushed(ResearchYakJob::class);
    Queue::assertNotPushed(RunYakJob::class);
});

test('save redirects to the new task with a success flash', function () {
    Queue::fake();
    Repository::factory()->create(['slug' => 'web', 'is_active' => true]);

    $this->post(route('tasks.store'), [
        'repo' => 'web',
        'mode' => 'fix',
        'description' => 'Add a CSV export to the reports page',
    ])->assertSessionHas('success');

    $task = YakTask::where('source', 'dashboard')->first();
    expect($task)->not->toBeNull();
});

test('save rejects an inactive repo', function () {
    Queue::fake();
    Repository::factory()->create(['slug' => 'web', 'is_active' => false]);

    $this->post(route('tasks.store'), [
        'repo' => 'web',
        'mode' => 'fix',
        'description' => 'something valid here',
    ])->assertSessionHasErrors('repo');

    expect(YakTask::where('source', 'dashboard')->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('save records the signed-in user as starter and responsible', function () {
    Queue::fake();
    $user = User::factory()->create(['name' => 'Dash Board']);
    $this->actingAs($user);
    Repository::factory()->create(['slug' => 'web', 'is_active' => true]);

    $this->post(route('tasks.store'), [
        'repo' => 'web',
        'mode' => 'fix',
        'description' => 'Tidy the footer',
    ])->assertRedirect();

    $task = YakTask::where('source', 'dashboard')->first();
    expect($task->author_name)->toBe('Dash Board')
        ->and($task->responsible_name)->toBe('Dash Board');
});

test('a dashboard task records the signed-in user as starter and responsible', function () {
    Queue::fake();
    $user = User::factory()->create();
    $this->actingAs($user);
    Repository::factory()->create(['slug' => 'web', 'is_active' => true]);

    $this->post(route('tasks.store'), [
        'repo' => 'web',
        'mode' => 'fix',
        'description' => 'Add a CSV export to the reports page',
    ])->assertRedirect();

    $task = YakTask::where('source', 'dashboard')->firstOrFail();
    expect($task->started_by_user_id)->toBe($user->id)
        ->and($task->responsible_user_id)->toBe($user->id);
});
