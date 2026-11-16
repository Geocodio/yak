<?php

namespace App\Http\Middleware;

use App\Models\Thread;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifySessionStoreToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $thread = Thread::withoutGlobalScopes()->whereKey($request->route('thread'))->first();
        abort_if($thread === null, 404);

        abort_unless(hash_equals($thread->sessionStoreToken(), (string) $request->bearerToken()), 401);

        return $next($request);
    }
}
