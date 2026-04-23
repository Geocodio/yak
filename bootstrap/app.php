<?php

use App\Channels\Drone\PollCommand;
use App\Http\Middleware\RestrictToIngress;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        PollCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: ['127.0.0.1', '172.16.0.0/12']);
        $middleware->alias([
            'restrict-to-ingress' => RestrictToIngress::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Internal endpoints (forward_auth targets) must return 401, not a login redirect.
        $exceptions->render(function (AuthenticationException $e, Request $request): ?Response {
            if ($request->is('internal/*')) {
                return response('Unauthenticated.', 401);
            }

            return null;
        });
    })->create();
