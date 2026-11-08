<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifySessionStoreToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('yak.threads.session_store_api_token');
        if (! is_string($expected) || $expected === '') {
            abort(404);
        }

        // ponytail: one shared token for every client; per-device tokens arrive with the laptop daemon.
        $given = (string) $request->bearerToken();
        abort_unless(hash_equals($expected, $given), 401);

        return $next($request);
    }
}
