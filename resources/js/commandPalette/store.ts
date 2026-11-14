import type { ShortcutId } from './shortcuts.ts';
import type { ActionGroup, PaletteAction } from './types.ts';

/** Groups shown after every page group, in this order. */
export const GROUP_ORDER: string[] = ['Create', 'Go to', 'Settings', 'Help'];

const registrations = new Map<symbol, { group: string; actions: PaletteAction[] }>();
const listeners = new Set<() => void>();
let snapshot: ActionGroup[] = [];

function rebuildSnapshot(): void {
    const merged = new Map<string, PaletteAction[]>();
    for (const { group, actions } of registrations.values()) {
        merged.set(group, [...(merged.get(group) ?? []), ...actions]);
    }

    const rank = (group: string): number => GROUP_ORDER.indexOf(group);

    snapshot = [...merged.entries()]
        .map(([group, actions]) => ({ group, actions }))
        .sort((left, right) => rank(left.group) - rank(right.group));

    listeners.forEach((listener) => listener());
}

/** Registers actions under a group for a token; registering again with the same token replaces them. */
export function registerActions(token: symbol, group: string, actions: PaletteAction[]): void {
    registrations.set(token, { group, actions });
    rebuildSnapshot();
}

export function unregisterActions(token: symbol): void {
    if (registrations.delete(token)) {
        rebuildSnapshot();
    }
}

/** The registered groups, page groups first. The same array is returned until a registration changes. */
export function getActionGroups(): ActionGroup[] {
    return snapshot;
}

export function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

export function findActionByShortcut(id: ShortcutId): PaletteAction | null {
    for (const { actions } of snapshot) {
        const found = actions.find((action) => action.shortcut === id);
        if (found) {
            return found;
        }
    }

    return null;
}
