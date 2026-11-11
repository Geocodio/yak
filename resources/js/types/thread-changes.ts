/** Mirrors the array shapes in `App\Http\Resources\ThreadDiffData` and `ThreadEnvironmentData`. */

export type DiffFileStatus = 'added' | 'modified' | 'deleted' | 'renamed';

export type ThreadDiffFile = {
    id: number;
    repo: string;
    path: string;
    oldPath: string | null;
    status: DiffFileStatus;
    additions: number | null;
    deletions: number | null;
    isBinary: boolean;
    isTooLarge: boolean;
};

export type ThreadDiffRepository = {
    repo: string;
    role: 'primary' | 'changes' | 'context';
    isThrowaway: boolean;
    baseSha: string;
    headSha: string;
    filesCount: number;
    additions: number;
    deletions: number;
};

export type ThreadDiffSummary = {
    baseSha: string;
    headSha: string;
    capturedAt: string;
    isTruncated: boolean;
    filesCount: number;
    additions: number;
    deletions: number;
    throwawayFilesCount: number;
    isTurnRunning: boolean;
    repositories: ThreadDiffRepository[];
    files: ThreadDiffFile[];
};

/** Body of `threads.diff.files.show`. */
export type ThreadDiffFilePatch = {
    path: string;
    oldPath: string | null;
    status: DiffFileStatus;
    isBinary: boolean;
    isTooLarge: boolean;
    patch: string | null;
};

export type ThreadEnvironmentRepository = {
    id: number;
    repo: string;
    role: 'primary' | 'changes' | 'context';
    path: string;
    setupStatus: 'pending' | 'cloning' | 'setup' | 'ready' | 'failed';
    setupError: string | null;
    setupOutput: string | null;
    addedBy: string | null;
    addedAt: string | null;
    isDatasetAttached: boolean;
    canMakeContextOnly: boolean;
};

export type ThreadEnvironment = {
    sandbox: { state: 'none' | 'starting' | 'running' | 'paused' | 'stopped' | 'destroying' | 'destroyed'; stateChangedAt: string | null };
    branch: string | null;
    pullRequest: { number: number; url: string; state: string } | null;
    deployment: { url: string; status: string } | null;
    account: { name: string; kind: string; isYours: boolean } | null;
    model: string | null;
    startedBy: { name: string; at: string } | null;
    stoppedAfterMinutes: number;
    repositories: ThreadEnvironmentRepository[];
    notUploadedDirectories: string[];
};
