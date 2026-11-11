<?php

namespace App\Http\Resources;

use App\Enums\RepositoryRole;
use App\Enums\RepositorySetupStatus;
use App\Enums\RunnerState;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadKind;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadStatus;
use App\Enums\ThreadVisibility;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use App\Services\ClaudeAccounts\AccountConcurrency;
use App\Services\Teleport\TeleportPageState;
use App\Services\Threads\Preview\ThreadPreviewPanelState;
use App\Services\Threads\SandboxCapacity;
use App\Services\Threads\ThreadAccess;

/**
 * Props of the `Threads/Show` page. The array shape below is the source of
 * truth for `resources/js/types/threads.ts`.
 */
final class ThreadPageData
{
    /**
     * @return array{
     *     thread: array{
     *         id: int,
     *         name: string,
     *         title: string,
     *         repo: string,
     *         kind: value-of<ThreadKind>,
     *         status: value-of<ThreadStatus>,
     *         visibility: value-of<ThreadVisibility>,
     *         branch: ?string,
     *         sandboxState: value-of<ThreadSandboxState>,
     *         runnerState: value-of<RunnerState>,
     *         ownerSide: value-of<ThreadOwnerSide>,
     *         blockedReason: ?value-of<ThreadBlockReason>,
     *         parentThreadId: ?int,
     *         rootThreadId: ?int,
     *         lastActivityAt: ?string,
     *         repositories: list<array{repo: string, role: value-of<RepositoryRole>, setupStatus: value-of<RepositorySetupStatus>}>,
     *         pullRequestCount: int,
     *         isOwner: bool,
     *         sharedWithCount: int,
     *         startedOnDevice: bool,
     *         preview: array{state: 'ready'|'sandbox_asleep'|'waking_sandbox'|'starting_dev_server'|'failing'|'no_manifest'|'invalid_manifest'|'destroyed'|'closed'|'local_machine'|'waiting_for_slot', url: ?string, host: ?string, repoLabel: string, canRestore: bool, safariFallback: bool, targets: list<array{threadId: int, title: string, kind: string, url: ?string, state: string, canRestore: bool}>, awakeCap: int, personAwakeCap: int, teleportCommand: ?string},
     *     },
     *     canDrive: bool,
     *     publishWarning: bool,
     *     teleport: array{state: 'none'|'waiting'|'teleporting'|'on_device', ownerDevice: ?array{id: int, name: string}, ownerName: ?string, since: ?string, viewerIsOwner: bool, command: string, link: string, hasDevices: bool, runningTurn: bool, requestedRunningTurn: ?string, openTeleportState: ?string, failure: ?array{code: string, message: string}, heldEvents: list<array{id: int, kind: string, title: string, summary: string, pullRequestUrl: ?string, createdAt: string, isTinted: bool}>, environmentNote: ?string, accountName: ?string, continuedFromThreadId: ?int, continuedFromTitle: ?string, canTeleport: bool},
     *     capacity: ?array{turns: array{used: int, limit: int}, sandboxes: array{used: int, limit: int}, share: array{used: int, limit: int}},
     * }
     */
    public static function build(Thread $thread, ?User $viewer): array
    {
        $access = app(ThreadAccess::class);
        $isOwner = $access->isOwner($viewer, $thread);
        $repositories = [];

        foreach ($thread->repositories as $repository) {
            $repositories[] = [
                'repo' => $repository->repo,
                'role' => $repository->role->value,
                'setupStatus' => $repository->setup_status->value,
            ];
        }

        return [
            'thread' => [
                'id' => $thread->id,
                'name' => $thread->name,
                'title' => $thread->title,
                'repo' => $thread->repo,
                'kind' => $thread->kind->value,
                'status' => $thread->status->value,
                'visibility' => $thread->effectiveVisibility()->value,
                'branch' => $thread->branch,
                'sandboxState' => $thread->sandbox_state->value,
                'runnerState' => $thread->runner_state->value,
                'ownerSide' => $thread->owner_side->value,
                'blockedReason' => $thread->blocked_reason?->value,
                'parentThreadId' => $thread->parent_thread_id,
                'rootThreadId' => $thread->root_thread_id,
                'lastActivityAt' => $thread->last_activity_at?->toIso8601String(),
                'repositories' => $repositories,
                'pullRequestCount' => $thread->repositories->whereNotNull('pull_request_url')->where('role', '!=', RepositoryRole::Context)->count(),
                'isOwner' => $isOwner,
                'sharedWithCount' => $isOwner ? ThreadShare::query()->where('thread_id', $thread->root_thread_id ?? $thread->id)->count() : 0,
                'startedOnDevice' => $thread->owner_side === ThreadOwnerSide::Device,
                'preview' => app(ThreadPreviewPanelState::class)->build($thread, $viewer),
            ],
            'canDrive' => $access->canDrive($viewer, $thread),
            'publishWarning' => $thread->effectiveVisibility() !== ThreadVisibility::Team,
            'teleport' => app(TeleportPageState::class)->for($thread, $viewer),
            'capacity' => self::capacity($thread),
        ];
    }

    /**
     * Turn, sandbox and personal share counters; null when the thread has no account or an owner, or a limit is unset.
     *
     * @return array{turns: array{used: int, limit: int}, sandboxes: array{used: int, limit: int}, share: array{used: int, limit: int}}|null
     */
    private static function capacity(Thread $thread): ?array
    {
        $account = $thread->claudeAccount;
        $ownerId = $thread->effectiveOwnerUserId();
        $sandboxLimit = (int) config('yak.threads.awake_cap');
        $shareLimit = (int) config('yak.threads.person_awake_cap');

        if ($account === null || $ownerId === null || $account->max_concurrent_turns < 1 || $sandboxLimit < 1 || $shareLimit < 1) {
            return null;
        }

        $sandboxes = app(SandboxCapacity::class);

        return [
            'turns' => ['used' => app(AccountConcurrency::class)->heldCount($account), 'limit' => $account->max_concurrent_turns],
            'sandboxes' => ['used' => $sandboxes->awakeCount(), 'limit' => $sandboxLimit],
            'share' => ['used' => $sandboxes->awakeCountFor($ownerId), 'limit' => $shareLimit],
        ];
    }
}
