export type PhonePillTone = 'ok' | 'warn' | 'fail' | 'info' | 'brand' | 'neutral';

export type PhonePill = { label: string; tone: PhonePillTone };

export type PhonePillInput = {
    /** The header status of a notice (token problem, usage limit, rebuild, waiting); these outrank everything else. */
    noticeStatus: { label: string; tone: 'info' | 'warn' | 'fail' | 'brand' } | null;
    isClosed: boolean;
    /** The thread waits for a repository to be picked; it has no sandbox, branch or work yet. */
    isWaitingForRepository?: boolean;
    isLegacy: boolean;
    isHeldForCoOwner: boolean;
    isWorking: boolean;
    pullRequest: { number: number; state: 'open' | 'merged' | 'closed' } | null;
    /** The thread stopped by itself and shows the stop card. */
    isStopped: boolean;
    /** The label of the failed progress step, such as "CI failed", when there is one. */
    failedStepLabel: string | null;
};

const PULL_REQUEST_STATE_LABELS = { open: 'Open', merged: 'Merged', closed: 'Closed' } as const;
const PULL_REQUEST_STATE_TONES = { open: 'ok', merged: 'brand', closed: 'neutral' } as const;

/** The one state pill of the phone header: a notice first, then closed, held, working, the pull request, stopped. */
export function phoneStatusPill(input: PhonePillInput): PhonePill | null {
    if (input.noticeStatus !== null) {
        return input.noticeStatus;
    }
    if (input.isClosed || input.isLegacy) {
        return { label: 'Closed', tone: 'neutral' };
    }
    if (input.isWaitingForRepository === true) {
        return { label: 'No repository yet', tone: 'warn' };
    }
    if (input.isHeldForCoOwner) {
        return { label: 'PR held for a co-owner', tone: 'warn' };
    }
    if (input.isWorking) {
        return { label: 'Working', tone: 'brand' };
    }
    if (input.pullRequest !== null) {
        return { label: `#${input.pullRequest.number} ${PULL_REQUEST_STATE_LABELS[input.pullRequest.state]}`, tone: PULL_REQUEST_STATE_TONES[input.pullRequest.state] };
    }
    if (input.isStopped) {
        return { label: input.failedStepLabel === null ? 'Stopped' : `Stopped, ${input.failedStepLabel}`, tone: 'fail' };
    }

    return null;
}

/** The progress step label without its attempt count: "CI failed, 2 of 2" becomes "CI failed". */
export function failedStepReason(label: string | null): string | null {
    return label === null ? null : label.replace(/,?\s*\d+ of \d+$/, '');
}

/** The phone progress row shows only while work runs or after it stopped; a finished thread and one waiting for a person do not need it. */
export function isProgressShownOnPhone(requestStatusKey: string): boolean {
    return requestStatusKey === 'working' || requestStatusKey === 'stopped';
}

/** Index of the step the row centres on: the failed step, else the current step, else the last finished one. */
export function progressFocusIndex(states: string[]): number {
    const failed = states.lastIndexOf('failed');

    if (failed !== -1) {
        return failed;
    }

    const current = states.lastIndexOf('current');

    return current !== -1 ? current : Math.max(0, states.lastIndexOf('done'));
}

/** A phone shows two steps before the focused one and one after it. */
export function isStepNearFocus(index: number, focusIndex: number): boolean {
    return index >= focusIndex - 2 && index <= focusIndex + 1;
}
