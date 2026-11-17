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
use App\Enums\TurnMessageKind;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Services\ClaudeAccounts\ClaudeModelCatalog;
use App\Services\Teleport\LocalTurns;
use App\Services\Threads\LegacyConversation;
use App\Support\TaskPageUrl;

/**
 * Props of the `Threads/Show` page, shared by the web page and the app's thread show.
 *
 * @phpstan-import-type Notice from EarlyWarningData
 * @phpstan-import-type ThreadRequestInfo from ThreadRequestData
 * @phpstan-import-type ThreadProgress from ThreadRequestData
 * @phpstan-import-type ThreadRunDetails from ThreadRequestData
 * @phpstan-import-type ThreadMilestone from ThreadMilestonesData
 *
 * @phpstan-type ThreadShow array{
 *     thread: array{
 *         id: string,
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
 *         parentThreadId: ?string,
 *         rootThreadId: ?string,
 *         lastActivityAt: ?string,
 *         repositories: list<array{repo: string, role: value-of<RepositoryRole>, setupStatus: value-of<RepositorySetupStatus>}>,
 *         pullRequestCount: int,
 *         isOwner: bool,
 *         canShare: bool,
 *         isStarred: bool,
 *         sharedWithCount: int,
 *         startedOnDevice: bool,
 *         preview: array{state: 'ready'|'sandbox_asleep'|'waking_sandbox'|'starting_dev_server'|'failing'|'no_manifest'|'invalid_manifest'|'destroyed'|'closed'|'local_machine'|'waiting_for_slot', url: ?string, host: ?string, repoLabel: string, canRestore: bool, safariFallback: bool, targets: list<array{threadId: string, title: string, kind: string, url: ?string, state: string, canRestore: bool}>, awakeCap: int, personAwakeCap: int, teleportCommand: ?string, port: ?int, coldStartCommand: ?string, setup: array{intent: ?string, isRunning: bool, endedWithoutManifest: bool}},
 *         pullRequestOpening: ?array{state: 'opening'|'held'|'failed', error: ?string, at: ?string},
 *     },
 *     canDrive: bool,
 *     diffTotals: ?array{additions: int, deletions: int, filesCount: int},
 *     pullRequest: ?array{number: int, url: string},
 *     brief: ?array{text: string, kind: string, model: ?string, effort: ?string, startedAt: string, branch: ?string},
 *     publishWarning: bool,
 *     teleport: array{state: 'none'|'waiting'|'teleporting'|'on_device', ownerDevice: ?array{id: int, name: string}, ownerName: ?string, since: ?string, viewerIsOwner: bool, command: string, link: string, hasDevices: bool, runningTurn: bool, requestedRunningTurn: ?string, openTeleportState: ?string, failure: ?array{code: string, message: string}, heldEvents: list<array{id: int, kind: string, title: string, summary: string, pullRequestUrl: ?string, createdAt: string, isTinted: bool}>, environmentNote: ?string, environmentSwitches: list<array{from: string, to: string, at: string}>, environmentSwitch: ?array{from: string, to: string, at: string}, accountName: ?string, continuedFromThreadId: ?string, continuedFromTitle: ?string, canTeleport: bool},
 *     capacity: ?array{turns: array{used: int, limit: int}, sandboxes: array{used: int, limit: int}, share: array{used: int, limit: int}},
 *     isLegacy: bool,
 *     legacyAnswers: list<array{runTaskId: string, html: string, at: string}>,
 *     messages: list<array{id: string, uuid: ?string, text: string, authorName: ?string, authorInitials: string, via: ?array{key: string, label: string, reference: ?string, url: ?string}, isReply: bool, isRetry: bool, retryNumber: ?int, model: ?string, effort: ?string, state: 'pending'|'read'|'cancelled', canEditText: bool, intent: ?string, sendKey: ?string, mode: 'steer'|'queue'|null, sentAt: string, readAfterSequence: ?int, attachmentIds: list<int>, bodyHtml: string, attachments: list<array<string, mixed>>}>,
 *     localTurnStints: list<array{stintId: string, movedAt: ?string, count: int, firstPromptText: string, startedAt: string, endedAt: string, hasMore: bool, authorName: string, authorInitials: string, turns: list<array<string, mixed>>}>,
 *     localTurns: array{stintId: string, movedAt: ?string, count: int, firstPromptText: string, startedAt: string, endedAt: string, hasMore: bool, authorName: string, authorInitials: string, turns: list<array<string, mixed>>}|null,
 *     walkthrough: ?array{isRecording: bool, isRendering: bool, video: ?array{url: string, thumbnailUrl: ?string, durationSeconds: float, chapters: list<array{title: string, startSeconds: float}>}, screenshots: list<array{url: string, caption: ?string}>, isInPullRequest: bool, isRenderFailed: bool, renderError: ?string, canRetryRender: bool},
 *     sharedFiles: list<array{id: string, name: string, kind: 'report'|'image'|'csv'|'pdf'|'text'|'file', sizeBytes: int, url: string, sharedWithTeam: bool, anchorSequence: ?int, createdAt: string}>,
 *     composer: array{state: 'idle'|'working'|'stopped'|'disabled_closed'|'legacy'|'disabled_computer'|'disabled_blocked'|'readonly', model: ?string, effort: ?string, models: list<array{family: string, latest: array{value: string, label: string, description: string, efforts: list<string>}, older: list<array{value: string, label: string, efforts: list<string>}>}>, accountName: ?string, nextAttachmentNumber: int, context: ?array{tokens: int, input: int, cacheRead: int, cacheCreation: int}, contextWindows: array<string, int>},
 *     notice: array{kind: 'account_invalid'|'account_expired'|'usage_limit'|'waiting_for_account'|'waiting_for_sandbox'|'waiting_for_repository'|'repository_setup_failed'|'credential_exposure'|'restore_failed'|'guard_turns'|'guard_time'|'restoring'|'moved', title: string, body: string, resetsAt: ?string, isOwnerOfAccount: bool, canContinue: bool, accountSettingsUrl: ?string, startOnOwnAccountUrl: ?string, repositoryUrl?: ?string, canContinueAfterSetup?: bool, failedSetupRunUrl?: ?string, repo?: ?string, threadId?: ?string, threadTitle?: ?string, restoreSteps: list<array{key: string, label: string, detail: string, status: 'pending'|'running'|'done'|'failed', error: ?string, durationSeconds: ?float, outputTail: list<string>, exitCode: ?int}>, waiting: ?array{position: ?int, turnsRunning: int, turnsLimit: int, accountName: string, slots: list<array{state: 'running'|'paused', idleMinutes: ?int, isMine: bool, isThis: bool}>, ownSlotsUsed: ?int, ownSlotsCap: ?int, stoppableThreadId: ?string}}|null,
 *     modelLabels: array<string, string>,
 *     stackParent: array{id: string, name: string, title: string}|null,
 *     request: ThreadRequestInfo,
 *     progress: ?ThreadProgress,
 *     runDetails: ThreadRunDetails,
 *     milestones: list<ThreadMilestone>,
 *     question: ?array{taskId: string, expiresAt: ?string, questions: list<array{id: string, header: string, question: string, options: list<array{label: string, description: string}>, multiSelect: bool}>},
 *     repositoryQuestion: array{prompt: string, options: list<string>, selected: ?string, canAnswer: bool}|null,
 *     earlyWarning: Notice|null,
 *     coOwner: array<string, mixed>|null,
 *     stopped: array{kind: string, summary: string, detail: string|null, nextStep: string, isWaitingForSetup: bool}|null,
 *     actions: array{canStop: bool, canRetry: bool, canCancel: bool, canMove: bool, moveTargets: list<string>, canRequestReview: bool, canRetryRender: bool},
 *     card: 'question'|'repository_question'|'early_warning'|'co_owner'|'stopped'|null,
 * }
 */
final class ThreadShowData
{
    /**
     * @return ThreadShow
     */
    public static function build(Thread $thread, User $viewer): array
    {
        $legacy = $thread->is_legacy ? LegacyConversation::for($thread) : null;

        return array_merge(ThreadPageData::build($thread, $viewer), [
            'isLegacy' => $legacy !== null,
            'legacyAnswers' => $legacy['answers'] ?? [],
            'messages' => $legacy['messages'] ?? ThreadMessagesData::for($thread, $viewer),
            'localTurnStints' => $stints = app(LocalTurns::class)->summaries($thread),
            // The first stint alone, kept for app API protocol 1 clients.
            'localTurns' => $stints[0] ?? null,
            'walkthrough' => ThreadWalkthroughData::for($thread),
            'sharedFiles' => ThreadSharedFilesData::for($thread),
            'composer' => ThreadComposerData::for($thread, $viewer),
            'notice' => ThreadNoticeData::for($thread, $viewer),
            'modelLabels' => app(ClaudeModelCatalog::class)->labels(),
            'stackParent' => self::stackParent($thread),
            'question' => ThreadQuestionData::for($thread),
            'milestones' => ThreadMilestonesData::for($thread),
            ...ThreadRequestData::for($thread, $viewer),
            ...ThreadCardsData::for($thread, $viewer),
        ]);
    }

    /**
     * The element id of each task's first message, by the task's public id, so the old `#turn-<id>` fragment
     * finds its message; the root task maps to the thread's first message. Only the thread's own tasks appear.
     *
     * @return array<string, string>
     */
    public static function turnAnchors(Thread $thread): array
    {
        return ThreadTurnMessage::query()
            ->join('task_runs', 'task_runs.id', '=', 'thread_turn_messages.task_run_id')
            ->join('tasks', 'tasks.id', '=', 'task_runs.yak_task_id')
            ->where('tasks.thread_id', $thread->id)
            ->where('thread_turn_messages.kind', TurnMessageKind::Prompt)
            ->orderBy('thread_turn_messages.id')
            ->get(['tasks.public_id as task_public_id', 'thread_turn_messages.uuid as message_uuid'])
            ->unique('task_public_id')
            ->mapWithKeys(fn (ThreadTurnMessage $row): array => [(string) $row->getAttribute('task_public_id') => TaskPageUrl::messageId((string) $row->getAttribute('message_uuid'))])
            ->all();
    }

    /**
     * @return array{id: string, name: string, title: string}|null
     */
    private static function stackParent(Thread $thread): ?array
    {
        $parent = $thread->parent_thread_id === null ? null : Thread::query()->withoutGlobalScopes()->find($thread->parent_thread_id);

        return $parent === null ? null : ['id' => $parent->public_id, 'name' => $parent->name, 'title' => $parent->title];
    }
}
