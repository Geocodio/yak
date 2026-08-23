<?php

use App\Enums\TaskMode;
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

test('markdown in the thread strips raw HTML instead of rendering it', function () {
    $task = YakTask::factory()->create([
        'description' => "Before the script.\n\n<script>alert(1)</script>\n\nAfter the script.",
        'status' => TaskStatus::Success,
    ]);

    $response = $this->get(route('tasks.show', $task));

    $response->assertOk();
    $response->assertDontSee('<script>alert(1)</script>', false);
    $response->assertSee('Before the script.');
    $response->assertSee('After the script.');
});

test('review mode context turn renders PR number, author, title, and body from context', function () {
    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'context' => json_encode([
            'pr_number' => 42,
            'author' => 'octocat',
            'title' => 'Fix the flaky test',
            'body' => 'This stabilizes the retry logic.',
        ]),
    ]);

    $this->get(route('tasks.show', $task))
        ->assertSee('PR #42')
        ->assertSee('octocat')
        ->assertSee('Fix the flaky test')
        ->assertSee('This stabilizes the retry logic.');
});

test('review mode context turn renders without crashing when context is empty', function () {
    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'context' => null,
    ]);

    $response = $this->get(route('tasks.show', $task));

    $response->assertOk();
    $response->assertDontSee('PR #', false);
    $response->assertDontSee('opened by');
});

test('clarification turn shows the reply-by TTL while awaiting clarification', function () {
    $task = YakTask::factory()->create([
        'status' => TaskStatus::AwaitingClarification,
        'clarification_options' => ['Convert in place', 'Keep both'],
        'clarification_expires_at' => now()->addHours(3),
    ]);

    $this->get(route('tasks.show', $task))
        ->assertSee('expires');
});
