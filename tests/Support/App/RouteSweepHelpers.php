<?php

use App\Enums\RepositorySetupStatus;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Models\YakTask;
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
    return collect(['thread_events', 'task_runs', 'pending_steering_messages', 'thread_turn_messages', ...VisibilityRoutes::groupNames('sweepTables')])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
        ->all();
}
