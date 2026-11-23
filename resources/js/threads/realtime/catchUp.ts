import { fetchJsonWithRetry } from '../../lib/fetchJsonWithRetry.ts';
import type { ThreadEventEnvelope } from '../../types/thread-events';

/** A stalled link holds a request open without failing it; past this the request counts as failed and is retried. */
const REQUEST_TIMEOUT_MS = 15000;

function getJson<T>(url: string, fetchImpl: typeof fetch): Promise<T> {
    // A request dropped on the way (a flaky link, a reset connection) is retried before the feed reports it as failed.
    return fetchJsonWithRetry<T>(url, { signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS), fetcher: fetchImpl });
}

/** Events after `after` (the Redis window when it covers the request, else stored entries only). */
export function fetchEventsAfter(
    threadId: string,
    after: number,
    fetchImpl: typeof fetch = fetch,
): Promise<{ events: ThreadEventEnvelope[]; latestSequence: number }> {
    return getJson(`/threads/${threadId}/events?after=${after}`, fetchImpl);
}

/** The full stored event behind a broadcast pointer. */
export function fetchEvent(threadId: string, sequence: number, fetchImpl: typeof fetch = fetch): Promise<ThreadEventEnvelope> {
    return getJson(`/threads/${threadId}/events/${sequence}`, fetchImpl);
}
