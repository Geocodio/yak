/**
 * Mirrors the array shapes documented on App\Services\Telemetry\AnalyticsReport
 * and AnalyticsController. Keep the two in sync.
 */

export type Percentiles = {
    p50: number | null;
    p90: number | null;
    p99: number | null;
    n: number;
};

export type AnalyticsFilters = {
    period: '7d' | '30d' | '90d';
    repo: string;
    source: string;
    event: string;
    repos: string[];
    sources: string[];
    periods: string[];
    range: { start: string; end: string; days: number };
    enabled: boolean;
    retentionDays: number;
};

export type Headline = {
    tasks: number;
    prsOpened: number;
    merged: number;
    closedUnmerged: number;
    openPrs: number;
    mergeRate: number | null;
    oneShotRate: number | null;
    humanTouchRate: number | null;
    failureRate: number | null;
    timeToPr: Percentiles;
    timeToMerge: Percentiles;
    costPerMergedPr: number | null;
    totalCost: number;
    reviews: number;
    reviewActedRate: number | null;
};

export type FunnelStage = { stage: string; label: string; count: number };

export type DailySeries = {
    days: string[];
    series: { key: string; values: number[] }[];
};

export type LatencySeries = {
    days: string[];
    p50: (number | null)[];
    p90: (number | null)[];
    p99: (number | null)[];
    n: number[];
};

export type StageRow = Percentiles & { stage: string; label: string };

export type OutlierRow = {
    runId: number;
    taskId: number;
    taskUrl: string;
    externalId: string | null;
    repo: string | null;
    kind: string;
    outcome: string | null;
    totalMs: number;
    agentMs: number | null;
    toolMs: number;
    numTurns: number;
    costUsd: number;
    startedAt: string;
};

export type FailureRow = { category: string; count: number; costUsd: number };

export type RunKindRow = {
    kind: string;
    runs: number;
    success: number;
    error: number;
    costUsd: number;
    avgCostUsd: number;
    avgTurns: number;
    avgAgentMs: number;
};

export type TokenSummary = {
    input: number;
    output: number;
    cacheRead: number;
    cacheCreation: number;
    cacheHitRate: number | null;
    apiRetries: number;
    synthesizedResults: number;
    permissionDenials: number;
};

export type ToolRow = { tool: string; calls: number; errors: number; ms: number; avgMs: number };

export type FeatureRow = { feature: string; count: number; sources: Record<string, number> };

export type WebhookChannelRow = {
    channel: string;
    accepted: number;
    skipped: number;
    rejected: number;
    duplicate: number;
    ignored: number;
    error: number;
};

export type WebhookReasonRow = { channel: string; outcome: string; reason: string; count: number };

export type WebhookSummary = { byChannel: WebhookChannelRow[]; reasons: WebhookReasonRow[] };

export type ReviewSummary = {
    reviews: number;
    incremental: number;
    findings: number;
    lgtmRate: number | null;
    bySeverity: Record<string, number>;
    thumbsUp: number;
    thumbsDown: number;
    resolution: Record<string, number>;
    timeToReview: Percentiles;
    byCategory: { category: string; findings: number; thumbsUp: number; thumbsDown: number }[];
};

export type QueueSeries = {
    hours: string[];
    series: { key: string; values: number[] }[];
};

export type EventRow = {
    id: number;
    occurredAt: string;
    name: string;
    repo: string | null;
    source: string | null;
    taskId: number | null;
    taskUrl: string | null;
    durationMs: number | null;
    value: number | null;
    properties: Record<string, unknown> | null;
};

export type EventExplorer = { names: string[]; rows: EventRow[] };

export type AnalyticsPageProps = {
    filters: AnalyticsFilters;
    headline: Headline;
    funnel: FunnelStage[];
    throughput: DailySeries;
    latency: LatencySeries;
    stages: StageRow[];
    outliers: OutlierRow[];
    failures: FailureRow[];
    runsByKind: RunKindRow[];
    tokens: TokenSummary;
    tools: ToolRow[];
    features: FeatureRow[];
    webhooks: WebhookSummary;
    reviews: ReviewSummary;
    queues: QueueSeries;
    explorer: EventExplorer;
};
