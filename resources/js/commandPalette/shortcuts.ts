/** The only list of hotkeys. Components bind a key through `PaletteAction.shortcut`, never directly. */
export const SHORTCUTS = {
    openPalette: { keys: ['mod', 'K'], label: 'Open the command palette', group: 'General' },
    showShortcuts: { keys: ['?'], label: 'Keyboard shortcuts', group: 'General' },
    newThread: { keys: ['C'], label: 'New thread', group: 'General' },
    toggleSidebar: { keys: ['['], label: 'Toggle the sidebar', group: 'General' },
    goHome: { keys: ['G', 'H'], label: 'Go to Home', group: 'General' },
    nextNeedsYou: { keys: ['G', 'N'], label: 'Next thread that needs you', group: 'General' },
    openPreview: { keys: ['G', 'P'], label: 'Open preview in a new window', group: 'Thread' },
    showFiles: { keys: ['G', 'F'], label: 'Show files', group: 'Thread' },
    showTerminal: { keys: ['G', 'T'], label: 'Show terminal', group: 'Thread' },
    showConversation: { keys: ['G', 'C'], label: 'Show conversation', group: 'Thread' },
    openPullRequest: { keys: ['G', 'R'], label: 'Open pull request on GitHub', group: 'Thread' },
    filterChangedFiles: { keys: ['/'], label: 'Filter changed files', group: 'Changes' },
} as const;

export type ShortcutId = keyof typeof SHORTCUTS;

/** Keys that only work inside the open palette; listed in the shortcuts dialog for display. */
export const PALETTE_KEYS: { keys: string[]; label: string }[] = [
    { keys: ['Enter'], label: 'Run the selected action' },
    { keys: ['Tab'], label: 'More actions for a result' },
    { keys: ['Backspace'], label: 'Go back one step' },
    { keys: ['Esc'], label: 'Close' },
];

/** The keycaps to render for a shortcut, with `mod` resolved for the platform. */
export function keycapsFor(id: ShortcutId, isMac: boolean): string[] {
    const modifier = isMac ? '⌘' : 'Ctrl';

    return SHORTCUTS[id].keys.map((key) => (key === 'mod' ? modifier : key));
}

/** One-line text for a tooltip: "⌘K" on Mac, "Ctrl+K" elsewhere. */
export function shortcutLabel(id: ShortcutId, isMac: boolean): string {
    return keycapsFor(id, isMac).join(isMac ? '' : '+');
}

const shortcutEntries = Object.entries(SHORTCUTS) as [ShortcutId, { keys: readonly string[] }][];

/**
 * Matches single keys and two-key sequences against `SHORTCUTS`. A sequence
 * lapses when the second key arrives more than `timeoutMilliseconds` after the
 * first. A key that does not complete a sequence is read as a fresh key. Callers
 * pass plain keys only (no ctrl, meta or alt); `mod` shortcuts are not handled here.
 */
export function createSequenceMatcher(timeoutMilliseconds: number): (key: string, now: number) => ShortcutId | null {
    let pendingKey: string | null = null;
    let pendingSince = 0;

    return (key, now) => {
        const pressed = key.toUpperCase();
        const firstKey = pendingKey !== null && now - pendingSince <= timeoutMilliseconds ? pendingKey : null;
        pendingKey = null;

        if (firstKey !== null) {
            const sequence = shortcutEntries.find(([, shortcut]) => shortcut.keys.length === 2 && shortcut.keys[0] === firstKey && shortcut.keys[1] === pressed);
            if (sequence) {
                return sequence[0];
            }
        }

        const single = shortcutEntries.find(([, shortcut]) => shortcut.keys.length === 1 && shortcut.keys[0] === pressed);
        if (single) {
            return single[0];
        }

        if (shortcutEntries.some(([, shortcut]) => shortcut.keys.length === 2 && shortcut.keys[0] === pressed)) {
            pendingKey = pressed;
            pendingSince = now;
        }

        return null;
    };
}

/** True while focus is somewhere the user is typing, where hotkeys must stay quiet. */
export function isTypingTarget(element: { tagName?: string; isContentEditable?: boolean } | null): boolean {
    if (element === null) {
        return false;
    }

    return element.isContentEditable === true || ['INPUT', 'TEXTAREA', 'SELECT'].includes(element.tagName ?? '');
}
