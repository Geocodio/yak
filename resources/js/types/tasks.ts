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
    /** The Sentry issue or flaky tests that started the task. */
    trigger: { label: string; url: string | null; lines: { text: string; url: string | null }[] } | null;
    startedBy: string | null;
    responsible: string | null;
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
    /** The run the page is focused on (`?run=`, else the live or latest run). */
    runId: number;
};

export type MediaItem = {
    id: number;
    kind: 'video' | 'image' | 'audio' | 'pdf' | 'text';
    url: string;
    thumbUrl: string | null;
    caption: string | null;
    /** Offered as a download button in the lightbox when set. */
    downloadUrl?: string | null;
    /** File name for the download, when it differs from the URL's. */
    downloadName?: string | null;
    /** The file's name, used to pick syntax highlighting for a text preview. */
    fileName?: string | null;
};

export type AttachmentData = {
    id: number;
    name: string;
    /** The label the message text uses for it, e.g. `Image #1`. */
    reference: string | null;
    url: string;
    mimeType: string;
    size: number;
    isImage: boolean;
    previewKind: 'image' | 'video' | 'audio' | 'pdf' | 'text' | null;
    downloadUrl: string;
};

export type QuestionOptionData = { label: string; description: string };

export type QuestionData = {
    id: string;
    header: string;
    question: string;
    multiSelect: boolean;
    options: QuestionOptionData[];
};

export type ClarificationAnswerItem = { header: string; answer: string | null; other: string | null; skipped: boolean };

export type ThreadEntryData = {
    kind: 'user' | 'yak' | 'clarification' | 'clarification-answers' | 'system' | 'review-context';
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
    /** Files sent with the message (`user`), including a reply to a clarification. */
    attachments?: AttachmentData[];
    answers?: ClarificationAnswerItem[];
    note?: string | null;
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
    /** Absolute time, `g:i:s A`. */
    at: string;
    /** ISO 8601; relative ages are computed from this on render. */
    createdAt: string;
    kind: 'tool' | 'prompt' | 'assistant' | 'level';
    error: boolean;
    milestone: boolean;
};

export type ActivitySummary = {
    entries: number;
    duration: string;
    latestId: number | null;
};

export type ActivityData = {
    rows: ActivityRow[];
    oldestId: number | null;
    hasOlder: boolean;
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
    riskAssessment?: {
        event: string;
        candidate: string;
        mode: string;
        risk_score: number | null;
        model_confidence: number | null;
        profile_version: string | null;
        scoring_version: number;
        reasons: string[];
        score_components: Record<string, number>;
        signals: Record<string, unknown>;
        observed: Record<string, unknown>;
    } | null;
    counts: { mustFix: number; shouldFix: number; consider: number };
    summaryHtml: string;
    comments: FindingComment[];
} | null;

export type ComposerData = {
    state: 'clarification' | 'questions' | 'steering' | 'follow_up' | 'disabled_failed' | 'disabled_closed';
    placeholder: string;
    note: string | null;
    buttonLabel: string | null;
    /** First attachment label number free in this conversation; labels never repeat across its messages. */
    nextAttachmentNumber: number;
    /** Messages waiting for the busy task, oldest first. */
    queued: QueuedMessage[];
};

/** How a message sent while Yak works reaches it: after the run finishes, or at its next tool call. */
export type SteeringMode = 'queue' | 'steer';

export type QueuedMessage = {
    id: number;
    text: string;
    mode: SteeringMode;
    /** Where it was sent from: `dashboard`, `slack`, `github_review`… */
    source: string;
    authorName: string | null;
    /** False for a GitHub review, which waits for the follow-up so the reviewer is asked to look again. */
    canSteer: boolean;
    attachments: AttachmentData[];
};

export type DebugData = Record<string, string>;

export type ActionsData = {
    canRetry: boolean;
    canCancel: boolean;
    canRerunReview: boolean;
    canRequestReview: boolean;
    canRetryRender: boolean;
    canReroute: boolean;
    rerouteTargets: string[];
};

export type TranscriptDetail = {
    label: string;
    value: string;
    error: boolean;
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
    html?: string;
    details?: TranscriptDetail[];
    prompt?: {
        user: string;
        system: string;
        meta: Record<string, string>;
    };
};
