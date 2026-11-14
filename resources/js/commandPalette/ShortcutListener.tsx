import { useEffect } from "react";
import { createSequenceMatcher, isTypingTarget } from "./shortcuts";
import { findActionByShortcut } from "./store";

const SEQUENCE_TIMEOUT_MILLISECONDS = 1000;

/** The palette marks its own popup with this attribute so it does not count as a blocking dialog. */
export const PALETTE_ATTRIBUTE = "data-command-palette";

/** True while a dialog other than the palette is open. Toasts also use `role="dialog"` and sit in a live region, so they are skipped. */
function isOtherDialogOpen(): boolean {
    return [
        ...document.querySelectorAll('[role="dialog"], [role="alertdialog"]'),
    ].some(
        (dialog) =>
            !dialog.hasAttribute(PALETTE_ATTRIBUTE) &&
            dialog.closest("[aria-live]") === null,
    );
}

/** The one global keydown listener for app shortcuts. Mounted once, by `AppCommandPalette`. */
export function ShortcutListener() {
    useEffect(() => {
        const match = createSequenceMatcher(SEQUENCE_TIMEOUT_MILLISECONDS);

        const onKeyDown = (event: KeyboardEvent) => {
            if (
                (event.metaKey || event.ctrlKey) &&
                !event.altKey &&
                event.key.toLowerCase() === "k"
            ) {
                event.preventDefault();
                window.dispatchEvent(new CustomEvent("yak:toggle-palette"));

                return;
            }
            if (
                event.metaKey ||
                event.ctrlKey ||
                event.altKey ||
                event.key.length !== 1 ||
                isTypingTarget(event.target as HTMLElement | null) ||
                isOtherDialogOpen()
            ) {
                return;
            }

            const id = match(event.key, event.timeStamp);
            const action = id === null ? null : findActionByShortcut(id);
            if (action === null || action.unavailable !== undefined) {
                return;
            }
            event.preventDefault();
            window.dispatchEvent(
                new CustomEvent("yak:run-palette-action", { detail: action }),
            );
        };

        window.addEventListener("keydown", onKeyDown);

        return () => window.removeEventListener("keydown", onKeyDown);
    }, []);

    return null;
}
