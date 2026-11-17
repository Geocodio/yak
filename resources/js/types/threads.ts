import type { StepView } from '@/components/activity/types';
import type { ModelFamily, PickerAccount } from '@/types/claudeAccounts';
import type { AttachmentData, QuestionData } from '@/types/tasks';
export type ThreadRepositoryData = {
    repo: string;
    role: 'primary' | 'changes' | 'context';
    setupStatus: 'pending' | 'cloning' | 'setup' | 'ready' | 'failed';
};

export type ThreadData = {
    id: string;
    name: string;
    title: string;
    repo: string;
    kind: 'root' | 'implement' | 'mockup' | 'research' | 'general';
    status: 'open' | 'closed';
    visibility: 'team' | 'private';
    branch: string | null;
    sandboxState: 'none' | 'starting' | 'running' | 'paused' | 'stopped' | 'destroying' | 'destroyed';
    runnerState: 'none' | 'starting' | 'idle' | 'busy' | 'stopping';
    ownerSide: 'yak' | 'device';
    blockedReason: string | null;
    parentThreadId: string | null;
    rootThreadId: string | null;
    lastActivityAt: string | null;
    repositories: ThreadRepositoryData[];
    pullRequestCount: number;
    isOwner: boolean;
    canShare: boolean;
    isStarred: boolean;
    sharedWithCount: number;
    startedOnDevice: boolean;
    preview: ThreadPreviewData;
    /** A pull request asked for from this page: opening, held for a co-owner, or failed; null otherwise. */
    pullRequestOpening: { state: 'opening' | 'held' | 'failed'; error: string | null; at: string | null } | null;
};

export type ThreadPreviewState =
    | 'ready'
    | 'sandbox_asleep'
    | 'waking_sandbox'
    | 'starting_dev_server'
    | 'failing'
    | 'no_manifest'
    | 'invalid_manifest'
    | 'destroyed'
    | 'closed'
    | 'local_machine'
    | 'waiting_for_slot';

export type ThreadPreviewTarget = {
    threadId: string;
    title: string;
    kind: string;
    url: string | null;
    state: string;
    canRestore: boolean;
};

export type ThreadPreviewData = {
    state: ThreadPreviewState;
    url: string | null;
    host: string | null;
    repoLabel: string;
    canRestore: boolean;
    safariFallback: boolean;
    targets: ThreadPreviewTarget[];
    awakeCap: number;
    personAwakeCap: number;
    teleportCommand: string | null;
    port: number | null;
    coldStartCommand: string | null;
    setup: ThreadPreviewSetup;
};

/** The latest request the Preview card made of Claude. */
export type ThreadPreviewSetup = {
    intent: 'add_preview_manifest' | 'fix_preview' | null;
    isRunning: boolean;
    endedWithoutManifest: boolean;
};

export type HeldEventData = {
    id: number;
    kind: string;
    title: string;
    summary: string;
    pullRequestUrl: string | null;
    createdAt: string;
    isTinted: boolean;
};

export type TeleportData = {
    state: 'none' | 'waiting' | 'teleporting' | 'on_device';
    ownerDevice: { id: number; name: string } | null;
    ownerName: string | null;
    since: string | null;
    viewerIsOwner: boolean;
    command: string;
    link: string;
    hasDevices: boolean;
    runningTurn: boolean;
    requestedRunningTurn: 'wait' | 'stop' | null;
    openTeleportState: string | null;
    failure: { code: string; message: string } | null;
    heldEvents: HeldEventData[];
    environmentNote: string | null;
    environmentSwitches: { from: string; to: string; at: string }[];
    environmentSwitch: { from: string; to: string; at: string } | null;
    accountName: string | null;
    continuedFromThreadId: string | null;
    continuedFromTitle: string | null;
    canTeleport: boolean;
};

/** The latest walkthrough recording; mirrors `App\Http\Resources\ThreadWalkthroughData`. */
export type ThreadWalkthrough = {
    isRecording: boolean;
    isRendering: boolean;
    video: { url: string; thumbnailUrl: string | null; durationSeconds: number; chapters: { title: string; startSeconds: number }[] } | null;
    screenshots: { url: string; caption: string | null }[];
    isInPullRequest: boolean;
};

/** A file Claude made for the person; mirrors `App\Http\Resources\ThreadSharedFilesData`. */
export type SharedFile = {
    id: string;
    name: string;
    kind: 'report' | 'image' | 'csv' | 'pdf' | 'text' | 'file';
    sizeBytes: number;
    url: string;
    sharedWithTeam: boolean;
    /** The thread event the file's turn ended at; the card sits after the reply up to it. */
    anchorSequence: number | null;
    createdAt: string;
};

/** One answer of Yak in a thread made from an old task; mirrors `App\Services\Threads\LegacyConversation`. */
export type LegacyAnswer = { runTaskId: string; html: string; at: string };

/** Status of a whole request; mirrors `App\Services\Threads\ThreadRequestStatus::for()`. */
export type ThreadRequestStatus = { key: 'working' | 'needs_you' | 'waiting_for_co_owner' | 'stopped' | 'done' | 'closed'; label: string; tone: 'info' | 'warn' | 'bad' | 'ok' | 'neutral' };

/** Request, progress and run details; mirrors `App\Http\Resources\ThreadRequestData`. */
export type ThreadRequest = {
    source: { key: string; label: string; reference: string | null; url: string | null };
    starter: { key: string; name: string; avatarUrl: string | null };
    responsibleName: string | null;
    status: ThreadRequestStatus;
};

export type ThreadProgress = { steps: { key: string; label: string; tooltip: string; state: 'done' | 'current' | 'failed' | 'upcoming' }[] };

export type ThreadRunDetails = {
    runCount: number;
    stepCount: number;
    attempts: string | null;
    durationSeconds: number;
    costUsd: number;
    apiSpendUsd: number;
    sessionId: string | null;
    startedAt: string | null;
    completedAt: string | null;
    errorLog: string | null;
    errorLogTruncated: boolean;
};

export type ThreadRunSummary = { runId: string; afterSequence: number; durationSeconds: number; stepCount: number; isRetry: boolean; model: string | null };

export type ThreadMilestone = {
    key: 'pushed' | 'ci_passed' | 'ci_failed' | 'pull_request_opened' | 'pull_request_held' | 'walkthrough_ready' | 'research_report';
    label: string;
    tone: 'ok' | 'warn' | 'bad' | 'neutral';
    url: string | null;
    /** The run that reached it; the task id for a thread made from an old task. */
    runId: string;
    /** The last thread event of that run; the row sits after it. */
    afterSequence: number;
};

/** Props of the `Threads/Show` page; mirrors `App\Http\Resources\ThreadPageData`. */
export type ThreadPageData = {
    thread: ThreadData;
    canDrive: boolean;
    diffTotals: { additions: number; deletions: number; filesCount: number } | null;
    pullRequest: { number: number; url: string } | null;
    brief: { text: string; kind: string; model: string | null; startedAt: string; branch: string | null } | null;
    teleport: TeleportData;
    publishWarning: boolean;
    walkthrough: ThreadWalkthrough | null;
    sharedFiles: SharedFile[];
    isLegacy: boolean;
    /** Yak's answers of a thread made from an old task, in time order; empty for other threads. */
    legacyAnswers: LegacyAnswer[];
    messages: ThreadMessage[];
    localTurnStints: LocalTurnsData[];
    composer: ThreadComposer;
    notice: ThreadNotice | null;
    capacity: ThreadCapacity | null;
    stackParent: { id: string; name: string; title: string } | null;
    /** The open clarification of the head task, or null when nothing waits for answers. */
    question: { taskId: string; expiresAt: string | null; questions: QuestionData[] } | null;
    /** Catalog label of each model id and family alias. */
    modelLabels: Record<string, string>;
    request: ThreadRequest;
    /** The staged row, or null when the head task is finished or the thread is closed. */
    progress: ThreadProgress | null;
    runDetails: ThreadRunDetails;
    milestones: ThreadMilestone[];
    /** Web only: the runs of the thread, so each answer can offer its full log. */
    runs: ThreadRunSummary[];
    /** Web only: the run and entry a `?log=` link names, or null. */
    openLog: { runId: string; entryId: number } | null;
};

/** One turn from the person's computer; mirrors the `Turn` shape of `App\Services\Teleport\LocalTurns`. */
export type LocalTurn = {
    id: number;
    at: string;
    text: string;
    html: string;
    items: ({ kind: 'text'; key: string; html: string; model: string | null } | { kind: 'tools'; key: string; steps: StepView[] })[];
};

/** The `localTurns` prop of `Threads/Show`: the fold summary and the newest turns. */
export type LocalTurnsData = {
    /** The teleport up that ended this stretch on the computer. */
    stintId: string;
    /** When the thread moved to the computer for this stretch; null for the stretch the thread began with. */
    movedAt: string | null;
    count: number;
    firstPromptText: string;
    startedAt: string;
    endedAt: string;
    hasMore: boolean;
    authorName: string;
    authorInitials: string;
    turns: LocalTurn[];
};

export type ThreadMessageVia = { key: string; label: string; reference: string | null; url: string | null };

export type ThreadMessage = {
    id: string;
    uuid: string | null;
    text: string;
    authorName: string;
    authorInitials: string;
    /** The channel the message came from; null for a dashboard message. */
    via: ThreadMessageVia | null;
    /** A follow-up that came from a channel. */
    isReply: boolean;
    isRetry: boolean;
    retryNumber: number | null;
    model: string | null;
    effort: string | null;
    state: 'pending' | 'read' | 'cancelled';
    canEditText: boolean;
    intent: 'add_preview_manifest' | 'fix_preview' | 'record_walkthrough' | 'record_walkthrough_and_open_pr' | null;
    sendKey: string | null;
    mode: 'steer' | 'queue' | null;
    sentAt: string;
    readAfterSequence: number | null;
    attachmentIds: number[];
    bodyHtml: string;
    attachments: AttachmentData[];
};

export type ThreadComposer = {
    state: 'idle' | 'working' | 'disabled_closed' | 'legacy' | 'disabled_computer' | 'disabled_blocked' | 'readonly';
    model: string | null;
    effort: string | null;
    models: ModelFamily[];
    accountName: string | null;
    nextAttachmentNumber: number;
    /** What the current session holds as of its latest assistant message; null until measured. */
    context: { tokens: number; input: number; cacheRead: number; cacheCreation: number } | null;
    /** Window size by model id and family alias, plus `default`. */
    contextWindows: Record<string, number> & { default: number };
};

export type ThreadNoticeKind =
    | 'account_invalid'
    | 'account_expired'
    | 'usage_limit'
    | 'waiting_for_account'
    | 'waiting_for_sandbox'
    | 'waiting_for_repository'
    | 'repository_setup_failed'
    | 'credential_exposure'
    | 'restore_failed'
    | 'guard_turns'
    | 'guard_time'
    | 'restoring'
    | 'moved';

export type ThreadWaiting = {
    position: number | null;
    turnsRunning: number;
    turnsLimit: number;
    accountName: string;
    slots: { state: 'running' | 'paused'; idleMinutes: number | null; isMine: boolean; isThis: boolean }[];
    ownSlotsUsed: number | null;
    ownSlotsCap: number | null;
    stoppableThreadId: string | null;
};

export type ThreadCapacity = {
    turns: { used: number; limit: number };
    sandboxes: { used: number; limit: number };
    share: { used: number; limit: number };
};

export type ThreadNotice = {
    kind: ThreadNoticeKind;
    title: string;
    body: string;
    resetsAt: string | null;
    isOwnerOfAccount: boolean;
    canContinue: boolean;
    accountSettingsUrl: string | null;
    startOnOwnAccountUrl: string | null;
    repositoryUrl?: string | null;
    /** The repository a moved request continues in; null while it waits for one. Set with `kind: 'moved'`. */
    repo?: string | null;
    threadId?: string | null;
    threadTitle?: string | null;
    waiting: ThreadWaiting | null;
    restoreSteps: {
        key: string;
        label: string;
        detail: string;
        status: 'pending' | 'running' | 'done' | 'failed';
        error: string | null;
        durationSeconds: number | null;
        outputTail: string[];
        exitCode: number | null;
    }[];
};

export type RepositorySetupState = 'pending' | 'running' | 'ready' | 'failed';

/** Props of the `Threads/New` page; mirrors `App\Http\Controllers\Threads\NewThreadController`. */
export type NewThreadPageData = {
    repos: { slug: string; name: string; setupStatus: RepositorySetupState; setupStep: string | null }[];
    picker: {
        accounts: PickerAccount[];
        defaultsByRepo: Record<string, { accountId: number | null; brokenDefaultAccountId: number | null; model: string | null; effort: string | null }>;
        models: ModelFamily[];
    };
    defaultRepo: string | null;
    emailToYakAddress: string | null;
};

export type StartRepositoryRole = 'changes' | 'context';

/** An extra repository chosen in the start dialog; the primary is the form's `repo`. */
export type StartRepository = { repo: string; role: StartRepositoryRole };

/** Mirrors `App\Http\Controllers\Threads\ThreadRepositoryProgressController`. */
export type RepositoryProgressData = {
    repositories: { repo: string; role: 'primary' | StartRepositoryRole; status: 'queued' | 'cloning' | 'setup' | 'ready' | 'failed' }[];
    isReady: boolean;
};
