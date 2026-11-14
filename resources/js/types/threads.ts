import type { StepView } from '@/components/activity/types';
import type { ModelFamily, PickerAccount } from '@/types/claudeAccounts';
import type { AttachmentData } from '@/types/tasks';
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
    sharedWithCount: number;
    startedOnDevice: boolean;
    preview: ThreadPreviewData;
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
    environmentSwitch: { from: string; to: string; at: string } | null;
    accountName: string | null;
    continuedFromThreadId: string | null;
    continuedFromTitle: string | null;
    canTeleport: boolean;
};

/** Props of the `Threads/Show` page; mirrors `App\Http\Resources\ThreadPageData`. */
export type ThreadPageData = {
    thread: ThreadData;
    canDrive: boolean;
    diffTotals: { additions: number; deletions: number } | null;
    pullRequest: { number: number; url: string } | null;
    brief: { text: string; kind: string; model: string | null; startedAt: string; branch: string | null } | null;
    teleport: TeleportData;
    publishWarning: boolean;
    messages: ThreadMessage[];
    localTurns: LocalTurnsData | null;
    composer: ThreadComposer;
    notice: ThreadNotice | null;
    capacity: ThreadCapacity | null;
    stackParent: { id: string; name: string; title: string } | null;
    /** Catalog label of each model id and family alias. */
    modelLabels: Record<string, string>;
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
    count: number;
    firstPromptText: string;
    startedAt: string;
    endedAt: string;
    hasMore: boolean;
    authorName: string;
    authorInitials: string;
    turns: LocalTurn[];
};

export type ThreadMessage = {
    id: string;
    uuid: string | null;
    text: string;
    authorName: string | null;
    authorInitials: string;
    model: string | null;
    state: 'pending' | 'read' | 'cancelled';
    sentAt: string;
    readAfterSequence: number | null;
    attachmentIds: number[];
    bodyHtml: string;
    attachments: AttachmentData[];
};

export type ThreadComposer = {
    state: 'idle' | 'working' | 'disabled_closed' | 'disabled_computer' | 'disabled_blocked' | 'readonly';
    model: string | null;
    models: ModelFamily[];
    accountName: string | null;
    nextAttachmentNumber: number;
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
    | 'restoring';

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
        defaultsByRepo: Record<string, { accountId: number | null; brokenDefaultAccountId: number | null; model: string | null }>;
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
