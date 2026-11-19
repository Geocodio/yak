import { useEffect, useRef, useState } from 'react';
import { THREAD_LIST_REFRESH_EVENT } from '@/lib/threadStar';
import { list } from '@/routes/threads';
import type { ThreadListData, ThreadListFilter } from '@/types/shell';

const REFRESH_INTERVAL_MS = 5000;
const REQUEST_TIMEOUT_MS = 20000;
const FILTERS_STORAGE_KEY = 'yak.threadList.filters';

function readStoredFilters(): { sources: string[]; starters: string[] } {
    try {
        const stored = JSON.parse(window.localStorage.getItem(FILTERS_STORAGE_KEY) ?? '{}');
        const asStrings = (value: unknown): string[] =>
            Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : [];

        return { sources: asStrings(stored?.sources), starters: asStrings(stored?.starters) };
    } catch {
        return { sources: [], starters: [] };
    }
}

/**
 * The sidebar thread list: starts from the shared first-paint data, then
 * refetches on filter change and every five seconds. A filter change aborts
 * the request in flight; a timed refresh waits for it, so a link slower than
 * the interval still gets answers.
 */
export function useThreadList(initial: ThreadListData | null) {
    const [storedFilters] = useState(readStoredFilters);
    const hasStoredFilters = storedFilters.sources.length > 0 || storedFilters.starters.length > 0;
    const [data, setData] = useState<ThreadListData | null>(hasStoredFilters ? null : initial);
    const [filter, setFilter] = useState<ThreadListFilter>('mine');
    const [sources, setSources] = useState<string[]>(storedFilters.sources);
    const [starters, setStarters] = useState<string[]>(storedFilters.starters);
    const [isLoading, setIsLoading] = useState(false);
    const controllerRef = useRef<AbortController | null>(null);
    const isFetching = useRef(false);
    const isFirstRun = useRef(true);

    useEffect(() => {
        // Shared props refresh on every Inertia visit; follow them while the
        // default Mine list is showing.
        if (filter === 'mine' && sources.length === 0 && starters.length === 0 && initial) {
            setData(initial);
        }
    }, [initial, filter, sources, starters]);

    useEffect(() => {
        try {
            window.localStorage.setItem(FILTERS_STORAGE_KEY, JSON.stringify({ sources, starters }));
        } catch {
            // Storage is unavailable: the picks last for this page view only.
        }
    }, [sources, starters]);

    const clearFilters = () => {
        setSources([]);
        setStarters([]);
    };

    useEffect(() => {
        const fetchList = async (showLoading: boolean) => {
            controllerRef.current?.abort();
            const controller = new AbortController();
            controllerRef.current = controller;
            if (showLoading) {
                setIsLoading(true);
            }
            isFetching.current = true;
            try {
                const response = await fetch(list.url({ query: { filter, sources, starters } }), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal: AbortSignal.any([controller.signal, AbortSignal.timeout(REQUEST_TIMEOUT_MS)]),
                });
                if (response.ok) {
                    setData((await response.json()) as ThreadListData);
                }
            } catch {
                // Aborted or offline: keep the rows already on screen.
            } finally {
                if (controllerRef.current === controller) {
                    isFetching.current = false;
                    setIsLoading(false);
                }
            }
        };

        const isInitialState = isFirstRun.current && !hasStoredFilters;
        isFirstRun.current = false;
        const timeout = isInitialState ? null : window.setTimeout(() => void fetchList(true), 0);
        const interval = window.setInterval(() => !isFetching.current && void fetchList(false), REFRESH_INTERVAL_MS);

        const onRefresh = () => void fetchList(false);
        window.addEventListener(THREAD_LIST_REFRESH_EVENT, onRefresh);

        return () => {
            window.removeEventListener(THREAD_LIST_REFRESH_EVENT, onRefresh);
            if (timeout !== null) {
                window.clearTimeout(timeout);
            }
            window.clearInterval(interval);
            controllerRef.current?.abort();
        };
    }, [filter, sources, starters]);

    return { data, filter, setFilter, sources, setSources, starters, setStarters, clearFilters, isLoading };
}
