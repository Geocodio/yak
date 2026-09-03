export type HealthTone = 'ok' | 'warn' | 'fail' | 'idle';

/** Human labels for a `HealthTone`, matching the Channels page's status wording. */
export const HEALTH_TONE_LABELS: Record<HealthTone, string> = {
    ok: 'Ok',
    warn: 'Warning',
    fail: 'Failed',
    idle: 'Idle',
};

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
