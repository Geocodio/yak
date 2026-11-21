import { useEffect } from 'react';
import { sendToApp } from '@/embed/bridge';
import { decodeFragment, openingFragment } from '@/lib/initialFragment';

const OLD_FRAGMENTS: Record<string, string> = { questions: 'card-question', 'co-owner': 'card-co-owner' };

/** The element id an address fragment points at; the names old task links used map to the thread's own ids. */
export function resolveFragment(fragment: string, turnAnchors: Record<string, string>): string {
    if (fragment.startsWith('turn-')) {
        return turnAnchors[fragment.slice('turn-'.length)] ?? fragment;
    }

    return OLD_FRAGMENTS[fragment] ?? fragment;
}

/** How long the target keeps its mark. */
const HIGHLIGHT_MS = 1600;

/** Marks the element the fragment points at and moves the screen reader to it, without scrolling again. */
function markTarget(element: HTMLElement): void {
    element.setAttribute('data-fragment-target', '');
    window.setTimeout(() => element.removeAttribute('data-fragment-target'), HIGHLIGHT_MS);

    if (!element.hasAttribute('tabindex') && element.matches('div, section, article, li')) {
        element.setAttribute('tabindex', '-1');
    }

    if (!isTyping(document.activeElement)) {
        element.focus({ preventScroll: true });
    }
}

/** Whether a person is typing in the element, so the target must not take the cursor from it. */
function isTyping(active: Element | null): boolean {
    return active instanceof HTMLElement && (active.matches('input, textarea, select') || active.isContentEditable);
}

/** Scrolls to the element once it renders, repeating briefly because the conversation pins itself to the newest entry while it settles, and marks it once. Calls `onMiss` when it never renders. Returns the stop function. */
function scrollToElement(id: string, onMiss: () => void): () => void {
    let attempts = 0;
    let foundAt: number | null = null;
    const timer = window.setInterval(() => {
        const element = document.getElementById(id);
        attempts += 1;

        if (element !== null) {
            if (foundAt === null) {
                foundAt = attempts;
                markTarget(element);
            }
            element.scrollIntoView({ block: 'center' });
        }

        if (attempts >= 30 || (foundAt !== null && attempts >= foundAt + 10)) {
            window.clearInterval(timer);

            if (foundAt === null) {
                onMiss();
            }
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
                stop = scrollToElement(resolveFragment(decodeFragment(fragment), turnAnchors), () => sendToApp({ type: 'focusMissed', fragment }));
            }
        };
        const onHashChange = () => follow(window.location.hash.slice(1));

        follow(window.location.hash.slice(1) || openingFragment(threadId));
        window.addEventListener('hashchange', onHashChange);

        return () => {
            stop();
            window.removeEventListener('hashchange', onHashChange);
        };
        // The fragment is read once per thread; later answers must not scroll the page again.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [threadId]);
}
