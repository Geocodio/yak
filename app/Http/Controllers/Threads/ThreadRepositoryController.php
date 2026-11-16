<?php

namespace App\Http\Controllers\Threads;

use App\Enums\RepositoryRole;
use App\Exceptions\RepositoryRoleBlocked;
use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Threads\UpdateThreadRepositoryRequest;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Threads\Repos\RepositorySetupRetry;
use App\Services\Threads\Repos\ThreadRepositoryRoles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Role switching and setup retry for one repository of a thread.
 */
class ThreadRepositoryController extends Controller
{
    public function update(UpdateThreadRepositoryRequest $request, Thread $thread, ThreadRepository $repository, ThreadRepositoryRoles $roles): RedirectResponse
    {
        try {
            RepositoryRole::from($request->validated('role')) === RepositoryRole::Changes
                ? $roles->promoteToChanges($repository)
                : $roles->makeContextOnly($repository);
        } catch (RepositoryRoleBlocked $blocked) {
            throw ValidationException::withMessages(['role' => $blocked->getMessage()]);
        }

        return back();
    }

    public function retry(Thread $thread, ThreadRepository $repository, RepositorySetupRetry $setupRetry): RedirectResponse
    {
        abort_unless(Gate::allows('view', $thread), 404);
        Gate::authorize('drive', $thread);

        try {
            $setupRetry->retry($thread, $repository);
        } catch (ThreadActionRefused $refusal) {
            throw ValidationException::withMessages(['repository' => match (true) {
                $refusal->errorCode === ThreadActionRefused::THREAD_CLOSED => $refusal->getMessage(),
                $repository->isPrimary() => 'Only an extra repository has a setup to retry.',
                default => 'Only a failed setup can be retried.',
            }]);
        }

        return back();
    }
}
