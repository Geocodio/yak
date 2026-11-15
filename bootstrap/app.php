<?php

use App\Channels\Drone\PollCommand;
use App\Exceptions\TeleportError;
use App\Exceptions\ThreadActionRefused;
use App\Http\Middleware\AuthenticateDevice;
use App\Http\Middleware\ClientProtocol;
use App\Http\Middleware\EnsureTaskDriveRights;
use App\Http\Middleware\EnsureThreadDriveRights;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RestrictToIngress;
use App\Http\Middleware\StampClientProtocol;
use App\Http\Middleware\VerifySessionStoreToken;
use App\Http\Middleware\VerifySshInternalToken;
use App\Http\Responses\CliError;
use App\Services\PreviewAccess;
use App\Support\ClientApi;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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
        $middleware->prepend(StampClientProtocol::class);
        $middleware->trustProxies(at: ['127.0.0.1', '172.16.0.0/12']);
        $middleware->alias([
            'restrict-to-ingress' => RestrictToIngress::class,
            'session-store-token' => VerifySessionStoreToken::class,
            'ssh-internal-token' => VerifySshInternalToken::class,
            'client.protocol' => ClientProtocol::class,
            'auth.device' => AuthenticateDevice::class,
            'task.drive' => EnsureTaskDriveRights::class,
            'thread.drive' => EnsureThreadDriveRights::class,
        ]);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: ClientProtocol::class);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: AuthenticateDevice::class);
        // The preview cookie is minted as a raw Set-Cookie header on the preview host, so it is never Laravel-encrypted.
        $middleware->encryptCookies(except: [PreviewAccess::COOKIE_NAME]);
        $middleware->validateCsrfTokens(except: ['api/cli/*', 'api/app/*', 'internal/*']);
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

            $api = ClientApi::for($request);

            if ($api !== null) {
                return response()->json(['error' => 'unauthenticated', 'message' => ClientApi::unauthenticatedMessage($api)], 401);
            }

            return null;
        });

        // The CLI and app APIs answer every error as {"error", "message", "details"?}.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (ClientApi::for($request) === null) {
                return null;
            }

            return response()->json([
                'error' => 'validation_failed',
                'message' => 'The request is not valid.',
                'details' => ['errors' => $e->errors()],
            ], 422);
        });
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (ClientApi::for($request) === null) {
                return null;
            }

            return response()->json([
                'error' => 'rate_limited',
                'message' => 'Too many requests. Try again shortly.',
            ], 429, $e->getHeaders());
        });
        $exceptions->render(function (TeleportError $e, Request $request) {
            if (ClientApi::for($request) === null) {
                return null;
            }

            return CliError::make($e->errorCode, $e->getMessage(), $e->status, $e->details);
        });
        $exceptions->render(function (ThreadActionRefused $e, Request $request) {
            if (ClientApi::for($request) === null) {
                return null;
            }

            return response()->json([
                'error' => $e->errorCode,
                'message' => $e->getMessage(),
                ...($e->details === [] ? [] : ['details' => (object) $e->details]),
            ], $e->status);
        });
        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) {
            if (ClientApi::for($request) === null) {
                return null;
            }

            return response()->json(['error' => 'forbidden', 'message' => 'You do not have access to this.'], 403);
        });
        $exceptions->render(function (QueryException|RedisException $e, Request $request) {
            if (ClientApi::for($request) === null) {
                return null;
            }

            return response()->json(['error' => 'unavailable', 'message' => 'Yak cannot answer right now. Try again in a moment.'], 503);
        });
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (ClientApi::for($request) === null) {
                return null;
            }

            return response()->json(['error' => 'not_found', 'message' => 'Not found.'], 404);
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (ClientApi::for($request) === null) {
                return null;
            }

            $status = $e->getStatusCode();
            // A 503 raised on purpose (a repeat that arrives while the first send runs) carries a sentence that says what to do.
            $message = match (true) {
                $status === 403 => 'You do not have access to this.',
                $status === 405 => 'This endpoint does not accept that method.',
                $status === 503 && $e->getMessage() !== '' => $e->getMessage(),
                default => 'Yak could not handle this request.',
            };

            return response()->json(['error' => ClientApi::errorCodeForStatus($status), 'message' => $message], $status, $e->getHeaders());
        });
        // Registered last: anything no renderer above claimed is a server error, and the client never sees HTML or the detail.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (ClientApi::for($request) === null || $e instanceof HttpResponseException) {
                return null;
            }

            return response()->json(['error' => 'server_error', 'message' => 'Yak could not handle this request. Try again.'], 500);
        });
    })->create();
