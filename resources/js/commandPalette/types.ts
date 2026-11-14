import type { ShortcutId } from './shortcuts.ts';

export type PaletteStep = {
    title: string;
    placeholder: string;
    items: (query: string) => PaletteAction[] | Promise<PaletteAction[]>;
};

export type PaletteAction = {
    id: string;
    label: string;
    /** Extra match words, never shown. */
    keywords: string[];
    /** Trailing muted text, for example "new window". */
    hint?: string;
    /** Key into the shortcut table. */
    shortcut?: ShortcutId;
    /** Makes the row a link; Cmd-click opens a new tab. */
    href?: string;
    destructive?: boolean;
    confirm?: { title: string; body: string; confirmLabel: string };
    /** Reason the action cannot run now. */
    unavailable?: string;
    run?: () => void;
    /** In place of `run`. */
    steps?: PaletteStep;
    /** Child actions of a search result. */
    more?: PaletteStep;
};

export type ActionGroup = { group: string; actions: PaletteAction[] };
