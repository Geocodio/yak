<?php

use App\Enums\TaskStatus;
use App\Models\TaskLog;
use App\Models\User;
use App\Models\YakTask;

function seedPagingLogs(YakTask $task, int $count): void
{
    $rows = [];
    foreach (range(1, $count) as $index) {
        $rows[] = [
            'yak_task_id' => $task->id,
            'attempt_number' => 1,
            'level' => 'info',
            'message' => "Step {$index}",
            'metadata' => json_encode(['type' => 'tool_use', 'tool' => 'Bash', 'input' => ['command' => "echo {$index}"], 'output' => (string) $index]),
            'created_at' => now()->subSeconds($count - $index),
        ];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        TaskLog::insert($chunk);
    }
}

test('the activity log starts with the newest 200 rows and loads older rows when scrolled to the top', function () {
    $this->actingAs(User::factory()->create());
    $task = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => now()]);
    seedPagingLogs($task, 250);

    $page = visit(route('tasks.show', $task))
        ->assertSee('250 entries')
        ->assertDontSee('Step 50')
        ->assertSee('Step 250');

    expect($page->script('document.querySelectorAll(\'[data-testid="activity-log"] [data-log-id]\').length'))->toBe(200);

    $page->script('document.querySelector(\'[data-testid="activity-log"] [data-scroller]\').scrollTop = 0');
    $page->wait(1)->assertSee('Step 1');

    expect($page->script('document.querySelectorAll(\'[data-testid="activity-log"] [data-log-id]\').length'))->toBe(250);
    // The row that was at the top before the load is still in view.
    expect($page->script('(() => { const scroller = document.querySelector(\'[data-testid="activity-log"] [data-scroller]\'); const row = scroller.querySelector(\'[data-log-id][data-log-text="Step 51"]\'); const r = row.getBoundingClientRect(); const s = scroller.getBoundingClientRect(); return r.top >= s.top - 2 && r.top <= s.bottom; })()'))->toBeTrue();
});

test('new rows append on poll without moving a reader who scrolled up', function () {
    $this->actingAs(User::factory()->create());
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);
    seedPagingLogs($task, 30);

    $page = visit(route('tasks.show', $task))->assertSee('Step 30');
    $page->script('document.querySelector(\'[data-testid="activity-log"] [data-scroller]\').scrollTop = 0');

    TaskLog::factory()->create(['yak_task_id' => $task->id, 'attempt_number' => 1, 'message' => 'Step 31 arrived', 'metadata' => ['type' => 'tool_use', 'tool' => 'Bash', 'input' => ['command' => 'echo 31'], 'output' => '31']]);

    // The running task polls every 5 seconds.
    $page->wait(7)->assertSee('Step 31 arrived')->assertVisible('[data-testid="jump-to-latest"]');
    expect($page->script('document.querySelector(\'[data-testid="activity-log"] [data-scroller]\').scrollTop'))->toBeLessThan(50);

    $page->click('[data-testid="jump-to-latest"]');
    expect($page->script('(() => { const s = document.querySelector(\'[data-testid="activity-log"] [data-scroller]\'); return s.scrollHeight - s.scrollTop - s.clientHeight < 48; })()'))->toBeTrue();
});

test('a running task whose log starts empty shows its first row within one poll', function () {
    $this->actingAs(User::factory()->create());
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);

    $page = visit(route('tasks.show', $task))->assertNoJavaScriptErrors();

    TaskLog::factory()->create(['yak_task_id' => $task->id, 'attempt_number' => 1, 'message' => 'First row arrived', 'metadata' => ['type' => 'tool_use', 'tool' => 'Bash', 'input' => ['command' => 'echo 1'], 'output' => '1']]);

    // The running task polls every 5 seconds.
    $page->wait(7)->assertSee('First row arrived');
});

test('switching runs replaces the rows with the chosen run\'s log', function () {
    $this->actingAs(User::factory()->create());
    $root = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => now()->subHour()]);
    $followUp = YakTask::factory()->create(['parent_task_id' => $root->id, 'status' => TaskStatus::Success, 'started_at' => now()]);
    TaskLog::factory()->create(['yak_task_id' => $root->id, 'attempt_number' => 1, 'message' => 'Root run row', 'metadata' => ['type' => 'tool_use', 'tool' => 'Bash', 'input' => ['command' => 'echo root'], 'output' => 'root']]);
    TaskLog::factory()->create(['yak_task_id' => $followUp->id, 'attempt_number' => 1, 'message' => 'Follow-up run row', 'metadata' => ['type' => 'tool_use', 'tool' => 'Bash', 'input' => ['command' => 'echo follow'], 'output' => 'follow']]);

    $page = visit(route('tasks.show', $root))
        ->assertSeeIn('[data-testid="activity-log"]', 'Follow-up run row')
        ->assertDontSeeIn('[data-testid="activity-log"]', 'Root run row');

    $page->click("[data-testid=\"run-chip-{$root->id}\"]")
        ->wait(1)
        ->assertSeeIn('[data-testid="activity-log"]', 'Root run row')
        ->assertDontSeeIn('[data-testid="activity-log"]', 'Follow-up run row')
        ->assertNoJavaScriptErrors();
});
