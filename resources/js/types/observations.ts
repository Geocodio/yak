export type ObservationRow = {
    id: number;
    repo: string | null;
    source: string;
    kind: string;
    kindLabel: string;
    outcome: 'acted' | 'declined' | string;
    summary: string;
    subject: string | null;
    referenceUrl: string | null;
    taskId: number | null;
    taskUrl: string | null;
    createdAgo: string;
    createdAt: string;
    createdTooltip: string;
};

export type ObservationPage = {
    data: ObservationRow[];
    current_page: number;
    last_page: number;
};

export type ObservationFilterOptions = {
    repos: string[];
};

export type ObservationFilters = {
    repo: string;
    outcome: string;
    options: ObservationFilterOptions;
};
