<?php

use App\Models\User;
use App\Models\YakTask;

test('task list page has no accessibility issues', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    YakTask::factory()->success()->create();
    YakTask::factory()->running()->create();
    YakTask::factory()->failed()->create();

    $page = visit(route('tasks'));

    $page->assertNoAccessibilityIssues();
});

test('task detail page has no accessibility issues', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $task = YakTask::factory()->success()->create([
        'description' => 'Accessibility test task',
        'result_summary' => 'Task completed successfully',
    ]);

    $page = visit(route('tasks.show', $task));

    $page->assertNoAccessibilityIssues();
});

test('task list page has no javascript errors', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    YakTask::factory()->count(3)->create();

    $page = visit(route('tasks'));

    $page->assertNoJavaScriptErrors();
});

test('task detail page has no javascript errors', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $task = YakTask::factory()->success()->create();

    $page = visit(route('tasks.show', $task));

    $page->assertNoJavaScriptErrors();
});

test('dashboard pages have no javascript errors', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    YakTask::factory()->success()->create();

    $pages = visit([
        route('tasks'),
        route('costs'),
        route('repos'),
        route('health'),
    ]);

    $pages->assertNoJavaScriptErrors();
});
