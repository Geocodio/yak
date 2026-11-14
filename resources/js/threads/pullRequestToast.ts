type OpeningState = 'opening' | 'held' | 'failed' | null;

export type PullRequestToast =
    | { kind: 'open'; number: number; url: string }
    | { kind: 'held' }
    | { kind: 'failed'; reason: string | null }
    | null;

/** Which toast, if any, ends an Open PR: only the step out of `opening` says how it went. */
export function pullRequestToast(was: OpeningState, now: OpeningState, pullRequest: { number: number; url: string } | null, error: string | null): PullRequestToast {
    if (was !== 'opening' || now === 'opening') {
        return null;
    }
    if (pullRequest !== null) {
        return { kind: 'open', number: pullRequest.number, url: pullRequest.url };
    }
    if (now === 'held') {
        return { kind: 'held' };
    }
    if (now === 'failed') {
        return { kind: 'failed', reason: error };
    }

    return null;
}
