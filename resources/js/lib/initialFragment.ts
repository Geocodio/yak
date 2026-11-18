/** The address fragment the page was opened with, read before the router rewrites the address without it. */
let openedWith: string = typeof window === 'undefined' ? '' : window.location.hash.slice(1);
let openedFor: string | null = null;

/** The fragment the page was opened with, for the first thread shown only; an in-app visit to another thread gets none. */
export function openingFragment(threadId: string): string {
    openedFor ??= threadId;

    if (openedFor !== threadId) {
        openedWith = '';
    }

    return openedWith;
}

/** Decodes a percent-encoded fragment; a malformed one is used as written. */
export function decodeFragment(fragment: string): string {
    try {
        return decodeURIComponent(fragment);
    } catch {
        return fragment;
    }
}
