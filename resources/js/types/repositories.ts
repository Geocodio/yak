export type RepositorySummary = {
    slug: string;
    name: string;
    ciLabel: string;
    setupStatus: string;
    sandboxBaseVersion: number | null;
    currentBaseVersion: number;
    isActive: boolean;
    isDefault: boolean;
    tasksTotal: number;
    tasks7d: number;
    prReviewEnabled: boolean;
    prReviews30d: number;
};

export type RepositoryDetail = {
    slug: string;
    name: string;
    description: string | null;
    agentInstructions: string | null;
    gitUrl: string | null;
    path: string;
    defaultBranch: string;
    publicSiteUrl: string | null;
    isActive: boolean;
    isDefault: boolean;
    ciSystem: string;
    sentryProject: string | null;
    prReviewEnabled: boolean;
    deploymentsEnabled: boolean;
    pathExcludes: string[] | null;
    githubFullName: string;
    githubUrl: string | null;
    githubNameDiverged: boolean;
};

export type RepositoryOptions = {
    ciSystems: { value: string; label: string }[];
    sentryProjects: { value: string; label: string }[];
    defaultPathExcludes: string[];
};

export type ManifestData = {
    port: number;
    healthProbePath: string;
    coldStart: string;
    checkoutRefresh: string;
    wakeTimeoutSeconds: number;
};

export type SandboxData = {
    snapshot: string | null;
    baseVersion: number | null;
    latestBaseVersion: number;
};

export type SetupHistoryRow = {
    status: string;
    id: string;
    startedAgo: string;
    duration: string;
};

export type RepositoryStats = {
    tasks: number;
    tasks7d: number;
    reviews30d: number;
};

export type GitHubSearchRepo = {
    id: number | null;
    fullName: string;
    name: string;
    description: string | null;
    defaultBranch: string;
    cloneUrl: string;
    private: boolean;
    language: string | null;
    pushedAt: string | null;
};
