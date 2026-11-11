<?php

namespace App\Http\Controllers\Internal;

use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSandboxState;
use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\Ssh\SandboxSshAddress;
use Illuminate\Http\JsonResponse;

class SshListenersController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $threads = Thread::withoutGlobalScopes()
            ->where('owner_side', ThreadOwnerSide::Yak->value)
            ->whereIn('sandbox_state', [
                ThreadSandboxState::Starting->value,
                ThreadSandboxState::Running->value,
                ThreadSandboxState::Paused->value,
                ThreadSandboxState::Stopped->value,
            ])
            ->where('id', '<=', SandboxSshAddress::MAX_THREAD_ID)
            ->orderBy('id')
            ->get(['id']);

        return response()->json([
            'listeners' => $threads->map(fn (Thread $thread): array => ['address' => SandboxSshAddress::for($thread), 'port' => 22])->all(),
        ]);
    }
}
