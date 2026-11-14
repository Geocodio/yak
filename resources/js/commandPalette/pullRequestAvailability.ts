/** Why "Open a pull request" cannot run while the thread's pull request is being opened or waits for a co-owner. */
export function pullRequestUnavailableReason(state: 'opening' | 'held' | 'failed' | null | undefined): string | undefined {
    if (state === 'opening') {
        return 'The pull request is being opened';
    }

    return state === 'held' ? 'Waiting for a co-owner' : undefined;
}
