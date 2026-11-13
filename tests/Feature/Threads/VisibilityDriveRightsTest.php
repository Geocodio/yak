<?php

use App\Models\PendingSteeringMessage;
use App\Models\YakTask;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Support\VisibilityRoutes;
use Tests\Support\VisibilityWorld;

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
    Bus::fake();
    Http::fake();
    Process::fake();
});

dataset('mutatingTaskProbes', function () {
    foreach (VisibilityRoutes::probeNames() as $name) {
        if (str_starts_with($name, 'tasks.') && ! in_array($name, ['tasks.show', 'tasks.log-entries', 'tasks.pr-images.show'], true)) {
            yield $name => [$name];
        }
    }
});

it('refuses a share recipient on every mutating task route', function (string $name) {
    $world = VisibilityWorld::build();
    $probe = VisibilityRoutes::probes($world)[$name];
    $status = $world->task->fresh()->status;
    $messages = PendingSteeringMessage::count();

    $this->actingAs($world->sharedViewer)->call($probe['method'], $probe['uri'], $probe['payload'])->assertForbidden();

    expect($world->task->fresh()->status)->toBe($status)
        ->and(PendingSteeringMessage::count())->toBe($messages);
    Queue::assertNothingPushed();
})->with('mutatingTaskProbes');

it('lets the owner through a mutating task route', function (string $name) {
    $world = VisibilityWorld::build();
    $probe = VisibilityRoutes::probes($world)[$name];

    $status = $this->actingAs($world->owner)->call($probe['method'], $probe['uri'], $probe['payload'])->getStatusCode();

    expect($status)->not->toBe(403);
})->with('mutatingTaskProbes');

it('lets a teammate drive a team thread task', function (string $name) {
    $world = VisibilityWorld::forTeamControl();
    $probe = VisibilityRoutes::probes($world)[$name];

    $status = $this->actingAs($world->other)->call($probe['method'], $probe['uri'], $probe['payload'])->getStatusCode();

    expect($status)->not->toBe(403);
})->with('mutatingTaskProbes');

it('does not gate a task without a thread', function () {
    $world = VisibilityWorld::build();
    $task = YakTask::factory()->create(['thread_id' => null]);

    $this->actingAs($world->sharedViewer)->post("/tasks/{$task->public_id}/cancel")->assertStatus(302);
});

it('keeps task reads open to a share recipient', function () {
    $world = VisibilityWorld::build();

    $this->actingAs($world->sharedViewer)->get("/tasks/{$world->task->public_id}")->assertOk();
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
