import { useEffect, useRef, useSyncExternalStore } from 'react';
import { getActionGroups, registerActions, subscribe, unregisterActions } from './store.ts';
import type { ActionGroup, PaletteAction } from './types.ts';

/** Registers actions while the calling component is mounted. Pass a memoized array. */
export function useCommandPaletteActions(group: string, actions: PaletteAction[]): void {
    const tokenRef = useRef<symbol>(Symbol(group));

    useEffect(() => {
        registerActions(tokenRef.current, group, actions);
    }, [group, actions]);

    useEffect(() => {
        const token = tokenRef.current;

        return () => unregisterActions(token);
    }, []);
}

export function useActionGroups(): ActionGroup[] {
    return useSyncExternalStore(subscribe, getActionGroups, getActionGroups);
}
