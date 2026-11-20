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
 * Enforces the protocol version of the CLI or app API. StampClientProtocol adds the server's range to every response.
 */
class ClientProtocol
{
    /**
     * The web group middleware that hold state; client API route groups exclude them.
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
        AppEmbedSession::class,
    ];

    /**
     * @param  string  $api  The client API this route group serves: `cli` or `app`.
     */
    public function handle(Request $request, Closure $next, string $api): Response
    {
        $current = (int) config("yak.{$api}.protocol");
        $minimum = (int) config("yak.{$api}.min_protocol");
        $header = (string) $request->header('X-Yak-Protocol', '');
        $client = ctype_digit($header) && strlen($header) <= 9 ? (int) $header : 0;
        $request->attributes->set('client_protocol', $client);

        if ($client < $minimum) {
            return response()->json([
                'error' => 'protocol_too_old',
                'message' => $api === 'app'
                    ? 'This build of the Yak app is too old for this Yak. Install the latest build.'
                    : 'This yak command is too old for Yak. Update the Yak plugin.',
                'details' => [
                    'min_protocol' => $minimum,
                    'current_protocol' => $current,
                    'client_protocol' => $client,
                ],
            ], 426);
        }

        return $next($request);
    }
}
