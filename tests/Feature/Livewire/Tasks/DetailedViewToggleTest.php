<?php

use App\Enums\TaskMode;
use App\Livewire\Tasks\TaskDetail;
use App\Models\TaskLog;
use App\Models\User;
use App\Models\YakTask;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

it('clamps the review PR body in condensed view and unclamps it in detailed view', function () {
    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'started_at' => now(),
        'context' => json_encode([
            'pr_number' => 2706,
            'title' => 'perf(geocoding): stop place lookups',
            'body' => "## Summary\n\n" . str_repeat('A long PR body that needs expanding. ', 40),
        ]),
    ]);

    $component = Livewire::test(TaskDetail::class, ['task' => $task]);

    expect($component->html())->toContain('data-testid="pr-body"')
        ->and($component->html())->toContain('line-clamp-3');

    expect($component->call('toggleDetailedView')->html())->not->toContain('line-clamp-3');
});

it('still groups consecutive thinking steps only in condensed view', function () {
    $task = YakTask::factory()->create(['started_at' => now()]);

    foreach (['thinking one', 'thinking two', 'thinking three'] as $message) {
        TaskLog::factory()->create([
            'yak_task_id' => $task->id,
            'attempt_number' => 1,
            'message' => $message,
            'level' => 'info',
            'metadata' => ['type' => 'assistant'],
        ]);
    }

    $component = Livewire::test(TaskDetail::class, ['task' => $task]);

    expect($component->html())->toContain('thinking-steps-badge');
    expect($component->call('toggleDetailedView')->html())->not->toContain('thinking-steps-badge');
});
