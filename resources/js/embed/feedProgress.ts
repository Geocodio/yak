export type FeedProgress = { threadId: string; lastSequence: number; isSettled: boolean };

let current: FeedProgress = { threadId: '', lastSequence: 0, isSettled: false };
const listeners = new Set<(progress: FeedProgress) => void>();

/** What the conversation's live feed knows: the newest sequence, and whether the first catch-up has finished. */
export function reportFeedProgress(progress: FeedProgress): void {
    current = progress;
    listeners.forEach((listener) => listener(progress));
}

export function feedProgressOf(threadId: string): FeedProgress {
    return current.threadId === threadId ? current : { threadId, lastSequence: 0, isSettled: false };
}

export function subscribeFeedProgress(listener: (progress: FeedProgress) => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}
