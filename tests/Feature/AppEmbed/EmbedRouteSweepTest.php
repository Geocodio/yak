<?php

use App\Http\Middleware\AppEmbedSession;
use App\Support\AppEmbedRoutes;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->withoutVite();
    // The HTTP kernel copies the web group onto the router when it is built; with telemetry off nothing built it yet.
    app(Kernel::class);
});

/**
 * A concrete request path for the route: each placeholder gets a value for which the router still picks this
 * very route (so a parameter constraint never turns the probe into an accidental 404).
 */
function embedSweepUri(LaravelRoute $route): ?string
{
    $method = collect($route->methods())->first(fn (string $method): bool => ! in_array($method, ['HEAD', 'OPTIONS'], true)) ?? 'GET';
    preg_match_all('/\{([^}?]+)\??\}/', $route->uri(), $found);
    $candidates = ['aaaaaaaa', '1', '12345', 'abc', 'a.b', 'example', 'a/b', 'overview', 'review', 'traces', 'logs', 'metrics', 'spans', '00000000-0000-4000-8000-000000000000', str_repeat('a', 26)];
    $assignments = [[]];

    foreach ($found[1] as $parameter) {
        $assignments = array_merge(...array_map(fn (array $assigned): array => array_map(fn (string $candidate): array => [...$assigned, $parameter => $candidate], $candidates), $assignments));
    }

    foreach ($assignments as $values) {
        $uri = '/' . ltrim((string) preg_replace_callback('/\{([^}?]+)\??\}/', fn (array $match): string => $values[$match[1]], $route->uri()), '/');

        try {
            $matched = Route::getRoutes()->match(Request::create($uri, $method));
        } catch (Throwable) {
            continue;
        }

        if ($matched->uri() === $route->uri() && $matched->getActionName() === $route->getActionName()) {
            return $method . ' ' . $uri;
        }
    }

    return null;
}

/**
 * @return list<LaravelRoute>
 */
function embedSweepRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        fn (LaravelRoute $route): bool => ! in_array($route->getName(), [...AppEmbedRoutes::ALLOWED, 'app.embed.session'], true)
            && $route->uri() !== AppEmbedRoutes::BROADCAST_AUTH_URI,
    ));
}

/**
 * @return list<class-string|string>
 */
function embedSweepMiddleware(LaravelRoute $route): array
{
    return array_map(fn (mixed $middleware): string => is_string($middleware) ? $middleware : $middleware::class, app('router')->gatherRouteMiddleware($route));
}

it('answers 404 to an embed session on every route outside the allow-list', function () {
    [$user, $device] = embedPhone();
    $session = embedSessionData($user, $device);
    $probed = 0;
    $unprobeable = [];
    $notFound = [];
    $stateless = [];

    foreach (embedSweepRoutes() as $route) {
        $middleware = embedSweepMiddleware($route);

        if (! in_array(AppEmbedSession::class, $middleware, true)) {
            // A route without the web state never sees a session, so a cookie cannot sign anyone in there.
            if (in_array(StartSession::class, $middleware, true)) {
                $stateless[] = $route->getName() ?? $route->uri();
            }

            continue;
        }

        $target = embedSweepUri($route);

        if ($target === null) {
            $unprobeable[] = $route->getName() ?? $route->uri();

            continue;
        }

        [$method, $uri] = explode(' ', $target, 2);
        actingAsEmbed($session);
        $status = $this->call($method, $uri)->status();
        $probed++;

        if ($status !== 404) {
            $notFound[] = "{$method} {$uri} answered {$status}";
        }
    }

    expect($unprobeable)->toBe([], 'no probe path found for: ' . implode(', ', $unprobeable))
        ->and($stateless)->toBe([], 'a session without the embed middleware on: ' . implode(', ', $stateless))
        ->and($notFound)->toBe([], 'reachable from an embed session: ' . implode(', ', $notFound))
        ->and($probed)->toBeGreaterThan(100);
});

it('answers 404 on the routes that must never be reachable', function (string $name) {
    [$user, $device] = embedPhone();
    $route = Route::getRoutes()->getByName($name);

    expect($route)->not->toBeNull();

    $target = embedSweepUri($route);
    expect($target)->not->toBeNull();

    [$method, $uri] = explode(' ', (string) $target, 2);
    actingAsEmbed(embedSessionData($user, $device));
    $this->call($method, $uri)->assertNotFound();
})->with([
    'device.approve',
    'device.deny',
    'settings.devices.pairing-codes.store',
    'settings.devices.destroy',
    'settings.ssh-keys.store',
    'profile.email-addresses.store',
    'settings.people.update',
    'account.destroy',
    'auth.google',
    'login',
    'letmein',
    'mcp.store',
    'threads.messages.store',
    'threads.agents.stop',
    'threads.agents.stop-all',
    'threads.agents.messages.store',
    'threads.step-limit.store',
    'threads.destroy',
    'threads.close',
    'threads.star',
    'threads.share.update',
    'threads.terminal-sessions.store',
    'threads.show',
    'deployments.destroy',
    'deployments.share.store',
    'deployments.share.destroy',
    'deployments.rebuild',
    'deployments.hibernation.update',
    'repos.manifest.update',
    'pr-reviews.for-pr.rerun',
    'pr-reviews.for-pr.runs.rerun',
    'deployments',
    'deployments.show',
    'pr-reviews',
    'pr-reviews.for-pr',
]);

it('keeps the sensitive routes off the allow-list', function () {
    $sensitive = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $route): bool => preg_match('/^(settings|profile|device|account|auth|mcp|skills|marketplaces|prompts|repos|deployments)\b|^(login|letmein|threads\.(messages|destroy|close|star|share\.|files|terminal|teleport|stack\.(children|take|split|drop|stop|limit)))/', (string) $route->getName()) === 1)
        ->map(fn (LaravelRoute $route): string => (string) $route->getName())
        ->all();

    expect(array_values(array_intersect($sensitive, AppEmbedRoutes::ALLOWED)))->toBe([]);
});

it('puts auth:app-embed on every app/embed route that is not the hand-off', function () {
    $embedRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn (LaravelRoute $route): bool => str_starts_with($route->uri(), 'app/embed/') && $route->getName() !== 'app.embed.session');

    expect($embedRoutes)->not->toBeEmpty();
    foreach ($embedRoutes as $route) {
        expect($route->gatherMiddleware())->toContain('auth:app-embed');
    }
});

it('names the hand-off route outside the allow-list and without auth', function () {
    $route = Route::getRoutes()->getByName('app.embed.session');

    expect($route->methods())->toBe(['POST'])->and(AppEmbedRoutes::ALLOWED)->not->toContain('app.embed.session')
        ->and($route->gatherMiddleware())->toContain('throttle:app-embed-redeem')->not->toContain('auth');
});

it('every allowed name exists', function () {
    $names = collect(Route::getRoutes()->getRoutes())->map(fn (LaravelRoute $route) => $route->getName())->filter()->all();

    expect(array_values(array_diff(AppEmbedRoutes::ALLOWED, $names)))->toBe([]);
});

it('keeps the web group on every browser route so a new route cannot skip the embed check', function () {
    $without = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $route): bool => in_array(StartSession::class, embedSweepMiddleware($route), true) && ! in_array(AppEmbedSession::class, embedSweepMiddleware($route), true))
        ->map(fn (LaravelRoute $route): string => $route->getName() ?? $route->uri())
        ->all();

    expect($without)->toBe([]);
});
