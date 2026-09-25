<?php

use App\Enums\TaskStatus;
use App\Models\Artifact;
use App\Models\TaskLog;
use App\Models\User;
use App\Models\YakTask;

test('on a phone the task page is three tabs and the desktop aside is not mounted', function () {
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
        ->assertMissing('[data-testid="activity-log"]:visible')
        ->click('[data-testid="task-tab-activity"]')
        ->assertVisible('[data-testid="activity-log"]')
        ->assertQueryStringHas('tab', 'activity')
        ->click('[data-testid="task-tab-details"]')
        ->assertVisible('[data-testid="walkthrough-card"]')
        ->assertNoJavaScriptErrors()
        // The "exactly one" check uses the Playwright locator count
        // assertion, the plugin's idiomatic form for this, rather than
        // `$page->script()`.
        ->assertCount('[data-testid="activity-log"]', 1)
        ->assertNotPresent('[data-testid="task-sidebar"]');
});

test('a tab deep link and a log deep link open the right tab on a phone', function () {
    $this->actingAs(User::factory()->create());
    $task = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => now()]);
    // The walkthrough card only renders once a render is owed or done; a
    // cut artifact gives the Details tab something to show.
    Artifact::factory()->for($task, 'task')->videoCut()->create();
    $log = TaskLog::factory()->create(['yak_task_id' => $task->id, 'attempt_number' => 1, 'message' => 'Deep linked', 'metadata' => ['type' => 'tool_use', 'tool' => 'Bash', 'input' => ['command' => 'true'], 'output' => 'ok']]);

    visit(route('tasks.show', [$task, 'tab' => 'details']))->on()->mobile()
        ->assertVisible('[data-testid="walkthrough-card"]');

    $page = visit(route('tasks.show', [$task, 'log' => $log->id]))->on()->mobile()
        ->assertVisible('[data-testid="activity-log"]')
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

test('the activity log does not overlap the cards below it on a short viewport', function () {
    $this->actingAs(User::factory()->create());

    $task = YakTask::factory()->success()->create();
    TaskLog::factory()->count(40)->for($task, 'task')->create();
    Artifact::factory()->for($task, 'task')->videoThumbnail()->create();
    Artifact::factory()->for($task, 'task')->videoCut()->create();

    $page = visit(route('tasks.show', $task))->on()->macbookAir();

    $page->assertVisible('[data-testid="activity-log"]');

    /** @var array{activityBottom: float, walkthroughTop: float} $rects */
    $rects = $page->script(
        '(() => {'
        . 'const activity = document.querySelector(\'[data-testid="activity-log"] [data-scroller]\');'
        . 'const walkthrough = document.querySelector(\'[data-testid="walkthrough-card"]\');'
        . 'return { activityBottom: activity.getBoundingClientRect().bottom, walkthroughTop: walkthrough.getBoundingClientRect().top };'
        . '})()'
    );

    expect($rects['activityBottom'])->toBeLessThanOrEqual($rects['walkthroughTop']);
});
