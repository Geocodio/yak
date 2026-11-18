import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { fetchJsonWithRetry } from '@/lib/fetchJsonWithRetry';
import type { TranscriptEntry } from '@/types/tasks';

type LogPage = { entries: TranscriptEntry[]; nextCursor: number | null };
type LoadedPage = LogPage & { cursor: number | null };

const REFRESH_INTERVAL_MS = 3000;

const hasPendingOutput = (page: LogPage) => page.entries.some((entry) => entry.kind === 'tool' && entry.output == null);

const SEARCH_DELAY_MS = 250;

/**
 * Reads the full log of one run in pages. A new `url` or a new search starts over, a failed request keeps what is
 * shown and offers a retry, and the next page is asked for only on request so a slow log never blocks the page.
 * While the run is live, a page that holds a tool call with no output yet is read again every few seconds, and only
 * that page, so the output of a running step shows up without reopening the log.
 */
export function useRunLog(url: string | null, isLive = false): {
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
    const [pages, setPages] = useState<LoadedPage[]>([]);
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
        setPages([]);
        request(null)
            .then((page) => {
                if (generation.current === current) {
                    setPages([{ ...page, cursor: null }]);
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

    const nextCursor = pages.length === 0 ? null : (pages[pages.length - 1].nextCursor ?? null);
    const entries = useMemo(() => pages.flatMap((page) => page.entries), [pages]);

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
                    setPages((previous) => [...previous, { ...page, cursor: nextCursor }]);
                }
            })
            .catch(() => generation.current === current && setMoreFailed(true))
            .finally(() => setIsLoadingMore(false));
    }, [nextCursor, isLoadingMore, request]);

    const pendingCursors = pages.filter(hasPendingOutput).map((page) => page.cursor);
    const hasPending = pendingCursors.length > 0;
    const pendingKey = JSON.stringify(pendingCursors);
    const wasLive = useRef(isLive);

    // Reads each page with an unanswered tool call again and swaps it in place, so pages that are complete are
    // never fetched and the entries before and after keep their identity, the selected step and the scroll position.
    const refreshPending = useCallback(() => {
        const current = generation.current;
        const cursors: (number | null)[] = JSON.parse(pendingKey);

        cursors.forEach((cursor) => {
            request(cursor)
                .then((fresh) => {
                    if (generation.current === current) {
                        setPages((previous) => previous.map((page) => (page.cursor === cursor ? { ...fresh, cursor } : page)));
                    }
                })
                .catch(() => undefined);
        });
    }, [pendingKey, request]);

    useEffect(() => {
        if (status !== 'ready' || !hasPending || !isLive) {
            return;
        }
        const interval = window.setInterval(refreshPending, REFRESH_INTERVAL_MS);

        return () => window.clearInterval(interval);
    }, [status, hasPending, isLive, refreshPending]);

    // The run ended while the log was open: one last read fills in what the final steps answered.
    useEffect(() => {
        if (wasLive.current && !isLive && hasPending) {
            refreshPending();
        }
        wasLive.current = isLive;
    }, [isLive, hasPending, refreshPending]);

    const search = useCallback((value: string) => {
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => setQuery(value.trim()), SEARCH_DELAY_MS);
    }, []);

    return { entries, status, hasMore: nextCursor !== null, isLoadingMore, moreFailed, loadMore, retry: () => setAttempt((value) => value + 1), search };
}
