<?php

use App\Enums\TaskStatus;
use App\Models\TaskLog;
use App\Models\User;
use App\Models\YakTask;

/**
 * @return array<string, TaskLog>
 */
function overlayTranscript(YakTask $task): array
{
    return [
        'first' => TaskLog::factory()->create([
            'yak_task_id' => $task->id,
            'attempt_number' => 1,
            'message' => 'Read calculateDistance signature',
            'created_at' => now()->subMinutes(5),
            'metadata' => ['type' => 'tool_use', 'tool' => 'Bash', 'input' => ['command' => 'grep -n calculateDistance lib/Geocodio/AddressParser/Locale/US/Parser.php'], 'output' => 'line 758'],
        ]),
        'second' => TaskLog::factory()->create([
            'yak_task_id' => $task->id,
            'attempt_number' => 1,
            'message' => 'Run phpstan on changed lib files',
            'created_at' => now()->subMinutes(3),
            'metadata' => ['type' => 'tool_use', 'tool' => 'Bash', 'input' => ['command' => 'vendor/bin/phpstan analyse --memory-limit=2G --no-progress'], 'output' => "Note: Using configuration file phpstan.neon.\n\n [OK] No errors"],
        ]),
    ];
}

test('clicking an activity entry opens the transcript overlay on it', function () {
    $this->actingAs(User::factory()->create());
    $task = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => now()]);
    $logs = overlayTranscript($task);

    visit(route('tasks.show', $task))
        ->click('[data-testid="log-entry"]:visible >> nth=1')
        ->assertVisible('[data-testid="transcript-overlay"]:visible')
        ->assertSee('vendor/bin/phpstan analyse')
        ->assertSee('Step 2 of 2')
        ->assertNoJavascriptErrors();
});

test('the overlay is the same width whichever entry is open', function () {
    $this->actingAs(User::factory()->create());
    $task = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => now()]);
    overlayTranscript($task);

    $page = visit(route('tasks.show', $task))
        ->click('[data-testid="log-entry"]:visible >> nth=0')
        ->assertVisible('[data-testid="transcript-overlay"]:visible');

    $widthOnFirst = $page->script('document.querySelector(\'[data-testid="transcript-overlay"]\').offsetWidth');

    $page->click('[data-testid="log-next"]')
        ->assertSee('Step 2 of 2');

    $widthOnSecond = $page->script('document.querySelector(\'[data-testid="transcript-overlay"]\').offsetWidth');

    expect($widthOnSecond)->toBe($widthOnFirst);
});

test('the overlay steps with the keyboard and closes with escape', function () {
    $this->actingAs(User::factory()->create());
    $task = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => now()]);
    overlayTranscript($task);

    visit(route('tasks.show', $task))
        ->click('[data-testid="open-transcript"]:visible')
        ->assertVisible('[data-testid="transcript-overlay"]:visible')
        ->assertSee('Step 1 of 2')
        ->keys('[data-testid="transcript-overlay"]', ['ArrowRight'])
        ->assertSee('Step 2 of 2')
        ->keys('[data-testid="transcript-overlay"]', ['Escape'])
        ->assertMissing('[data-testid="transcript-overlay"]:visible')
        ->assertNoJavascriptErrors();
});

test('the overlay rail scrolls the opened entry into view', function () {
    $this->actingAs(User::factory()->create());
    $task = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => now()]);

    $logs = [];
    foreach (range(1, 40) as $i) {
        $logs[] = TaskLog::factory()->create([
            'yak_task_id' => $task->id,
            'attempt_number' => 1,
            'message' => "Step number {$i}",
            'created_at' => now()->subMinutes(60 - $i),
            'metadata' => ['type' => 'tool_use', 'tool' => 'Bash', 'input' => ['command' => "echo {$i}"], 'output' => (string) $i],
        ]);
    }

    // Entry 35 is far below the fold of the rail.
    $page = visit(route('tasks.show', $task) . '?log=' . $logs[34]->id)
        ->assertVisible('[data-testid="transcript-overlay"]:visible')
        ->assertSee('Step 35 of 40');

    $selectedIsInView = $page->script(<<<'JS'
        (() => {
            const overlay = document.querySelector('[data-testid="transcript-overlay"]');
            const row = overlay.querySelector('[data-testid="log-entry-open"]');
            const scroller = row.closest('[data-scroller]') ?? row.parentElement;
            const r = row.getBoundingClientRect();
            const s = scroller.getBoundingClientRect();
            return r.top >= s.top - 1 && r.bottom <= s.bottom + 1;
        })()
    JS);

    expect($selectedIsInView)->toBeTrue();
});

test('clicking a row deep in the sidebar list opens the rail scrolled to it', function () {
    $this->actingAs(User::factory()->create());
    $task = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => now()]);

    foreach (range(1, 40) as $i) {
        TaskLog::factory()->create([
            'yak_task_id' => $task->id,
            'attempt_number' => 1,
            'message' => "Step number {$i}",
            'created_at' => now()->subMinutes(60 - $i),
            'metadata' => ['type' => 'tool_use', 'tool' => 'Bash', 'input' => ['command' => "echo {$i}"], 'output' => (string) $i],
        ]);
    }

    $page = visit(route('tasks.show', $task))
        ->click('[data-testid="log-entry"]:visible >> nth=29')
        ->assertVisible('[data-testid="transcript-overlay"]:visible')
        ->assertSee('Step 30 of 40');

    $selectedIsInView = $page->script(<<<'JS'
        (() => {
            const overlay = document.querySelector('[data-testid="transcript-overlay"]');
            const row = overlay.querySelector('[data-log-selected]');
            const scroller = row.closest('[data-scroller]');
            const r = row.getBoundingClientRect();
            const s = scroller.getBoundingClientRect();
            return r.top >= s.top - 1 && r.bottom <= s.bottom + 1;
        })()
    JS);

    expect($selectedIsInView)->toBeTrue();
});
