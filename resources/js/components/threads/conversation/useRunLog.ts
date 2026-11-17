import { useCallback, useEffect, useRef, useState } from 'react';
import { fetchJsonWithRetry } from '@/lib/fetchJsonWithRetry';
import type { TranscriptEntry } from '@/types/tasks';

type LogPage = { entries: TranscriptEntry[]; nextCursor: number | null };

const SEARCH_DELAY_MS = 250;

/**
 * Reads the full log of one run in pages. A new `url` or a new search starts over, a failed request keeps what is
 * shown and offers a retry, and the next page is asked for only on request so a slow log never blocks the page.
 */
export function useRunLog(url: string | null): {
    entries: TranscriptEntry[];
    status: 'loading' | 'ready' | 'error';
    hasMore: boolean;
    isLoadingMore: boolean;
    /** The last request for a further page failed; the pages already loaded stay. */
    moreFailed: boolean;
    loadMore: () => void;
    retry: () => void;
    search: (query: string) => void;
} {
    const [entries, setEntries] = useState<TranscriptEntry[]>([]);
    const [nextCursor, setNextCursor] = useState<number | null>(null);
    const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
    const [isLoadingMore, setIsLoadingMore] = useState(false);
    const [moreFailed, setMoreFailed] = useState(false);
    const [query, setQuery] = useState('');
    const [attempt, setAttempt] = useState(0);
    const timer = useRef<number | undefined>(undefined);
    const generation = useRef(0);

    const request = useCallback(
        (cursor: number | null) => {
            const params = new URLSearchParams();
            if (cursor !== null) {
                params.set('cursor', String(cursor));
            }
            if (query !== '') {
                params.set('search', query);
            }

            return fetchJsonWithRetry<LogPage>(`${url}${params.size > 0 ? `?${params}` : ''}`);
        },
        [url, query],
    );

    useEffect(() => {
        if (url === null) {
            return;
        }
        const current = ++generation.current;
        setStatus('loading');
        setMoreFailed(false);
        setEntries([]);
        setNextCursor(null);
        request(null)
            .then((page) => {
                if (generation.current === current) {
                    setEntries(page.entries);
                    setNextCursor(page.nextCursor);
                    setStatus('ready');
                }
            })
            .catch(() => {
                if (generation.current === current) {
                    setStatus('error');
                }
            });

        return () => {
            generation.current++;
        };
    }, [url, request, attempt]);

    useEffect(() => () => window.clearTimeout(timer.current), []);

    const loadMore = useCallback(() => {
        if (nextCursor === null || isLoadingMore) {
            return;
        }
        const current = generation.current;
        setIsLoadingMore(true);
        setMoreFailed(false);
        request(nextCursor)
            .then((page) => {
                if (generation.current === current) {
                    setEntries((previous) => [...previous, ...page.entries]);
                    setNextCursor(page.nextCursor);
                }
            })
            .catch(() => generation.current === current && setMoreFailed(true))
            .finally(() => setIsLoadingMore(false));
    }, [nextCursor, isLoadingMore, request]);

    const search = useCallback((value: string) => {
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => setQuery(value.trim()), SEARCH_DELAY_MS);
    }, []);

    return { entries, status, hasMore: nextCursor !== null, isLoadingMore, moreFailed, loadMore, retry: () => setAttempt((value) => value + 1), search };
}
