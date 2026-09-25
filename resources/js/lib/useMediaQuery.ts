import { useSyncExternalStore } from 'react';

type Entry = {
    list: MediaQueryList;
    subscribe: (onChange: () => void) => () => void;
    getSnapshot: () => boolean;
};

const entries = new Map<string, Entry>();

/**
 * `subscribe`/`getSnapshot` are cached per query string rather than recreated
 * on every render -- `useSyncExternalStore` re-subscribes whenever the
 * function identity it's given changes, so a fresh closure each render would
 * remove and re-add the `matchMedia` change listener on every render.
 */
function getEntry(query: string): Entry {
    let entry = entries.get(query);
    if (!entry) {
        const list = window.matchMedia(query);
        entry = {
            list,
            subscribe: (onChange) => {
                list.addEventListener('change', onChange);
                return () => list.removeEventListener('change', onChange);
            },
            getSnapshot: () => list.matches,
        };
        entries.set(query, entry);
    }
    return entry;
}

/** Tracks a media query client-side, used to mount the desktop aside only from `lg` up. */
export function useMediaQuery(query: string): boolean {
    const entry = getEntry(query);
    return useSyncExternalStore(entry.subscribe, entry.getSnapshot);
}
