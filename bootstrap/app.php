<?php

use App\Channels\Drone\PollCommand;
use App\Exceptions\TeleportError;
use App\Http\Middleware\AuthenticateDevice;
use App\Http\Middleware\CliProtocol;
use App\Http\Middleware\EnsureTaskDriveRights;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RestrictToIngress;
use App\Http\Middleware\VerifySessionStoreToken;
use App\Http\Middleware\VerifySshInternalToken;
use App\Http\Responses\CliError;
use App\Services\PreviewAccess;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(__DIR__ . '/../routes/channels.php', ['middleware' => ['web', 'auth']])
    ->withCommands([
        PollCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: ['127.0.0.1', '172.16.0.0/12']);
        $middleware->alias([
            'restrict-to-ingress' => RestrictToIngress::class,
            'session-store-token' => VerifySessionStoreToken::class,
            'ssh-internal-token' => VerifySshInternalToken::class,
            'cli.protocol' => CliProtocol::class,
            'auth.device' => AuthenticateDevice::class,
            'task.drive' => EnsureTaskDriveRights::class,
        ]);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: CliProtocol::class);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: AuthenticateDevice::class);
        // The preview cookie is minted as a raw Set-Cookie header on the preview host, so it is never Laravel-encrypted.
        $middleware->encryptCookies(except: [PreviewAccess::COOKIE_NAME]);
        $middleware->validateCsrfTokens(except: ['api/cli/*', 'internal/*']);
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['secret']);

        // Internal endpoints (forward_auth targets) must return 401, not a login redirect.
        $exceptions->render(function (AuthenticationException $e, Request $request): Response|JsonResponse|null {
            if ($request->is('internal/*')) {
                return response('Unauthenticated.', 401);
            }

            if ($request->is('api/cli/*')) {
                return response()->json(['error' => 'unauthenticated', 'message' => 'Sign in with yak login.'], 401);
            }

            return null;
        });

        // The CLI API answers every error as {"error", "message", "details"?}.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/cli/*')) {
                return null;
            }

            return response()->json([
                'error' => 'validation_failed',
                'message' => 'The request is not valid.',
                'details' => ['errors' => $e->errors()],
            ], 422);
        });
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->is('api/cli/*')) {
                return null;
            }

            return response()->json([
                'error' => 'rate_limited',
                'message' => 'Too many requests. Try again shortly.',
            ], 429, $e->getHeaders());
        });
        $exceptions->render(function (TeleportError $e, Request $request) {
            if (! $request->is('api/cli/*')) {
                return null;
            }

            return CliError::make($e->errorCode, $e->getMessage(), $e->status, $e->details);
        });
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/cli/*')) {
                return null;
            }

            return response()->json(['error' => 'not_found', 'message' => 'Not found.'], 404);
        });
    })->create();
