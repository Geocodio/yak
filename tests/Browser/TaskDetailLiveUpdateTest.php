<?php

use App\Enums\TaskStatus;
use App\Models\User;
use App\Models\YakTask;

test('task detail page reflects status update without manual refresh', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $task = YakTask::factory()->running()->create([
        'description' => 'Implementing search feature',
    ]);

    $page = visit(route('tasks.show', $task));

    $page->assertSee('running')
        ->assertSee('Implementing search feature');

    $task->status = TaskStatus::Success;
    $task->result_summary = 'Search feature implemented successfully';
    $task->completed_at = now();
    $task->saveQuietly();

    $page->wait(16)
        ->assertSee('success')
        ->assertSee('Search feature implemented successfully');
});

test('task detail shows running status with pulse indicator', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $task = YakTask::factory()->running()->create([
        'description' => 'Running task test',
    ]);

    $page = visit(route('tasks.show', $task));

    $page->assertSee('running')
        ->assertPresent('.animate-pulse');
});
