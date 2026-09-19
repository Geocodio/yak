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
    reviewPolicy: ReviewPolicy;
    riskProfiles: { active: RiskProfile | null; drafts: RiskProfile[] };
    riskProfileActionUrl: string;
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
    defaultReviewPolicy: ReviewPolicy;
    ciSystems: { value: string; label: string }[];
    sentryProjects: { value: string; label: string }[];
    defaultPathExcludes: string[];
};

export type ReviewPolicy = {
    mode: 'off' | 'shadow' | 'enforce';
    allowed_paths: string[];
    blocked_paths: string[];
    required_checks: { name: string; app_id: number }[];
    required_statuses: { name: string; creator_id: number }[];
    max_files: number;
    max_lines: number;
    max_risk_score: number;
    min_confidence: number;
    profile_max_age_days: number;
};

export type RiskProfile = {
    version: string;
    source_sha: string;
    approved_by?: string;
    approved_at?: string;
    areas: { name: string; paths: string[]; symbols: string[]; risk: string; rationale: string; evidence: string[] }[];
    unknowns: string[];
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

export type RepositoryDocsLinks = {
    guide: string;
    adding: string;
    setup: string;
    claudeMd: string;
    routing: string;
    prReview: string;
    refresh: string;
    rerunSetup: string;
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
