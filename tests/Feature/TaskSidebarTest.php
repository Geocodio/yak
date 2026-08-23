<?php

use App\Enums\TaskStatus;
use App\Livewire\Tasks\TaskDetail;
use App\Models\TaskLog;
use App\Models\User;
use App\Models\YakTask;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

test('focusRun points the log panel at that run', function () {
    $root = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => now()->subHour()]);
    $child = YakTask::factory()->create(['parent_task_id' => $root->id, 'status' => TaskStatus::Success, 'started_at' => now()]);
    TaskLog::factory()->create(['yak_task_id' => $root->id, 'message' => 'root log line', 'attempt_number' => 1]);
    TaskLog::factory()->create(['yak_task_id' => $child->id, 'message' => 'child log line', 'attempt_number' => 1]);

    Livewire::test(TaskDetail::class, ['task' => $root])
        ->call('focusRun', $root->id)
        ->assertSee('root log line')
        ->call('focusRun', $child->id)
        ->assertSee('child log line');
});

test('progress checklist renders only while in flight', function () {
    $running = YakTask::factory()->create(['status' => TaskStatus::Running]);
    Livewire::test(TaskDetail::class, ['task' => $running])->assertSeeHtml('data-testid="progress-checklist"');

    $done = YakTask::factory()->create(['status' => TaskStatus::Success]);
    Livewire::test(TaskDetail::class, ['task' => $done])->assertDontSeeHtml('data-testid="progress-checklist"');
});

test('log entry opens in drawer', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => now()]);
    TaskLog::factory()->create([
        'yak_task_id' => $task->id,
        'message' => 'Ran a command',
        'attempt_number' => 1,
        'metadata' => ['type' => 'tool_use', 'tool' => 'Bash', 'input' => ['command' => 'ls -la'], 'output' => 'total 0'],
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openLogDrawer', 0)
        ->assertSee('ls -la')
        ->assertSee('total 0');
});

test('page has exactly one poll timer even though the sidebar renders twice', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running]);

    $html = Livewire::test(TaskDetail::class, ['task' => $task])->html();

    expect(substr_count($html, 'wire:poll'))->toBe(1);
});
