import { useCallback, useEffect, useState } from 'react';
import { fetchJsonWithRetry } from '@/lib/fetchJsonWithRetry';
import { fillJsonCache, readCachedJson, writeCachedJson } from './jsonCache';

type State<T> = { url: string; data: T | null; error: string | null; isFetching: boolean };

function initialState<T>(url: string): State<T> {
    return { url, data: readCachedJson<T>(url) ?? null, error: null, isFetching: true };
}

/** Loads a URL into the shared cache ahead of time, so the panel that later reads it opens with data. */
export function prefetchJson(url: string): Promise<void> {
    return fillJsonCache(url, (target) => fetchJsonWithRetry(target));
}

/**
 * Fetches a JSON endpoint. It starts from the last response for the URL when
 * one is cached and refetches in the background (`isRefreshing`). `isLoading`
 * is true only while there is nothing to show, a failed refetch keeps the shown
 * data, and `reload` refetches without clearing it.
 */
export function useJson<T>(url: string): { data: T | null; isLoading: boolean; isRefreshing: boolean; error: string | null; reload: () => void } {
    const [state, setState] = useState<State<T>>(() => initialState<T>(url));
    const [tick, setTick] = useState(0);

    let current = state;
    if (state.url !== url) {
        current = initialState<T>(url);
        setState(current);
    }

    useEffect(() => {
        const controller = new AbortController();
        setState((previous) => (previous.url === url && !previous.isFetching ? { ...previous, isFetching: true } : previous));
        fetchJsonWithRetry<T>(url, { signal: controller.signal })
            .then((body) => {
                writeCachedJson(url, body);
                setState({ url, data: body, error: null, isFetching: false });
            })
            .catch((failure: unknown) => {
                if (controller.signal.aborted) {
                    return;
                }
                const message = failure instanceof Error ? failure.message : 'Request failed';
                setState((previous) => ({ url, data: previous.url === url ? previous.data : null, error: message, isFetching: false }));
            });

        return () => controller.abort();
    }, [url, tick]);

    const reload = useCallback(() => setTick((value) => value + 1), []);

    return {
        data: current.data,
        isLoading: current.data === null && current.isFetching,
        isRefreshing: current.data !== null && current.isFetching,
        error: current.error,
        reload,
    };
}
