<?php

namespace App\Services\Threads\Repos;

use App\DataTransferObjects\AddRepositoryOutcome;
use App\Enums\RepositoryRole;
use App\Enums\RepositorySetupStatus;
use App\Events\ThreadRepositoryChanged;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Threads\Contracts\SandboxManager;
use Throwable;

/**
 * Adds a repository to a running thread on Claude's request. Nothing is cloned and no row
 * is created for a repository Yak cannot reach.
 */
class AddRepository
{
    public function __construct(
        private readonly RepositoryAccess $access,
        private readonly SandboxManager $sandboxes,
        private readonly ThreadGitHubCredentials $credentials,
        private readonly ExtraRepositorySetup $setup,
        private readonly RepositoryCardPublisher $cards,
    ) {}

    public function add(Thread $thread, string $slug, RepositoryRole $role, string $reason): AddRepositoryOutcome
    {
        $slug = trim($slug);

        if ($role === RepositoryRole::Primary || preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $slug) !== 1) {
            return new AddRepositoryOutcome('invalid', message: 'Give a repository as owner/name, with role changes or context.');
        }

        $path = ThreadRepository::sandboxPathFor($slug, $role);
        $existing = $thread->repositories()->where('repo', $slug)->first();

        if ($existing !== null) {
            return new AddRepositoryOutcome('duplicate', $existing->sandbox_path);
        }

        if ($thread->repositories()->where('sandbox_path', $path)->exists()) {
            return new AddRepositoryOutcome('invalid', message: "Another repository already uses {$path}.");
        }

        $result = $this->access->check($slug);

        if (! $result->allowed) {
            $this->cards->publishDenied($thread->id, $slug, $role->value, $reason !== '' ? $reason : null, (string) $result->reason);

            return new AddRepositoryOutcome('no_access', message: $result->reason);
        }

        $this->sandboxes->exec($thread, ['mkdir', '-p', '/extras']);

        $repository = $thread->repositories()->create([
            'repo' => $slug,
            'role' => $role,
            'sandbox_path' => $path,
            'setup_status' => RepositorySetupStatus::Pending,
            'added_by' => 'claude',
            'add_reason' => $reason !== '' ? $reason : null,
        ]);
        ThreadRepositoryChanged::dispatch($thread, $repository);

        try {
            $this->credentials->refresh($thread);
        } catch (Throwable $exception) {
            $repository->update(['setup_status' => RepositorySetupStatus::Failed, 'setup_error' => 'Could not give the sandbox access to the repository.']);
            ThreadRepositoryChanged::dispatch($thread, $repository);

            return new AddRepositoryOutcome('setup_failed', $path, $repository->setup_error);
        }

        $outcome = $this->setup->setUp($thread, $repository);

        return $outcome->status === RepositorySetupStatus::Ready
            ? new AddRepositoryOutcome('added', $path)
            : new AddRepositoryOutcome('setup_failed', $path, $outcome->error);
    }
}
