import { useEffect } from 'react';
import { SHORTCUTS, createSequenceMatcher, isTypingTarget } from './shortcuts';
import { findActionByShortcut } from './store';

const SEQUENCE_TIMEOUT_MILLISECONDS = 1000;
const PALETTE_KEY = SHORTCUTS.openPalette.keys[1].toLowerCase();

/** The palette marks its own popup with this attribute so it does not count as a blocking surface. */
export const PALETTE_ATTRIBUTE = 'data-command-palette';

const BLOCKING_SURFACES = '[role="dialog"], [role="alertdialog"], [role="menu"], [role="listbox"]';

/**
 * True while a dialog or menu other than the palette is open. Toasts also use `role="dialog"` and sit in a live
 * region, so they are skipped, as is anything inside the palette.
 */
function isOtherSurfaceOpen(): boolean {
    return [...document.querySelectorAll(BLOCKING_SURFACES)].some((surface) => surface.closest(`[${PALETTE_ATTRIBUTE}], [aria-live]`) === null);
}

/** The one global keydown listener for app shortcuts. Mounted once, by `AppCommandPalette`. */
export function ShortcutListener() {
    useEffect(() => {
        const match = createSequenceMatcher(SEQUENCE_TIMEOUT_MILLISECONDS);

        const onKeyDown = (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && !event.altKey && event.key.toLowerCase() === PALETTE_KEY) {
                event.preventDefault();
                if (!event.repeat) {
                    window.dispatchEvent(new CustomEvent('yak:toggle-palette'));
                }

                return;
            }
            if (event.metaKey || event.ctrlKey || event.altKey || event.repeat || event.key.length !== 1 || isTypingTarget(event.target as HTMLElement | null) || isOtherSurfaceOpen()) {
                return;
            }

            const id = match(event.key, event.timeStamp);
            const action = id === null ? null : findActionByShortcut(id);
            if (action === null || action.unavailable !== undefined) {
                return;
            }
            event.preventDefault();
            window.dispatchEvent(new CustomEvent('yak:run-palette-action', { detail: action }));
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    return null;
}
