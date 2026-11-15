<?php

use App\Models\Artifact;
use App\Models\CoOwnerRequest;
use App\Models\Observation;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\VisibilityRoutes;

/**
 * Probes for every app route that takes `{task}`, except the signed media routes (they carry no phone token).
 *
 * @return array<string, array{method: string, path: string, isDrive: bool, body: array<string, mixed>}>
 */
function appTaskProbes(): array
{
    $probe = VisibilityRoutes::probe(...);

    return [
        'app.tasks.show' => $probe('GET', '/tasks/{task}', false),
        'app.tasks.co-owner.candidates' => $probe('GET', '/tasks/{task}/co-owner/candidates', false),
        'app.tasks.retry' => $probe('POST', '/tasks/{task}/retry', true),
        'app.tasks.cancel' => $probe('POST', '/tasks/{task}/cancel', true),
        'app.tasks.rerun-review' => $probe('POST', '/tasks/{task}/rerun-review', true),
        'app.tasks.retry-render' => $probe('POST', '/tasks/{task}/retry-render', true),
        'app.tasks.reroute' => $probe('POST', '/tasks/{task}/reroute', true, ['repo' => 'example/web']),
        'app.tasks.co-owner.approve' => $probe('POST', '/tasks/{task}/co-owner/approve', true),
        'app.tasks.co-owner.request-changes' => $probe('POST', '/tasks/{task}/co-owner/request-changes', true, ['feedback' => 'Please add a test.']),
        'app.tasks.co-owner.reject' => $probe('POST', '/tasks/{task}/co-owner/reject', true),
        'app.tasks.co-owner.withdraw' => $probe('POST', '/tasks/{task}/co-owner/withdraw', true),
        'app.tasks.co-owner.tag' => $probe('POST', '/tasks/{task}/co-owner/tag', true, ['user_id' => 1]),
        'app.tasks.co-owner.decline' => $probe('POST', '/tasks/{task}/co-owner/decline', true),
        'app.tasks.clarification-answers.store' => $probe('POST', '/tasks/{task}/clarification-answers', true, ['answers' => [], 'note' => 'Use the second option.']),
    ];
}

/**
 * A task on a private thread with a co-owner request and a requested render.
 *
 * @return array{owner: User, thread: Thread, task: YakTask}
 */
function sweepTask(): array
{
    $owner = User::factory()->create(['name' => 'Test person']);
    $thread = privateThreadOf($owner, ['title' => 'Retry failed uploads', 'repo' => 'example/api']);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'started_by_user_id' => $owner->id, 'description' => 'Hidden upgrade of the payments SDK', 'public_id' => 'k3m9p2qa']);
    CoOwnerRequest::factory()->create(['task_id' => $task->id]);
    Artifact::factory()->create(['yak_task_id' => $task->id]);

    return ['owner' => $owner, 'thread' => $thread, 'task' => $task];
}

function taskSweepRequest(array $probe, string $taskKey, array $headers): TestResponse
{
    $path = '/api/app/v1' . str_replace('{task}', $taskKey, $probe['path']);

    return test()->json($probe['method'], $path, $probe['body'], $headers);
}

/** @return array<string, mixed> */
function taskSweepState(): array
{
    return [
        'tasks' => DB::table('tasks')->pluck('status', 'id')->all(),
        'requests' => DB::table('co_owner_requests')->get()->map(fn (object $row): array => (array) $row)->all(),
        'renders' => DB::table('artifacts')->pluck('render_requested_at', 'id')->all(),
    ];
}

it('probes every app task route', function () {
    $routes = VisibilityRoutes::applicationRoutes();
    $taskRoutes = collect(array_keys($routes))
        ->filter(fn (string $name): bool => str_starts_with($name, 'app.tasks.') || in_array($name, ['app.observations.index', 'app.media.task-attachments', 'app.media.pr-images'], true))
        ->values()->all();
    $classified = VisibilityRoutes::groupNames('classified');

    expect(array_values(array_diff($taskRoutes, $classified)))->toBe([]);

    $needsTask = collect($taskRoutes)
        ->filter(fn (string $name): bool => str_contains($routes[$name]->uri(), '{task}') && ! str_starts_with($name, 'app.media.'))
        ->values()->all();

    expect(array_values(array_diff($needsTask, array_keys(appTaskProbes()))))->toBe([])
        ->and(array_values(array_diff(array_keys(appTaskProbes()), $taskRoutes)))->toBe([]);
});

it('answers a stranger on a private thread task exactly like a missing task', function (string $name) {
    ['task' => $task] = sweepTask();
    $probe = appTaskProbes()[$name];
    $headers = phoneHeadersFor(User::factory()->create());

    $missing = taskSweepRequest($probe, 'zzzzzzzz', $headers)->assertNotFound();
    taskSweepRequest($probe, $task->public_id, $headers)->assertNotFound()->assertExactJson($missing->json());
    taskSweepRequest($probe, (string) $task->id, $headers)->assertNotFound()->assertExactJson($missing->json());
})->with(array_keys(appTaskProbes()));

it('refuses a share recipient on every task action', function (string $name) {
    Queue::fake();
    ['owner' => $owner, 'thread' => $thread, 'task' => $task] = sweepTask();
    $recipient = shareThreadWith($thread, $owner);
    $before = taskSweepState();

    taskSweepRequest(appTaskProbes()[$name], $task->public_id, phoneHeadersFor($recipient))
        ->assertForbidden()->assertJsonPath('error', 'forbidden');

    Queue::assertNothingPushed();
    expect(taskSweepState())->toBe($before);
})->with(array_keys(array_filter(appTaskProbes(), fn (array $probe): bool => $probe['isDrive'])));

it('lets a share recipient read the task', function (string $name) {
    ['owner' => $owner, 'thread' => $thread, 'task' => $task] = sweepTask();
    $recipient = shareThreadWith($thread, $owner);

    taskSweepRequest(appTaskProbes()[$name], $task->public_id, phoneHeadersFor($recipient))->assertSuccessful();
})->with(['app.tasks.show', 'app.tasks.co-owner.candidates']);

it('keeps hidden tasks out of the lists', function () {
    ['task' => $task] = sweepTask();
    Observation::factory()->create(['yak_task_id' => $task->id, 'summary' => 'Hidden link']);
    $stranger = User::factory()->create();
    $headers = phoneHeadersFor($stranger);
    $own = YakTask::factory()->create(['started_by_user_id' => $stranger->id, 'description' => 'Visible cleanup', 'thread_id' => null]);

    $bodies = [];
    foreach (['tasks', 'reviews', 'setup'] as $tab) {
        $bodies[] = $this->getJson("/api/app/v1/tasks?tab={$tab}", $headers)->assertOk();
    }
    $bodies[] = $this->getJson('/api/app/v1/tasks?search=Hidden', $headers)->assertOk();
    $bodies[] = $this->getJson('/api/app/v1/observations', $headers)->assertOk();

    foreach ($bodies as $response) {
        expect($response->getContent())->not->toContain('Hidden upgrade of the payments SDK')->not->toContain($task->public_id);
    }
    expect($bodies[0]->getContent())->toContain($own->public_id)
        ->and($bodies[0]->json('counts.tasks'))->toBe(1)
        ->and($bodies[3]->json('tasks'))->toBe([])
        ->and($bodies[4]->json('observations'))->each->toHaveKey('taskId', null);
});
