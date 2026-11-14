import { useEffect, useRef, useState } from 'react';
import { fetchJsonWithRetry } from '@/lib/fetchJsonWithRetry';
import { search } from '@/routes/palette';
import type { PaletteSearchPullRequest, PaletteSearchRepository, PaletteSearchResults, PaletteSearchThread } from '@/types/paletteSearch';
import { latestOnly } from './latestOnly';

const DEBOUNCE_MILLISECONDS = 150;
const MINIMUM_CHARACTERS = 2;

const NO_RESULTS: PaletteSearchResults = { threads: [], repositories: [], pullRequests: [] };

/**
 * Server search for the palette. Results of the previous query stay until the
 * next answer arrives; they clear when the palette closes or the query is too
 * short. An answer to an older query, or one that lands after close, is dropped.
 */
export function usePaletteSearch(query: string, isOpen: boolean): { threads: PaletteSearchThread[]; repositories: PaletteSearchRepository[]; pullRequests: PaletteSearchPullRequest[]; isLoading: boolean } {
    const [results, setResults] = useState<PaletteSearchResults>(NO_RESULTS);
    const [isLoading, setIsLoading] = useState(false);
    const guard = useRef(latestOnly<PaletteSearchResults>());
    const trimmed = query.trim();
    const isSearching = isOpen && trimmed.length >= MINIMUM_CHARACTERS;

    useEffect(() => {
        if (!isSearching) {
            setResults(NO_RESULTS);
            setIsLoading(false);

            return;
        }

        const controller = new AbortController();
        setIsLoading(true);
        const timer = setTimeout(() => {
            guard.current(fetchJsonWithRetry<PaletteSearchResults>(search.url({ query: { query: trimmed } }), { signal: controller.signal }))
                .then((body) => {
                    if (body !== null && !controller.signal.aborted) {
                        setResults(body);
                        setIsLoading(false);
                    }
                })
                .catch(() => {
                    if (!controller.signal.aborted) {
                        setIsLoading(false);
                    }
                });
        }, DEBOUNCE_MILLISECONDS);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [isSearching, trimmed]);

    return { ...results, isLoading };
}
