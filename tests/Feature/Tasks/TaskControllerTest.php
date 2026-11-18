<?php

use App\Models\TaskLog;
use App\Models\User;
use App\Models\YakTask;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('it requires authentication', function () {
    auth()->logout();
    $task = YakTask::factory()->create();

    $this->get(route('tasks.show', $task))->assertRedirect(route('login'));
});

/**
 * The build's actual Inertia asset version, needed to make a partial
 * reload request -- an empty or stale version gets a 409 conflict instead
 * of a page response.
 */
function inertiaVersion(YakTask $task): string
{
    return (string) test()->get(route('tasks.show', $task), ['X-Inertia' => 'true'])->headers->get('X-Inertia-Version');
}

function seedLogs(YakTask $task, int $count, int $attempt = 1): void
{
    $rows = [];
    foreach (range(1, $count) as $index) {
        $rows[] = [
            'yak_task_id' => $task->id,
            'attempt_number' => $attempt,
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
