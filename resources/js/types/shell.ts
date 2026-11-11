export type ThreadListFilter = 'mine' | 'everyone';

export type ThreadListRow = {
    id: number;
    title: string;
    repo: string;
    status: 'running' | 'blocked' | 'paused' | 'stopped';
    needsYouReason: 'question' | 'blocked' | 'unseen' | null;
    needsYouLabel: string | null;
    isPrivate: boolean;
    isUnattended: boolean;
    lastActivityAt: string | null;
    group: 'today' | 'this_week' | 'older';
    children: ThreadListRow[];
};

export type ThreadListData = {
    needsYou: ThreadListRow[];
    threads: ThreadListRow[];
    needsYouCount: number;
};

export type OperationsPanelData = {
    counts: { repositories: number; deployments: number; prReviews: number; observations: number };
    deploymentsRunning: number;
    prReviewsOpen: number;
    observationsNew: number;
    repositories: { slug: string; initials: string; status: string }[];
};
