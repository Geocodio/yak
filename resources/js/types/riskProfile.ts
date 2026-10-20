// Mirrors App\Http\Resources\RiskProfilePageData::from() and App\Support\RiskMapTree.

export type Risk = 'low' | 'medium' | 'high' | 'critical' | 'unknown';

const HELD_RISKS: readonly Risk[] = ['critical', 'high', 'unknown'];

/** Held risks need a co-owner before Yak opens a PR; medium and low open as normal PRs. */
export function isHeldRisk(risk: Risk): boolean {
    return HELD_RISKS.includes(risk);
}

export type PullRequestRef = { number: number; title: string; url: string };

export type ChangedIn = {
    sha: string;
    url: string | null;
    authorName: string;
    date: string;
    pullRequest: PullRequestRef | null;
};

export type RiskMapSegment = { area: number | null; isFloor: boolean; isUnchecked: boolean; files: number };

export type RiskMapNode = {
    /** Opaque key. Synthetic ids start with "/" ("/files:app/Http"); slug it before using it in a data-testid. */
    id: string;
    name: string;
    kind: 'directory' | 'files' | 'other';
    files: number;
    paths: string[];
    pathCount?: number;
    children: RiskMapNode[];
    segments?: RiskMapSegment[];
};

export type RiskMapCoverageRow = { name: string; files: number; coveredFiles: number; uncheckedFiles: number; floorFiles: number };

export type RiskMapGap = { path: string; files: number; isAggregate: boolean };

export type RiskMap =
    | {
          available: true;
          totalFiles: number;
          coveredFiles: number;
          uncheckedFiles: number;
          tree: RiskMapNode[];
          coverage: RiskMapCoverageRow[];
          gaps: RiskMapGap[];
      }
    | { available: false; reason: 'truncated' | 'too-large' | 'unreachable' };

export type RiskArea = {
    index: number;
    name: string;
    risk: Risk;
    rationale: string;
    evidence: string[];
    symbols: string[];
    paths: { glob: string; files: number | null }[];
    files: number | null;
    owners: string[];
    matchesNoFile: boolean;
};

export type RiskProfileFile = {
    validCommitSha: string | null;
    error: string | null;
    errorCommitSha: string | null;
    errorCommitUrl: string | null;
    errorPullRequest: PullRequestRef | null;
    blobUrl: string | null;
    editUrl: string | null;
    changedIn: ChangedIn | null;
};

export type RiskProfileGeneration = {
    status: 'running' | 'waiting' | 'failed';
    task: { id: number; url: string; startedAt: string | null; completedAt: string | null; error: string | null } | null;
    pullRequest: (PullRequestRef & { areas: number | null; unknowns: number | null }) | null;
};

export type RiskProfileDetail = {
    gateMode: 'off' | 'enforce';
    alwaysHeld: string[];
    riskCounts: Record<Risk, number>;
    unknowns: string[];
    areas: RiskArea[];
};

export type RiskProfilePage = {
    state: 'profile' | 'empty' | 'never-valid' | 'unavailable';
    source: 'file' | 'database' | null;
    readState: 'read' | 'unreachable' | 'unavailable';
    readAt: string | null;
    readError: string | null;
    commitSha: string | null;
    file: RiskProfileFile | null;
    generation: RiskProfileGeneration | null;
    profile: RiskProfileDetail | null;
    /** Null only in the unavailable, empty and never-valid states. */
    map: RiskMap | null;
};

export type RiskProfileRepository = {
    slug: string;
    name: string;
    defaultBranch: string;
    githubUrl: string | null;
    editUrl: string;
    generateUrl: string;
};

// Mirrors App\Services\RiskProfileChangeCheck::run().
export type ChangeTestFile = {
    path: string;
    previousPath: string | null;
    area: ChangeTestAreaRef | null;
    isFloor: boolean;
    isUnchecked: boolean;
    isHeld: boolean;
};

export type ChangeTestAreaRef = { index: number; name: string; risk: string };

export type ChangeTestResult = {
    input: string;
    verdict: 'held' | 'normal' | 'invalid' | 'not-found' | 'error' | 'no-profile';
    kind: 'pr' | 'branch' | null;
    label: string;
    url: string | null;
    message: string | null;
    files: ChangeTestFile[];
    heldAreas: ChangeTestAreaRef[];
    isFloorHeld: boolean;
    asks: string[];
    capReason: 'listing' | 'budget' | null;
    capMessage: string | null;
    holdReason: string | null;
    gateMode: 'off' | 'enforce';
};
