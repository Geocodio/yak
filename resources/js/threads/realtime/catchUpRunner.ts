import type { ThreadEventEnvelope } from '../../types/thread-events';

type CatchUpResult = { events: ThreadEventEnvelope[]; latestSequence: number };

/**
 * Whether the poll timer must fetch over HTTP: until the first catch-up lands, after a failed one,
 * and for as long as the socket is not connected (a network that blocks websockets still passes HTTP).
 */
export function needsHttpCatchUp(feed: { hasCaughtUp: boolean; lastCatchUpFailed: boolean; socketState: string }): boolean {
    return !feed.hasCaughtUp || feed.lastCatchUpFailed || feed.socketState !== 'connected';
}

export type LinkState = { kind: 'ok' | 'reconnecting' | 'offline'; since: number | null };

/** A socket that is still connecting right after page load is normal; past this it counts as down. */
const SOCKET_GRACE_MS = 5000;

/**
 * What the person should be told: `offline` when requests fail, `reconnecting` when only the live socket is down
 * (events then arrive through the HTTP poll, a few seconds late).
 */
export function linkKind(feed: { lastCatchUpFailed: boolean; isBrowserOnline: boolean; hasSocket: boolean; socketDownForMs: number }): LinkState['kind'] {
    if (feed.lastCatchUpFailed || !feed.isBrowserOnline) {
        return 'offline';
    }

    return feed.hasSocket && feed.socketDownForMs >= SOCKET_GRACE_MS ? 'reconnecting' : 'ok';
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
