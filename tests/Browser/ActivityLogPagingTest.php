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

test('the stream starts with the newest 200 rows and loads older rows on request', function () {
    $this->actingAs(User::factory()->create());
    $task = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => now()]);
    seedPagingLogs($task, 250);

    $page = visit(route('tasks.show', $task))
        ->assertSee('Ran 250 commands')
        ->click('[data-testid="finished-summary"] > summary')
        ->click('[data-testid="step-group"] > summary')
        ->assertSee('Load 50 earlier steps')
        ->assertSee('echo 250');

    expect($page->script('document.querySelectorAll(\'[data-testid="step-row"]\').length'))->toBe(200);

    $page->click('[data-testid="load-earlier"]')->wait(1)->assertSee('echo 1');

    expect($page->script('document.querySelectorAll(\'[data-testid="step-row"]\').length'))->toBe(250);
    expect($page->script('document.querySelectorAll(\'[data-testid="load-earlier"]\').length'))->toBe(0);
});

test('new rows append on poll', function () {
    $this->actingAs(User::factory()->create());
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);
    seedPagingLogs($task, 30);

    $page = visit(route('tasks.show', $task))->assertSee('echo 30');

    TaskLog::factory()->create(['yak_task_id' => $task->id, 'attempt_number' => 1, 'message' => 'Step 31 arrived', 'metadata' => ['type' => 'tool_use', 'tool' => 'Bash', 'input' => ['command' => 'echo 31'], 'output' => '31']]);

    // The running task polls every 5 seconds.
    $page->wait(7)->assertSee('echo 31')->assertNoJavaScriptErrors();
});

test('a running task whose log starts empty shows its first row within one poll', function () {
    $this->actingAs(User::factory()->create());
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);

    $page = visit(route('tasks.show', $task))->assertNoJavaScriptErrors();

    TaskLog::factory()->create(['yak_task_id' => $task->id, 'attempt_number' => 1, 'message' => 'First row arrived', 'metadata' => ['type' => 'tool_use', 'tool' => 'Bash', 'input' => ['command' => 'echo 1'], 'output' => '1']]);

    // The running task polls every 5 seconds.
    $page->wait(7)->assertSee('echo 1');
});

test('switching runs replaces the stream with the chosen run\'s log', function () {
    $this->actingAs(User::factory()->create());
    $root = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => now()->subHour(), 'result_summary' => 'Root run done']);
    $followUp = YakTask::factory()->create(['parent_task_id' => $root->id, 'status' => TaskStatus::Success, 'started_at' => now(), 'result_summary' => 'Follow-up run done']);
    TaskLog::factory()->create(['yak_task_id' => $root->id, 'attempt_number' => 1, 'message' => 'Root run row', 'metadata' => ['type' => 'tool_use', 'tool' => 'Bash', 'input' => ['command' => 'echo root'], 'output' => 'root']]);
    TaskLog::factory()->create(['yak_task_id' => $followUp->id, 'attempt_number' => 1, 'message' => 'Follow-up run row', 'metadata' => ['type' => 'tool_use', 'tool' => 'Bash', 'input' => ['command' => 'echo follow'], 'output' => 'follow']]);

    $page = visit(route('tasks.show', $root))
        ->click('[data-testid="finished-summary"] > summary')
        ->click('[data-testid="step-group"] > summary')
        ->assertSeeIn('[data-testid="activity-stream"]', 'echo follow')
        ->assertDontSeeIn('[data-testid="activity-stream"]', 'echo root');

    $page->click("[data-testid=\"show-run-{$root->public_id}\"]")
        ->wait(1)
        ->click('[data-testid="finished-summary"] > summary')
        ->click('[data-testid="step-group"] > summary')
        ->assertSeeIn('[data-testid="activity-stream"]', 'echo root')
        ->assertDontSeeIn('[data-testid="activity-stream"]', 'echo follow')
        ->assertNoJavaScriptErrors();
});
