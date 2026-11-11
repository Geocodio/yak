<?php

namespace App\Http\Controllers\Threads;

use App\Enums\RepositoryRole;
use App\Enums\RepositorySetupStatus;
use App\Events\ThreadRepositoryChanged;
use App\Exceptions\RepositoryRoleBlocked;
use App\Http\Controllers\Controller;
use App\Http\Requests\Threads\UpdateThreadRepositoryRequest;
use App\Jobs\RetryRepositorySetupJob;
use App\Models\Thread;
use App\Models\ThreadRepository;
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

    public function retry(Thread $thread, ThreadRepository $repository): RedirectResponse
    {
        abort_unless(Gate::allows('view', $thread), 404);
        Gate::authorize('drive', $thread);

        if ($repository->isPrimary()) {
            throw ValidationException::withMessages(['repository' => 'Only an extra repository has a setup to retry.']);
        }

        if ($repository->setup_status !== RepositorySetupStatus::Failed) {
            throw ValidationException::withMessages(['repository' => 'Only a failed setup can be retried.']);
        }

        $repository->update(['setup_status' => RepositorySetupStatus::Pending, 'setup_error' => null]);
        ThreadRepositoryChanged::dispatch($thread, $repository);
        RetryRepositorySetupJob::dispatch($thread, $repository);

        return back();
    }
}
