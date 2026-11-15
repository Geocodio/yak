<?php

use App\Enums\RepositorySetupStatus;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadShareMode;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\ThreadSharing;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\VisibilityRoutes;
use Tests\TestCase;

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
    return collect(['thread_events', 'task_runs', 'pending_steering_messages', 'thread_turn_messages', 'thread_file_edits', ...VisibilityRoutes::groupNames('sweepTables')])
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

it('never wakes or writes for a share recipient', function (string $name) {
    ['thread' => $thread, 'owner' => $owner, 'placeholders' => $placeholders] = sweepThread();
    $thread->update(['sandbox_state' => ThreadSandboxState::Paused]);
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $owner, ThreadShareMode::People, [$recipient->id]);
    $sandboxes = app(SandboxManager::class);
    $callsBefore = count($sandboxes->calls);

    appRouteSweepRequest($this, VisibilityRoutes::appThreadProbes()[$name], $thread->public_id, phoneHeadersFor($recipient), $placeholders);

    $wakesAndWrites = collect(array_slice($sandboxes->calls, $callsBefore))
        ->filter(fn (array $call): bool => in_array($call['operation'], ['resume', 'start', 'create', 'destroy', 'stop'], true)
            || ($call['operation'] === 'exec' && (($call['arguments']['stdin'] ?? null) !== null || in_array('mv', $call['arguments']['command'], true))));

    expect($wakesAndWrites)->toBeEmpty()
        ->and($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Paused);
})->with(array_keys(array_filter(VisibilityRoutes::appThreadProbes(), fn (string $name): bool => str_contains($name, 'terminal') || str_contains($name, 'files'), ARRAY_FILTER_USE_KEY)));
