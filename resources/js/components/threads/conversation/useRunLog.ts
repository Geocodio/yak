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
 * While the run is live, the last page and any page that holds a tool call with no output yet are read again every
 * few seconds, so new steps and the output of a running step show up without reopening the log.
 */
export function useRunLog(url: string | null, isLive = false): {
    entries: TranscriptEntry[];
    status: 'loading' | 'ready' | 'error';
    hasMore: boolean;
    isLoadingMore: boolean;
    /** The last request for a further page failed; the pages already loaded stay. */
    moreFailed: boolean;
    /** The server refused the last request because searches came too fast. */
    isRateLimited: boolean;
    loadMore: () => void;
    retry: () => void;
    search: (query: string) => void;
    /** The search the loaded entries answer; it trails what is typed by the debounce. */
    query: string;
} {
    const [pages, setPages] = useState<LoadedPage[]>([]);
    const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
    const [isLoadingMore, setIsLoadingMore] = useState(false);
    const [moreFailed, setMoreFailed] = useState(false);
    const [isRateLimited, setIsRateLimited] = useState(false);
    const [query, setQuery] = useState('');
    const [attempt, setAttempt] = useState(0);
    const timer = useRef<number | undefined>(undefined);
    const generation = useRef(0);

    // Another log starts with a clean search, so a term typed in the last run never filters this one unseen.
    useEffect(() => {
        window.clearTimeout(timer.current);
        setQuery('');
    }, [url]);

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
        setIsRateLimited(false);
        setPages([]);
        request(null)
            .then((page) => {
                if (generation.current === current) {
                    setPages([{ ...page, cursor: null }]);
                    setStatus('ready');
                }
            })
            .catch((failure: unknown) => {
                if (generation.current === current) {
                    setIsRateLimited(failure instanceof Error && failure.message.includes('(429)'));
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

    const pagesRef = useRef(pages);
    pagesRef.current = pages;
    const wasLive = useRef(isLive);
    const isRefreshing = useRef(false);

    // Reads again each page with an unanswered tool call, and the last page while it can still grow, and swaps it in
    // place, so complete pages are never fetched and the entries before and after keep their identity, the selected
    // step and the scroll position. A refresh that is still running is not started twice.
    const refreshPending = useCallback(
        (includesLastPage: boolean) => {
            if (isRefreshing.current) {
                return;
            }
            const loaded = pagesRef.current;
            const last = loaded[loaded.length - 1];
            const cursors = new Set(loaded.filter(hasPendingOutput).map((page) => page.cursor));
            if (includesLastPage && last !== undefined && last.nextCursor === null) {
                cursors.add(last.cursor);
            }
            if (cursors.size === 0) {
                return;
            }
            const current = generation.current;
            isRefreshing.current = true;

            Promise.allSettled(
                [...cursors].map((cursor) =>
                    request(cursor).then((fresh) => {
                        if (generation.current === current) {
                            setPages((previous) => previous.map((page) => (page.cursor === cursor ? { ...fresh, cursor } : page)));
                        }
                    }),
                ),
            ).finally(() => {
                isRefreshing.current = false;
            });
        },
        [request],
    );

    useEffect(() => {
        // A search reads the whole run on the server, so a searched log is a snapshot and is not read again.
        if (status !== 'ready' || !isLive || query !== '') {
            return;
        }
        const interval = window.setInterval(() => refreshPending(true), REFRESH_INTERVAL_MS);

        return () => window.clearInterval(interval);
    }, [status, isLive, query, refreshPending]);

    // The run ended while the log was open: one last read fills in what the final steps answered.
    useEffect(() => {
        if (wasLive.current && !isLive) {
            refreshPending(true);
        }
        wasLive.current = isLive;
    }, [isLive, refreshPending]);

    const search = useCallback((value: string) => {
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => setQuery(value.trim()), SEARCH_DELAY_MS);
    }, []);

    return { entries, status, hasMore: nextCursor !== null, isLoadingMore, moreFailed, isRateLimited, loadMore, retry: () => setAttempt((value) => value + 1), search, query };
}
