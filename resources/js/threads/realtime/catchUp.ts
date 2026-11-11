import type { ThreadEventEnvelope } from '../../types/thread-events';

async function getJson<T>(url: string, fetchImpl: typeof fetch): Promise<T> {
    const response = await fetchImpl(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

    if (!response.ok) {
        throw new Error(`Thread events request failed with ${response.status}`);
    }

    return (await response.json()) as T;
}

/** Events after `after` (the Redis window when it covers the request, else stored entries only). */
export function fetchEventsAfter(
    threadId: number,
    after: number,
    fetchImpl: typeof fetch = fetch,
): Promise<{ events: ThreadEventEnvelope[]; latestSequence: number }> {
    return getJson(`/threads/${threadId}/events?after=${after}`, fetchImpl);
}

/** The full stored event behind a broadcast pointer. */
export function fetchEvent(threadId: number, sequence: number, fetchImpl: typeof fetch = fetch): Promise<ThreadEventEnvelope> {
    return getJson(`/threads/${threadId}/events/${sequence}`, fetchImpl);
}
