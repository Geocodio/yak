import type { PickerAccount } from '@/types/claudeAccounts';
import type { AttachmentData } from '@/types/tasks';
export type ThreadRepositoryData = {
    repo: string;
    role: 'primary' | 'changes' | 'context';
    setupStatus: 'pending' | 'cloning' | 'setup' | 'ready' | 'failed';
};

export type ThreadData = {
    id: number;
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
    parentThreadId: number | null;
    rootThreadId: number | null;
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
    threadId: number;
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
    accountName: string | null;
    continuedFromThreadId: number | null;
    canTeleport: boolean;
};

/** Props of the `Threads/Show` page; mirrors `App\Http\Resources\ThreadPageData`. */
export type ThreadPageData = {
    thread: ThreadData;
    canDrive: boolean;
    teleport: TeleportData;
    publishWarning: boolean;
    messages: ThreadMessage[];
    composer: ThreadComposer;
    notice: ThreadNotice | null;
    stackParent: { id: number; name: string; title: string } | null;
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
    models: string[];
    accountName: string | null;
    nextAttachmentNumber: number;
};

export type ThreadNoticeKind =
    | 'account_invalid'
    | 'account_expired'
    | 'usage_limit'
    | 'waiting_for_account'
    | 'waiting_for_sandbox'
    | 'credential_exposure'
    | 'restore_failed'
    | 'guard_turns'
    | 'guard_time'
    | 'restoring';

export type ThreadNotice = {
    kind: ThreadNoticeKind;
    title: string;
    body: string;
    resetsAt: string | null;
    isOwnerOfAccount: boolean;
    canContinue: boolean;
    restoreSteps: { label: string; status: 'pending' | 'running' | 'done' | 'failed'; error: string | null }[];
};

/** Props of the `Threads/New` page; mirrors `App\Http\Controllers\Threads\NewThreadController`. */
export type NewThreadPageData = {
    repos: { slug: string; name: string }[];
    picker: {
        accounts: PickerAccount[];
        defaultsByRepo: Record<string, { accountId: number | null; model: string | null }>;
        models: string[];
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
