export type ThreadListFilter = 'mine' | 'yak' | 'everyone' | 'closed' | 'review';

export type ThreadSource = { key: string; label: string; reference: string | null; url: string | null };
export type ThreadStarter = { key: string; name: string; avatarUrl: string | null };
export type ThreadListFacets = {
    sources: { key: string; label: string; count: number }[];
    starters: { key: string; name: string; avatarUrl: string | null; count: number }[];
};

export type ThreadReviewSummary = {
    state: 'back_to_you' | 'not_reviewed' | 'with_yak' | 'approved';
    pullRequests: { repo: string; number: number; url: string; isDraft: boolean }[];
    blockedLabel: 'Conflicts' | 'CI failing' | null;
    isCiPassing: boolean;
    hasNewCommitsSinceApproval: boolean;
    otherApprovals: number;
};

export type ThreadListRow = {
    id: string;
    title: string;
    repo: string;
    step: string | null;
    status: 'running' | 'blocked' | 'paused' | 'stopped' | 'closed' | 'waiting_for_repository';
    needsYouReason: 'question' | 'blocked' | 'co_owner' | 'early_warning' | 'repository_question' | null;
    needsYouLabel: string | null;
    needsYouDetail: string | null;
    isUnread: boolean;
    source: ThreadSource;
    starter: ThreadStarter;
    isPrivate: boolean;
    isShared: boolean;
    isUnattended: boolean;
    isStarred: boolean;
    canDelete: boolean;
    ownerInitials: string | null;
    lastActivityAt: string | null;
    group: 'today' | 'this_week' | 'older';
    children: ThreadListRow[];
    review?: ThreadReviewSummary;
};

export type NeedsYouTaskItem = {
    type: 'task';
    taskId: string;
    title: string;
    needsYouReason: 'question' | 'co_owner';
    needsYouLabel: string;
    isPrivate: boolean;
    lastActivityAt: string;
};

export type ThreadListData = {
    starred: ThreadListRow[];
    needsYou: ThreadListRow[];
    threads: ThreadListRow[];
    needsYouCount: number;
    reviewCount: number;
    review?: {
        needsGitHubLink: boolean;
        backToYou: ThreadListRow[];
        notReviewed: ThreadListRow[];
        withYak: ThreadListRow[];
        approved: ThreadListRow[];
    };
    hasAnyThreads: boolean;
    facets: ThreadListFacets;
};

export type OperationsPanelData = {
    counts: { repositories: number; deployments: number; prReviews: number; observations: number };
    deploymentsRunning: number;
    prReviewsOpen: number;
    observationsNew: number;
    repositories: { slug: string; initials: string; status: string }[];
};
