import type { TaskStatus } from '@/lib/status';

export type TaskPr = {
    number: number | null;
    state: 'open' | 'merged' | 'closed';
    url: string | null;
};

export type FollowUpRow = {
    id: number;
    status: TaskStatus;
    description: string;
    externalId: string | null;
    createdAgo: string;
};

export type TaskRow = {
    id: number;
    status: TaskStatus;
    source: string;
    sourceLabel: string;
    by: string | null;
    repo: string | null;
    repoUrl: string | null;
    description: string;
    externalId: string | null;
    externalUrl: string | null;
    pr: TaskPr | null;
    previewUrl: string | null;
    previewGif: string | null;
    deploymentUrl: string | null;
    cost: string | null;
    createdAgo: string;
    createdAt: string | null;
    createdTooltip: string;
    followUps: FollowUpRow[];
};

export type TaskTab = 'tasks' | 'reviews' | 'setup';

export type TaskCounts = {
    tasks: number;
    reviews: number;
    setup: number;
};

export type TaskFilters = {
    status: string;
    source: string;
    repo: string;
    pr: string;
    sort: string;
    direction: 'asc' | 'desc';
    tab: TaskTab;
    options: {
        repos: string[];
        sources: string[];
    };
};

export type SetupCardItem = {
    title: string;
    body: string;
    done: boolean;
    url: string;
    external: boolean;
};

export type SetupCard = {
    items: SetupCardItem[];
} | null;

export type TaskPage = {
    data: TaskRow[];
    current_page: number;
    last_page: number;
    links: unknown;
    meta?: unknown;
};

// -- Task detail (Tasks/Show) --

export type TaskDetail = {
    id: number;
    status: TaskStatus;
    statusLabel: string;
    mode: 'fix' | 'research' | 'review' | 'setup';
    headline: string;
    summary: string;
    repo: string | null;
    repoUrl: string | null;
    sourceLabel: string;
    sourceUrl: string | null;
    model: string | null;
    turns: number | null;
    duration: string;
    cost: string | null;
    branch: string | null;
    nextSteps: string | null;
    error: string | null;
    externalId: string | null;
    pr: TaskPr | null;
    researchArtifactUrl: string | null;
    attemptCount: number;
    attempt: number;
};

export type MediaItem = {
    id: number;
    kind: 'video' | 'image';
    url: string;
    thumbUrl: string | null;
    caption: string | null;
};

export type ThreadEntryData = {
    kind: 'user' | 'yak' | 'clarification' | 'system' | 'review-context';
    who: string | null;
    meta: string;
    bodyHtml: string;
    fullText?: string | null;
    options?: string[];
    expiresIn?: string | null;
    superseded?: boolean;
    live?: boolean;
    error?: string | null;
    links?: { label: string; url: string }[];
    media?: MediaItem[];
};

export type RunSummary = {
    id: number;
    label: string;
    live: boolean;
};

export type ProgressStep = {
    label: string;
    tooltip: string;
    done: boolean;
    current: boolean;
};

export type ActivityRow = {
    id: number;
    badge: string | null;
    text: string;
    at: string;
    kind: 'tool' | 'prompt' | 'assistant' | 'level';
    error: boolean;
    milestone: boolean;
    group: number | null;
};

export type ActivityData = {
    entries: number;
    duration: string;
    rows: ActivityRow[];
};

export type Chapter = {
    title: string;
    seconds: number;
};

export type WalkthroughData = {
    status: 'none' | 'rendering' | 'ready' | 'failed';
    videoUrl?: string;
    posterUrl?: string | null;
    chapters?: Chapter[];
    error?: string;
};

export type DeploymentData = {
    status: string;
    hostname: string;
    url: string;
} | null;

export type FindingComment = {
    severity: 'must_fix' | 'should_fix' | 'consider' | string;
    path: string | null;
    line: number | null;
    category: string | null;
    bodyHtml: string;
};

export type FindingsData = {
    verdict: string;
    counts: { mustFix: number; shouldFix: number; consider: number };
    summaryHtml: string;
    comments: FindingComment[];
} | null;

export type ComposerData = {
    state: 'clarification' | 'steering' | 'follow_up' | 'disabled_failed' | 'disabled_closed';
    placeholder: string;
    note: string | null;
    buttonLabel: string | null;
};

export type DebugData = Record<string, string>;

export type ActionsData = {
    canRetry: boolean;
    canCancel: boolean;
    canRerunReview: boolean;
    canRetryRender: boolean;
    canReroute: boolean;
    rerouteTargets: string[];
};

export type TranscriptEntry = {
    id: number;
    badge: string | null;
    text: string;
    at: string;
    kind: 'tool' | 'prompt' | 'assistant' | 'level';
    error: boolean;
    milestone: boolean;
    tool?: string;
    input?: string | null;
    output?: string | null;
    prompt?: {
        user: string;
        system: string;
        meta: Record<string, string>;
    };
};
