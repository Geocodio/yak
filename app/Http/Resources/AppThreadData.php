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
use App\Models\User;

/**
 * Thread show for the app: the web page props plus the panes it offers, the open question and the latest event sequence.
 */
final class AppThreadData
{
    /**
     * @return array{
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
     *         isStarred: bool,
     *         sharedWithCount: int,
     *         startedOnDevice: bool,
     *         preview: array{state: 'ready'|'sandbox_asleep'|'waking_sandbox'|'starting_dev_server'|'failing'|'no_manifest'|'invalid_manifest'|'destroyed'|'closed'|'local_machine'|'waiting_for_slot', url: ?string, host: ?string, repoLabel: string, canRestore: bool, safariFallback: bool, targets: list<array{threadId: string, title: string, kind: string, url: ?string, state: string, canRestore: bool}>, awakeCap: int, personAwakeCap: int, teleportCommand: ?string, port: ?int, coldStartCommand: ?string, setup: array{intent: ?string, isRunning: bool, endedWithoutManifest: bool}},
     *         pullRequestOpening: ?array{state: 'opening'|'held'|'failed', error: ?string, at: ?string},
     *     },
     *     canDrive: bool,
     *     diffTotals: ?array{additions: int, deletions: int},
     *     pullRequest: ?array{number: int, url: string},
     *     brief: ?array{text: string, kind: string, model: ?string, startedAt: string, branch: ?string},
     *     publishWarning: bool,
     *     teleport: array{state: 'none'|'waiting'|'teleporting'|'on_device', ownerDevice: ?array{id: int, name: string}, ownerName: ?string, since: ?string, viewerIsOwner: bool, command: string, link: string, hasDevices: bool, runningTurn: bool, requestedRunningTurn: ?string, openTeleportState: ?string, failure: ?array{code: string, message: string}, heldEvents: list<array{id: int, kind: string, title: string, summary: string, pullRequestUrl: ?string, createdAt: string, isTinted: bool}>, environmentNote: ?string, environmentSwitch: ?array{from: string, to: string, at: string}, accountName: ?string, continuedFromThreadId: ?string, continuedFromTitle: ?string, canTeleport: bool},
     *     capacity: ?array{turns: array{used: int, limit: int}, sandboxes: array{used: int, limit: int}, share: array{used: int, limit: int}},
     *     messages: list<array{id: string, uuid: ?string, text: string, authorName: ?string, authorInitials: string, model: ?string, state: 'pending'|'read'|'cancelled', intent: ?string, sentAt: string, readAfterSequence: ?int, attachmentIds: list<int>, bodyHtml: string, attachments: list<array<string, mixed>>}>,
     *     localTurns: ?array{count: int, firstPromptText: string, startedAt: string, endedAt: string, hasMore: bool, authorName: string, authorInitials: string, turns: list<array<string, mixed>>},
     *     walkthrough: ?array{isRecording: bool, isRendering: bool, video: ?array{url: string, thumbnailUrl: ?string, durationSeconds: float, chapters: list<array{title: string, startSeconds: float}>}, screenshots: list<array{url: string, caption: ?string}>, isInPullRequest: bool},
     *     sharedFiles: list<array{id: string, name: string, kind: 'report'|'image'|'csv'|'pdf'|'text'|'file', sizeBytes: int, url: string, sharedWithTeam: bool, anchorSequence: ?int, createdAt: string}>,
     *     composer: array{state: 'idle'|'working'|'disabled_closed'|'disabled_computer'|'disabled_blocked'|'readonly', model: ?string, models: list<array{family: string, latest: array{value: string, label: string, description: string}, older: list<array{value: string, label: string}>}>, accountName: ?string, nextAttachmentNumber: int, context: ?array{tokens: int, input: int, cacheRead: int, cacheCreation: int}, contextWindows: array<string, int>},
     *     notice: array{kind: 'account_invalid'|'account_expired'|'usage_limit'|'waiting_for_account'|'waiting_for_sandbox'|'waiting_for_repository'|'repository_setup_failed'|'credential_exposure'|'restore_failed'|'guard_turns'|'guard_time'|'restoring', title: string, body: string, resetsAt: ?string, isOwnerOfAccount: bool, canContinue: bool, accountSettingsUrl: ?string, startOnOwnAccountUrl: ?string, repositoryUrl?: ?string, restoreSteps: list<array{key: string, label: string, detail: string, status: 'pending'|'running'|'done'|'failed', error: ?string, durationSeconds: ?float, outputTail: list<string>, exitCode: ?int}>, waiting: ?array{position: ?int, turnsRunning: int, turnsLimit: int, accountName: string, slots: list<array{state: 'running'|'paused', idleMinutes: ?int, isMine: bool, isThis: bool}>, ownSlotsUsed: ?int, ownSlotsCap: ?int, stoppableThreadId: ?string}}|null,
     *     modelLabels: array<string, string>,
     *     stackParent: array{id: string, name: string, title: string}|null,
     *     panes: array{chat: true, preview: bool, terminal: bool, files: bool, changes: true},
     *     question: array{taskId: string, expiresAt: ?string, questions: list<array{id: string, header: string, question: string, options: list<array{label: string, description: string}>, multiSelect: bool}>}|null,
     *     latestSequence: int,
     * }
     */
    public static function for(Thread $thread, User $viewer): array
    {
        $data = ThreadShowData::build($thread, $viewer);
        $hasNoSandbox = $thread->sandbox_state === ThreadSandboxState::None;

        return $data + [
            'panes' => [
                'chat' => true,
                'preview' => $data['thread']['preview']['state'] !== 'no_manifest',
                'terminal' => ! $hasNoSandbox,
                'files' => ! $hasNoSandbox,
                'changes' => true,
            ],
            'question' => ThreadQuestionData::for($thread),
            'latestSequence' => (int) Thread::withoutGlobalScopes()->whereKey($thread->id)->value('event_sequence'),
        ];
    }
}
