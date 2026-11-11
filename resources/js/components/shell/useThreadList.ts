import { useEffect, useRef, useState } from 'react';
import { list } from '@/routes/threads';
import type { ThreadListData, ThreadListFilter } from '@/types/shell';

const SEARCH_DEBOUNCE_MS = 250;
const REFRESH_INTERVAL_MS = 5000;

/**
 * The sidebar thread list: starts from the shared first-paint data, then
 * refetches on filter or search change (search debounced) and every five
 * seconds. A newer request aborts the one still in flight.
 */
export function useThreadList(initial: ThreadListData | null) {
    const [data, setData] = useState<ThreadListData | null>(initial);
    const [filter, setFilter] = useState<ThreadListFilter>('mine');
    const [search, setSearch] = useState('');
    const [isLoading, setIsLoading] = useState(false);
    const controllerRef = useRef<AbortController | null>(null);
    const isFirstRun = useRef(true);

    useEffect(() => {
        // Shared props refresh on every Inertia visit; follow them while the
        // default Mine list with no search is showing.
        if (filter === 'mine' && search === '' && initial) {
            setData(initial);
        }
    }, [initial, filter, search]);

    useEffect(() => {
        const fetchList = async (showLoading: boolean) => {
            controllerRef.current?.abort();
            const controller = new AbortController();
            controllerRef.current = controller;
            if (showLoading) {
                setIsLoading(true);
            }
            try {
                const query: { filter: ThreadListFilter; search?: string } = { filter };
                if (search !== '') {
                    query.search = search;
                }
                const response = await fetch(list.url({ query }), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });
                if (response.ok) {
                    setData((await response.json()) as ThreadListData);
                }
            } catch {
                // Aborted or offline: keep the rows already on screen.
            } finally {
                if (controllerRef.current === controller) {
                    setIsLoading(false);
                }
            }
        };

        const isInitialState = isFirstRun.current;
        isFirstRun.current = false;
        const delay = search === '' ? 0 : SEARCH_DEBOUNCE_MS;
        const timeout = isInitialState ? null : window.setTimeout(() => void fetchList(true), delay);
        const interval = window.setInterval(() => void fetchList(false), REFRESH_INTERVAL_MS);

        return () => {
            if (timeout !== null) {
                window.clearTimeout(timeout);
            }
            window.clearInterval(interval);
            controllerRef.current?.abort();
        };
    }, [filter, search]);

    return { data, filter, setFilter, search, setSearch, isLoading };
}
