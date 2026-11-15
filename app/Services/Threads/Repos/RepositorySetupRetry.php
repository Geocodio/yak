<?php

namespace App\Services\Threads\Repos;

use App\Enums\RepositorySetupStatus;
use App\Events\ThreadRepositoryChanged;
use App\Exceptions\ThreadActionRefused;
use App\Jobs\RetryRepositorySetupJob;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Devices\ShortLockWait;

/**
 * Retries the setup of an extra repository, for the web and the app. A repeat while the
 * setup already runs changes nothing.
 */
class RepositorySetupRetry
{
    /**
     * @return bool whether a retry was started
     */
    public function retry(Thread $thread, ThreadRepository $repository): bool
    {
        return ShortLockWait::threadTransaction(function () use ($thread, $repository): bool {
            $locked = ThreadRepository::query()->lockForUpdate()->findOrFail($repository->id);

            if (! $thread->isOpen()) {
                throw ThreadActionRefused::because(ThreadActionRefused::THREAD_CLOSED, 'This thread is closed.');
            }

            if ($locked->isPrimary() || $locked->setup_status === RepositorySetupStatus::Ready) {
                throw ThreadActionRefused::because(ThreadActionRefused::NOTHING_TO_RETRY, 'Only a failed setup of an extra repository can be retried.');
            }

            if ($locked->setup_status !== RepositorySetupStatus::Failed) {
                return false;
            }

            $locked->update(['setup_status' => RepositorySetupStatus::Pending, 'setup_error' => null]);
            ThreadRepositoryChanged::dispatch($thread, $locked);
            RetryRepositorySetupJob::dispatch($thread, $locked)->afterCommit();

            return true;
        });
    }
}
