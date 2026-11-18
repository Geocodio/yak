/** Mirrors the shapes documented on RepositoryRunData. */
export type RepositoryRunKind = 'setup' | 'repository_research';

export type RepositoryRunStatus = 'queued' | 'running' | 'ready' | 'done' | 'failed' | 'cancelled';

export type RepositoryRunRow = {
    id: string;
    kind: RepositoryRunKind;
    status: RepositoryRunStatus;
    resultLine: string;
    startedBy: string;
    costUsd: number;
    startedAt: string;
    url: string;
    isLive: boolean;
};

export type RepositoryRunRemembered = {
    testCommand: string | null;
    previewPort: number | null;
    startCommand: string | null;
    snapshot: string | null;
};

export type RepositoryRunDetail = {
    id: string;
    kind: RepositoryRunKind;
    status: RepositoryRunStatus;
    isLive: boolean;
    startedAt: string;
    startedBy: string;
    model: string | null;
    stepCount: number;
    durationSeconds: number;
    costUsd: number;
    resultLine: string;
    answerHtml: string | null;
    remembered: RepositoryRunRemembered | null;
    waitingThreads: { id: string; title: string; url: string }[];
    error: string | null;
    errorIsTruncated: boolean;
    errorDetails: string | null;
    canRetry: boolean;
    logUrl: string;
    retryUrl: string | null;
    threadUrl: string | null;
    riskProfileUrl: string | null;
};
