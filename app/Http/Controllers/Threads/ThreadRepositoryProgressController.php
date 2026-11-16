<?php

namespace App\Http\Controllers\Threads;

use App\Enums\RepositorySetupStatus;
use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Models\ThreadRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Per-repository clone and setup progress, polled by the start dialog.
 */
class ThreadRepositoryProgressController extends Controller
{
    public function __invoke(Thread $thread): JsonResponse
    {
        abort_unless(Gate::allows('view', $thread), 404);

        $repositories = $thread->repositories()->orderBy('id')->get()->map(fn (ThreadRepository $repository): array => [
            'repo' => $repository->repo,
            'role' => $repository->role->value,
            'status' => $repository->setup_status === RepositorySetupStatus::Pending ? 'queued' : $repository->setup_status->value,
        ]);

        return response()->json([
            'repositories' => $repositories->all(),
            'isReady' => $repositories->isNotEmpty() && $repositories->every(fn (array $row): bool => $row['status'] === 'ready'),
        ]);
    }
}
