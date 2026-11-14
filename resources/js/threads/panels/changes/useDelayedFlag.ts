import { useEffect, useState } from 'react';

/** True once `isActive` has held for `delayMs`, so a wait that ends quickly never flashes a placeholder. */
export function useDelayedFlag(isActive: boolean, delayMs = 150): boolean {
    const [isShown, setIsShown] = useState(false);

    useEffect(() => {
        if (!isActive) {
            setIsShown(false);
            return;
        }
        const timer = setTimeout(() => setIsShown(true), delayMs);
        return () => clearTimeout(timer);
    }, [isActive, delayMs]);

    return isActive && isShown;
}
