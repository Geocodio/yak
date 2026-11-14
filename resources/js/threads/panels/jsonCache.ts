/**
 * The last JSON response per URL, kept for the life of the page so a panel
 * that opens again, or opens after the page fetched the same URL, shows that
 * response at once while a fresh copy loads.
 */
// ponytail: unbounded per page session; add an LRU cap if long sessions grow memory.
const cache = new Map<string, unknown>();
const inFlight = new Map<string, Promise<void>>();

export function readCachedJson<T>(url: string): T | undefined {
    return cache.get(url) as T | undefined;
}

export function writeCachedJson(url: string, data: unknown): void {
    cache.set(url, data);
}

/** Loads a URL into the cache unless it is cached or already loading. A failure leaves the cache as it was. */
export function fillJsonCache(url: string, fetcher: (url: string) => Promise<unknown>): Promise<void> {
    if (cache.has(url)) {
        return Promise.resolve();
    }
    const pending = inFlight.get(url);
    if (pending !== undefined) {
        return pending;
    }
    const request = fetcher(url)
        .then((data) => {
            cache.set(url, data);
        })
        .catch(() => {})
        .finally(() => inFlight.delete(url));
    inFlight.set(url, request);

    return request;
}

export function clearJsonCache(): void {
    cache.clear();
    inFlight.clear();
}
