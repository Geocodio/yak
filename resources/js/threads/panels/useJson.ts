import { useCallback, useEffect, useState } from 'react';

/** Fetches a JSON endpoint and exposes a `reload` that refetches without clearing the shown data. */
export function useJson<T>(url: string): { data: T | null; isLoading: boolean; error: string | null; reload: () => void } {
    const [data, setData] = useState<T | null>(null);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [tick, setTick] = useState(0);

    useEffect(() => {
        const controller = new AbortController();
        fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Request failed (${response.status})`);
                }
                return response.json() as Promise<T>;
            })
            .then((body) => {
                setData(body);
                setError(null);
                setIsLoading(false);
            })
            .catch((failure: unknown) => {
                if (controller.signal.aborted) {
                    return;
                }
                setError(failure instanceof Error ? failure.message : 'Request failed');
                setIsLoading(false);
            });

        return () => controller.abort();
    }, [url, tick]);

    const reload = useCallback(() => setTick((value) => value + 1), []);

    return { data, isLoading, error, reload };
}
