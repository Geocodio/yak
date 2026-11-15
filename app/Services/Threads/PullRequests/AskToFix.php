<?php

namespace App\Services\Threads\PullRequests;

use App\Enums\ThreadOwnerSide;
use App\Exceptions\ThreadActionRefused;
use App\Jobs\Threads\RetryRepositoryCiJob;
use App\Models\Thread;
use App\Models\ThreadRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * A person's request to have Yak fix one repository's failing CI. It does not
 * spend the repository's automatic retry budget, and the job's uniqueness makes
 * a repeat a no-op.
 */
class AskToFix
{
    /**
     * @throws ModelNotFoundException when the row is not a changes repository of this thread with an open pull request
     * @throws ThreadActionRefused
     */
    public function request(Thread $thread, int $rowId): void
    {
        $row = ThreadRepository::query()->where('thread_id', $thread->id)->whereKey($rowId)->first();

        if ($row === null || ! $row->isChangesRepo() || ! $row->hasOpenPullRequest()) {
            throw (new ModelNotFoundException)->setModel(ThreadRepository::class, [$rowId]);
        }

        if ($thread->owner_side === ThreadOwnerSide::Device) {
            throw ThreadActionRefused::because(ThreadActionRefused::THREAD_OWNED_BY_DEVICE, 'Your computer is working on this thread. Hand it back to Yak before asking for a fix.');
        }

        if (! $thread->isOpen()) {
            throw ThreadActionRefused::because(ThreadActionRefused::THREAD_CLOSED, 'This thread is closed.');
        }

        if ($row->ci_status !== 'failing') {
            throw ThreadActionRefused::because(ThreadActionRefused::CI_NOT_FAILING, 'CI is not failing on this pull request.');
        }

        RetryRepositoryCiJob::dispatch($row->id, $this->failureOutput($row));
    }

    private function failureOutput(ThreadRepository $row): ?string
    {
        $sections = [];

        foreach ($row->ci_checks ?? [] as $check) {
            if ($check['state'] === 'failing') {
                $sections[] = "## {$check['name']}\n" . ($check['excerpt'] ?? '');
            }
        }

        return $sections === [] ? null : implode("\n\n", $sections);
    }
}
