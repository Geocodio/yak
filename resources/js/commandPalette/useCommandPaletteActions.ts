import { useEffect, useSyncExternalStore } from 'react';
import { getActionGroups, registerActions, subscribe, unregisterActions } from './store.ts';
import type { ActionGroup, PaletteAction } from './types.ts';

/** Registers actions while the calling component is mounted. Pass a memoized array. */
export function useCommandPaletteActions(group: string, actions: PaletteAction[]): void {
    useEffect(() => {
        const token = Symbol(group);
        registerActions(token, group, actions);

        return () => unregisterActions(token);
    }, [group, actions]);
}

export function useActionGroups(): ActionGroup[] {
    return useSyncExternalStore(subscribe, getActionGroups, getActionGroups);
}
