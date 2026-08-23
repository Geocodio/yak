<?php

use App\Enums\TaskStatus;
use App\Models\User;
use App\Models\YakTask;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

test('thread renders user and yak turns', function () {
    $task = YakTask::factory()->create([
        'description' => 'Fix the duplicate entry crash',
        'result_summary' => 'Guarded the insert with a validation rule.',
        'status' => TaskStatus::Success,
        'started_at' => now()->subMinutes(5),
        'completed_at' => now(),
    ]);

    $this->get(route('tasks.show', $task))
        ->assertSee('Fix the duplicate entry crash')
        ->assertSee('Guarded the insert with a validation rule.');
});

test('long description shows summary with expand affordance', function () {
    $task = YakTask::factory()->create([
        'description' => str_repeat('very long request ', 100),
        'description_summary' => 'Wants the crash fixed.',
    ]);

    $this->get(route('tasks.show', $task))
        ->assertSee('Wants the crash fixed.')
        ->assertSee('full request');
});

test('clarification options render as chips', function () {
    $task = YakTask::factory()->create([
        'status' => TaskStatus::AwaitingClarification,
        'clarification_options' => ['Convert in place', 'Keep both'],
    ]);

    $this->get(route('tasks.show', $task))
        ->assertSee('Convert in place')
        ->assertSee('Keep both');
});

test('intro banner is gone', function () {
    $task = YakTask::factory()->create();

    $this->get(route('tasks.show', $task))
        ->assertDontSee('data-testid="task-detail-intro"', false);
});
