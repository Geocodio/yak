import type { ApprovalLevel, ApprovalMode, OpenPullRequestRow } from '@/types/approvals';

export type LevelDefinition = { n: ApprovalLevel; codename: string; title: string; detail: string; summary: string };

export const LEVELS: LevelDefinition[] = [
    { n: 1, codename: 'COCKED PISTOL', title: 'Review only', detail: 'Yak reviews every PR and approves nothing. Same as off.', summary: 'Maximum readiness. Yak reviews every PR and approves nothing.' },
    { n: 2, codename: 'FAST PACE', title: 'No runtime change', detail: 'Docs, comments, copy, test-only changes and pure renames.', summary: 'Yak may approve only changes that cannot alter what runs in production.' },
    { n: 3, codename: 'ROUND HOUSE', title: 'Adds presentation and internal changes', detail: 'UI, styles and layout. Admin and developer tooling. Logging and diagnostics.', summary: 'Yak may also approve changes to how things look, internal tooling and logging.' },
    { n: 4, codename: 'DOUBLE TAKE', title: 'Adds behavior changes with a test', detail: 'Changes to what runs for customers, when the PR adds or updates a test file.', summary: 'Yak may also approve behavior changes, as long as the PR adds or updates a test.' },
    { n: 5, codename: 'FADE OUT', title: 'Adds any other clean PR', detail: 'Behavior changes without a test, as long as the review is clean.', summary: 'Yak may approve any clean PR that stays clear of protected assets.' },
];

export const NO_GO = [
    "Any finding in Yak's review",
    'Anything touching a protected asset',
    'Yak config, CI, dependencies, migrations, secrets and auth config',
    'Pull requests Yak opened itself',
];

export const ARM_HELP = {
    off: 'DEFCON 1 never approves, so there is nothing to arm. Pick DEFCON 2 or higher first.',
    locked: 'Safety on. Yak notes on each review what it would approve at this level, and approves nothing.',
    armed: 'Yak approves PRs that clear this level. It withdraws its approval and re-reviews whenever a new commit lands.',
    shadow: 'Safety on. Yak notes on each review what it would approve at this level. Read about two weeks of those before arming.',
};

export const STAND_DOWN_HELP = {
    already: 'Already standing down. DEFCON 1 is live and Yak approves nothing.',
    ready: 'One click, no confirmation, effective immediately. Sets DEFCON 1, review only, so Yak stops approving. Approvals Yak already gave stay until the next push to that PR.',
};

export function describe({ level, mode }: { level: ApprovalLevel; mode: ApprovalMode }): string {
    const state = level === 1 ? 'review only' : mode === 'enforce' ? 'armed' : 'shadow';

    return `DEFCON ${level} ${LEVELS[level - 1].codename}, ${state}`;
}

export function levelColor(level: ApprovalLevel, part: 'fill' | 'ink' | 'text' | 'tint' | 'glow'): string {
    return `var(--defcon-${level}-${part})`;
}

/** Loosening the level or arming needs a second key. */
export function needsConfirmation(live: { level: ApprovalLevel; mode: ApprovalMode }, draft: { level: ApprovalLevel; mode: ApprovalMode }): boolean {
    const liveArmed = live.level > 1 && live.mode === 'enforce';
    const draftArmed = draft.level > 1 && draft.mode === 'enforce';

    return draft.level > live.level || (draftArmed && !liveArmed);
}

/** Mirrors the level gate in ReviewApprovalPolicy. */
export function allowedKinds(level: ApprovalLevel, hasTestChange: boolean): string[] {
    switch (level) {
        case 1:
            return [];
        case 2:
            return ['no_runtime_change'];
        case 3:
            return ['no_runtime_change', 'presentation_or_internal'];
        case 4:
            return hasTestChange ? ['no_runtime_change', 'presentation_or_internal', 'behavior_change'] : ['no_runtime_change', 'presentation_or_internal'];
        default:
            return ['no_runtime_change', 'presentation_or_internal', 'behavior_change'];
    }
}

export type PullRequestOutcome = {
    kind: 'approve' | 'human' | 'waiting' | 'failed' | 'checking';
    label: string;
    reason: string;
    checks: string[];
};

export function outcomeAt(row: OpenPullRequestRow, level: ApprovalLevel, repositoryHolds: string[]): PullRequestOutcome {
    if (row.status === 'pending') {
        return { kind: 'checking', label: 'Checking', reason: 'Working through the checks for this commit.', checks: [] };
    }

    if (row.status === 'failed') {
        return { kind: 'failed', label: 'Could not check', reason: row.error ?? 'The check failed.', checks: [] };
    }

    const holds = [...row.holds, ...repositoryHolds];

    if (holds.length > 0) {
        return { kind: 'human', label: 'A human reviews', reason: holds[0], checks: holds };
    }

    if (row.waiting) {
        return { kind: 'waiting', label: 'Waiting', reason: 'Yak has not reviewed this commit yet.', checks: [] };
    }

    if (level === 1) {
        return { kind: 'human', label: 'A human reviews', reason: 'DEFCON 1 approves nothing.', checks: [] };
    }

    if (row.changeKind !== null && allowedKinds(level, row.hasTestChange).includes(row.changeKind)) {
        return { kind: 'approve', label: 'Would approve', reason: `Clean review. Fits DEFCON ${level}.`, checks: [] };
    }

    const fittingLevel = ([2, 3, 4, 5] as ApprovalLevel[]).find((candidate) => row.changeKind !== null && allowedKinds(candidate, row.hasTestChange).includes(row.changeKind));

    return {
        kind: 'human',
        label: 'A human reviews',
        reason: fittingLevel ? `Needs DEFCON ${fittingLevel} or higher.` : 'Yak could not tell what kind of change this is.',
        checks: [],
    };
}

/** How many open pull requests Yak would approve at each level, index 0 being DEFCON 1. */
export function approvedCountsByLevel(rows: OpenPullRequestRow[], repositoryHolds: string[]): number[] {
    return ([1, 2, 3, 4, 5] as ApprovalLevel[]).map((level) => rows.filter((row) => outcomeAt(row, level, repositoryHolds).kind === 'approve').length);
}
