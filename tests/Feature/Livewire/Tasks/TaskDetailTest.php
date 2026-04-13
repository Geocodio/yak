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

test('it shows activity log with task logs', function () {
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
        ->assertSee('Activity')
        ->assertDontSee('Timeline')
        ->assertSee('Task created from Slack message')
        ->assertSee('Assessment complete');
});

test('it uses fast polling for running tasks', function () {
    $task = YakTask::factory()->running()->create();

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertSeeHtml('wire:poll.5s');
});

test('it uses slow polling for completed tasks', function () {
    $task = YakTask::factory()->success()->create();

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertSeeHtml('wire:poll.15s');
});

test('it shows activity log with expandable entries', function () {
    $task = YakTask::factory()->success()->create();

    TaskLog::factory()->create([
        'yak_task_id' => $task->id,
        'message' => 'Searching for CSV export handling',
        'metadata' => ['tool' => 'grep', 'duration' => '1.2s'],
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertSee('Activity')
        ->assertDontSee('Session Log')
        ->assertSee('Searching for CSV export handling')
        ->call('toggleLog', 0)
        ->assertSee('grep');
});

test('milestone logs get milestone styling', function () {
    $task = YakTask::factory()->success()->create();

    TaskLog::factory()->create([
        'yak_task_id' => $task->id,
        'message' => 'Task created from Slack message',
        'metadata' => ['source' => 'slack'],
    ]);

    TaskLog::factory()->create([
        'yak_task_id' => $task->id,
        'message' => 'Searching for CSV export handling',
        'metadata' => ['type' => 'tool_use', 'tool' => 'grep'],
    ]);

    $component = Livewire::test(TaskDetail::class, ['task' => $task]);
    $html = $component->html();

    expect($html)->toContain('data-testid="milestone-log"');
    expect($html)->toContain('data-testid="log-entry"');
});

test('warning and error logs are milestones regardless of type', function () {
    $task = YakTask::factory()->success()->create();

    TaskLog::factory()->create([
        'yak_task_id' => $task->id,
        'level' => 'warning',
        'message' => 'Test suite failed',
        'metadata' => ['type' => 'tool_use', 'tool' => 'bash'],
    ]);

    $component = Livewire::test(TaskDetail::class, ['task' => $task]);
    $html = $component->html();

    expect($html)->toContain('data-testid="milestone-log"');
    expect($html)->not->toContain('data-testid="log-entry"');
});

test('follow button is visible for active tasks', function () {
    $task = YakTask::factory()->running()->create();

    TaskLog::factory()->create(['yak_task_id' => $task->id]);

    $component = Livewire::test(TaskDetail::class, ['task' => $task]);

    expect($component->html())->toContain('data-testid="follow-button"');
});

test('follow button is hidden for completed tasks', function () {
    $task = YakTask::factory()->success()->create();

    TaskLog::factory()->create(['yak_task_id' => $task->id]);

    $component = Livewire::test(TaskDetail::class, ['task' => $task]);

    expect($component->html())->not->toContain('data-testid="follow-button"');
});

test('isMilestone returns true for logs without tool_use or assistant type', function () {
    $log = TaskLog::factory()->make(['metadata' => ['source' => 'slack']]);
    expect(TaskDetail::isMilestone($log))->toBeTrue();

    $log = TaskLog::factory()->make(['metadata' => null]);
    expect(TaskDetail::isMilestone($log))->toBeTrue();

    $log = TaskLog::factory()->make(['metadata' => []]);
    expect(TaskDetail::isMilestone($log))->toBeTrue();
});

test('isMilestone returns false for tool_use and assistant logs', function () {
    $log = TaskLog::factory()->make(['metadata' => ['type' => 'tool_use', 'tool' => 'grep']]);
    expect(TaskDetail::isMilestone($log))->toBeFalse();

    $log = TaskLog::factory()->make(['metadata' => ['type' => 'assistant']]);
    expect(TaskDetail::isMilestone($log))->toBeFalse();
});

test('isMilestone returns true for error or warning regardless of type', function () {
    $log = TaskLog::factory()->make([
        'level' => 'error',
        'metadata' => ['type' => 'tool_use', 'tool' => 'bash'],
    ]);
    expect(TaskDetail::isMilestone($log))->toBeTrue();

    $log = TaskLog::factory()->make([
        'level' => 'warning',
        'metadata' => ['type' => 'assistant'],
    ]);
    expect(TaskDetail::isMilestone($log))->toBeTrue();
});

test('activity section is hidden when no logs exist', function () {
    $task = YakTask::factory()->success()->create();

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertDontSee('Activity');
});

test('milestone logs do not show expand chevron', function () {
    $task = YakTask::factory()->success()->create();

    TaskLog::factory()->create([
        'yak_task_id' => $task->id,
        'message' => 'Task completed',
        'metadata' => [],
    ]);

    $component = Livewire::test(TaskDetail::class, ['task' => $task]);
    $html = $component->html();

    // Milestone entry should not contain a chevron icon
    // Find the milestone log entry and check it has the spacer span instead
    expect($html)->toContain('data-testid="milestone-log"');
    expect($html)->not->toContain('chevron-right');
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
