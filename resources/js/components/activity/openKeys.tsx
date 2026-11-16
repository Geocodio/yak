import { createContext, useContext, useRef, type ReactNode } from 'react';

type OpenKeys = { has: (key: string) => boolean; set: (key: string, open: boolean) => void };

const OpenKeysContext = createContext<OpenKeys | null>(null);

/**
 * Remembers which rows and groups are open by key. A row that moves between
 * containers as the run grows (the active group folding away, say) mounts
 * again; this keeps it open if the reader opened it.
 */
export function OpenKeysProvider({ children }: { children: ReactNode }) {
    const keysRef = useRef(new Set<string>());
    const valueRef = useRef<OpenKeys>({
        has: (key) => keysRef.current.has(key),
        set: (key, open) => {
            if (open) {
                keysRef.current.add(key);
            } else {
                keysRef.current.delete(key);
            }
        },
    });

    return <OpenKeysContext.Provider value={valueRef.current}>{children}</OpenKeysContext.Provider>;
}

/** Open state under one or more keys: it counts as open if any key was left open, and is remembered under all of them. */
export function useOpenKey(...keys: string[]): { defaultOpen: boolean; remember: (open: boolean) => void } {
    const openKeys = useContext(OpenKeysContext);

    return { defaultOpen: keys.some((key) => openKeys?.has(key)), remember: (open) => keys.forEach((key) => openKeys?.set(key, open)) };
}
