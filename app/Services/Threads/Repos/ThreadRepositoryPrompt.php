<?php

namespace App\Services\Threads\Repos;

use App\Enums\RepositorySetupStatus;
use App\Models\Thread;
use App\Models\ThreadRepository;
use Illuminate\Support\Collection;

/**
 * What the agent is told about the repositories of a multi-repo thread.
 */
class ThreadRepositoryPrompt
{
    /** Empty for a thread with a single repository. */
    public static function section(Thread $thread): string
    {
        $repositories = self::repositories($thread);

        if ($repositories->count() < 2) {
            return '';
        }

        return trim(view('prompts.partials.repositories', ['repositories' => $repositories])->render());
    }

    /**
     * @return list<string>
     */
    public static function extraDirectories(Thread $thread): array
    {
        return array_values(self::repositories($thread)
            ->filter(fn (ThreadRepository $repository): bool => $repository->isExtra())
            ->map(fn (ThreadRepository $repository): string => $repository->sandbox_path)
            ->all());
    }

    public static function setupFailureNotice(Thread $thread): ?string
    {
        $failed = self::repositories($thread)->first(fn (ThreadRepository $repository): bool => $repository->setup_status === RepositorySetupStatus::Failed);

        return $failed === null ? null : "Setup failed for {$failed->repo}. Stop and ask the person before continuing in that repo.";
    }

    /**
     * @return Collection<int, ThreadRepository>
     */
    private static function repositories(Thread $thread): Collection
    {
        return $thread->repositories()->orderByRaw("role = 'primary' desc")->orderBy('id')->get();
    }
}
