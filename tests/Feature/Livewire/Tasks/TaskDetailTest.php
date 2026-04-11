<?php

use App\Enums\TaskMode;
use App\Livewire\Tasks\TaskDetail;
use App\Models\Artifact;
use App\Models\TaskLog;
use App\Models\User;
use App\Models\YakTask;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('it renders task info', function () {
    $task = YakTask::factory()->running()->create([
        'description' => 'Fix duplicate CSV header in batch export',
        'source' => 'slack',
        'repo' => 'geocodio',
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertSee('Fix duplicate CSV header in batch export')
        ->assertSee('Slack')
        ->assertSee('geocodio')
        ->assertSee('running');
});

test('it is accessible at /tasks/{id} route', function () {
    $task = YakTask::factory()->success()->create();

    $response = $this->get('/tasks/' . $task->id);

    $response->assertOk();
    $response->assertSeeLivewire(TaskDetail::class);
});

test('it requires authentication', function () {
    auth()->logout();

    $task = YakTask::factory()->create();

    $this->get('/tasks/' . $task->id)->assertRedirect(route('login'));
});

test('it shows pr link for completed fix tasks', function () {
    $task = YakTask::factory()->success()->create([
        'mode' => TaskMode::Fix,
        'pr_url' => 'https://github.com/org/repo/pull/123',
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertSee('Pull Request')
        ->assertSeeHtml('href="https://github.com/org/repo/pull/123"');
});

test('it shows research link for completed research tasks', function () {
    $task = YakTask::factory()->success()->create([
        'mode' => TaskMode::Research,
    ]);

    Artifact::factory()->research()->create(['yak_task_id' => $task->id]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertSee('Research Findings')
        ->assertSee('View research artifact');
});

test('it shows clarification options when awaiting clarification', function () {
    $task = YakTask::factory()->awaitingClarification()->create([
        'clarification_options' => ['Refactor the module', 'Add a new endpoint', 'Do both'],
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertSee('Clarification')
        ->assertSee('Awaiting reply')
        ->assertSee('Refactor the module')
        ->assertSee('Add a new endpoint')
        ->assertSee('Do both');
});

test('it shows clarification ttl countdown', function () {
    $task = YakTask::factory()->awaitingClarification()->create([
        'clarification_expires_at' => now()->addDays(2),
    ]);

    $component = Livewire::test(TaskDetail::class, ['task' => $task]);

    $component->assertSee('Awaiting reply');
    $html = $component->html();
    expect($html)->toContain('from now');
});

test('it toggles debug section', function () {
    $task = YakTask::factory()->success()->create([
        'session_id' => 'ses_test123',
        'model_used' => 'opus',
        'num_turns' => 12,
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertSee('Debug Details')
        ->assertDontSee('ses_test123')
        ->call('toggleDebug')
        ->assertSee('ses_test123')
        ->assertSee('opus')
        ->assertSee('12');
});

test('it shows debug error log', function () {
    $task = YakTask::factory()->failed()->create([
        'error_log' => 'Fatal error: something went wrong',
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('toggleDebug')
        ->assertSee('Fatal error: something went wrong');
});

test('it shows timeline from task logs', function () {
    $task = YakTask::factory()->success()->create();

    TaskLog::factory()->create([
        'yak_task_id' => $task->id,
        'message' => 'Task created from Slack message',
        'created_at' => now()->subMinutes(10),
    ]);

    TaskLog::factory()->create([
        'yak_task_id' => $task->id,
        'message' => 'Assessment complete',
        'created_at' => now()->subMinutes(5),
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertSee('Timeline')
        ->assertSee('Task created from Slack message')
        ->assertSee('Assessment complete');
});

test('it uses polling for live updates', function () {
    $task = YakTask::factory()->running()->create();

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertSeeHtml('wire:poll.15s');
});

test('it shows session log with expandable entries', function () {
    $task = YakTask::factory()->success()->create();

    TaskLog::factory()->create([
        'yak_task_id' => $task->id,
        'message' => 'Searching for CSV export handling',
        'metadata' => ['tool' => 'grep', 'duration' => '1.2s'],
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertSee('Session Log')
        ->assertSee('Searching for CSV export handling')
        ->call('toggleLog', 0)
        ->assertSee('grep');
});

test('it shows screenshots section', function () {
    $task = YakTask::factory()->success()->create();

    Artifact::factory()->screenshot()->create([
        'yak_task_id' => $task->id,
        'filename' => 'screenshot-before.png',
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertSee('Media')
        ->assertSee('screenshot-before.png');
});

test('it shows video player for video artifacts', function () {
    $task = YakTask::factory()->success()->create();

    Artifact::factory()->video()->create([
        'yak_task_id' => $task->id,
        'filename' => 'recording.mp4',
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertSee('Media')
        ->assertSee('recording.mp4')
        ->assertSeeHtml('<video');
});

test('it shows mode in meta pills', function () {
    $task = YakTask::factory()->create(['mode' => TaskMode::Research]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertSee('Research');
});

test('it shows breadcrumb with task identifier', function () {
    $task = YakTask::factory()->create(['external_id' => 'SLACK-001']);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertSee('Tasks')
        ->assertSee('SLACK-001');
});

test('it shows active status indicator for running tasks', function () {
    $task = YakTask::factory()->running()->create();

    $component = Livewire::test(TaskDetail::class, ['task' => $task]);
    $component->assertSeeHtml('animate-pulse');
});

test('it does not show active indicator for completed tasks', function () {
    $task = YakTask::factory()->success()->create();

    $component = Livewire::test(TaskDetail::class, ['task' => $task]);
    $component->assertDontSeeHtml('animate-pulse');
});
