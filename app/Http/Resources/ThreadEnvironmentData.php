<?php

namespace App\Http\Resources;

use App\Enums\ClaudeAccountKind;
use App\Enums\RepositoryRole;
use App\Enums\RepositorySetupStatus;
use App\Enums\ThreadSandboxState;
use App\Models\BranchDeployment;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;

/**
 * Body of `threads.environment.show`. The array shape below is the source of
 * truth for `resources/js/types/thread-changes.ts`. The account's secret is
 * never read into the payload.
 */
final class ThreadEnvironmentData
{
    /**
     * @return array{
     *     sandbox: array{state: value-of<ThreadSandboxState>, stateChangedAt: ?string},
     *     branch: ?string,
     *     pullRequest: ?array{number: int, url: string, state: string},
     *     deployment: ?array{url: string, status: string},
     *     account: ?array{name: string, kind: value-of<ClaudeAccountKind>, isYours: bool},
     *     model: ?string,
     *     startedBy: ?array{name: string, at: string},
     *     stoppedAfterMinutes: int,
     *     repositories: list<array{repo: string, role: value-of<RepositoryRole>, setupStatus: value-of<RepositorySetupStatus>}>,
     * }
     */
    public static function build(Thread $thread, ?User $viewer): array
    {
        $account = $thread->claudeAccount;
        $creatorId = $thread->getAttribute('created_by_user_id');
        $creator = $creatorId === null ? null : User::query()->whereKey($creatorId)->first();
        $startedAt = $thread->created_at?->toIso8601String();

        $repositories = [];

        foreach ($thread->repositories as $repository) {
            $repositories[] = [
                'repo' => $repository->repo,
                'role' => $repository->role->value,
                'setupStatus' => $repository->setup_status->value,
            ];
        }

        return [
            'sandbox' => [
                'state' => $thread->sandbox_state->value,
                'stateChangedAt' => $thread->sandbox_state_changed_at?->toIso8601String(),
            ],
            'branch' => $thread->branch,
            'pullRequest' => self::pullRequest($thread),
            'deployment' => self::deployment($thread),
            'account' => $account === null ? null : [
                'name' => $account->name,
                'kind' => $account->kind->value,
                'isYours' => $viewer !== null && $account->owner_user_id === $viewer->id,
            ],
            'model' => $thread->model,
            'startedBy' => $creator === null || $startedAt === null ? null : [
                'name' => $creator->name,
                'at' => $startedAt,
            ],
            'stoppedAfterMinutes' => (int) config('yak.threads.pause_to_stop_minutes'),
            'repositories' => $repositories,
        ];
    }

    /**
     * @return ?array{number: int, url: string, state: string}
     */
    private static function pullRequest(Thread $thread): ?array
    {
        $task = $thread->tasks()->whereNotNull('pr_url')->latest('id')->first();
        $number = $task?->pullRequestNumber();

        if (! $task instanceof YakTask || $task->pr_url === null || $number === null) {
            return null;
        }

        return ['number' => $number, 'url' => $task->pr_url, 'state' => (string) $task->prState()];
    }

    /**
     * @return ?array{url: string, status: string}
     */
    private static function deployment(Thread $thread): ?array
    {
        if ($thread->branch === null) {
            return null;
        }

        $deployment = BranchDeployment::query()
            ->where('branch_name', $thread->branch)
            ->whereHas('repository', fn ($query) => $query->where('slug', $thread->repo))
            ->latest('id')
            ->first();

        return $deployment === null ? null : [
            'url' => 'https://' . $deployment->hostname,
            'status' => $deployment->status->value,
        ];
    }
}
