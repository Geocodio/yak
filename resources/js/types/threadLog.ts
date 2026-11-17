export type ThreadLogTone = 'ok' | 'warn' | 'bad' | 'info' | 'neutral';

export type ThreadLogSort = 'last_activity' | 'source' | 'starter' | 'repo';

export type ThreadLogRow = {
    id: string;
    url: string;
    title: string;
    statusLine: string;
    status: { key: string; label: string; tone: ThreadLogTone };
    source: { key: string; label: string; reference: string | null; url: string | null };
    starter: { key: string; name: string; avatarUrl: string | null };
    repo: string | null;
    pullRequest: { number: number | null; url: string | null; state: 'open' | 'merged' | 'closed' | 'held' } | null;
    costUsd: number;
    lastActivityAt: string;
    followUpCount: number;
};

export type ThreadLogPage = {
    data: ThreadLogRow[];
    current_page: number;
    last_page: number;
    links: unknown;
    meta?: unknown;
};

export type ThreadLogFilters = {
    status: string[];
    sources: string[];
    starters: string[];
    repo: string;
    pull_request: string;
    needs_co_owner: boolean;
    search: string;
    sort: ThreadLogSort;
    direction: 'asc' | 'desc';
};

export type ThreadLogFacets = {
    sources: { key: string; label: string; count: number }[];
    starters: { key: string; name: string; avatarUrl: string | null; count: number }[];
    repos: string[];
};

export type ThreadLogCounts = { total: number; needsCoOwner: number };

export const THREAD_LOG_STATUSES: { key: string; label: string }[] = [
    { key: 'needs_you', label: 'Needs you' },
    { key: 'waiting_for_co_owner', label: 'Waiting for a co-owner' },
    { key: 'working', label: 'Working' },
    { key: 'stopped', label: 'Stopped' },
    { key: 'done', label: 'Done' },
    { key: 'closed', label: 'Closed' },
];
