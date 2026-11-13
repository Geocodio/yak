<?php

use App\Enums\TaskStatus;
use App\Models\Artifact;
use App\Models\TaskLog;
use App\Models\User;
use App\Models\YakTask;

test('on a phone the task page is two tabs and the desktop aside is not mounted', function () {
    $this->actingAs(User::factory()->create());
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);
    TaskLog::factory()->count(3)->for($task, 'task')->create();
    // The walkthrough card only renders once a render is owed or done; a
    // cut artifact gives the Details tab something to show.
    Artifact::factory()->for($task, 'task')->videoCut()->create();

    $page = visit(route('tasks.show', $task))->on()->mobile();

    $page->assertMissing('[data-testid="details-drawer-trigger"]')
        ->assertVisible('[data-testid="task-tabs-mobile"]')
        ->assertVisible('[data-testid="task-summary"]')
        ->assertVisible('[data-testid="activity-stream"]')
        ->assertMissing('[data-testid="task-tab-activity"]')
        ->click('[data-testid="task-tab-details"]')
        ->assertQueryStringHas('tab', 'details')
        ->assertVisible('[data-testid="walkthrough-card"]')
        ->assertNoJavaScriptErrors()
        // The "exactly one" check uses the Playwright locator count
        // assertion, the plugin's idiomatic form for this, rather than
        // `$page->script()`.
        ->assertCount('[data-testid="activity-stream"]', 1)
        ->assertNotPresent('[data-testid="task-sidebar"]');
});

test('a tab deep link and a log deep link open the right panel on a phone', function () {
    $this->actingAs(User::factory()->create());
    $task = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => now()]);
    // The walkthrough card only renders once a render is owed or done; a
    // cut artifact gives the Details tab something to show.
    Artifact::factory()->for($task, 'task')->videoCut()->create();
    $log = TaskLog::factory()->create(['yak_task_id' => $task->id, 'attempt_number' => 1, 'message' => 'Deep linked', 'metadata' => ['type' => 'tool_use', 'tool' => 'Bash', 'input' => ['command' => 'true'], 'output' => 'ok']]);

    visit(route('tasks.show', [$task, 'tab' => 'details']))->on()->mobile()
        ->assertVisible('[data-testid="walkthrough-card"]');

    $page = visit(route('tasks.show', [$task, 'log' => $log->id]))->on()->mobile()
        ->assertVisible('[data-testid="log-entry-sheet"]')
        ->assertSee('Deep linked');

    $heights = $page->script(
        '(() => ({'
        . 'sheet: document.querySelector(\'[data-testid="log-entry-sheet"]\').getBoundingClientRect().height,'
        . 'viewport: window.innerHeight,'
        . '}))()'
    );

    expect(abs($heights['sheet'] - $heights['viewport']))->toBeLessThan(4);
});

test('desktop shows sidebar without trigger', function () {
    $this->actingAs(User::factory()->create());
    $task = YakTask::factory()->create();

    visit(route('tasks.show', $task))
        ->assertVisible('[data-testid="task-sidebar"]:visible')
        ->assertMissing('[data-testid="details-drawer-trigger"]');
});

test('the conversation does not run under the aside on a short viewport', function () {
    $this->actingAs(User::factory()->create());

    $task = YakTask::factory()->success()->create();
    TaskLog::factory()->count(40)->for($task, 'task')->create();
    Artifact::factory()->for($task, 'task')->videoThumbnail()->create();
    Artifact::factory()->for($task, 'task')->videoCut()->create();

    $page = visit(route('tasks.show', $task))->on()->macbookAir()->assertVisible('[data-testid="yak-bubble"]');

    /** @var array{bubbleRight: float, asideLeft: float} $rects */
    $rects = $page->script(
        '(() => {'
        . 'const bubble = document.querySelector(\'[data-testid="yak-bubble"]\');'
        . 'const aside = document.querySelector(\'[data-testid="task-sidebar"]\');'
        . 'return { bubbleRight: bubble.getBoundingClientRect().right, asideLeft: aside.getBoundingClientRect().left };'
        . '})()'
    );

    expect($rects['bubbleRight'])->toBeLessThanOrEqual($rects['asideLeft']);
});

test('on a phone a long run offers its earlier steps without leaving the conversation', function () {
    $this->actingAs(User::factory()->create());
    $task = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => now()]);
    $rows = [];
    foreach (range(1, 250) as $index) {
        $rows[] = [
            'yak_task_id' => $task->id,
            'attempt_number' => 1,
            'level' => 'info',
            'message' => "Step {$index}",
            'metadata' => json_encode(['type' => 'tool_use', 'tool' => 'Bash', 'input' => ['command' => "echo {$index}"], 'output' => (string) $index]),
            'created_at' => now()->subSeconds(250 - $index),
        ];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        TaskLog::insert($chunk);
    }

    $page = visit(route('tasks.show', $task))->on()->mobile()
        ->click('[data-testid="finished-summary"] > summary')
        ->click('[data-testid="step-group"] > summary')
        ->assertSee('Load 50 earlier steps')
        ->assertNoJavaScriptErrors();

    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
});
