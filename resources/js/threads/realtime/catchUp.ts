import type { ThreadEventEnvelope } from '../../types/thread-events';

/** A stalled link holds a request open without failing it; past this the request counts as failed and is retried. */
const REQUEST_TIMEOUT_MS = 15000;

async function getJson<T>(url: string, fetchImpl: typeof fetch): Promise<T> {
    const response = await fetchImpl(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS) });

    if (!response.ok) {
        throw new Error(`Thread events request failed with ${response.status}`);
    }

    return (await response.json()) as T;
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
