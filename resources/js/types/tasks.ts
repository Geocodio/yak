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
