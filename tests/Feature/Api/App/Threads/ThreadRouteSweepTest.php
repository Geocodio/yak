<?php

use App\Enums\ThreadShareMode;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadSharing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\VisibilityRoutes;

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
