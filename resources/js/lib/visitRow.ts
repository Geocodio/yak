import { router } from '@inertiajs/react';
import type { VisitOptions } from '@inertiajs/core';
import type { MouseEvent } from 'react';

/**
 * Click and middle-click handlers for a table row that links to a page.
 * Cmd/Ctrl/Shift-click and middle-click open the page in a new tab, like a
 * plain link; a normal click visits it through Inertia. Clicks on links or
 * buttons inside the row are left to those elements.
 */
export function rowLinkHandlers(url: string, options: VisitOptions = {}, onVisit?: () => void) {
    const open = (event: MouseEvent) => {
        if (event.target instanceof Element && event.target.closest('a, button') !== null) {
            return;
        }

        if (event.button === 1 || event.metaKey || event.ctrlKey || event.shiftKey) {
            window.open(url, '_blank', 'noopener');
            return;
        }

        if (event.button === 0) {
            onVisit?.();
            router.visit(url, options);
        }
    };

    return { onClick: open, onAuxClick: open };
}
