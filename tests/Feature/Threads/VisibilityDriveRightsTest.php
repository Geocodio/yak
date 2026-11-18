<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Support\VisibilityWorld;

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
    Bus::fake();
    Http::fake();
    Process::fake();
});

it('keeps task reads open to a share recipient', function () {
    $world = VisibilityWorld::build();

    $this->actingAs($world->sharedViewer)->get("/tasks/{$world->task->public_id}")->assertRedirect(route('threads.show', $world->thread));
    $this->actingAs($world->sharedViewer)->get(route('threads.show', $world->thread))->assertOk();
    expect($this->actingAs($world->sharedViewer)->get("/tasks/{$world->task->public_id}/pr-images/abc123")->getStatusCode())->toBe(404);
});

it('gates every mutating task route with task.drive', function () {
    $ungated = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'tasks.')
            && str_contains($route->uri(), '{task}')
            && array_diff($route->methods(), ['GET', 'HEAD']) !== [])
        ->reject(fn ($route) => in_array('task.drive', $route->gatherMiddleware(), true))
        ->map(fn ($route) => $route->getName())
        ->values()->all();

    expect($ungated)->toBe([]);
});
