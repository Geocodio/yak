import { useEffect } from 'react';
import { initialFragment } from '@/lib/initialFragment';

const OLD_FRAGMENTS: Record<string, string> = { questions: 'card-question', 'co-owner': 'card-co-owner' };

/** The element id an address fragment points at; the names old task links used map to the thread's own ids. */
export function resolveFragment(fragment: string, turnAnchors: Record<string, string>): string {
    if (fragment.startsWith('turn-')) {
        return turnAnchors[fragment.slice('turn-'.length)] ?? fragment;
    }

    return OLD_FRAGMENTS[fragment] ?? fragment;
}

/** Scrolls to the element once it renders, repeating briefly because the conversation pins itself to the newest entry while it settles. Returns the stop function. */
function scrollToElement(id: string): () => void {
    let attempts = 0;
    let foundAt: number | null = null;
    const timer = window.setInterval(() => {
        const element = document.getElementById(id);
        attempts += 1;

        if (element !== null) {
            foundAt ??= attempts;
            element.scrollIntoView({ block: 'center' });
        }

        if (attempts >= 30 || (foundAt !== null && attempts >= foundAt + 10)) {
            window.clearInterval(timer);
        }
    }, 100);

    return () => window.clearInterval(timer);
}

/** Scrolls to the element the address fragment names, on load and when the fragment changes; the page is built after load, so the browser cannot. */
export function useFragmentScroll(threadId: string, turnAnchors: Record<string, string>) {
    useEffect(() => {
        let stop = () => {};
        const follow = (fragment: string) => {
            stop();

            if (fragment !== '') {
                stop = scrollToElement(resolveFragment(decodeURIComponent(fragment), turnAnchors));
            }
        };
        const onHashChange = () => follow(window.location.hash.slice(1));

        follow(window.location.hash.slice(1) || initialFragment);
        window.addEventListener('hashchange', onHashChange);

        return () => {
            stop();
            window.removeEventListener('hashchange', onHashChange);
        };
        // The fragment is read once per thread; later answers must not scroll the page again.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [threadId]);
}
