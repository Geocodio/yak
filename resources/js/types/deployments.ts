export type DeploymentTone = 'ok' | 'warn' | 'fail' | 'info' | 'idle';

export type DeploymentRow = {
    id: number;
    repoSlug: string;
    branch: string;
    status: string;
    statusLabel: string;
    tone: DeploymentTone;
    hostname: string;
    lastAccessedAgo: string | null;
    longLived: boolean;
    hibernatesAfter: string;
};

export type DeploymentDetail = {
    id: number;
    hostname: string;
    url: string;
    repoSlug: string;
    branch: string;
    status: string;
    statusLabel: string;
    tone: DeploymentTone;
    commit: string | null;
    templateVersion: number;
    repoTemplateVersion: number;
    lastAccessedAgo: string | null;
    failure: string | null;
};

export type HibernationData = {
    longLived: boolean;
    autoLongLived: boolean;
    timeout: string;
};

export type ShareLinkData = {
    active: boolean;
    expiresAgo: string | null;
} | null;

export type DeploymentLogEntry = {
    at: string;
    phase: string | null;
    message: string;
    output: string;
    error: boolean;
};

export type DeploymentFilters = {
    status: string;
};
