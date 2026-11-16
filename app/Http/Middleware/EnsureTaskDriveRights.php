<?php

namespace App\Http\Middleware;

use App\Models\YakTask;
use App\Services\Threads\ThreadAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mutating legacy task routes drive the task, so a thread task needs drive
 * rights on its thread. A share recipient can read but not drive.
 */
class EnsureTaskDriveRights
{
    public function handle(Request $request, Closure $next): Response
    {
        $task = $request->route('task');

        if ($task instanceof YakTask && $task->thread_id !== null && $task->thread !== null) {
            abort_unless(app(ThreadAccess::class)->canDrive($request->user(), $task->thread), 403);
        }

        return $next($request);
    }
}
