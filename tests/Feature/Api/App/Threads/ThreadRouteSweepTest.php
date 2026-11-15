<?php

use App\Enums\RepositorySetupStatus;
use App\Enums\ThreadShareMode;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadSharing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\VisibilityRoutes;
use Tests\TestCase;

beforeEach(fn () => fakeApns(preventStrayRequests: false));

/**
 * Adds placeholders to a set, refusing a key that is already set so a group cannot
 * silently replace a core value or another group's value.
 *
 * @param  array<string, string>  $existing
 * @param  array<string, string>  $added
 * @return array<string, string>
 */
function mergeSweepPlaceholders(array $existing, array $added): array
{
    foreach (array_keys($added) as $key) {
        if (array_key_exists($key, $existing)) {
            throw new InvalidArgumentException("Sweep placeholder {$key} is already set.");
        }
    }

    return [...$existing, ...$added];
}

/**
 * @param  array{method: string, path: string, isDrive: bool, body: array<string, mixed>}  $probe
 * @param  array<string, string>  $headers
 * @param  array<string, string>  $placeholders  `{name}` => value, from the world's group closures
 */
function appRouteSweepRequest(TestCase $test, array $probe, string $threadKey, array $headers, array $placeholders = []): TestResponse
{
    $replacements = mergeSweepPlaceholders(['{thread}' => $threadKey, '{message}' => '6f1d7c0e-5b7a-4a52-9c1e-0d2f3a4b5c6d'], $placeholders);
    $path = '/api/app/v1' . strtr($probe['path'], $replacements);

    return $test->json($probe['method'], $path, $probe['body'], [...VisibilityRoutes::groupHeaders(), ...$headers]);
}

/**
 * The sweep's world: one private thread with a task, an event and the shared
 * repository row `example/web` (setup failed). Group files add rows through
 * their `sweepWorld` closures and return placeholders.
 *
 * @return array{thread: Thread, owner: User, repository: ThreadRepository, placeholders: array<string, string>}
 */
function sweepThread(): array
{
    $owner = User::factory()->create(['name' => 'Test person']);
    $thread = privateThreadOf($owner, ['title' => 'Retry failed uploads', 'repo' => 'example/api']);
    YakTask::factory()->create(['thread_id' => $thread->id, 'started_by_user_id' => $owner->id]);

    ThreadEvent::query()->create(['thread_id' => $thread->id, 'sequence' => 1, 'type' => 'turn', 'payload' => ['turnId' => 1, 'state' => 'completed'], 'created_at' => now()]);

    $repository = ThreadRepository::factory()->create([
        'thread_id' => $thread->id,
        'repo' => 'example/web',
        'setup_status' => RepositorySetupStatus::Failed,
    ]);

    $world = ['thread' => $thread, 'owner' => $owner, 'repository' => $repository];
    $placeholders = ['{repository}' => (string) $repository->id];

    foreach (VisibilityRoutes::groupWorlds() as $extendWorld) {
        $placeholders = mergeSweepPlaceholders($placeholders, $extendWorld($world));
    }

    return [...$world, 'placeholders' => $placeholders];
}

/** @return array<string, int> */
function sweepRowCounts(): array
{
    return collect(['thread_events', 'task_runs', 'pending_steering_messages', 'thread_turn_messages', ...VisibilityRoutes::groupNames('sweepTables')])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
        ->all();
}

it('refuses a sweep placeholder that is already set', function () {
    expect(fn () => mergeSweepPlaceholders(['{thread}' => 'a'], ['{thread}' => 'b']))->toThrow(InvalidArgumentException::class)
        ->and(mergeSweepPlaceholders(['{thread}' => 'a'], ['{other}' => 'b']))->toBe(['{thread}' => 'a', '{other}' => 'b']);
});

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
    ['thread' => $thread, 'placeholders' => $placeholders] = sweepThread();
    $probe = VisibilityRoutes::appThreadProbes()[$name];
    $headers = phoneHeadersFor(User::factory()->create());

    $missing = appRouteSweepRequest($this, $probe, 'zzzzzzzz', $headers, $placeholders)->assertNotFound();
    appRouteSweepRequest($this, $probe, $thread->public_id, $headers, $placeholders)->assertNotFound()->assertExactJson($missing->json());
    appRouteSweepRequest($this, $probe, (string) $thread->id, $headers, $placeholders)->assertNotFound()->assertExactJson($missing->json());
})->with(array_keys(VisibilityRoutes::appThreadProbes()));

it('refuses a share recipient on every action', function (string $name) {
    ['thread' => $thread, 'owner' => $owner, 'placeholders' => $placeholders] = sweepThread();
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $owner, ThreadShareMode::People, [$recipient->id]);
    $before = sweepRowCounts();

    appRouteSweepRequest($this, VisibilityRoutes::appThreadProbes()[$name], $thread->public_id, phoneHeadersFor($recipient), $placeholders)
        ->assertForbidden()->assertJsonPath('error', 'forbidden');

    expect(sweepRowCounts())->toBe($before);
})->with(array_keys(array_filter(VisibilityRoutes::appThreadProbes(), fn (array $probe): bool => $probe['isDrive'])));

it('lets a share recipient read', function (string $name) {
    ['thread' => $thread, 'owner' => $owner, 'placeholders' => $placeholders] = sweepThread();
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $owner, ThreadShareMode::People, [$recipient->id]);

    appRouteSweepRequest($this, VisibilityRoutes::appThreadProbes()[$name], $thread->public_id, phoneHeadersFor($recipient), $placeholders)->assertSuccessful();
})->with(array_keys(array_filter(
    VisibilityRoutes::appThreadProbes(),
    fn (array $probe, string $name): bool => ! $probe['isDrive'] && $name !== 'app.threads.share.show',
    ARRAY_FILTER_USE_BOTH,
)));

/** @return array<string, array{method: string, path: string, isDrive: bool, body: array<string, mixed>}> */
function overviewSweepProbes(): array
{
    return VisibilityRoutes::groupsIn()['overview']['appThreadProbes'];
}

it('hides every overview route from a stranger', function (string $name) {
    ['thread' => $thread, 'placeholders' => $placeholders] = sweepThread();
    $probe = overviewSweepProbes()[$name];
    $headers = phoneHeadersFor(User::factory()->create());
    $child = Thread::query()->where('parent_thread_id', $thread->id)->sole();
    $isChildRoute = str_contains($probe['path'], '{child}');
    unset($placeholders['{child}']);

    $request = fn (Thread|string $key): TestResponse => appRouteSweepRequest(
        $this,
        $probe,
        $isChildRoute ? $thread->public_id : ($key instanceof Thread ? $key->public_id : $key),
        $headers,
        $isChildRoute ? [...$placeholders, '{child}' => $key instanceof Thread ? $key->public_id : $key] : $placeholders,
    );

    $missing = $request('zzzzzzzz')->assertNotFound();
    $request($isChildRoute ? $child : $thread)->assertNotFound()->assertExactJson($missing->json());
    $numeric = $isChildRoute ? (string) $child->id : (string) $thread->id;
    $request($numeric)->assertNotFound()->assertExactJson($missing->json());
})->with(array_keys(overviewSweepProbes()));

it('changes nothing for a share recipient on an overview action', function (string $name) {
    Queue::fake();
    ['thread' => $thread, 'owner' => $owner, 'repository' => $repository, 'placeholders' => $placeholders] = sweepThread();
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $owner, ThreadShareMode::People, [$recipient->id]);
    $child = Thread::query()->where('parent_thread_id', $thread->id)->sole();
    $probe = overviewSweepProbes()[$name];
    $counts = sweepRowCounts();
    $columns = fn (): array => [
        $thread->fresh()->only(['stack_stopped_at', 'child_awake_limit']),
        $child->fresh()->only(['taken_over_at', 'split_requested_at', 'status']),
        $repository->fresh()->setup_status,
    ];
    $stored = $columns();

    $response = appRouteSweepRequest($this, $probe, $thread->public_id, phoneHeadersFor($recipient), $placeholders);

    if ($probe['isDrive']) {
        $response->assertForbidden()->assertJsonPath('error', 'forbidden')->assertJsonPath('message', 'You do not have access to this.');
    } else {
        $response->assertSuccessful();
    }

    expect(sweepRowCounts())->toBe($counts)->and($columns())->toEqual($stored);
    Queue::assertNothingPushed();
})->with(array_keys(overviewSweepProbes()));

it('probes the overview routes', function () {
    expect(array_keys(overviewSweepProbes()))->toHaveCount(9)
        ->and(array_diff(array_keys(overviewSweepProbes()), VisibilityRoutes::appThreads()))->toBe([]);
});
