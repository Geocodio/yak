import { router } from '@inertiajs/react';

function patchedUrl(patch: Record<string, string | number | undefined | null>): URL {
    const url = new URL(window.location.href);

    Object.entries(patch).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '') {
            url.searchParams.delete(key);
        } else {
            url.searchParams.set(key, String(value));
        }
    });

    return url;
}

/**
 * Patches the current URL's query string and visits it via Inertia,
 * preserving scroll/state -- used for the run/attempt query params on the
 * task detail page, which drive most of `TaskDetailData::build()`
 * (`resolveFocusedRun`), so switching runs/attempts intentionally re-fetches
 * everything. An empty (default) `only` is a FULL visit, not a partial one
 * (Inertia treats `only.length === 0` as "not a partial reload") -- pass a
 * non-empty `only` only when narrower data genuinely suffices.
 */
export function navigateTaskQuery(patch: Record<string, string | number | undefined | null>, only: string[] = []) {
    const url = patchedUrl(patch);

    router.get(url.pathname + url.search, {}, { preserveState: true, preserveScroll: true, replace: true, only });
}

/**
 * Updates the query string in place via `history.replaceState` with no
 * Inertia visit at all -- used for the transcript overlay's `?log=` deep
 * link when the entry is already loaded client-side, so stepping through
 * entries with the keyboard never round-trips to the server.
 */
export function replaceTaskQuery(patch: Record<string, string | number | undefined | null>) {
    const url = patchedUrl(patch);
    window.history.replaceState(window.history.state, '', url.pathname + url.search);
}
