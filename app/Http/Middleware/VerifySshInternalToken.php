<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the host-side ssh helpers (wake daemon, key lookup, terminal bridge). An unset token locks every route.
 */
class VerifySshInternalToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('yak.ssh.internal_token');

        abort_if($expected === '' || ! hash_equals($expected, (string) $request->bearerToken()), 401);

        return $next($request);
    }
}
