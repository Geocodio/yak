import { useEffect, useState } from 'react';

/** True while a thread page has a dock panel open (it sets `data-thread-docked` on the body). */
export function useThreadDocked(): boolean {
    const [isDocked, setIsDocked] = useState(() => typeof document !== 'undefined' && document.body.dataset.threadDocked !== undefined);

    useEffect(() => {
        const read = () => setIsDocked(document.body.dataset.threadDocked !== undefined);
        const observer = new MutationObserver(read);
        observer.observe(document.body, { attributes: true, attributeFilter: ['data-thread-docked'] });
        read();

        return () => observer.disconnect();
    }, []);

    return isDocked;
}
