export type CostSummary = {
    claudeCode: { amount: number; tasks: number };
    apiSpend: { amount: number; calls: number };
    taskCount: number;
    avgCost: number;
    avgDuration: string;
    successRate: number;
    clarificationRate: number;
};

export type VideoSummary = {
    rendered: number;
    failed: number;
    avgRenderTime: string;
    outputMb: number;
    voiceoverCredits: number;
};

export type ChartBucket = {
    label: string;
    claudeCode: number;
    api: number;
    current: boolean;
};

export type ChartData = {
    buckets: ChartBucket[];
    max: number;
};

export type BreakdownRow = {
    date: string;
    tasks: number;
    sources: Record<string, number>;
    total: number;
};

export type ApiSpendRow = {
    date: string;
    calls: number;
    total: number;
};

export type MergeRateRow = {
    repo: string;
    totalPrs: number;
    merged: number;
    closed: number;
    pending: number;
    rate: number;
};

export type CostFilters = {
    period: 'daily' | 'weekly' | 'monthly';
    repo: string;
    source: string;
    repos: string[];
    sources: string[];
};
