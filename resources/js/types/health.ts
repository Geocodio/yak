export type HealthTone = 'ok' | 'warn' | 'fail' | 'idle';

export type HealthCheckMeta = {
    id: string;
    name: string;
    docsUrl: string | null;
};

export type HealthResultData = {
    status: HealthTone;
    message: string;
    checkedAgo: string | null;
    actionLabel: string | null;
    actionUrl: string | null;
};

export type ChannelRow = {
    name: string;
    slug: string;
    status: HealthTone;
    statusLabel: string;
    message: string | null;
    description: string;
    docsUrl: string;
    enabled: boolean;
    required: boolean;
};
