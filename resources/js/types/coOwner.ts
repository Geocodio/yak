/** Mirrors the `Panel` shape in app/Http/Resources/CoOwnerPanelData.php. */
export type CoOwnerStatus = 'pending' | 'approved' | 'changes_requested' | 'rejected' | 'withdrawn' | 'expired' | 'superseded';

export type CoOwnerArea = {
    name: string;
    risk: string;
    rationale: string;
    files: { path: string; status: string }[];
};

export type CoOwnerAttempt = { entry: string; kind: string; outcome: string };

export type CoOwnerViewer = {
    isRequester: boolean;
    isLinked: boolean;
    login: string | null;
    /** A placeholder once the request is resolved. */
    access: 'write' | 'read' | 'unlinked' | 'error';
};

export type CoOwnerLinkError = {
    kind: 'taken' | 'failed';
    code: string | null;
    ownerName: string | null;
    ownerLogin: string | null;
};

export type CoOwnerData = {
    id: number;
    status: CoOwnerStatus;
    heldAt: string;
    expiresAt: string;
    decidedAt: string | null;
    decidedBy: string | null;
    feedback: string | null;
    repoName: string;
    branch: string | null;
    compareUrl: string;
    prUrl: string | null;
    areas: CoOwnerArea[];
    holdReasons: string[];
    stat: { additions: number; deletions: number; files: number };
    asked: string[];
    attempts: CoOwnerAttempt[];
    fallbackOwner: string | null;
    viewer: CoOwnerViewer;
    connectUrl: string;
    requesterName: string;
    linkError: CoOwnerLinkError | null;
};

/** `errors.coOwner` after a refused decision. */
export type CoOwnerDecisionError = { reason: string; status: number | null; message: string };
