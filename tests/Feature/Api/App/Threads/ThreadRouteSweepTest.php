<?php

use App\Enums\ThreadShareMode;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadSharing;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\VisibilityRoutes;
use Tests\TestCase;

/**
 * @param  array{method: string, path: string, isDrive: bool, body: array<string, mixed>}  $probe
 * @param  array<string, string>  $headers
 */
function appRouteSweepRequest(TestCase $test, array $probe, string $threadKey, array $headers): TestResponse
{
    $path = '/api/app/v1' . str_replace(['{thread}', '{message}'], [$threadKey, '6f1d7c0e-5b7a-4a52-9c1e-0d2f3a4b5c6d'], $probe['path']);

    return $test->json($probe['method'], $path, $probe['body'], $headers);
}

/** @return array{0: Thread, 1: User} */
function sweepThread(): array
{
    $owner = User::factory()->create(['name' => 'Test person']);
    $thread = privateThreadOf($owner, ['title' => 'Retry failed uploads', 'repo' => 'example/api']);
    YakTask::factory()->create(['thread_id' => $thread->id, 'started_by_user_id' => $owner->id]);

    ThreadEvent::query()->create(['thread_id' => $thread->id, 'sequence' => 1, 'type' => 'turn', 'payload' => ['turnId' => 1, 'state' => 'completed'], 'created_at' => now()]);

    return [$thread, $owner];
}

/** @return array<string, int> */
function sweepRowCounts(): array
{
    return collect(['thread_events', 'task_runs', 'pending_steering_messages', 'thread_turn_messages'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
        ->all();
}

it('probes every app thread route', function () {
    $routes = VisibilityRoutes::applicationRoutes();
    $threadRoutes = collect(array_keys($routes))
        ->filter(fn (string $name): bool => str_starts_with($name, 'app.threads.') || $name === 'app.broadcasting.auth')
        ->values()->all();
    $probed = array_keys(VisibilityRoutes::appThreadProbes());

    expect(array_values(array_diff($threadRoutes, VisibilityRoutes::appThreads())))->toBe([])
        ->and(array_values(array_diff($probed, VisibilityRoutes::appThreads())))->toBe([]);

    $needsThread = collect($threadRoutes)->filter(fn (string $name): bool => str_contains($routes[$name]->uri(), '{thread}'))->values()->all();
    expect(array_values(array_diff($needsThread, $probed)))->toBe([]);
});

it('answers a stranger on a private thread exactly like a missing thread', function (string $name) {
    [$thread] = sweepThread();
    $probe = VisibilityRoutes::appThreadProbes()[$name];
    $headers = phoneHeadersFor(User::factory()->create());

    $missing = appRouteSweepRequest($this, $probe, 'zzzzzzzz', $headers)->assertNotFound();
    appRouteSweepRequest($this, $probe, $thread->public_id, $headers)->assertNotFound()->assertExactJson($missing->json());
    appRouteSweepRequest($this, $probe, (string) $thread->id, $headers)->assertNotFound()->assertExactJson($missing->json());
})->with(array_keys(VisibilityRoutes::appThreadProbes()));

it('refuses a share recipient on every action', function (string $name) {
    [$thread, $owner] = sweepThread();
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $owner, ThreadShareMode::People, [$recipient->id]);
    $before = sweepRowCounts();

    appRouteSweepRequest($this, VisibilityRoutes::appThreadProbes()[$name], $thread->public_id, phoneHeadersFor($recipient))
        ->assertForbidden()->assertJsonPath('error', 'forbidden');

    expect(sweepRowCounts())->toBe($before);
})->with(array_keys(array_filter(VisibilityRoutes::appThreadProbes(), fn (array $probe): bool => $probe['isDrive'])));

it('lets a share recipient read', function (string $name) {
    [$thread, $owner] = sweepThread();
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $owner, ThreadShareMode::People, [$recipient->id]);

    appRouteSweepRequest($this, VisibilityRoutes::appThreadProbes()[$name], $thread->public_id, phoneHeadersFor($recipient))->assertSuccessful();
})->with(array_keys(array_filter(
    VisibilityRoutes::appThreadProbes(),
    fn (array $probe, string $name): bool => ! $probe['isDrive'] && $name !== 'app.threads.share.show',
    ARRAY_FILTER_USE_BOTH,
)));
