import type { ThreadEventEnvelope } from '../../types/thread-events';

type CatchUpResult = { events: ThreadEventEnvelope[]; latestSequence: number };

/**
 * Whether the poll timer must fetch over HTTP: until the first catch-up lands, after a failed one,
 * and for as long as the socket is not connected (a network that blocks websockets still passes HTTP).
 */
export function needsHttpCatchUp(feed: { hasCaughtUp: boolean; lastCatchUpFailed: boolean; socketState: string }): boolean {
    return !feed.hasCaughtUp || feed.lastCatchUpFailed || feed.socketState !== 'connected';
}

/** Serializes catch-ups: a request that arrives mid-flight schedules exactly one more run once the current one succeeds. */
export function createCatchUpRunner(options: {
    fetchAfter: (after: number) => Promise<CatchUpResult>;
    getLastSequence: () => number;
    onResult: (result: CatchUpResult) => void;
    onStatus: (status: 'catching-up' | 'live' | 'offline') => void;
}): { request(): Promise<void> } {
    let inFlight: Promise<void> | null = null;
    let rerunRequested = false;

    const run = async (): Promise<void> => {
        do {
            rerunRequested = false;
            options.onStatus('catching-up');

            try {
                options.onResult(await options.fetchAfter(options.getLastSequence()));
                options.onStatus('live');
            } catch {
                options.onStatus('offline');

                return;
            }
        } while (rerunRequested);
    };

    return {
        request(): Promise<void> {
            if (inFlight) {
                rerunRequested = true;

                return inFlight;
            }

            inFlight = run().finally(() => {
                inFlight = null;
            });

            return inFlight;
        },
    };
}
