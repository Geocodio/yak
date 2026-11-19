import type { FindingsData } from '@/types/tasks';

export type PrReviewCommentRow = {
    id: number;
    repoSlug: string | null;
    prNumber: number | null;
    prUrl: string | null;
    filePath: string;
    lineNumber: number;
    severity: 'must_fix' | 'should_fix' | 'consider' | string;
    category: string;
    thumbsUp: number;
    thumbsDown: number;
};

export type PrReviewCommentPage = {
    data: PrReviewCommentRow[];
    current_page: number;
    last_page: number;
};

export type PrReviewStats = {
    reviews: number;
    suggestions: number;
    thumbsUpRate: number;
    mostDownvotedCategory: string | null;
};

export type PrReviewerStat = {
    login: string;
    total: number;
    up: number;
    down: number;
};

export type PrReviewFilterOptions = {
    repos: string[];
    categories: string[];
    reviewers: string[];
};

export type PrReviewFilters = {
    repo: string;
    severity: string;
    category: string;
    scope: string;
    reviewer: string;
    reactions: boolean;
    sort: string;
    dir: 'asc' | 'desc';
    tab: 'all' | 'by_reviewer';
    options: PrReviewFilterOptions;
};

export type PrReviewForPrSummary = {
    repoSlug: string;
    number: number;
    title: string;
    url: string;
};

export type PrReviewRunStatus = 'queued' | 'reviewing' | 'reviewed' | 'failed' | 'cancelled';

/** One review run of a pull request; mirrors the shape documented in `App\Http\Resources\PrReviewRunData`. */
export type PrReviewRun = {
    id: string;
    status: PrReviewRunStatus;
    scope: 'full' | 'incremental' | string;
    startedAt: string;
    commit: string | null;
    model: string | null;
    stepCount: number;
    durationSeconds: number;
    costUsd: number;
    verdict: string | null;
    counts: { mustFix: number; shouldFix: number; consider: number };
    resolvedCount: number;
    findings: FindingsData;
    error: string | null;
    errorIsTruncated: boolean;
    /** The whole reason, sent only when `error` is cut. */
    errorDetails: string | null;
    canRerun: boolean;
    logUrl: string;
    isLive: boolean;
    latestActivity: string | null;
};

export type PrReviewPullRequest = {
    repository: string;
    author: string | null;
    state: 'open' | 'closed' | 'merged';
    branch: string | null;
    preview: { status: string; statusLabel: string; tone: string; hostname: string; url: string } | null;
    runCount: number;
    failedCount: number;
    totalCostUsd: number;
    helpfulCount: number;
    notHelpfulCount: number;
    thread: { id: string; title: string } | null;
    source: { key: string; label: string; reference: string | null; url: string } | null;
};
