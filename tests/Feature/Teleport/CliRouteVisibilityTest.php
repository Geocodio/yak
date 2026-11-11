<?php

use App\Enums\TeleportDirection;
use App\Enums\TeleportState;
use App\Exceptions\TeleportError;
use App\Models\Device;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\ThreadSession;
use App\Services\Teleport\TeleportAuthorizer;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/**
 * Every route this track registers under the CLI prefix that names a thread or a teleport.
 *
 * @return list<array{0: string, 1: string}>
 */
function cliThreadRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var LaravelRoute $route */
        $uri = $route->uri();

        if (! str_starts_with($uri, 'api/cli/v1/threads') && ! str_starts_with($uri, 'api/cli/v1/teleports')) {
            continue;
        }

        $method = collect($route->methods())->first(fn (string $method): bool => ! in_array($method, ['HEAD', 'OPTIONS'], true));
        $routes[] = [(string) $method, $uri];
    }

    return $routes;
}

it('answers a private thread of someone else like a missing thread on every thread route', function () {
    [, $outsiderToken] = makeDevice();
    [, $secondUserToken] = makeDevice();
    $private = Thread::factory()->private()->create();
    $session = ThreadSession::factory()->create(['thread_id' => $private->id]);
    $body = ['session_id' => $session->session_id, 'rel_path' => $session->session_id . '.jsonl'];
    $checked = 0;

    foreach (cliThreadRoutes() as [$method, $uri]) {
        if (! str_contains($uri, '{thread}') && ! str_contains($uri, '{sessionId}')) {
            continue;
        }

        $url = fn (int $threadId): string => '/' . str_replace(['{thread}', '{sessionId}'], [(string) $threadId, $session->session_id], $uri);
        $missing = $this->json($method, $url(999999), $body, cliHeaders($outsiderToken));
        $missing->assertNotFound();

        foreach ([$outsiderToken, $secondUserToken] as $token) {
            $hidden = $this->json($method, $url($private->id), $body, cliHeaders($token));

            expect($hidden->status())->toBe(404, "{$method} {$uri}")
                ->and($hidden->json('error'))->toBe('thread_not_found', "{$method} {$uri}");
        }

        $checked++;
    }

    expect($checked)->toBeGreaterThan(0);
});

it('answers another device\'s teleport as not found on every teleport route', function () {
    [$owner] = makeDevice();
    [, $otherToken] = makeDevice($owner->user);
    $teleport = Teleport::factory()->create(['device_id' => $owner->id, 'thread_id' => Thread::factory()]);

    foreach (cliThreadRoutes() as [$method, $uri]) {
        if (! str_contains($uri, '{teleport}')) {
            continue;
        }

        $response = $this->json($method, '/' . str_replace('{teleport}', $teleport->id, $uri), ['reason' => 'error'], cliHeaders($otherToken));

        expect($response->status())->toBe(404, "{$method} {$uri}")
            ->and($response->json('error'))->toBe('teleport_not_found', "{$method} {$uri}");
    }
});

it('lets a session call through only with an open teleport of this device', function (TeleportDirection $direction, TeleportState $state, bool $write, bool $allowed) {
    $device = Device::factory()->create();
    $thread = Thread::factory()->create();
    Teleport::factory()->create(['thread_id' => $thread->id, 'device_id' => $device->id, 'direction' => $direction, 'state' => $state]);

    $call = fn () => app(TeleportAuthorizer::class)->forSession($device, $thread, $write);

    if ($allowed) {
        expect($call())->toBeInstanceOf(Teleport::class);

        return;
    }

    expect($call)->toThrow(TeleportError::class);
})->with([
    'write up open' => [TeleportDirection::Up, TeleportState::Open, true, true],
    'write up preparing' => [TeleportDirection::Up, TeleportState::Preparing, true, false],
    'write down ready' => [TeleportDirection::Down, TeleportState::Ready, true, false],
    'read up open' => [TeleportDirection::Up, TeleportState::Open, false, true],
    'read up preparing' => [TeleportDirection::Up, TeleportState::Preparing, false, true],
    'read down ready' => [TeleportDirection::Down, TeleportState::Ready, false, true],
    'read down preparing' => [TeleportDirection::Down, TeleportState::Preparing, false, false],
    'read up completed' => [TeleportDirection::Up, TeleportState::Completed, false, false],
]);

it('rejects another device and a missing teleport', function () {
    $device = Device::factory()->create();
    $thread = Thread::factory()->create();
    Teleport::factory()->create(['thread_id' => $thread->id]);

    expect(fn () => app(TeleportAuthorizer::class)->forSession($device, $thread, false))
        ->toThrow(TeleportError::class, 'no open teleport');
});
