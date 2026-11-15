<?php

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

const PUBLIC_APP_ROUTES = ['app.instance', 'app.device.code', 'app.device.token', 'app.device.pair'];

const REQUIRED_APP_MIDDLEWARE = ['client.protocol:app', 'auth.device:phone'];

/**
 * The app routes that miss the protocol check or the phone token, or that are not
 * controller actions (a closure would escape the visibility coverage and sweep).
 *
 * @return list<string>
 */
function offendingAppRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $route): bool => str_starts_with($route->uri(), 'api/app/v1/'))
        ->reject(fn (LaravelRoute $route): bool => in_array($route->getName(), PUBLIC_APP_ROUTES, true))
        ->reject(function (LaravelRoute $route): bool {
            $middleware = $route->gatherMiddleware();

            return is_string($route->getAction('uses'))
                && array_diff(REQUIRED_APP_MIDDLEWARE, $middleware) === []
                && array_intersect(REQUIRED_APP_MIDDLEWARE, $route->excludedMiddleware()) === [];
        })
        ->map(fn (LaravelRoute $route): string => $route->getName() ?? $route->uri())
        ->values()
        ->all();
}

it('puts every app route behind a phone token except discovery and pairing', function (): void {
    $offending = offendingAppRoutes();

    expect($offending)->toBe([], 'App routes without a phone token: ' . implode(', ', $offending));
});

it('flags a route that removes the phone token, and a closure route', function (): void {
    Route::middleware(REQUIRED_APP_MIDDLEWARE)->prefix('api/app/v1')->group(function (): void {
        Route::get('guard-probe-removed', [stdClass::class, 'index'])->withoutMiddleware('auth.device:phone')->name('guard.removed');
        Route::get('guard-probe-closure', fn (): string => 'x')->name('guard.closure');
        Route::get('guard-probe-ok', [stdClass::class, 'index'])->name('guard.ok');
    });

    expect(offendingAppRoutes())->toContain('guard.removed', 'guard.closure')->not->toContain('guard.ok');
});
