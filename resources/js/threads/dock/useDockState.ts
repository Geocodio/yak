import { useCallback, useEffect, useState } from 'react';
import { dockHashFor, parseDockHash } from './dockState';

/** The active dock panel id, kept in the URL hash so a reload or a shared link keeps the panel open. */
export function useDockState(): { activeId: string | null; setActiveId: (id: string | null) => void } {
    const [activeId, setActiveIdState] = useState<string | null>(() => (typeof window === 'undefined' ? null : parseDockHash(window.location.hash)));

    useEffect(() => {
        const onHashChange = () => setActiveIdState(parseDockHash(window.location.hash));
        window.addEventListener('hashchange', onHashChange);

        return () => window.removeEventListener('hashchange', onHashChange);
    }, []);

    const setActiveId = useCallback((id: string | null) => {
        setActiveIdState(id);
        window.history.replaceState(window.history.state, '', `${window.location.pathname}${window.location.search}${dockHashFor(id)}`);
    }, []);

    return { activeId, setActiveId };
}
