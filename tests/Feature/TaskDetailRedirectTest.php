<?php

use App\Models\User;
use App\Models\YakTask;

test('child task URL redirects to root with turn anchor', function () {
    $this->actingAs(User::factory()->create());
    $root = YakTask::factory()->create();
    $child = YakTask::factory()->create(['parent_task_id' => $root->id]);

    $this->get(route('tasks.show', $child))
        ->assertRedirect(route('tasks.show', $root) . '#turn-' . $child->id);
});

test('root task renders', function () {
    $this->actingAs(User::factory()->create());
    $root = YakTask::factory()->create();

    $this->get(route('tasks.show', $root))->assertOk();
});
