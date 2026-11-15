<?php

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

const PUBLIC_APP_ROUTES = ['app.instance', 'app.device.code', 'app.device.token', 'app.device.pair'];

it('puts every app route behind a phone token except discovery and pairing', function (): void {
    $offending = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $route): bool => str_starts_with($route->uri(), 'api/app/v1/'))
        ->reject(fn (LaravelRoute $route): bool => in_array($route->getName(), PUBLIC_APP_ROUTES, true))
        ->reject(function (LaravelRoute $route): bool {
            $middleware = $route->gatherMiddleware();

            return in_array('client.protocol:app', $middleware, true) && in_array('auth.device:phone', $middleware, true);
        })
        ->map(fn (LaravelRoute $route): string => $route->getName() ?? $route->uri())
        ->values()
        ->all();

    expect($offending)->toBe([], 'App routes without a phone token: ' . implode(', ', $offending));
});
