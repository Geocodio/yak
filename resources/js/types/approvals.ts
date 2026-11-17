export type ApprovalMode = 'shadow' | 'enforce';
export type ApprovalLevel = 1 | 2 | 3 | 4 | 5;

export type ApprovalsRepository = {
    slug: string;
    name: string;
    defaultBranch: string;
    githubUrl: string | null;
    editUrl: string;
    updateUrl: string;
    draftConcernsUrl: string;
    checkUrl: string;
};

export type ApprovalSetting = {
    level: ApprovalLevel;
    mode: ApprovalMode;
    changedBy: string | null;
    changedAt: string | null;
};

export type ConcernApproval = 'always_human' | 'when_tested';

export type ApprovalConcerns = {
    state: 'ready' | 'missing' | 'invalid';
    items: { name: string; approval: ConcernApproval; description: string }[];
    fileUrl: string | null;
    editFileUrl: string | null;
};

export type ApprovalShadow = {
    days: number;
    reviews: number;
    wouldApprove: number;
    heldByConcern: number;
    firstRecordedAt: string | null;
    recent: { number: number | null; title: string | null; url: string; createdAt: string }[];
};

export type ApprovalGithub = {
    requiredChecks: 'present' | 'missing' | 'unknown';
    concernsPullRequest: { number: number; url: string } | null;
};

export type OpenPullRequestRow = {
    number: number;
    title: string;
    author: string;
    url: string;
    headSha: string;
    status: 'pending' | 'done' | 'failed';
    waiting: boolean;
    holds: string[];
    changeKind: string | null;
    hasTestChange: boolean;
    error: string | null;
    reviewNowUrl: string;
    yakReviewUrl: string | null;
};

export type OpenPullRequests = {
    checkedAt: string | null;
    error: string | null;
    pending: number;
    rows: OpenPullRequestRow[];
};

export type ApprovalsPage = {
    repository: ApprovalsRepository;
    approval: ApprovalSetting;
    concerns: ApprovalConcerns;
    shadow: ApprovalShadow;
    github?: ApprovalGithub;
    openPullRequests: OpenPullRequests;
    repositoryHolds: string[];
};
