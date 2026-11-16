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

export type ApprovalsPage = {
    repository: ApprovalsRepository;
    approval: ApprovalSetting;
    concerns: ApprovalConcerns;
    shadow: ApprovalShadow;
    github?: ApprovalGithub;
};
