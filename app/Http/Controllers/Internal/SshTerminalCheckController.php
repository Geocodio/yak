<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Models\User;
use App\Services\Ssh\SshAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SshTerminalCheckController extends Controller
{
    public function __invoke(Request $request, SshAccess $access): JsonResponse|Response
    {
        $request->validate([
            'thread_id' => ['required', 'integer'],
            'user_id' => ['required', 'integer'],
        ]);

        $thread = Thread::withoutGlobalScopes()->find($request->integer('thread_id'));
        $user = User::query()->find($request->integer('user_id'));

        if ($thread === null || $user === null || ! $access->canOpenShell($user, $thread)) {
            return response('', 403);
        }

        return response()->json((object) []);
    }
}
