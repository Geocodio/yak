<?php

use App\Models\Observation;
use App\Models\User;
use App\Models\YakTask;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('observations page is accessible at /observations', function () {
    $this->get('/observations')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Observations/Index'));
});

test('lists observations newest first', function () {
    Observation::factory()->create(['summary' => 'older', 'created_at' => now()->subHour()]);
    Observation::factory()->create(['summary' => 'newer', 'created_at' => now()]);

    $this->get(route('observations'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('observations.data', 2)
            ->where('observations.data.0.summary', 'newer')
            ->where('observations.data.1.summary', 'older'));
});

test('filters by outcome', function () {
    Observation::factory()->create(['summary' => 'acted on it']);
    Observation::factory()->declined()->create(['summary' => 'left it alone']);

    $this->get(route('observations', ['outcome' => 'declined']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('observations.data', 1)
            ->where('observations.data.0.summary', 'left it alone'));
});

test('filters by repository', function () {
    Observation::factory()->create(['repo' => 'acme/widgets', 'summary' => 'widgets']);
    Observation::factory()->create(['repo' => 'acme/gadgets', 'summary' => 'gadgets']);

    $this->get(route('observations', ['repo' => 'acme/gadgets']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('observations.data', 1)
            ->where('observations.data.0.summary', 'gadgets'));
});

test('links an observation to the task it produced', function () {
    $task = YakTask::factory()->pending()->create();
    Observation::factory()->create(['yak_task_id' => $task->id]);

    $this->get(route('observations'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('observations.data.0.taskId', $task->id)
            ->where('observations.data.0.taskUrl', route('tasks.show', $task->id)));
});

test('names each kind in a human label', function () {
    Observation::factory()->create(['kind' => 'flaky_test.existing_pr']);

    $this->get(route('observations'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('observations.data.0.kindLabel', 'PR already out'));
});
