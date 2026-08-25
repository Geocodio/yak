<?php

use App\Enums\TaskStatus;
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
        ->call('openTranscript', $logs['edit']->id)
        ->assertSet('transcriptLogId', $logs['edit']->id)
        ->assertSet('transcriptOpen', true)
        ->assertSee('vendor/bin/phpstan analyse');
});

it('steps forward and backward through the visible entries', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);
    $logs = transcript($task);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openTranscript', $logs['bash']->id)
        ->call('nextLog')
        ->assertSet('transcriptLogId', $logs['assistant']->id)
        ->call('nextLog')
        ->assertSet('transcriptLogId', $logs['edit']->id)
        ->call('previousLog')
        ->assertSet('transcriptLogId', $logs['assistant']->id);
});

it('clamps stepping at both ends of the transcript', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);
    $logs = transcript($task);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openTranscript', $logs['bash']->id)
        ->call('previousLog')
        ->assertSet('transcriptLogId', $logs['bash']->id)
        ->call('openTranscript', $logs['edit']->id)
        ->call('nextLog')
        ->assertSet('transcriptLogId', $logs['edit']->id);
});

it('steps within the active filter, skipping entries it hides', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);
    $logs = transcript($task);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('setFilter', 'actions')
        ->call('openTranscript', $logs['bash']->id)
        ->call('nextLog')
        // The assistant line is hidden by the Actions filter, so next is the
        // following tool call.
        ->assertSet('transcriptLogId', $logs['edit']->id);
});

it('reports the position of the open entry within the visible set', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);
    $logs = transcript($task);

    $component = Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openTranscript', $logs['assistant']->id);

    expect($component->instance()->transcriptPosition())->toBe(['position' => 2, 'total' => 3]);
});

it('enters the visible set from the nearest end when the open entry is filtered out', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);
    $logs = transcript($task);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openTranscript', $logs['assistant']->id)
        ->call('setFilter', 'actions')
        ->call('nextLog')
        ->assertSet('transcriptLogId', $logs['bash']->id);
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
        ->assertSet('transcriptOpen', true)
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
        ->assertSet('transcriptOpen', false)
        ->assertSet('transcriptLogId', null);
});

it('ignores a deep link to a log that no longer exists', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);

    Livewire::withQueryParams(['log' => 99999999])
        ->test(TaskDetail::class, ['task' => $task])
        ->assertSet('transcriptOpen', false)
        ->assertSet('transcriptLogId', null);
});

it('highlights the open entry in the activity list', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);
    $logs = transcript($task);

    $html = Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openTranscript', $logs['edit']->id)
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
        ->call('openTranscript', $log->id)
        ->html();

    // The message renders once as the drawer body, with no heading echoing
    // it. (It also appears in the activity list, which the sidebar renders
    // for both the desktop column and the mobile drawer.)
    expect($html)->not->toContain('data-testid="transcript-heading"')
        ->and($html)->toContain('data-testid="transcript-message"');
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
        ->call('openTranscript', $log->id)
        ->html();

    expect($html)->toContain('data-testid="transcript-heading"')
        ->and($html)->toContain('Run the new test file');
});

it('opens the transcript cold on the first visible entry', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);
    $logs = transcript($task);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openTranscriptCold')
        ->assertSet('transcriptOpen', true)
        ->assertSet('transcriptLogId', $logs['bash']->id);
});

it('keeps the entry already selected when opening cold', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);
    $logs = transcript($task);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openTranscript', $logs['edit']->id)
        ->call('closeTranscript')
        ->call('openTranscriptCold')
        ->assertSet('transcriptLogId', $logs['edit']->id);
});

it('opens cold without a selection when the run has no entries', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openTranscriptCold')
        ->assertSet('transcriptOpen', true)
        ->assertSet('transcriptLogId', null)
        ->assertSee('Pick an entry on the left');
});

it('renders the overlay at a fixed size rather than sized to its content', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);
    $logs = transcript($task);

    $html = Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openTranscript', $logs['bash']->id)
        ->html();

    // The old flyout used min-w only, so it shrink-wrapped per entry.
    expect($html)->toContain('data-testid="transcript-overlay"')
        ->and($html)->toContain('w-[calc(100vw-2rem)]')
        ->and($html)->toContain('max-w-none')
        ->and($html)->not->toContain('!min-w-[420px]');
});

it('shows the run picker inside the overlay for a follow-up chain', function () {
    $root = YakTask::factory()->create(['started_at' => now()->subHour()]);
    $child = YakTask::factory()->create(['parent_task_id' => $root->id, 'started_at' => now()]);
    $log = TaskLog::factory()->create([
        'yak_task_id' => $child->id,
        'attempt_number' => 1,
        'metadata' => ['type' => 'tool_use', 'tool' => 'Bash', 'input' => ['command' => 'ls'], 'output' => 'ok'],
    ]);

    $html = Livewire::test(TaskDetail::class, ['task' => $root])
        ->call('openTranscript', $log->id)
        ->html();

    expect($html)->toContain('data-testid="transcript-run-picker"');
});

it('follows the tail on a live run until the reader pins an entry', function () {
    $live = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);
    $logs = transcript($live);

    $component = Livewire::test(TaskDetail::class, ['task' => $live]);

    // Opened cold on a live run: follow the tail, and land on the newest
    // entry rather than the oldest, since what matters is what is happening.
    expect($component->call('openTranscriptCold')->html())->toContain('activityFollow(true)');
    $component->assertSet('transcriptLogId', $logs['edit']->id);

    // Clicking a row pins it — following would yank them off what they chose.
    expect($component->call('openTranscript', $logs['bash']->id)->html())
        ->toContain('activityFollow(false)');

    // Stepping is a deliberate choice too.
    $component->call('closeTranscript')->call('openTranscriptCold');
    expect($component->call('nextLog')->html())->toContain('activityFollow(false)');
});

it('never follows the tail on a finished run', function () {
    $task = YakTask::factory()->create([
        'status' => TaskStatus::Success,
        'started_at' => now(),
        'completed_at' => now(),
    ]);
    $logs = transcript($task);

    $component = Livewire::test(TaskDetail::class, ['task' => $task]);

    // A finished run has no tail to follow, and opens at the beginning.
    expect($component->call('openTranscriptCold')->html())->toContain('activityFollow(false)');
    $component->assertSet('transcriptLogId', $logs['bash']->id);
});

it('exposes the sidebar affordance for opening the transcript', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);
    transcript($task);

    $html = Livewire::test(TaskDetail::class, ['task' => $task])->html();

    expect($html)->toContain('data-testid="open-transcript"');
});

it('drops the ?log= parameter when the transcript is closed', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);
    $logs = transcript($task);

    // transcriptLogId is bound to ?log= with except:null, so nulling it
    // removes the parameter -- a refresh after closing must not reopen it.
    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openTranscript', $logs['edit']->id)
        ->assertSet('transcriptLogId', $logs['edit']->id)
        ->call('closeTranscript')
        ->assertSet('transcriptOpen', false)
        ->assertSet('transcriptLogId', null);
});

it('drops the ?log= parameter when the overlay is dismissed with escape', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);
    $logs = transcript($task);

    // Escape and click-outside close the modal client-side via wire:model,
    // which never calls closeTranscript().
    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openTranscript', $logs['edit']->id)
        ->set('transcriptOpen', false)
        ->assertSet('transcriptLogId', null);
});

it('reopens on the entry you were last reading', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);
    $logs = transcript($task);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openTranscript', $logs['edit']->id)
        ->call('closeTranscript')
        ->call('openTranscriptCold')
        ->assertSet('transcriptLogId', $logs['edit']->id);
});

it('shows how long a tool call took and how much it printed', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);
    $log = TaskLog::factory()->create([
        'yak_task_id' => $task->id,
        'attempt_number' => 1,
        'message' => 'Run phpstan → exit 0',
        'metadata' => [
            'type' => 'tool_use',
            'tool' => 'Bash',
            'input' => ['command' => 'vendor/bin/phpstan analyse'],
            'output' => "line one\nline two\nline three",
            'output_lines' => 3,
            'duration_ms' => 12400,
        ],
    ]);

    $html = Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openTranscript', $log->id)
        ->html();

    expect($html)->toContain('data-testid="entry-duration"')
        ->and($html)->toContain('12s')
        ->and($html)->toContain('3 lines of output');
});
