<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the CLI protocol version and stamps the server's protocol range on every response.
 */
class CliProtocol
{
    /**
     * The web group middleware that hold state; CLI route groups exclude them.
     *
     * @var list<class-string>
     */
    public const WITHOUT_WEB_STATE = [
        StartSession::class,
        ShareErrorsFromSession::class,
        AddQueuedCookiesToResponse::class,
        EncryptCookies::class,
        PreventRequestForgery::class,
        HandleInertiaRequests::class,
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $current = (int) config('yak.cli.protocol');
        $minimum = (int) config('yak.cli.min_protocol');
        $header = (string) $request->header('X-Yak-Protocol', '');
        $client = ctype_digit($header) ? (int) $header : 0;

        if ($client < $minimum) {
            $response = response()->json([
                'error' => 'protocol_too_old',
                'message' => 'This yak command is too old for Yak. Update the Yak plugin.',
                'details' => [
                    'min_protocol' => $minimum,
                    'current_protocol' => $current,
                    'client_protocol' => $client,
                ],
            ], 426);
        } else {
            $response = $next($request);
        }

        $response->headers->set('X-Yak-Protocol', (string) $current);
        $response->headers->set('X-Yak-Min-Protocol', (string) $minimum);

        return $response;
    }
}
