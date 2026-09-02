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
    bodyHtml: string;
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

export type PrReviewEntry = {
    id: number;
    scope: 'full' | 'incremental' | string;
    dismissed: boolean;
    createdAgo: string | null;
    reviewer: string;
    commitSha: string;
    taskId: number;
    findings: FindingsData;
};
