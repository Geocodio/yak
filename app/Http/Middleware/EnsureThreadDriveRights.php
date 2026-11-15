<?php

namespace App\Http\Middleware;

use App\Models\Thread;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Action routes on a thread: a person who cannot see it gets a 404, one who can
 * see but not drive it (a share recipient) gets a 403.
 */
class EnsureThreadDriveRights
{
    public function handle(Request $request, Closure $next): Response
    {
        $thread = $request->route('thread');

        if ($thread instanceof Thread) {
            abort_unless(Gate::allows('view', $thread), 404);
            abort_unless(Gate::allows('drive', $thread), 403);
        }

        return $next($request);
    }
}
