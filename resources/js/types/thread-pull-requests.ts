export type PullRequestCheck = {
    name: string;
    state: string;
    durationSeconds: number | null;
    excerpt: string | null;
};

export type PullRequestRow = {
    rowId: number;
    repo: string;
    repoInitials: string;
    role: string;
    number: number | null;
    url: string;
    title: string;
    state: 'open' | 'merged' | 'closed';
    closedAt: string | null;
    ci: { status: string; passedChecks: number; totalChecks: number; checks: PullRequestCheck[] } | null;
    mergeAfter: { repo: string; number: number | null; reason: string | null } | null;
    siblingBlock: string | null;
    canAskToFix: boolean;
};

export type RepositoryWithoutPullRequest = {
    repo: string;
    role: string;
    reason: 'context' | 'unchanged' | 'pending';
    note: string;
};

export type ThreadPullRequestsData = {
    state: 'none' | 'waiting_for_ci' | 'blocked' | 'ready' | 'partial' | 'done';
    banner: { title: string; body: string; tone: 'info' | 'danger' | 'success' };
    openCount: number;
    totalCount: number;
    canDrive: boolean;
    pullRequests: PullRequestRow[];
    withoutPullRequest: RepositoryWithoutPullRequest[];
};
