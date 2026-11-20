import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

export { livePropsSignature } from './livePropsSignature';

const SETTLE_MS = 300;
const RETRY_MS = 3000;

let mutationsInFlight = 0;

// An action answers with a redirect and flashes its outcome for the next request. A reload that reaches the server
// before that redirect is followed takes the flash, and the card never shows the error, so reloads wait for actions.
router.on('start', (event) => {
    if (event.detail.visit.method !== 'get') {
        mutationsInFlight++;
    }
});
router.on('finish', (event) => {
    if (event.detail.visit.method !== 'get') {
        mutationsInFlight--;
    }
});

/** True while a non-GET visit is running, so a reload of other props does not take its flash. */
export function hasMutationsInFlight(): boolean {
    return mutationsInFlight > 0;
}

/**
 * The status pill, the composer and the panels render server props. A turn or thread event means those
 * props are stale, so this reloads them, and keeps trying while the link is down.
 */
export function useRefreshThreadProps(signature: string): void {
    const seen = useRef(signature);

    useEffect(() => {
        if (seen.current === signature) {
            return;
        }

        seen.current = signature;
        let timer = window.setTimeout(reload, SETTLE_MS);

        function reload(): void {
            if (mutationsInFlight > 0) {
                timer = window.setTimeout(reload, SETTLE_MS);

                return;
            }

            const retry = (): false => {
                timer = window.setTimeout(reload, RETRY_MS);

                return false;
            };

            router.reload({ showProgress: false, onNetworkError: retry, onHttpException: () => false });
        }

        return () => window.clearTimeout(timer);
    }, [signature]);
}
