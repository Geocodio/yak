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
};
