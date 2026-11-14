export type ThreadListFilter = 'mine' | 'yak' | 'everyone' | 'closed';

export type ThreadListRow = {
    id: string;
    title: string;
    repo: string;
    status: 'running' | 'blocked' | 'paused' | 'stopped' | 'closed' | 'waiting_for_repository';
    needsYouReason: 'question' | 'blocked' | 'unseen' | null;
    needsYouLabel: string | null;
    isPrivate: boolean;
    isShared: boolean;
    isUnattended: boolean;
    isStarred: boolean;
    canDelete: boolean;
    ownerInitials: string | null;
    lastActivityAt: string | null;
    group: 'today' | 'this_week' | 'older';
    children: ThreadListRow[];
};

export type ThreadListData = {
    starred: ThreadListRow[];
    needsYou: ThreadListRow[];
    threads: ThreadListRow[];
    needsYouCount: number;
    hasAnyThreads: boolean;
};

export type OperationsPanelData = {
    counts: { repositories: number; deployments: number; prReviews: number; observations: number };
    deploymentsRunning: number;
    prReviewsOpen: number;
    observationsNew: number;
    repositories: { slug: string; initials: string; status: string }[];
};
