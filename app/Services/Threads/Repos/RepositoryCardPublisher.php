<?php

namespace App\Services\Threads\Repos;

use App\Enums\RepositorySetupStatus;
use App\Models\ThreadRepository;
use App\Services\Threads\ThreadEventPublisher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Puts the current state of an added repository into the thread's conversation as a stored
 * `repository` event; the page keeps the latest event per repo as one card. The event rides
 * the thread's own channel, so a private thread's card never leaves it.
 */
class RepositoryCardPublisher
{
    public function __construct(private readonly ThreadEventPublisher $events) {}

    public function publish(ThreadRepository $repository): void
    {
        $state = match ($repository->setup_status) {
            RepositorySetupStatus::Pending, RepositorySetupStatus::Cloning => 'running',
            RepositorySetupStatus::Setup => 'setup',
            RepositorySetupStatus::Ready => 'done',
            RepositorySetupStatus::Failed => 'setup_failed',
        };
        $finished = in_array($state, ['done', 'setup_failed'], true);

        $this->append($repository->thread_id, [
            'repo' => $repository->repo,
            'role' => $repository->role->value,
            'state' => $state,
            'path' => $repository->sandbox_path,
            'reason' => $repository->add_reason,
            'cloneSeconds' => null,
            'setupSeconds' => $finished && $repository->setup_started_at !== null ? (int) $repository->setup_started_at->diffInSeconds(now(), absolute: true) : null,
            'output' => $repository->setup_output,
            'error' => $repository->setup_error,
        ]);
    }

    public function publishDenied(int $threadId, string $slug, string $role, ?string $reason, string $error): void
    {
        $this->append($threadId, [
            'repo' => $slug,
            'role' => $role,
            'state' => 'no_access',
            'path' => null,
            'reason' => $reason,
            'cloneSeconds' => null,
            'setupSeconds' => null,
            'output' => null,
            'error' => $error,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function append(int $threadId, array $data): void
    {
        try {
            $this->events->append($threadId, 'repository', $data);
        } catch (Throwable $exception) {
            Log::channel('yak')->warning('Repository card could not be published', ['thread_id' => $threadId, 'error' => $exception->getMessage()]);
        }
    }
}
