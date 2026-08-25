<?php

use App\Livewire\Tasks\TaskDetail;
use App\Models\TaskLog;
use App\Models\User;
use App\Models\YakTask;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

/**
 * Builds a run with a mixed transcript: a tool call, an assistant line,
 * another tool call, a milestone. Returns the logs keyed by label.
 *
 * @return array<string, TaskLog>
 */
function transcript(YakTask $task): array
{
    return [
        'bash' => TaskLog::factory()->create([
            'yak_task_id' => $task->id,
            'attempt_number' => 1,
            'message' => 'Read calculateDistance signature',
            'created_at' => now()->subMinutes(5),
            'metadata' => ['type' => 'tool_use', 'tool' => 'Bash', 'input' => ['command' => 'grep -n calculateDistance lib/Foo.php'], 'output' => 'line 42'],
        ]),
        'assistant' => TaskLog::factory()->create([
            'yak_task_id' => $task->id,
            'attempt_number' => 1,
            'message' => '## Summary of the plan',
            'level' => 'info',
            'created_at' => now()->subMinutes(4),
            'metadata' => ['type' => 'assistant'],
        ]),
        'edit' => TaskLog::factory()->create([
            'yak_task_id' => $task->id,
            'attempt_number' => 1,
            'message' => 'Run phpstan on changed lib files',
            'created_at' => now()->subMinutes(3),
            'metadata' => ['type' => 'tool_use', 'tool' => 'Bash', 'input' => ['command' => 'vendor/bin/phpstan analyse'], 'output' => 'No errors'],
        ]),
    ];
}

it('opens the drawer by log id rather than list position', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);
    $logs = transcript($task);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openLogDrawer', $logs['edit']->id)
        ->assertSet('drawerLogId', $logs['edit']->id)
        ->assertSet('drawerOpen', true)
        ->assertSee('vendor/bin/phpstan analyse');
});

it('steps forward and backward through the visible entries', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);
    $logs = transcript($task);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openLogDrawer', $logs['bash']->id)
        ->call('nextLog')
        ->assertSet('drawerLogId', $logs['assistant']->id)
        ->call('nextLog')
        ->assertSet('drawerLogId', $logs['edit']->id)
        ->call('previousLog')
        ->assertSet('drawerLogId', $logs['assistant']->id);
});

it('clamps stepping at both ends of the transcript', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);
    $logs = transcript($task);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openLogDrawer', $logs['bash']->id)
        ->call('previousLog')
        ->assertSet('drawerLogId', $logs['bash']->id)
        ->call('openLogDrawer', $logs['edit']->id)
        ->call('nextLog')
        ->assertSet('drawerLogId', $logs['edit']->id);
});

it('steps within the active filter, skipping entries it hides', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);
    $logs = transcript($task);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('setFilter', 'actions')
        ->call('openLogDrawer', $logs['bash']->id)
        ->call('nextLog')
        // The assistant line is hidden by the Actions filter, so next is the
        // following tool call.
        ->assertSet('drawerLogId', $logs['edit']->id);
});

it('reports the position of the open entry within the visible set', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);
    $logs = transcript($task);

    $component = Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openLogDrawer', $logs['assistant']->id);

    expect($component->instance()->drawerPosition())->toBe(['position' => 2, 'total' => 3]);
});

it('enters the visible set from the nearest end when the open entry is filtered out', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);
    $logs = transcript($task);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openLogDrawer', $logs['assistant']->id)
        ->call('setFilter', 'actions')
        ->call('nextLog')
        ->assertSet('drawerLogId', $logs['bash']->id);
});

it('narrows the activity list by search across message, tool, and command', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);
    $logs = transcript($task);

    $component = Livewire::test(TaskDetail::class, ['task' => $task])
        ->set('logSearch', 'phpstan');

    expect($component->instance()->navigableLogIds())->toBe([$logs['edit']->id]);

    $component->set('logSearch', 'calculateDistance');
    expect($component->instance()->navigableLogIds())->toBe([$logs['bash']->id]);

    $component->set('logSearch', '');
    expect($component->instance()->navigableLogIds())->toHaveCount(3);
});

it('search is case insensitive', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);
    $logs = transcript($task);

    $component = Livewire::test(TaskDetail::class, ['task' => $task])
        ->set('logSearch', 'PHPSTAN');

    expect($component->instance()->navigableLogIds())->toBe([$logs['edit']->id]);
});

it('opens a deep-linked log on mount and focuses its run and attempt', function () {
    $root = YakTask::factory()->create(['started_at' => now()->subHour(), 'attempts' => 2]);
    $child = YakTask::factory()->create([
        'parent_task_id' => $root->id,
        'started_at' => now(),
        'attempts' => 1,
    ]);

    $log = TaskLog::factory()->create([
        'yak_task_id' => $child->id,
        'attempt_number' => 1,
        'message' => 'Deep linked step',
        'metadata' => ['type' => 'tool_use', 'tool' => 'Bash', 'input' => ['command' => 'echo hi'], 'output' => 'hi'],
    ]);

    Livewire::withQueryParams(['log' => $log->id])
        ->test(TaskDetail::class, ['task' => $root])
        ->assertSet('drawerOpen', true)
        ->assertSet('focusedRunId', $child->id)
        ->assertSet('visibleAttempt', 1)
        ->assertSee('echo hi');
});

it('ignores a deep link to a log from another task', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);
    $other = YakTask::factory()->create(['started_at' => now()]);
    $foreign = TaskLog::factory()->create(['yak_task_id' => $other->id, 'attempt_number' => 1]);

    Livewire::withQueryParams(['log' => $foreign->id])
        ->test(TaskDetail::class, ['task' => $task])
        ->assertSet('drawerOpen', false)
        ->assertSet('drawerLogId', null);
});

it('ignores a deep link to a log that no longer exists', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);

    Livewire::withQueryParams(['log' => 99999999])
        ->test(TaskDetail::class, ['task' => $task])
        ->assertSet('drawerOpen', false)
        ->assertSet('drawerLogId', null);
});

it('highlights the open entry in the activity list', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);
    $logs = transcript($task);

    $html = Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openLogDrawer', $logs['edit']->id)
        ->html();

    expect($html)->toContain('data-testid="log-entry-open"');
});

it('does not repeat an assistant message in both the drawer heading and body', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);
    $log = TaskLog::factory()->create([
        'yak_task_id' => $task->id,
        'attempt_number' => 1,
        'message' => 'A distinctive assistant sentence.',
        'metadata' => ['type' => 'assistant'],
    ]);

    $html = Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openLogDrawer', $log->id)
        ->html();

    // The message renders once as the drawer body, with no heading echoing
    // it. (It also appears in the activity list, which the sidebar renders
    // for both the desktop column and the mobile drawer.)
    expect($html)->not->toContain('data-testid="log-drawer-heading"')
        ->and($html)->toContain('data-testid="log-drawer-message"');
});

it('still shows a heading for tool entries, where it summarizes the call', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);
    $log = TaskLog::factory()->create([
        'yak_task_id' => $task->id,
        'attempt_number' => 1,
        'message' => 'Run the new test file',
        'metadata' => ['type' => 'tool_use', 'tool' => 'Bash', 'input' => ['command' => 'pest tests/Foo.php'], 'output' => 'ok'],
    ]);

    $html = Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openLogDrawer', $log->id)
        ->html();

    expect($html)->toContain('data-testid="log-drawer-heading"')
        ->and($html)->toContain('Run the new test file');
});
